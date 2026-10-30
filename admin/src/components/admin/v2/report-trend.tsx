"use client"

import { formatVnd, moneyCents } from "@/lib/money"

type Row = { bucket: string; metrics: { net_sales: string | null } }

export function ReportTrend({ rows }: { rows: Row[] }) {
  const values = rows.map(row => row.metrics.net_sales === null ? null : moneyCents(row.metrics.net_sales))
  const scale = values.reduce<bigint>((max, value) => value !== null && (value < BigInt(0) ? -value : value) > max ? (value < BigInt(0) ? -value : value) : max, BigInt(0))
  return <figure className="av2-trend" aria-label="Biểu đồ doanh thu thuần theo kỳ từ báo cáo nội bộ">
    <figcaption>Doanh thu thuần theo kỳ · Cùng bộ lọc với báo cáo bên dưới</figcaption>
    {!rows.length ? <p>Chưa có giao dịch phù hợp.</p> : <div className="av2-trend-scroll"><div className="av2-trend-bars">{rows.map((row, index) => {
      const value = values[index]
      const height = value === null || scale === BigInt(0) ? 0 : Number((value < BigInt(0) ? -value : value) * BigInt(1000) / scale) / 10
      const label = row.metrics.net_sales === null ? "Chưa đủ dữ liệu" : formatVnd(row.metrics.net_sales)
      return <div key={row.bucket} className="av2-trend-column" title={`${row.bucket}: ${label}`}><span className="av2-trend-value">{label}</span><div className="av2-trend-track"><span style={{ height: `${height}%` }} data-negative={value !== null && value < BigInt(0)} /></div><span>{row.bucket}</span></div>
    })}</div></div>}
    <p>Giá trị đầy đủ ở bảng diễn biến. NULL được giữ là thiếu dữ liệu; biểu đồ chỉ thay cách trình bày.</p>
  </figure>
}
