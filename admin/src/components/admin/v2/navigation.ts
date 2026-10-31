import type { Profile } from "@/lib/api"

export type AdminDestination = { label: string; screen: string; tab?: string }
export type AdminSection = { key: string; label: string; icon: string; items: AdminDestination[] }

export const adminSections: AdminSection[] = [
  { key: "overview", label: "Tổng quan", icon: "dashboard", items: [{ label: "Dashboard hệ thống", screen: "dashboard" }, { label: "Tổng quan Shop", screen: "shop-overview" }, { label: "Báo cáo kinh doanh", screen: "reports" }] },
  { key: "sales", label: "Bán hàng & đơn hàng", icon: "bag", items: [{ label: "Đơn hàng Online", screen: "orders" }, { label: "Bán hàng tại quầy (POS)", screen: "pos" }, { label: "Hóa đơn", screen: "invoices" }, { label: "Giao nhận", screen: "delivery" }, { label: "Đối soát COD", screen: "delivery", tab: "cod" }, { label: "Yêu cầu hủy đơn", screen: "after-sales", tab: "cancel" }, { label: "Đổi trả & hoàn tiền", screen: "after-sales", tab: "returns" }] },
  { key: "stock", label: "Vật tư & kho", icon: "box", items: [{ label: "Danh sách sản phẩm", screen: "products" }, { label: "Tồn theo lô & HSD", screen: "inventory" }, { label: "Nhập / xuất kho", screen: "stock" }, { label: "Điều chuyển chi nhánh", screen: "transfers" }, { label: "Nhà cung cấp", screen: "suppliers" }] },
  { key: "marketing", label: "Marketing", icon: "chart", items: [{ label: "Chiến dịch", screen: "marketing" }, { label: "Voucher", screen: "vouchers" }, { label: "Phát hành mã", screen: "vouchers", tab: "issue" }, { label: "Báo cáo ưu đãi", screen: "marketing", tab: "report" }] },
  { key: "customers", label: "Khách hàng & đại lý", icon: "users", items: [{ label: "Khách hàng", screen: "customers" }, { label: "Đại lý B2B", screen: "b2b" }, { label: "Bảng giá sỉ", screen: "b2b", tab: "prices" }, { label: "Báo giá", screen: "quotes" }, { label: "Công nợ", screen: "debts" }, { label: "Mua định kỳ", screen: "subscriptions" }] },
  { key: "care", label: "Chăm sóc khách hàng", icon: "chat", items: [{ label: "Danh sách ticket", screen: "tickets" }, { label: "Chi tiết / phản hồi", screen: "ticket" }, { label: "Hội thoại", screen: "chat" }, { label: "Đánh giá sản phẩm", screen: "reviews" }, { label: "Hỏi đáp sản phẩm", screen: "questions" }, { label: "Thông báo", screen: "notifications" }] },
  { key: "system", label: "Quản trị hệ thống", icon: "settings", items: [{ label: "Nhân viên", screen: "employees" }, { label: "Vai trò & phạm vi", screen: "roles" }, { label: "Chuỗi & chi nhánh", screen: "branches" }, { label: "Sync / Outbox", screen: "sync" }, { label: "Cấu hình Shop", screen: "shop-settings" }, { label: "Hồ sơ cá nhân", screen: "profile" }] },
  { key: "ai", label: "AI & tích hợp", icon: "spark", items: [{ label: "AI Settings · DISABLED", screen: "ai" }] },
]

