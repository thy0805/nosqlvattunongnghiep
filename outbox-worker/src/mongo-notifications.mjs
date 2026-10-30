import { Long } from "mongodb"
import { decimalId } from "./contract.mjs"

export async function syncMongoNotifications(database, session, event, hook = async () => {}) {
  if (!["inventory.alert_opened", "inventory.alert_resolved"].includes(event.event_type)) return
  const incidentId = decimalId(event.aggregate_id), options = { session }
  const guard = await database.collection("coordination_guards").updateOne({ _id: "domain:v1" }, { $inc: { version: Long.ONE } }, options)
  if (guard.matchedCount !== 1) throw new Error("NOTIFICATION_GUARD_MISSING")
  const incident = await database.collection("inventory_alert_incidents").findOne({ _id: incidentId }, options)
  if (!incident || incident.branch_id !== event.payload.branch_id) throw new Error("NOTIFICATION_INCIDENT_MISSING")
  if (incident.status !== "open" && incident.status !== "resolved") throw new Error("NOTIFICATION_INCIDENT_STATE")
  const resolvedAt = incident.resolved_at ?? null
  if ((incident.status === "resolved") !== (resolvedAt instanceof Date)) throw new Error("NOTIFICATION_RESOLUTION_INVALID")
  await hook("before-notifications", event, session)
  const collection = database.collection("notifications")
  if (event.event_type === "inventory.alert_opened") {
    const payload = event.payload
    if (!Array.isArray(payload.recipient_user_ids) || !["low_stock", "expiry"].includes(payload.kind)
      || typeof payload.message !== "string" || payload.kind !== incident.kind) throw new Error("NOTIFICATION_PAYLOAD_INVALID")
    for (const userId of [...new Set(payload.recipient_user_ids.map(decimalId))]) {
      const id = `${event._id}:${userId}`, existing = await collection.findOne({ _id: id }, options)
      const document = { event_id: decimalId(event._id), user_id: userId, branch_id: decimalId(payload.branch_id), incident_id: incidentId,
        kind: payload.kind, status: "unread", message: payload.message, created_at: event.created_at, read_at: null }
      if (existing && ["event_id", "user_id", "branch_id", "incident_id", "kind", "message"].some(key => existing[key] !== document[key])) throw new Error("NOTIFICATION_CONFLICT")
      await collection.updateOne({ _id: id }, { $setOnInsert: document, $set: { resolved_at: resolvedAt ?? existing?.resolved_at ?? null } }, { ...options, upsert: true })
    }
  } else {
    if (event.payload.incident_id !== incidentId || !resolvedAt) throw new Error("NOTIFICATION_NOT_RESOLVED")
    await collection.updateMany({ incident_id: incidentId }, { $set: { resolved_at: resolvedAt } }, options)
  }
  await hook("after-notifications", event, session)
}
