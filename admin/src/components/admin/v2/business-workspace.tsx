"use client"

import dynamic from "next/dynamic"
import Link from "next/link"
import { useRouter } from "next/navigation"
import type { MouseEvent, ReactNode } from "react"
import { useAdminProfile } from "@/components/admin/admin-shell"
import { destinationAllowed, findDestination } from "./navigation"
import { AdminCard } from "./primitives"
import { RoleMatrix } from "./role-matrix"
import { ShellShowcase } from "./shell-showcase"
import { NotificationPanel } from "@/components/admin/notification-panel"
import "./business.css"

function Loading() { return <div role="status" className="av2-loading">Đang mở chức năng…</div> }
const Catalog = dynamic(() => import("@/app/admin/catalog/page"), { loading: Loading })
const Inventory = dynamic(() => import("@/app/admin/inventory/page"), { loading: Loading })
const Purchases = dynamic(() => import("@/app/admin/purchases/page"), { loading: Loading })
const Movements = dynamic(() => import("@/app/admin/inventory/movements/page"), { loading: Loading })
const Transfers = dynamic(() => import("@/app/admin/transfers/page"), { loading: Loading })
const Suppliers = dynamic(() => import("@/app/admin/suppliers/page"), { loading: Loading })
const Alerts = dynamic(() => import("@/app/admin/alerts/page"), { loading: Loading })
const Sales = dynamic(() => import("@/app/admin/sales/page"), { loading: Loading })
const Customers = dynamic(() => import("@/app/admin/customers/page"), { loading: Loading })
const Debts = dynamic(() => import("@/app/admin/debts/page"), { loading: Loading })
const Users = dynamic(() => import("@/app/admin/users/page"), { loading: Loading })
const Organization = dynamic(() => import("@/app/admin/organization/page"), { loading: Loading })
const Sync = dynamic(() => import("@/app/admin/sync/page"), { loading: Loading })
const Reports = dynamic(() => import("@/app/admin/reports/page"), { loading: Loading })
const Commerce = dynamic(() => import("@/components/admin/commerce-workspace").then(module => module.CommerceWorkspace), { loading: Loading })

const commerceDestinations: Record<string, string> = { orders: "orders", products: "products&tab=publications", settings: "shop-settings", pages: "shop-settings&tab=pages", banners: "shop-settings&tab=banners", support: "tickets", moderation: "reviews", returns: "after-sales&tab=returns", marketing: "marketing", business: "b2b", reports: "shop-overview" }

const legacyLinks: Record<string, string> = { "/admin": "dashboard", "/admin/catalog": "products", "/admin/inventory": "inventory", "/admin/purchases": "stock", "/admin/inventory/movements": "stock&tab=movements", "/admin/transfers": "transfers", "/admin/suppliers": "suppliers", "/admin/alerts": "inventory&tab=alerts", "/admin/sales": "pos", "/admin/customers": "customers", "/admin/debts": "debts", "/admin/users": "employees", "/admin/organization": "branches", "/admin/reports": "reports", "/admin/sync": "sync", "/admin/commerce": "orders" }

const screenDescriptions: Record<string, string> = {
  dashboard: "Tổng quan kinh doanh và kho hàng trong phạm vi được phân quyền.", products: "Tra cứu vật tư, đơn vị, giá và nội dung trưng bày.",
  inventory: "Theo dõi số lượng, lô và hạn dùng tại các kho được phân quyền.", orders: "Theo dõi và xử lý đơn trực tuyến theo trạng thái thực tế.",
  pos: "Chọn kho, vật tư và lô thực xuất. Giá và thuế được hệ thống chốt khi bán.", invoices: "Tra cứu đơn bán, hóa đơn và bản in chứng từ.",
  customers: "Quản lý khách hàng theo chuỗi và thông tin liên hệ.", b2b: "Quản lý hồ sơ đại lý và bảng giá sỉ.", quotes: "Theo dõi và xử lý báo giá dành cho đại lý.",
  tickets: "Tiếp nhận và phản hồi yêu cầu hỗ trợ khách hàng.", ticket: "Chi tiết yêu cầu và lịch sử phản hồi.", chat: "Hội thoại hỗ trợ theo từng ticket.",
  roles: "Ba vai trò cố định, áp dụng theo phân công và ngày hiệu lực.", reports: "Kỳ báo cáo theo giờ Việt Nam; số liệu từ hóa đơn đã xác nhận.",
  employees: "Tài khoản, phân công và phạm vi chuỗi/chi nhánh.", stock: "Chứng từ nhập hàng và lịch sử biến động tồn.",
  transfers: "Yêu cầu, xuất, nhận và đối soát điều chuyển chi nhánh.", debts: "Khoản phải thu, phải trả và các lần thanh toán.",
  'shop-settings': "Cấu hình cửa hàng, chính sách và nội dung hiển thị.", 'shop-overview': "Báo cáo riêng của kênh Shop theo dữ liệu giao dịch thực tế.",
}

