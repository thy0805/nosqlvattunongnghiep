"use client"

import { useCart } from "./cart-context"
export function ShopStatus() {
 const { ready, error, chainId, refresh } = useCart()
 if (error) return <div className="ng-notice error" role="alert">{error}<button onClick={() => void refresh().catch(() => {})}>Thử tải lại</button></div>
 if (!ready) return <div className="ng-loading" role="status"><span/> Đang tải cửa hàng…</div>
 if (!chainId) return <div className="ng-empty"><h2>Cửa hàng chưa mở bán</h2><p>Chưa có chuỗi nào công khai danh mục. Vui lòng quay lại sau.</p></div>
 return null
}
export function Message({ children, error = false }: { children: React.ReactNode; error?: boolean }) { return children ? <div className={`ng-notice ${error ? "error" : ""}`} role={error ? "alert" : "status"}>{children}</div> : null }
