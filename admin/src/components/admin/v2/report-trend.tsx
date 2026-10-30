"use client"

import { formatVnd, moneyCents } from "@/lib/money"

type Row = { bucket: string; metrics: { net_sales: string | null } }

export function ReportTrend({ rows }: { rows: Row[] }) {
  const values = rows.map(row => row.metrics.net_sales === null ? null : moneyCents(row.metrics.net_sales))
  const scale = values.reduce<bigint>((max, value) => value !== null && (value < BigInt(0) ? -value : value) > max ? (value < BigInt(0) ? -value : value) : max, BigInt(0))
  const negative = values.some(value => value !== null && value < BigInt(0))
  const baseline = negative ? 110 : 190
  const amplitude = negative ? 80 : 160
  const points = rows.map((row, index) => ({
    x: rows.length === 1 ? 370 : 45 + index * 650 / (rows.length - 1),
    y: values[index] === null ? null : baseline - (scale === BigInt(0) ? 0 : Number(values[index]! * BigInt(amplitude * 100) / scale) / 100),
    label: row.metrics.net_sales === null ? "Chưa đủ dữ liệu" : formatVnd(row.metrics.net_sales),
    bucket: row.bucket,
  }))
  const segments: string[][] = [[]]
  for (const point of points) {
    if (point.y === null) segments.push([])
    else segments[segments.length - 1].push(`${point.x},${point.y}`)
  }
  return <figure className="av2-trend" aria-label="Biểu đồ doanh thu thuần theo kỳ từ báo cáo nội bộ">
    <figcaption><span>Diễn biến doanh thu thuần</span><span className="av2-chart-legend">Theo kỳ báo cáo</span></figcaption>
    {!rows.length ? <p className="av2-chart-empty">Chưa có giao dịch phù hợp.</p> : <div className="av2-trend-scroll"><svg className="av2-line-chart" viewBox="0 0 740 235" role="img" aria-label="Doanh thu thuần theo mốc thời gian"><title>Doanh thu thuần từ báo cáo nội bộ; dữ liệu thiếu được ngắt đoạn.</title>{[30,70,110,150,190].map(y=><line key={y} x1="40" x2="705" y1={y} y2={y} className="av2-chart-grid"/>)}<line x1="40" x2="705" y1={baseline} y2={baseline} className="av2-chart-baseline"/>{segments.filter(segment=>segment.length>1).map((segment,index)=><polyline key={index} points={segment.join(" ")} fill="none" stroke="currentColor" strokeWidth="3" strokeLinejoin="round"/>)}{points.map((point,index)=><g key={point.bucket}>{point.y!==null&&<circle cx={point.x} cy={point.y} r="4" className="av2-chart-point"><title>{`${point.bucket}: ${point.label}`}</title></circle>}{(rows.length<=8||index===0||index===rows.length-1||index%Math.ceil(rows.length/6)===0)&&<text x={point.x} y="220" textAnchor="middle">{point.bucket}</text>}</g>)}</svg></div>}
    <p>Giá trị chính xác xem ở bảng báo cáo. NULL giữ là thiếu dữ liệu; biểu đồ không nối qua mốc thiếu.</p>
  </figure>
}
