"use client"

import Link from "next/link"
import { useState } from "react"
import { Menu, ShoppingCart, UserRound, Heart, Search, X, MapPin, ChevronDown, Truck, ArrowRight } from "lucide-react"
import { useCart } from "./cart-context"

export function ShopHeader() {
  const { cart, customer, chains, chainId, selectChain, home } = useCart()
  const [open, setOpen] = useState(false)
  const categories = home?.categories ?? []
  return <header className="ng-header av-header">
    <div className="av-announcement"><div className="page-shell"><p><Truck size={15}/><strong>Đồng hành cùng mùa vụ</strong><span> · Vật tư, hạt giống và dụng cụ nông nghiệp</span></p><nav><Link href="/shop/support">Hỗ trợ khách hàng</Link><Link href="/shop/orders">Theo dõi đơn hàng</Link><Link href="/shop/business">Hợp tác đại lý</Link><Link href="/login">Dành cho nhân viên <ArrowRight size={12}/></Link></nav></div></div>
    <div className="page-shell av-header-main">
      <Link href="/shop" className="av-logo" aria-label="Agrovia — Vụ Xanh, trang chủ"><img src="/images/agrovia/agrovia-logo.svg" alt="Agrovia — Vụ Xanh" width="132" height="60"/></Link>
      <label className="av-location"><MapPin size={22}/><span><small>Cửa hàng của bạn</small><select aria-label="Chọn cửa hàng" value={chainId} disabled={!chains.length} onChange={e => selectChain(e.target.value)}>{chains.length ? chains.map(c => <option key={c.id} value={c.id}>{c.name}</option>) : <option value="">Đang tải…</option>}</select></span></label>
      <form action="/shop/products" className="ng-search av-search"><Search size={20}/><input aria-label="Tìm vật tư" name="search" placeholder="Tìm hạt giống, phân bón, thuốc bảo vệ thực vật…"/><button aria-label="Tìm kiếm"><Search size={19}/></button></form>
      <nav className="ng-actions av-actions"><Link href="/shop/wishlist" aria-label="Yêu thích"><Heart size={21}/></Link><Link href="/shop/account" aria-label="Tài khoản"><UserRound size={21}/><span>{customer ? customer.name.split(" ").at(-1) : "Đăng nhập"}</span></Link><Link className="av-cart" href="/shop/cart" aria-label={`Giỏ hàng: ${cart.items.length} sản phẩm`}><ShoppingCart size={20}/><span>Giỏ hàng</span><b>{cart.items.length}</b></Link><button className="ng-menu" aria-label={open ? "Đóng menu" : "Mở menu"} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button></nav>
    </div>
    <div className={`av-nav-wrap ${open ? "is-open" : ""}`}><div className="page-shell av-nav"><nav aria-label="Danh mục Shop" onClick={() => setOpen(false)}><Link href="/shop/products">Tất cả vật tư <ChevronDown size={13}/></Link>{categories.slice(0,5).map(c => <Link key={c.id} href={`/shop/products?category_id=${c.id}`}>{c.name}</Link>)}{!categories.length && <><Link href="/shop/support">Tư vấn vật tư</Link><Link href="/shop/orders">Đơn hàng của tôi</Link></>}</nav><Link className="av-deals" href="/shop/offers">Ưu đãi hôm nay <ArrowRight size={14}/></Link></div></div>
  </header>
}
