import { runtimeEnvironment } from "@/lib/runtime-environment"
import { cookies } from "next/headers"
import { nosqlServerApiUrl } from "./server-origin"
import { moneyCents, moneyFromCents } from "./money"
import type { Profile } from "./api"

export type DashboardInsights = { chain: string; customers: number | null; orders: number | null; period: string; comparisonPeriod: string; current: string | null; previous: string | null; months: { label: string; raw: string | null }[]; year: string }
type Sales = { trend: { bucket: string; metrics: { net_sales: string | null } }[] }

export async function dashboardInsights(): Promise<DashboardInsights | null> {
  const session = (await cookies()).get(runtimeEnvironment().sessionCookie)
  if (!session) return null
  const headers = { Accept: "application/json", Origin: runtimeEnvironment().web, Cookie: `${runtimeEnvironment().sessionCookie}=${encodeURIComponent(session.value)}` }
  async function read<T>(path: string): Promise<T | null> {
    try { const response = await fetch(`${nosqlServerApiUrl()}${path}`, { headers, cache: "no-store" }); return response.ok ? await response.json() as T : null } catch { return null }
  }
  const profile = await read<Profile>("/api/me")
  if (!profile?.user.can_view_reports || profile.report_chains.length !== 1) return null
  const chain = profile.report_chains[0]
  const date = new Date().toLocaleDateString("sv-SE", { timeZone: "Asia/Ho_Chi_Minh" })
  const [year, month, day] = date.split("-").map(Number)
  const previousEnd = new Date(Date.UTC(year, month - 1, 0))
  const previousMonth = previousEnd.toISOString().slice(0, 7)
  const cutoff = `${previousMonth}-${String(Math.min(day, previousEnd.getUTCDate())).padStart(2, "0")}`
  const params = (period: string, value: string) => new URLSearchParams({ period, date: value, chain_id: chain.id })
  const [annual, current, previous, customers, shop] = await Promise.all([
    read<Sales>(`/api/reports/sales?${params("year", date)}`),
    read<Sales>(`/api/reports/sales?${params("month", date)}`),
    read<Sales>(`/api/reports/sales?${params("month", `${previousMonth}-01`)}`),
    read<{ data: unknown[] }>(`/api/shop-admin/${chain.id}/marketing/customers`),
    read<{ data: { status_counts: Record<string, number> } }>(`/api/shop-admin/${chain.id}/reports?from=${date.slice(0, 7)}-01&to=${date}`),
  ])
  const sum = (report: Sales | null, until: string) => {
    if (!report) return null
    let total = BigInt(0)
    for (const row of report.trend.filter(row => row.bucket <= until)) { const value = row.metrics.net_sales === null ? null : moneyCents(row.metrics.net_sales); if (value === null) return null; total += value }
    return moneyFromCents(total)
  }
  return { chain: chain.name, customers: customers ? customers.data.length : null, orders: shop ? Object.values(shop.data.status_counts).reduce((a, b) => a + b, 0) : null, period: `01/${String(month).padStart(2,"0")}–${String(day).padStart(2,"0")}/${String(month).padStart(2,"0")}/${year}`, comparisonPeriod: `01/${previousMonth.slice(5)}–${cutoff.slice(8)}/${previousMonth.slice(5)}/${previousMonth.slice(0,4)}`, current: sum(current, date), previous: sum(previous, cutoff), months: annual?.trend.map(row => ({ label: row.bucket, raw: row.metrics.net_sales })) ?? [], year: String(year) }
}
