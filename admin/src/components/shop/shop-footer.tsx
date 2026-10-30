"use client"

import Link from "next/link"
import { Leaf } from "lucide-react"
import { useCart } from "./cart-context"
export function ShopFooter() {
  const { home } = useCart()
  return <footer className="ng-footer av-footer"><div className="page-shell ng-footer-grid"><div><Link className="av-footer-logo" href="/shop"><img src="/images/agrovia/agrovia-logo.svg" alt="Agrovia — Vụ Xanh" width="150" height="64"/></Link><p>{home?.description || "Kết nối vật tư, hạt giống và dụng cụ với từng mùa vụ. Chọn đúng nhu cầu, chăm tốt cây trồng."}</p>{home?.contact && <p>{home.contact}</p>}</div><div><h3>Khám phá Agrovia</h3><Link href="/shop/products">Danh mục vật tư</Link><Link href="/shop/orders">Theo dõi đơn hàng</Link><Link href="/shop/wishlist">Sản phẩm yêu thích</Link><Link href="/shop/account">Tài khoản khách hàng</Link></div><div><h3>Chăm sóc khách hàng</h3><Link href="/shop/policies/delivery">Giao hàng & nhận tại cửa hàng</Link><Link href="/shop/policies/returns">Đổi trả và hoàn tiền</Link><Link href="/shop/support">Tìm vật tư & hỗ trợ</Link></div><div><h3>Cửa hàng & chi nhánh</h3>{home?.branches.map(b => <p key={b.id}><strong>{b.name}</strong><br/>{b.address}</p>)}{!home?.branches.length && <p>Thông tin chi nhánh chưa được công khai.</p>}<Link href="/login">Dành cho nhân viên →</Link></div></div><div className="page-shell ng-footer-note"><span><Leaf size={15}/> Agrovia — Vụ Xanh</span><span>Đồng hành cùng người làm nông</span></div></footer>
}
