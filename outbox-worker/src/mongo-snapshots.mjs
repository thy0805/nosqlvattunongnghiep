import { Long } from "mongodb"
import { decimalId } from "./contract.mjs"
import { metricsFromTotals } from "./mongo-metrics.mjs"

function rational(value) {
  const match = /^(-?)(\d+)(?:\.(\d*))?(?:E([+-]?\d+))?$/i.exec(String(value))
  if (!match || Math.abs(Number(match[4] ?? 0)) > 100) throw new Error("SNAPSHOT_DECIMAL_INVALID")
  const scale = (match[3] ?? "").length - Number(match[4] ?? 0)
  const n = BigInt(match[2] + (match[3] ?? "")) * (match[1] ? -1n : 1n)
  return scale < 0 ? [n * 10n ** BigInt(-scale), 1n] : [n, 10n ** BigInt(scale)]
}

export function roundedProduct(a, b = "1") {
  const [an, ad] = rational(a), [bn, bd] = rational(b)
  const n = an * bn * 100n, d = ad * bd, sign = n < 0n ? -1n : 1n, positive = n * sign
  return (positive / d + (positive % d * 2n >= d ? 1n : 0n)) * sign
}

function money(n) {
  const sign = n < 0n ? "-" : "", p = n < 0n ? -n : n
  return `${sign}${p / 100n}.${String(p % 100n).padStart(2, "0")}`
}

function bounds(period, start) {
  const [year, month, day] = start.split("-").map(Number)
  const end = new Date(Date.UTC(year, month - 1, day))
  if (period === "day") end.setUTCDate(end.getUTCDate() + 1)
  else if (period === "month") end.setUTCMonth(end.getUTCMonth() + 1)
  else if (period === "year") end.setUTCFullYear(end.getUTCFullYear() + 1)
  else throw new Error("SNAPSHOT_PERIOD_INVALID")
  return [new Date(`${start}T00:00:00+07:00`), new Date(`${end.toISOString().slice(0, 10)}T00:00:00+07:00`)]
}

export async function sourceTotals(database, session, chainId, branchId, period, start) {
  const options = { session }, [from, to] = bounds(period, start)
  const branches = await database.collection("branches").find({ chain_id: chainId }, options).toArray()
  const ids = branches.map(b => b._id).filter(id => branchId === null || id === branchId)
  const sales = database.collection("sales_orders").find({ status: "confirmed", branch_id: { $in: ids }, sold_at: { $gte: from, $lt: to } }, options)
  const total = { invoice_count: 0, gross_sales: 0n, total_discount: 0n, total_tax: 0n, cogs: 0n, amount_collected: 0n, incomplete_cost_count: 0, incomplete_tax_count: 0, invoice_mismatch_count: 0 }
  for await (const sale of sales) {
    const invoice = await database.collection("invoices").findOne({ sales_order_id: sale._id }, options)
    const items = await database.collection("sales_order_items").find({ order_id: sale._id }, options).toArray()
    if (!invoice || items.length === 0) continue
    let gross = 0n, discount = 0n, tax = 0n, cost = 0n, missingCost = false, missingTax = false
    for (const item of items) {
      gross += roundedProduct(item.quantity, item.unit_price)
      discount += roundedProduct(item.discount_amount)
      if (item.tax_amount !== null) tax += roundedProduct(item.tax_amount)
      if (item.unit_cost_snapshot !== null) cost += roundedProduct(item.quantity, item.unit_cost_snapshot)
      else missingCost = true
      if (item.tax_rate_snapshot === null || item.tax_amount === null) missingTax = true
    }
    total.invoice_count++
    total.gross_sales += gross; total.total_discount += discount; total.total_tax += tax; total.cogs += cost
    total.incomplete_cost_count += Number(missingCost); total.incomplete_tax_count += Number(missingTax)
    total.invoice_mismatch_count += Number(roundedProduct(invoice.total_amount) !== gross - discount + tax)
    for await (const payment of database.collection("payments").find({ invoice_id: invoice._id, status: "completed" }, options)) total.amount_collected += roundedProduct(payment.amount)
  }
  return metricsFromTotals(Object.fromEntries(Object.entries(total).map(([k, v]) => [k, typeof v === "bigint" ? money(v) : v])))
}

export async function rebuildForMongoEvent(database, session, event) {
  let orderId = event.event_type === "sale.confirmed" ? decimalId(event.aggregate_id) : null
  if (event.event_type === "debt.payment_recorded") {
    if (event.payload.source_type === "purchase_receipt") return
    if (event.payload.source_type !== "invoice") throw new Error("SNAPSHOT_SOURCE_TYPE_INVALID")
    const invoice = await database.collection("invoices").findOne({ _id: decimalId(event.payload.source_id) }, { session })
    if (!invoice) throw new Error("SNAPSHOT_INVOICE_MISSING")
    orderId = invoice.sales_order_id
  }
  if (orderId === null) return
  const sale = await database.collection("sales_orders").findOne({ _id: decimalId(orderId) }, { session })
  if (!sale || !(sale.sold_at instanceof Date) || !Number.isFinite(sale.sold_at.getTime())) throw new Error("SNAPSHOT_ORDER_MISSING")
  const branch = await database.collection("branches").findOne({ _id: sale.branch_id }, { session })
  if (!branch) throw new Error("SNAPSHOT_BRANCH_MISSING")
  const day = new Date(sale.sold_at.getTime() + 7 * 3600000).toISOString().slice(0, 10)
  for (const [period, start] of [["day", day], ["month", day.slice(0, 7) + "-01"], ["year", day.slice(0, 4) + "-01-01"]]) {
    for (const branchId of [sale.branch_id, null]) {
      const key = `${branch.chain_id}:${branchId ?? "all"}:${period}:${start}`
      await database.collection("coordination_guards").updateOne({ _id: `snapshot:${key}` }, { $inc: { version: Long.ONE } }, { session, upsert: true })
      const metrics = await sourceTotals(database, session, branch.chain_id, branchId, period, start)
      await database.collection("dashboard_snapshots").replaceOne({ _id: key }, {
        _id: key, chain_id: branch.chain_id, branch_id: branchId, period, period_start: start,
        metrics, source_event_id: decimalId(event._id), updated_at: new Date(),
      }, { session, upsert: true })
    }
  }
}
