import { ArrowDownRight, ArrowUpRight, CalendarDays, ShoppingBag, Users } from "lucide-react"
import type { DashboardInsights } from "@/lib/dashboard-insights"
import { formatVnd, moneyCents } from "@/lib/money"

export function growthPercent(current: string | null, previous: string | null): string | null {
  const a = current === null ? null : moneyCents(current), b = previous === null ? null : moneyCents(previous)
  if (a === null || b === null || b <= BigInt(0)) return null
  const change = a - b, absolute = change < BigInt(0) ? -change : change
  const rounded = (absolute * BigInt(10000) + b / BigInt(2)) / b
  return `${rounded === BigInt(0) ? "" : change < BigInt(0) ? "-" : "+"}${rounded / BigInt(100)}${rounded % BigInt(100) ? `,${String(rounded % BigInt(100)).padStart(2,"0")}` : ""}%`
}

export function DashboardInsightCards({ data }: { data: DashboardInsights }) {
  const growth = growthPercent(data.current, data.previous)
  const decrease = growth?.startsWith("-")
  const unchanged = growth === "0%"
  const Direction = decrease ? ArrowDownRight : ArrowUpRight
  return <section className="av2-insights" aria-label="Khách hàng, đơn Shop và doanh thu tháng">
    <article className="av2-insight-card" data-insight="customers"><span className="av2-kpi-icon"><Users size={22}/></span><p>Khách hàng của chuỗi</p><strong>{data.customers === null ? "Chưa đủ quyền hoặc dữ liệu" : data.customers.toLocaleString("vi-VN")}</strong><small>Customer có quan hệ với {data.chain} · không gồm nhân viên</small></article>
    <article className="av2-insight-card" data-insight="orders"><span className="av2-kpi-icon"><ShoppingBag size={22}/></span><p>Đơn Shop trong tháng</p><strong>{data.orders === null ? "Chưa đủ quyền hoặc dữ liệu" : data.orders.toLocaleString("vi-VN")}</strong><small>Ngày tạo {data.period} · mọi trạng thái · không gồm POS</small></article>
    <article className="av2-insight-card" data-insight="month"><span className="av2-kpi-icon"><CalendarDays size={22}/></span><p>Doanh thu tháng đến hiện tại</p><strong>{data.current === null ? "Chưa đủ dữ liệu" : formatVnd(data.current)}</strong><small>Nội bộ trước thuế · {data.period}</small><div className={`av2-growth ${growth ? unchanged ? "flat" : decrease ? "down" : "up" : "unavailable"}`} data-growth={growth ?? "unavailable"}>{growth && !unchanged && <Direction size={16}/>}<b>{growth ?? "Chưa đủ dữ liệu so sánh"}</b></div><small>So với {data.comparisonPeriod}: {data.previous === null ? "chưa tải được" : formatVnd(data.previous)}{data.previous !== null && moneyCents(data.previous) === BigInt(0) ? " · kỳ trước bằng 0, không tính %" : ""}</small></article>
  </section>
}
