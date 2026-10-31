"use client"

import Link from "next/link"
import { useState, type ReactNode } from "react"
import { ArrowDownLeft, ArrowUpRight, ChartNoAxesCombined, Copy, FileCheck2, ReceiptText, RefreshCw, Wallet } from "lucide-react"
import { shopMoney } from "@/lib/shop-api"
import { orderLabels } from "@/lib/shop-orders"
import type { Report } from "../commerce-reports"
import { FinancialBars, OrderStatusDonut } from "./report-charts"
import { moneyCents } from "@/lib/money"
import "./shop-overview.css"

type Props = { data: Report | null; error: string; busy: boolean; from: string; to: string; setFrom: (value: string) => void; setTo: (value: string) => void; onLoad: () => void; branchFilter?: ReactNode }
const metricLabels: Record<string, string> = { invoice_count: "Số hóa đơn", invoice_total: "Tổng hóa đơn gốc", discount: "Chiết khấu trên hóa đơn", shipping: "Phí giao", return_credit: "Credit trả hàng", adjusted_invoice_total: "Giá trị sau credit", collected: "Đã thu", refunded: "Đã hoàn", net_collected: "Thu ròng", remaining: "Còn phải thu", average_invoice: "Trung bình hóa đơn gốc" }
const headlineMetrics = ["invoice_total", "adjusted_invoice_total", "net_collected", "remaining"]
const metricIcons = [ReceiptText, FileCheck2, ArrowDownLeft, Wallet]
const metricNotes = ["Theo ngày xuất hóa đơn", "Đã điều chỉnh credit trả hàng", "Đã thu trừ khoản đã hoàn", "Theo kết quả đối chiếu của Shop"]
const statusLabels: Record<string, string> = { ...orderLabels, dispatched: "Đã xuất giao", shipment_returned: "Giao hàng hoàn về" }

function ReportTable({ title, description, count, columns, children, empty }: { title: string; description: string; count: number; columns: string[]; children: ReactNode; empty: string }) {
  return <section className="av2-sr-panel" aria-label={title}>
    <div className="av2-sr-panel-heading"><div><h3>{title}</h3><p>{description}</p></div><span className="av2-sr-count">{count} dòng</span></div>
    <div className="av2-sr-table-scroll" role="region" aria-label={`Bảng ${title}`} tabIndex={0}>
      <table><thead><tr>{columns.map((column, index) => <th scope="col" key={column} className={index ? "av2-sr-number" : undefined}>{column}</th>)}</tr></thead>
        <tbody>{count ? children : <tr><td colSpan={columns.length} className="av2-sr-empty">{empty}</td></tr>}</tbody>
      </table>
    </div>
    <p className="av2-sr-scroll-note">Có thể cuộn ngang trong bảng để xem đầy đủ các cột.</p>
  </section>
}