export function BusinessWorkspace({ screen, tab, dashboard }: { screen: string; tab: string; dashboard?: ReactNode }) {
  const profile = useAdminProfile()
  const router = useRouter()
  const current = findDestination(screen, tab)
  if (!current || !destinationAllowed(screen, profile)) return <ShellShowcase screen={screen} tab={tab} />

  function changeCommerceTab(nextTab: string, chain: string) {
    const params = new URLSearchParams(`screen=${commerceDestinations[nextTab] ?? "orders"}`)
    if (chain) params.set("chain", chain)
    router.push(`/admin/v2?${params}`)
  }

  const commerce = (initialTab: string) => <Commerce initialTab={initialTab} onTabChange={changeCommerceTab} orderView={screen === "after-sales" ? "cancel" : screen === "delivery" ? tab === "cod" ? "cod" : "delivery" : "orders"} marketingKind={screen === "vouchers" ? "vouchers" : "campaigns"} moderationKind={screen === "questions" ? "questions" : "reviews"} businessSection={screen === "quotes" ? "quotes" : tab === "prices" ? "prices" : "dealers"} />

  let content: ReactNode
  switch (screen) {
    case "products": content = tab === "publications" ? commerce("products") : <Catalog />; break
    case "product-form": case "categories": content = <Catalog />; break
    case "inventory": content = tab === "alerts" ? <Alerts /> : <Inventory />; break
    case "stock": content = tab === "movements" ? <Movements /> : <Purchases />; break
    case "transfers": content = <Transfers />; break
    case "suppliers": content = <Suppliers />; break
    case "pos": case "invoices": content = <Sales />; break
    case "orders": case "delivery": content = commerce("orders"); break
    case "after-sales": content = commerce(tab === "cancel" ? "orders" : "returns"); break
    case "marketing": case "vouchers": content = commerce("marketing"); break
    case "customers": content = <Customers />; break
    case "b2b": case "quotes": content = commerce("business"); break
    case "debts": content = <Debts />; break
    case "tickets": case "ticket": case "chat": content = commerce("support"); break
    case "reviews": case "questions": content = commerce("moderation"); break
    case "notifications": content = <NotificationPanel />; break
    case "employees": content = <Users />; break
    case "roles": content = <RoleMatrix />; break
    case "branches": content = <Organization />; break
    case "sync": content = <Sync />; break
    case "shop-settings": content = commerce(tab || "settings"); break
    case "reports": content = <Reports />; break
    case "shop-overview": content = commerce("reports"); break
    case "dashboard": content = dashboard; break
    case "profile": content = <AdminCard title="Hồ sơ nhân sự" description="Thông tin từ phiên đăng nhập hiện hành."><dl className="av2-profile"><dt>Tài khoản</dt><dd>{profile.user.username}</dd><dt>Chuỗi</dt><dd>{profile.chains.map(chain => chain.name).join(", ") || "Chưa được phân công"}</dd><dt>Chi nhánh</dt><dd>{profile.branches.map(branch => branch.name).join(", ") || "Chưa được phân công"}</dd><dt>Báo cáo nội bộ</dt><dd>{profile.user.can_view_reports ? "Được phép" : "Không được phép"}</dd><dt>Quản lý Sync</dt><dd>{profile.user.can_manage_sync ? "Được phép" : "Không được phép"}</dd></dl><p>Hồ sơ chỉ đọc. Thay đổi tài khoản và phân công thực hiện tại mục Nhân viên theo quyền hiện hành.</p><Link className="av2-button" href="/admin/v2?screen=employees">Mở quản lý nhân viên</Link></AdminCard>; break
    case "subscriptions": content = <AdminCard title="Mua định kỳ · Chưa có màn quản trị" description="API Customer hiện hành được giữ nguyên."><p>Chưa có API danh sách mua định kỳ dành cho nhân viên. Không dùng phiên Customer để thay thế và không bật lịch chạy tự động.</p><button className="av2-button" disabled>Danh sách Admin chưa khả dụng</button></AdminCard>; break
    case "ai": content = <AdminCard title="AI Settings · DISABLED"><p>AI đang tắt theo yêu cầu. Chưa kết nối provider hoặc nhận API key.</p><button className="av2-button" disabled>AI chưa được bật</button></AdminCard>; break
    default: return <ShellShowcase screen={screen} tab={tab} />
  }

  function followInternalLink(event: MouseEvent<HTMLDivElement>) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
    const target = (event.target as HTMLElement).closest<HTMLAnchorElement>("a[href]")
    if (!target || target.target || target.hasAttribute("download")) return
    const url = new URL(target.href, window.location.origin)
    const destination = legacyLinks[url.pathname]
    if (url.origin !== window.location.origin || !destination) return
    event.preventDefault()
    const next = new URL(`/admin/v2?screen=${destination}`, window.location.origin)
    url.searchParams.forEach((value, key) => { if (!["screen", "tab"].includes(key)) next.searchParams.set(key, value) })
    router.push(next.pathname + next.search)
  }

  return <><div className="av2-page-heading"><div><p className="av2-eyebrow">AGROVIA · QUẢN TRỊ</p><h1>{current.item.label}</h1><p>{screenDescriptions[screen] ?? "Dữ liệu trong phạm vi bạn được phân quyền."}</p></div><Link className="av2-button" href="/admin">Giao diện cũ ↗</Link></div>
    {screen === "shop-settings" && <div className="av2-view-tabs">{[["", "Cửa hàng"], ["pages", "Chính sách"], ["banners", "Banner"]].map(([key, label]) => <Link key={key} href={`/admin/v2?screen=shop-settings${key ? `&tab=${key}` : ""}`} aria-current={tab === key ? "page" : undefined}>{label}</Link>)}</div>}
    {screen === "products" && <div className="av2-view-tabs"><Link href="/admin/v2?screen=products" aria-current={!tab ? "page" : undefined}>Catalog nguồn</Link><Link href="/admin/v2?screen=products&tab=publications" aria-current={tab === "publications" ? "page" : undefined}>Trưng bày & nội dung Shop</Link></div>}
    {screen === "categories" && <div className="av2-view-tabs"><Link href="/admin/v2?screen=categories" aria-current={!tab ? "page" : undefined}>Nhóm vật tư</Link><Link href="/admin/v2?screen=categories&tab=units" aria-current={tab === "units" ? "page" : undefined}>Đơn vị tính</Link></div>}
    {screen === "debts" && <div className="av2-view-tabs"><Link href="/admin/v2?screen=debts" aria-current={!tab ? "page" : undefined}>Phải thu & phải trả</Link><Link href="/admin/v2?screen=debts&tab=payable" aria-current={tab === "payable" ? "page" : undefined}>Khoản phải trả</Link></div>}
    {screen === "invoices" && <p className="av2-notice">Mở chi tiết đơn bán để xem hóa đơn và in chứng từ. Hệ thống dùng danh sách đơn bán hiện hành; chưa có sổ hóa đơn độc lập.</p>}
    {screen === "delivery" && <p className="av2-notice">{tab === "cod" ? "Các đơn có phương thức COD. Mở đơn để xem giao nhận, khoản thu và đối soát hiện hành." : "Đơn giao tận nơi. Mở đơn để soạn, đóng gói và xử lý giao nhận theo trạng thái thực."} Duyệt đơn không đồng nghĩa đã xuất kho, giao hoặc thu tiền.</p>}
    {["tickets", "ticket", "chat"].includes(screen) && <p className="av2-notice">Mở một ticket để xem hoặc phản hồi hội thoại. Đồng bộ qua HTTP; chưa có chat realtime riêng.</p>}
    {screen === "vouchers" && tab === "issue" && <p className="av2-notice">Dùng form voucher hiện hành bên dưới. Phát hành hàng loạt chưa có API nên không khả dụng.</p>}
    {screen === "marketing" && tab === "report" && <p className="av2-notice">Số liệu theo từng chương trình nằm trong thẻ campaign/voucher và mục “Theo chi nhánh và đơn hàng”. Không cộng doanh thu giữa các chương trình thành tổng doanh thu Shop.</p>}
    {screen === "roles" && <p className="av2-notice">Ba vai trò và phạm vi nằm trong phân công nhân viên. Không có trình tạo quyền tùy ý.</p>}
    {["reports", "dashboard"].includes(screen) && <p className="av2-notice">Nguồn báo cáo nội bộ theo hóa đơn đã xác nhận, chưa điều chỉnh theo hoàn trả Shop. <Link href="/admin/v2?screen=shop-overview">Xem riêng Báo cáo Shop</Link>.</p>}
    {screen === "shop-overview" && <p className="av2-notice">Nguồn báo cáo Shop và điều chỉnh đổi trả theo API Shop hiện hành; không gộp với báo cáo bán nội bộ. Không có số liệu truy cập hoặc funnel.</p>}
    {screen === "inventory" && <div className="av2-view-tabs"><Link href="/admin/v2?screen=inventory" aria-current={!tab ? "page" : undefined}>Tồn theo lô & hạn dùng</Link><Link href="/admin/v2?screen=inventory&tab=alerts" aria-current={tab === "alerts" ? "page" : undefined}>Cảnh báo & ngưỡng tồn</Link></div>}
    {screen === "stock" && <div className="av2-view-tabs"><Link href="/admin/v2?screen=stock" aria-current={!tab ? "page" : undefined}>Phiếu nhập hàng</Link><Link href="/admin/v2?screen=stock&tab=movements" aria-current={tab === "movements" ? "page" : undefined}>Biến động tồn</Link></div>}
    <div className="av2-business" data-view={screen} data-tab={tab} onClickCapture={followInternalLink}>{content}</div>
  </>
}
