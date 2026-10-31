"use client"

import { useEffect, useId, useState, type ReactNode } from "react"
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { formatVnd, moneyCents } from "@/lib/money"
import "./report-charts.css"

export type MoneyPoint = { label: string; raw: string | null }
type PlotPoint = MoneyPoint & { value: number | null }
const colors = Array.from({ length: 6 }, (_, i) => `var(--av2-chart-${i + 1})`)

function scaleMoney(rows: MoneyPoint[]) {
  const cents = rows.map(row => row.raw === null ? null : moneyCents(row.raw))
  const max = cents.reduce<bigint>((max, value) => value !== null && (value < BigInt(0) ? -value : value) > max ? (value < BigInt(0) ? -value : value) : max, BigInt(0))
  let unit = BigInt(100), rank = 0
  while (max / unit >= BigInt(1000000)) { unit *= BigInt(1000); rank++ }
  const unitLabel = ["₫", "nghìn ₫", "triệu ₫", "tỷ ₫"][rank] ?? `10^${rank * 3} ₫`
  return { unitLabel, points: rows.map((row, i) => ({ ...row, value: cents[i] === null ? null : Number(cents[i]! * BigInt(1000) / unit) / 1000 })) }
}

function ChartFrame({ title, description, source, rows, children, footer }: { title: string; description: string; source: string; rows: MoneyPoint[]; children: ReactNode; footer?: string }) {
  return <figure className="av2-chart-card" aria-label={title} data-chart-source={source}>
    <figcaption><div><h3>{title}</h3><p>{description}</p></div><span className="av2-chart-source">{source === "shop" ? "Shop" : "Nội bộ"}</span></figcaption>
    {children}
    <ul className="av2-chart-accessible" aria-label={`Dữ liệu ${title}`}>{rows.map((row, i) => <li key={i} data-label={row.label} data-value={row.raw ?? "NULL"}>{row.label}: {row.raw === null ? "Chưa đủ dữ liệu" : formatVnd(row.raw)}</li>)}</ul>
    {footer && <p className="av2-chart-footnote">{footer}</p>}
  </figure>
}

function ChartTooltip({ active, payload }: { active?: boolean; payload?: readonly { payload?: PlotPoint }[] }) {
  const point = payload?.[0]?.payload
  return active && point ? <div className="av2-chart-tooltip"><span>{point.label}</span><strong>{point.raw === null ? "Chưa đủ dữ liệu" : formatVnd(point.raw)}</strong></div> : null
}

function useChartReady() {
  const [ready, setReady] = useState(false)
  useEffect(() => setReady(true), [])
  return ready
}

export function FinancialTrend({ rows }: { rows: { bucket: string; metrics: { net_sales: string | null } }[] }) {
  const ready = useChartReady()
  const id = useId().replace(/[^a-zA-Z0-9]/g, "")
  const raw = rows.map(row => ({ label: row.bucket, raw: row.metrics.net_sales }))
  const { unitLabel, points } = scaleMoney(raw)
  return <ChartFrame title="Diễn biến doanh thu thuần" description="Theo mốc xác nhận trong kỳ · Asia/Ho_Chi_Minh" source="internal" rows={raw} footer={`${rows.length} mốc thời gian từ báo cáo. NULL được giữ là thiếu dữ liệu; không nối qua mốc thiếu. Tooltip hiển thị giá trị gốc, trục được rút gọn khi cần.`}>
    <div className="av2-chart-legend"><span><i/>Doanh thu thuần trước thuế</span><small>Đơn vị: {unitLabel}</small></div>
    {!rows.length ? <p className="av2-chart-empty">Chưa có giao dịch phù hợp.</p> : <div className="av2-chart-canvas" data-chart-kind="area">{ready && <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 300, height: 280 }}><AreaChart data={points} margin={{ top: 16, right: 18, left: 6, bottom: 12 }} accessibilityLayer>
      <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--av2-chart-1)" stopOpacity={0.24}/><stop offset="100%" stopColor="var(--av2-chart-1)" stopOpacity={0.01}/></linearGradient></defs>
      <CartesianGrid stroke="var(--av2-line)" strokeDasharray="4 5" vertical={false}/>
      <XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={28} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => String(value).replace(/^\d{4}-/, "")}/>
      <YAxis width={64} tickLine={false} axisLine={false} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 1 })}/>
      <Tooltip content={<ChartTooltip/>} cursor={{ stroke: "var(--av2-accent)", strokeDasharray: "3 3" }}/>
      <Area type="linear" dataKey="value" stroke="var(--av2-chart-1)" strokeWidth={3} fill={`url(#${id})`} connectNulls={false} dot={{ r: 4, fill: "var(--av2-surface)", strokeWidth: 2 }} activeDot={{ r: 6 }} isAnimationActive={false}/>
    </AreaChart></ResponsiveContainer>}</div>}
  </ChartFrame>
}