export function ShopOverviewReport({ data, error, busy, from, to, setFrom, setTo, onLoad, branchFilter }: Props) {
  const [copyMessage, setCopyMessage] = useState("")
  async function copyOrder(value: string) {
    try { await navigator.clipboard.writeText(value); setCopyMessage(`Đã sao chép mã đơn ${value}.`) }
    catch { setCopyMessage("Chưa sao chép được. Bạn có thể chọn trực tiếp mã đơn trong bảng.") }
  }
  const statuses = Object.entries(data?.status_counts ?? {})
  const topProducts = [...(data?.products ?? [])].sort((a, b) => { const left = moneyCents(a.sale_total), right = moneyCents(b.sale_total); return left === null || right === null ? 0 : left > right ? -1 : left < right ? 1 : 0 }).slice(0, 8)
  return <section className="av2-shop-report" aria-label="Báo cáo Tổng quan Shop" aria-busy={busy}>
    <form className="av2-sr-filter" onSubmit={event => { event.preventDefault(); onLoad() }}>
      <div className="av2-sr-filter-label"><ChartNoAxesCombined size={20}/><div><h2>Kết quả bán hàng trực tuyến</h2><p>Chọn kỳ để đối chiếu hoạt động Shop.</p></div></div>
      <div className="av2-sr-filter-fields">{branchFilter}<label>Từ ngày<input type="date" value={from} onChange={event => setFrom(event.target.value)} required/></label><label>Đến ngày<input type="date" value={to} onChange={event => setTo(event.target.value)} required/></label><button className="av2-button av2-button-primary" disabled={busy}><RefreshCw size={16}/>{busy ? "Đang tổng hợp…" : "Xem báo cáo"}</button></div>
    </form>
    {error && <p role="alert" className="av2-sr-error">{error}</p>}
    {busy && !data && <p role="status" className="av2-sr-empty">Đang tải số liệu báo cáo…</p>}
    {data && <>
      <p className="av2-sr-basis">{data.basis}</p>
      <div className="av2-sr-kpis">{headlineMetrics.filter(key => key in data.summary).map((key, index) => {
        const Icon = metricIcons[index]
        return <article className="av2-sr-kpi" key={key} data-metric={key}><div><p>{metricLabels[key]}</p><span className="av2-sr-icon"><Icon size={21}/></span></div><strong>{shopMoney(String(data.summary[key]))}</strong><small>{metricNotes[index]}</small></article>
      })}</div>
      <section className="av2-sr-panel" aria-label="Chi tiết các chỉ số Shop"><div className="av2-sr-panel-heading"><div><h3>Chi tiết thu tiền & điều chỉnh</h3><p>Các chỉ số bổ sung của cùng kỳ báo cáo.</p></div></div>
        <dl className="av2-sr-metrics">{Object.entries(data.summary).filter(([key]) => !headlineMetrics.includes(key)).map(([key, value]) => <div key={key} data-metric={key}><dt>{metricLabels[key] ?? key}</dt><dd>{key === "invoice_count" ? value : shopMoney(String(value))}</dd></div>)}</dl>
      </section>
      <div className="av2-shop-chart-primary">
        <OrderStatusDonut rows={statuses.map(([key, count]) => ({ key, count, label: statusLabels[key] ?? "Trạng thái chưa có nhãn" }))}/>
        <FinancialBars title="Sản phẩm theo giá trị bán" description="Tối đa 8 sản phẩm có giá trị bán cao nhất; số xuất và nhận trả xem riêng trong bảng." rows={topProducts.map(product => ({ label: product.name, raw: product.sale_total }))}/>
      </div>
      <FinancialBars title="Giá trị hóa đơn theo chi nhánh" description="Tổng giá trị hóa đơn gốc của Shop; credit và khoản thu được đối chiếu riêng." rows={data.by_branch.map(branch => ({ label: branch.name, raw: branch.invoice_total }))}/>
      <ReportTable title="Theo chi nhánh" description="Giá trị hóa đơn và công nợ theo từng chi nhánh." count={data.by_branch.length} columns={["Chi nhánh", "Số hóa đơn", "Tổng giá trị hóa đơn", "Credit trả hàng", "Còn phải thu"]} empty="Chưa có hóa đơn của chi nhánh trong kỳ.">
        {data.by_branch.map(branch => <tr key={branch.branch_id}><th scope="row">{branch.name}</th><td className="av2-sr-number">{branch.invoice_count}</td><td className="av2-sr-number">{shopMoney(branch.invoice_total)}</td><td className="av2-sr-number">{shopMoney(branch.credit)}</td><td className="av2-sr-number av2-sr-emphasis">{shopMoney(branch.remaining)}</td></tr>)}
      </ReportTable>
      <ReportTable title="Sản phẩm đã xuất" description="Số lượng xuất, nhận trả và giá trị bán từ chứng từ gốc." count={data.products.length} columns={["Sản phẩm", "Số lượng xuất", "Số lượng nhận trả", "Giá trị bán"]} empty="Chưa có sản phẩm đã xuất trong kỳ.">
        {data.products.map(product => <tr key={product.product_id}><th scope="row">{product.name}</th><td className="av2-sr-number">{product.quantity}</td><td className="av2-sr-number">{product.returned_quantity}</td><td className="av2-sr-number">{shopMoney(product.sale_total)}</td></tr>)}
      </ReportTable>
      <ReportTable title="Đối chiếu chứng từ" description="Giữ riêng tổng hóa đơn, credit trả hàng, khoản thu và khoản hoàn." count={data.orders.length} columns={["Mã đơn", "Mã hóa đơn", "Tổng tiền", "Credit trả hàng", "Đã thu", "Hoàn tiền", "Còn phải thu"]} empty="Chưa có hóa đơn trực tuyến trong kỳ.">
        {data.orders.map(order => <tr key={order.order_id}><th scope="row"><div className="av2-sr-order-code"><span>{order.order_no}</span><button type="button" onClick={() => void copyOrder(order.order_no)} aria-label={`Sao chép mã đơn ${order.order_no}`} title="Sao chép mã đơn"><Copy size={15}/></button></div></th><td className="av2-sr-number">#{order.invoice_id}</td><td className="av2-sr-number">{shopMoney(order.invoice_total)}</td><td className="av2-sr-number">{shopMoney(order.return_credit)}</td><td className="av2-sr-number">{shopMoney(order.paid)}</td><td className="av2-sr-number">{shopMoney(order.refund)}</td><td className="av2-sr-number av2-sr-emphasis">{shopMoney(order.remaining)}</td></tr>)}
      </ReportTable>
      <p role="status" className="av2-sr-copy-status">{copyMessage}</p>
      <Link className="av2-sr-related" href="/admin/reports">Báo cáo doanh số nghiệp vụ <ArrowUpRight size={17}/></Link>
    </>}
  </section>
}
