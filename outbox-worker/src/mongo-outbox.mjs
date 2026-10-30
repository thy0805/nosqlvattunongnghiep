import { randomUUID } from "node:crypto"
import { isDeepStrictEqual } from "node:util"
import { Long } from "mongodb"
import { auditDocument, backoffSeconds, decimalId } from "./contract.mjs"
import { rebuildForMongoEvent } from "./mongo-snapshots.mjs"
import { syncMongoNotifications } from "./mongo-notifications.mjs"

const events = new Map([
  ["inventory.alert_opened", "inventory_alert_incident"], ["inventory.alert_resolved", "inventory_alert_incident"],
  ["purchase.approved", "purchase_receipt"], ["sale.confirmed", "sales_order"], ["debt.payment_recorded", "payment"],
  ...["requested", "approved", "rejected", "dispatched", "received", "reconciled"].map(s => [`transfer.${s}`, "stock_transfer"]),
])

export function validateEvent(event) {
  decimalId(event._id); decimalId(event.aggregate_id)
  if (events.get(event.event_type) !== event.aggregate_type || event.event_version !== 1) throw new Error("OUTBOX_EVENT_UNSUPPORTED")
  if (!event.payload || typeof event.payload !== "object" || Array.isArray(event.payload)) throw new Error("OUTBOX_PAYLOAD_INVALID")
  if (!(event.created_at instanceof Date) || !Number.isFinite(event.created_at.getTime())) throw new Error("OUTBOX_DATE_INVALID")
  for (const key of ["actor_id", "branch_id", "from_branch_id", "to_branch_id"]) if (event.payload[key] !== undefined && event.payload[key] !== null) decimalId(event.payload[key])
  decimalId(event.payload.branch_id ?? event.payload.from_branch_id)
}

export class MongoOutbox {
  constructor(client, database, { clock = () => new Date(), hook = async () => {} } = {}) {
    this.client = client; this.database = database; this.clock = clock; this.hook = hook
    this.events = database.collection("outbox_events")
  }

  async claimOne() {
    const now = this.clock()
    await this.events.updateMany({ status: "processing", attempt_count: { $gte: 5 }, lease_until: { $lte: now } }, {
      $set: { status: "failed", locked_at: null, lease_token: null, lease_until: null, last_error: "Worker interrupted after final attempt" }, $inc: { _version: Long.ONE },
    })
    return this.events.findOneAndUpdate({ attempt_count: { $lt: 5 }, $or: [
      { status: { $in: ["pending", "retry"] }, next_attempt_at: { $lte: now } },
      { status: "processing", lease_until: { $lte: now } },
    ] }, { $set: { status: "processing", locked_at: now, lease_until: new Date(now.getTime() + 120000), lease_token: randomUUID() },
      $inc: { attempt_count: 1, _version: Long.ONE } }, { sort: { id_sort: 1 }, returnDocument: "after" })
  }

  fence(event) {
    return { _id: event._id, status: "processing", attempt_count: event.attempt_count, lease_token: event.lease_token, lease_until: { $gt: this.clock() } }
  }

  async deliver(event) {
    const session = this.client.startSession()
    let callbackAttempts = 0, uncertain = false
    const deadline = Date.now() + 30000
    try {
      await session.withTransaction(async () => {
        if (++callbackAttempts > 5 || Date.now() >= deadline) throw new Error("OUTBOX_TRANSACTION_DEADLINE")
        const fence = await this.events.updateOne(this.fence(event), { $inc: { _version: Long.ONE } }, { session })
        if (fence.matchedCount !== 1) throw new Error("OUTBOX_LEASE_LOST")
        validateEvent(event)
        await this.hook("before-projections", event, session)
        const audit = auditDocument({ ...event, id: event._id })
        await this.database.collection("audit_logs").updateOne({ _id: event._id }, { $setOnInsert: audit }, { session, upsert: true })
        const existing = await this.database.collection("audit_logs").findOne({ _id: event._id }, { session })
        for (const key of Object.keys(audit).filter(k => k !== "synced_at")) {
          if (!isDeepStrictEqual(existing[key], audit[key])) throw new Error("OUTBOX_AUDIT_CONFLICT")
        }
        await this.hook("after-audit", event, session)
        await syncMongoNotifications(this.database, session, event, this.hook)
        await rebuildForMongoEvent(this.database, session, event)
        await this.hook("after-projections", event, session)
        const ack = await this.events.updateOne(this.fence(event), { $set: {
          status: "published", published_at: this.clock(), locked_at: null, lease_token: null, lease_until: null, last_error: null,
        }, $inc: { _version: Long.ONE } }, { session })
        if (ack.matchedCount !== 1) throw new Error("OUTBOX_LEASE_LOST")
        await this.hook("after-ack-before-commit", event, session)
      }, { readConcern: { level: "snapshot" }, readPreference: "primary", writeConcern: { w: "majority", j: true, wtimeoutMS: 10000 }, maxCommitTimeMS: 10000, timeoutMS: 30000 })
      await this.hook("after-commit", event, session)
      return { event_id: event._id, status: "published" }
    } catch (error) {
      uncertain = error.hasErrorLabel?.("UnknownTransactionCommitResult") === true || error.name === "MongoOperationTimeoutError"
      if (uncertain) throw new Error("OUTBOX_COMMIT_UNCERTAIN", { cause: error })
      const failed = event.attempt_count >= 5
      const result = await this.events.updateOne(this.fence(event), { $set: {
        status: failed ? "failed" : "retry", locked_at: null, lease_token: null, lease_until: null,
        last_error: ["OUTBOX_LEASE_LOST", "OUTBOX_EVENT_UNSUPPORTED", "OUTBOX_PAYLOAD_INVALID", "OUTBOX_SCOPE_MISSING"].includes(error.message) ? error.message : "OUTBOX_DELIVERY_FAILED",
        next_attempt_at: new Date(this.clock().getTime() + backoffSeconds(event.attempt_count) * 1000),
      }, $inc: { _version: Long.ONE } })
      return { event_id: event._id, status: result.matchedCount === 1 ? failed ? "failed" : "retry" : "lease_lost" }
    } finally { await session.endSession() }
  }
}