export const legacyDestinations: Record<string, { href: string; note: string }> = {
  dashboard: { href: "/admin", note: "Tổng quan nội bộ hiện hành." },
  "shop-overview": { href: "/admin/commerce", note: "Chọn Báo cáo Shop. Báo cáo Shop và bán nội bộ có cơ sở tính riêng." },
  reports: { href: "/admin/reports", note: "Báo cáo bán nội bộ; chưa gộp hoàn trả, Shop hoặc KPI truy cập." },
  orders: { href: "/admin/commerce", note: "Chọn Đơn trực tuyến trong giao diện cũ." },
  pos: { href: "/admin/sales", note: "Luồng bán tại quầy hiện hành." },
  invoices: { href: "/admin/sales", note: "Xem hóa đơn trong chi tiết đơn bán. Chưa có sổ hóa đơn độc lập như mẫu V2." },
  delivery: { href: "/admin/commerce", note: "Chọn Đơn trực tuyến, mở đơn để xử lý giao nhận và thu tiền." },
  "after-sales": { href: "/admin/commerce", note: "Yêu cầu hủy nằm trong đơn; đổi trả và hoàn tiền ở tab Trả hàng & hoàn tiền." },
  products: { href: "/admin/catalog", note: "Catalog nguồn; trưng bày công khai dùng tab Sản phẩm công khai tại Shop Online." },
  "product-form": { href: "/admin/catalog", note: "Dùng form vật tư/nội dung hiện hành theo quyền catalog." },
  categories: { href: "/admin/catalog", note: "Giữ cây danh mục, đơn vị tính và nội dung vật tư." },
  inventory: { href: "/admin/inventory", note: "Tồn kho hiện hành. Ngưỡng và cảnh báo vẫn ở màn Cảnh báo." },
  stock: { href: "/admin/purchases", note: "Nhập hàng theo phiếu. Xem lịch sử tại /admin/inventory/movements; không sửa tồn trực tiếp." },
  transfers: { href: "/admin/transfers", note: "Giữ đầy đủ quy trình điều chuyển và đối soát." },
  suppliers: { href: "/admin/suppliers", note: "Đối tác nhà cung cấp và phạm vi hiện hành." },
  marketing: { href: "/admin/commerce", note: "Chọn Khuyến mãi & voucher. Chỉ sử dụng báo cáo hiện có." },
  vouchers: { href: "/admin/commerce", note: "Chọn Khuyến mãi & voucher; chưa tích hợp màn phát hành hàng loạt trong V2." },
  customers: { href: "/admin/customers", note: "Khách hàng nội bộ; tài khoản Customer của Shop là danh tính riêng." },
  b2b: { href: "/admin/commerce", note: "Chọn Đại lý & báo giá; quyền do API hiện hành quyết định." },
  quotes: { href: "/admin/commerce", note: "Chọn Đại lý & báo giá. Không chấp nhận báo giá thay khách." },
  debts: { href: "/admin/debts", note: "Giữ phải thu, phải trả và các khoản điều chỉnh đúng nguồn." },
  tickets: { href: "/admin/commerce", note: "Chọn Hỗ trợ khách hàng; hội thoại qua HTTP, không realtime." },
  ticket: { href: "/admin/commerce", note: "Chọn Hỗ trợ khách hàng rồi mở yêu cầu trong phạm vi được phép." },
  chat: { href: "/admin/commerce", note: "Dùng cùng hội thoại ticket trong Hỗ trợ khách hàng, không có engine chat riêng." },
  reviews: { href: "/admin/commerce", note: "Chọn Đánh giá & hỏi đáp; API kiểm quyền duyệt." },
  questions: { href: "/admin/commerce", note: "Chọn Đánh giá & hỏi đáp." },
  notifications: { href: "/admin/alerts", note: "Thông báo nội bộ và cảnh báo tồn; không sử dụng feed Customer." },
  employees: { href: "/admin/users", note: "Quản lý tài khoản và phân công hiện hành." },
  roles: { href: "/admin/users", note: "Ba vai trò và phạm vi phân công; không có trình tạo quyền tùy ý." },
  branches: { href: "/admin/organization", note: "Giữ chuỗi, chi nhánh, kho và kho mặc định." },
  sync: { href: "/admin/sync", note: "Outbox hiện hành, giữ bản sửa ngày B01. Không khởi động worker." },
  "shop-settings": { href: "/admin/commerce", note: "Chọn Cấu hình cửa hàng, Chính sách hoặc Banner. Tùy chọn hủy chưa chốt được khóa." },
}

export function destinationHref(item: AdminDestination) {
  const params = new URLSearchParams({ screen: item.screen })
  if (item.tab) params.set("tab", item.tab)
  return `/admin/v2?${params}`
}

export function destinationAllowed(screen: string, profile: Profile, tab = "") {
  if (["sync", "employees", "shop-settings", "marketing", "vouchers", "b2b", "quotes", "reviews", "questions"].includes(screen) || (screen === "products" && tab === "publications")) return profile.user.can_manage_sync
  if (screen === "product-form") return profile.user.can_manage_catalog
  if (["reports", "shop-overview"].includes(screen)) return profile.user.can_view_reports
  return true
}

export function findDestination(screen: string, tab = ""): { group: AdminSection; item: AdminDestination } | null {
  if ((screen === "product-form" || screen === "categories") && !tab) return findDestination("products")
  const extraTabs: Record<string, Record<string, string>> = { inventory: { alerts: "Cảnh báo & ngưỡng tồn" }, stock: { movements: "Biến động tồn" }, products: { publications: "Nội dung trưng bày Shop" }, categories: { units: "Đơn vị tính" }, debts: { payable: "Công nợ phải trả" }, "shop-settings": { pages: "Chính sách Shop", banners: "Banner Shop" }, "after-sales": { refunds: "Hoàn tiền" } }
  if (extraTabs[screen]?.[tab]) {
    const parent = findDestination(screen, "") ?? findDestination(screen, screen === "after-sales" ? "returns" : "")
    if (parent) return { group: parent.group, item: { label: extraTabs[screen][tab], screen, tab } }
  }
  for (const group of adminSections) {
    const item = group.items.find(item => item.screen === screen && (item.tab ?? "") === tab)
    if (item) return { group, item }
  }
  return null
}
