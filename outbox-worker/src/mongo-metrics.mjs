function cents(value) {
  const text = String(value ?? "0")
  if (!/^-?\d+(\.\d{1,2})?$/.test(text)) throw new Error(`Số tiền snapshot không hợp lệ: ${text}`)
  const negative = text.startsWith("-")
  const [whole, fraction = ""] = (negative ? text.slice(1) : text).split(".")
  const amount = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"))
  return negative ? -amount : amount
}

function money(value) {
  const sign = value < 0n ? "-" : ""
  const positive = value < 0n ? -value : value
  return `${sign}${positive / 100n}.${String(positive % 100n).padStart(2, "0")}`
}

export function metricsFromTotals(row) {
  const gross = cents(row.gross_sales)
  const discount = cents(row.total_discount)
  const tax = cents(row.total_tax)
  const cost = cents(row.cogs)
  const collected = cents(row.amount_collected)
  const net = gross - discount
  const invoice = net + tax
  const costMissing = Number(row.incomplete_cost_count) > 0
  const taxMissing = Number(row.incomplete_tax_count) > 0
  return {
    invoice_count: Number(row.invoice_count), gross_sales: money(gross), total_discount: money(discount),
    net_sales: money(net), total_tax: taxMissing ? null : money(tax),
    invoice_total: taxMissing ? null : money(invoice),
    cogs: costMissing ? null : money(cost), gross_profit: costMissing ? null : money(net - cost),
    amount_collected: money(collected), receivable_remaining: taxMissing ? null : money(invoice - collected),
    incomplete_cost_count: Number(row.incomplete_cost_count),
    incomplete_tax_count: Number(row.incomplete_tax_count),
    invoice_mismatch_count: Number(row.invoice_mismatch_count),
  }
}

