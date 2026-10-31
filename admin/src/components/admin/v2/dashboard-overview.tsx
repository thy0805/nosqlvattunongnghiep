import Link from "next/link"
import { ArrowUpRight, Boxes, CircleDollarSign, ReceiptText, TrendingUp, Wallet } from "lucide-react"
import type { InventoryResponse } from "@/lib/api"
import { formatVnd } from "@/lib/money"
import { formatQuantity } from "@/lib/quantity"
import { ReportTrend } from "./report-trend"
import { AdminCard, AdminTable } from "./primitives"
import { DashboardInsightCards } from "./dashboard-insight-cards"
import type { DashboardInsights } from "@/lib/dashboard-insights"
import { FinancialBars, MonthlySales } from "./report-charts"

export type DashboardReport = { summary: { invoice_count: number; net_sales: string; gross_profit: string | null; invoice_total: string | null; amount_collected: string; receivable_remaining: string | null; incomplete_cost_count: number; incomplete_tax_count: number; invoice_mismatch_count: number }; trend?: { bucket: string; metrics: { net_sales: string | null } }[]; by_branch?: { branch_id: string; branch_name: string; metrics: { net_sales: string | null } }[] }

export function DashboardOverview({ inventory, report, insights }: { insights?: DashboardInsights | null; inventory: InventoryResponse | null; report: DashboardReport | "forbidden" | "multi_chain" | null }) {
  const data = report && typeof report === "object" ? report : null
  const metrics = data ? [
    { label: "Doanh thu thuần", value: data.summary.net_sales, icon: TrendingUp },
    { label: "Lợi nhuận gộp", value: data.summary.gross_profit, icon: CircleDollarSign },
    { label: "Tiền đã thu", value: data.summary.amount_collected, icon: Wallet },
    { label: "Còn phải thu", value: data.summary.receivable_remaining, icon: ReceiptText },
  ] : []
  return <div className="av2-dashboard">
    {insights && <DashboardInsightCards data={insights}/>}
    {data && <><div className="av2-kpi-grid">{metrics.map(({ label, value, icon: Icon }) => <section className="av2-kpi" key={label}><span className="av2-kpi-icon"><Icon size={21}/></span><p>{label}</p><strong>{value === null ? "Chưa đủ dữ liệu" : formatVnd(value)}</strong><small>Tháng hiện tại · Báo cáo nội bộ</small></section>)}</div>{(data.summary.incomplete_cost_count > 0 || data.summary.incomplete_tax_count > 0 || data.summary.invoice_mismatch_count > 0) && <p role="alert" className="av2-notice">Có chứng từ thiếu snapshot hoặc tổng hóa đơn không khớp. Mở báo cáo để xem chi tiết trước khi sử dụng số liệu.</p>}</>}
    {report !== "forbidden" && !data && <p role={report === "multi_chain" ? "status" : "alert"} className="av2-notice">{report === "multi_chain" ? "Bạn có quyền ở nhiều chuỗi. Chọn một chuỗi tại báo cáo để xem số liệu chính xác." : "Không tải được báo cáo bán hàng."} <Link href="/admin/v2?screen=reports">Mở báo cáo</Link></p>}
    <div className={`av2-dashboard-middle ${data ? "" : "inventory-only"}`}>{data && <div><ReportTrend rows={data.trend ?? []}/><p className="av2-data-note">{data.summary.invoice_count} hóa đơn đã xác nhận. Doanh thu và lợi nhuận gộp trước thuế; tiền thu được trình bày riêng. <Link href="/admin/v2?screen=reports">Chi tiết báo cáo ↗</Link></p></div>}
      <AdminCard title="Tổng quan kho hàng" description="Trong phạm vi được phân quyền.">{inventory ? <><div className="av2-stock-summary">{[["Dòng tồn kho",inventory.summary.inventory_rows],["Lô gần hết hạn",inventory.summary.expiring_lots],["Chi nhánh có tồn",inventory.summary.branches_with_stock]].map(([label,value])=><div key={label}><span>{label}</span><strong>{value.toLocaleString("vi-VN")}</strong></div>)}</div><Link className="av2-button av2-button-primary" href="/admin/v2?screen=inventory">Xem tồn kho <ArrowUpRight size={16}/></Link></> : <p role="alert">Không tải được số liệu kho. <Link href="/admin/v2?screen=dashboard">Thử lại</Link></p>}</AdminCard>
    </div>
    {insights && <MonthlySales rows={insights.months} year={insights.year}/>}
    {data && <FinancialBars title="Doanh thu thuần theo chi nhánh" description="Báo cáo nội bộ · tháng hiện tại, trước thuế" source="internal" rows={(data.by_branch ?? []).map(branch => ({ label: branch.branch_name, raw: branch.metrics.net_sales }))}/>}
    <AdminCard title="Một số dòng tồn kho" description="Tối đa 5 dòng đầu theo tên vật tư; không phải danh sách bán chạy."><div className="av2-table-toolbar"><span><Boxes size={17}/> Tồn kho hiện tại</span><Link className="av2-button" href="/admin/v2?screen=inventory">Xem tất cả ↗</Link></div>{!inventory ? <p role="alert" className="ng-empty">Không tải được dữ liệu.</p> : !inventory.data.length ? <p className="ng-empty">Chưa có dòng tồn kho trong phạm vi được phân quyền.</p> : <AdminTable caption="Tồn kho theo lô" headings={["Vật tư","Chi nhánh / kho","Số lô","Số lượng","Hạn dùng","Trạng thái"]}>{inventory.data.map(item=><tr key={item.id}><td><div className="av2-product-identity"><span className="av2-product-placeholder"><Boxes size={20}/></span><strong>{item.product_name}</strong></div></td><td>{item.branch_name}<small>{item.warehouse_name}</small></td><td>{item.lot_no}</td><td>{formatQuantity(item.quantity)} {item.unit_name}</td><td>{item.expires_on ? new Intl.DateTimeFormat("vi-VN").format(new Date(`${item.expires_on.slice(0,10)}T00:00:00`)) : "Không áp dụng"}</td><td><span className="av2-status">{item.status}</span></td></tr>)}</AdminTable>}</AdminCard>
  </div>
}