export function FinancialBars({ title, description, rows, source = "shop" }: { title: string; description: string; rows: MoneyPoint[]; source?: string }) {
  const ready = useChartReady()
  const { unitLabel, points } = scaleMoney(rows)
  return <ChartFrame title={title} description={description} source={source} rows={rows} footer="Các thanh so sánh cùng một đại lượng. Giá trị chính xác giữ ở bảng đối chiếu và tooltip.">
    <div className="av2-chart-legend"><span><i/>Giá trị theo từng dòng</span><small>Đơn vị: {unitLabel}</small></div>
    {!rows.length ? <p className="av2-chart-empty">Chưa có dữ liệu trong kỳ.</p> : <div className="av2-chart-canvas av2-chart-bars" style={{ height: Math.max(240, rows.length * 43 + 50) }} data-chart-kind="bar">{ready && <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 300, height: 280 }}><BarChart layout="vertical" data={points} margin={{ top: 8, right: 22, left: 2, bottom: 8 }} barSize={17} accessibilityLayer>
      <CartesianGrid stroke="var(--av2-line)" strokeDasharray="4 5" horizontal={false}/>
      <XAxis type="number" tickLine={false} axisLine={false} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 1 })}/>
      <YAxis type="category" dataKey="label" width={116} tickLine={false} axisLine={false} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => String(value).length > 19 ? `${String(value).slice(0, 18)}…` : String(value)}/>
      <Tooltip content={<ChartTooltip/>} cursor={{ fill: "var(--av2-soft)" }}/>
      <Bar dataKey="value" radius={[0, 5, 5, 0]} isAnimationActive={false}>{points.map((_, i) => <Cell key={i} fill={colors[i % colors.length]}/>)}</Bar>
    </BarChart></ResponsiveContainer>}</div>}
  </ChartFrame>
}

type CountPoint = { label: string; key: string; count: number }
function CountTooltip({ active, payload }: { active?: boolean; payload?: readonly { payload?: CountPoint }[] }) {
  const point = payload?.[0]?.payload
  return active && point ? <div className="av2-chart-tooltip"><span>{point.label}</span><strong>{point.count.toLocaleString("vi-VN")} đơn</strong></div> : null
}

export function OrderStatusDonut({ rows }: { rows: CountPoint[] }) {
  const ready = useChartReady()
  const total = rows.reduce((sum, row) => sum + row.count, 0)
  return <figure className="av2-chart-card" aria-label="Tỷ trọng đơn theo trạng thái" data-chart-source="shop">
    <figcaption><div><h3>Tỷ trọng đơn theo trạng thái</h3><p>Đơn được tạo trong kỳ · trạng thái hiện tại</p></div><span className="av2-chart-source">Shop</span></figcaption>
    {!total ? <p className="av2-chart-empty">Chưa có đơn được tạo trong kỳ.</p> : <div className="av2-chart-donut" data-chart-kind="donut"><div className="av2-donut-center"><strong>{total.toLocaleString("vi-VN")}</strong><span>đơn trong kỳ</span></div>{ready && <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 300, height: 280 }}><PieChart accessibilityLayer><Pie data={rows} dataKey="count" nameKey="label" innerRadius="64%" outerRadius="86%" paddingAngle={2} stroke="var(--av2-surface)" strokeWidth={3} isAnimationActive={false}>{rows.map((row, i) => <Cell key={row.key} fill={colors[i % colors.length]}/>)}</Pie><Tooltip content={<CountTooltip/>}/></PieChart></ResponsiveContainer>}</div>}
    <ul className="av2-donut-legend">{rows.map((row, i) => <li key={row.key} data-order-status={row.key} data-count={row.count}><span><i style={{ background: colors[i % colors.length] }}/>{row.label}</span><strong>{row.count.toLocaleString("vi-VN")}<small> đơn</small></strong></li>)}</ul>
    <p className="av2-chart-footnote">Tỷ trọng theo số đơn; không biểu thị doanh thu hoặc tỷ lệ chuyển đổi.</p>
  </figure>
}

export function MonthlySales({ rows, year }: { rows: MoneyPoint[]; year: string }) {
  const ready = useChartReady()
  const { unitLabel, points } = scaleMoney(rows)
  return <ChartFrame title="Doanh thu theo tháng" description={`Năm ${year} · doanh thu thuần nội bộ trước thuế`} source="internal" rows={rows} footer="Chỉ hiển thị tháng có giao dịch trong báo cáo năm. Tháng đang diễn ra là số lũy kế đến hiện tại; không phân bổ hoặc bịa số cho tháng khác.">
    <div className="av2-chart-legend"><span><i/>Doanh thu thuần</span><small>Đơn vị: {unitLabel}</small></div>
    {!rows.length ? <p className="av2-chart-empty">Chưa có tháng phát sinh doanh thu trong phạm vi được xem.</p> : <div className="av2-chart-canvas" data-chart-kind="monthly">{ready && <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 300, height: 280 }}><BarChart data={points} margin={{ top: 16, right: 18, left: 6, bottom: 12 }} barSize={36} accessibilityLayer>
      <CartesianGrid stroke="var(--av2-line)" strokeDasharray="4 5" vertical={false}/><XAxis dataKey="label" axisLine={false} tickLine={false} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => `Tháng ${String(value).slice(5)}`}/><YAxis width={64} axisLine={false} tickLine={false} tick={{ fill: "var(--av2-muted)", fontSize: 11 }} tickFormatter={value => Number(value).toLocaleString("vi-VN")}/><Tooltip content={<ChartTooltip/>} cursor={{ fill: "var(--av2-soft)" }}/><Bar dataKey="value" fill="var(--av2-chart-1)" radius={[6,6,0,0]} isAnimationActive={false}/>
    </BarChart></ResponsiveContainer>}</div>}
  </ChartFrame>
}
