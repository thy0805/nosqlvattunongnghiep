"use client"

import Link from "next/link"
import { useState } from "react"
import { ArrowRight, ShieldCheck, CreditCard, Truck, MessagesSquare, Leaf, PackageSearch } from "lucide-react"
import { useCart } from "@/components/shop/cart-context"
import { ProductCard } from "@/components/shop/product-card"
import { ShopStatus } from "@/components/shop/shop-shared"

export default function ShopPage() {
  const { home, ready } = useCart()
  const [slide, setSlide] = useState(0)
  const slides = [{ id: "agrovia-crop", image: "/images/agrovia/c809908f80d2238200.png", title: "Vật tư chăm sóc cây trồng cho mùa vụ", href: "/shop/products" }, ...(home?.banners ?? [])]
  const active = slides[Math.min(slide, slides.length - 1)]
  const products = home?.products ?? []
  const best = home?.bestselling ?? []
  const offers = home?.offers ?? []
  return <main className="av-home"><div className="page-shell">
    <section className="av-hero" aria-label="Khám phá Agrovia"><div className="av-carousel"><Link href={active.href} aria-label={active.title}><img className="av-hero-banner" src={active.image} alt={active.title} fetchPriority="high"/></Link>{slides.length > 1 && <div className="av-dots">{slides.map((b,i) => <button key={b.id} aria-label={`Xem banner ${i+1}: ${b.title}`} aria-pressed={slide === i} onClick={() => setSlide(i)}/>)}</div>}</div><div className="av-hero-aside"><article className="av-season"><p>CHĂM CÂY ĐÚNG CÁCH</p><h1>Gieo lựa chọn tốt.<br/>Đón mùa xanh mới.</h1><Link href="/shop/products">Khám phá vật tư <ArrowRight size={16}/></Link></article><article className="av-wholesale"><p>CÙNG NGƯỜI LÀM NÔNG</p><h2>Cần chọn vật tư cho vườn?</h2><span>Xem công dụng, quy cách và lưu ý sử dụng trước khi mua.</span><Link href="/shop/support">Tìm vật tư phù hợp <ArrowRight size={16}/></Link></article></div></section>
    <ShopStatus/>
    <section className="av-category-panel"><div className="av-section-heading"><h2>Mua sắm theo danh mục</h2><Link href="/shop/products">Xem tất cả <ArrowRight size={17}/></Link></div><div className="av-categories"><Link href="/shop/products?sort=price_asc" className="av-category"><span className="av-offer-circle">%</span><strong>Giá tốt</strong><small>Khám phá vật tư</small></Link>{home?.categories.map(c => <Link className="av-category" key={c.id} href={`/shop/products?category_id=${c.id}`}><span>{c.image ? <img src={c.image} alt="" loading="lazy"/> : <Leaf size={36}/>}</span><strong>{c.name}</strong><small>Xem danh mục</small></Link>)}</div>{!ready && <div className="av-skeleton" aria-label="Đang tải danh mục"/>}{ready && !home?.categories.length && <p className="ng-muted">Danh mục đang được cửa hàng cập nhật.</p>}</section>
    <div className="av-trust"><div><ShieldCheck/><span>Thông tin rõ ràng<small>Nguồn sản phẩm từ cửa hàng</small></span></div><div><CreditCard/><span>Thanh toán linh hoạt<small>Theo phương thức đã chọn</small></span></div><div><Truck/><span>Nhận hàng thuận tiện<small>Giao hàng hoặc nhận tại chi nhánh</small></span></div><div><MessagesSquare/><span>Hỗ trợ mùa vụ<small>Tìm thông tin vật tư phù hợp</small></span></div></div>
    <section className="av-help"><h2>Hôm nay, bạn cần gì cho mùa vụ?</h2><div>{(home?.categories ?? []).slice(0,4).map(c => <Link key={c.id} href={`/shop/products?category_id=${c.id}`}><Leaf size={26}/><span>{c.name}</span><ArrowRight size={17}/></Link>)}<Link href="/shop/support"><PackageSearch size={26}/><span>Tìm theo công dụng</span><ArrowRight size={17}/></Link></div></section>
    <section className="av-product-section"><div className="av-section-heading"><h2>Vật tư mới lên kệ</h2><Link href="/shop/products?sort=newest">Xem tất cả <ArrowRight size={17}/></Link></div>{!ready ? <div className="av-product-skeletons">{[1,2,3,4,5].map(i => <div className="av-skeleton" key={i}/>)}</div> : products.length ? <div className="ng-product-grid">{products.map(p => <ProductCard key={p.id} product={p}/>)}</div> : <div className="ng-empty"><PackageSearch/><h3>Cửa hàng đang chuẩn bị danh mục</h3><p>Sản phẩm sẽ xuất hiện khi được công khai.</p></div>}</section>
    {!!offers.length && <section className="av-product-section"><div className="av-section-heading"><h2>Ưu đãi đang áp dụng</h2><Link href="/shop/products?sort=price_asc">Xem danh mục <ArrowRight size={17}/></Link></div><div className="ng-product-grid">{offers.map(p => <ProductCard key={p.id} product={p}/>)}</div></section>}
    {!!best.length && <section className="av-product-section"><div className="av-section-heading"><h2>Được chọn cho mùa vụ</h2><Link href="/shop/products?sort=bestselling">Xem tất cả <ArrowRight size={17}/></Link></div><div className="ng-product-grid">{best.map(p => <ProductCard key={p.id} product={p}/>)}</div></section>}
    {home?.categories.map(c => { const selected = products.filter(p => p.category_id === c.id); return selected.length ? <section className="av-product-section" key={c.id}><div className="av-section-heading"><h2>{c.name}</h2><Link href={`/shop/products?category_id=${c.id}`}>Xem tất cả <ArrowRight size={17}/></Link></div><div className="ng-product-grid">{selected.map(p => <ProductCard key={p.id} product={p}/>)}</div></section> : null })}
    <section className="av-contact-banner"><div><p>CÙNG AGROVIA — VỤ XANH</p><h2>Mỗi mùa vụ, thêm một lựa chọn tốt.</h2><span>{home?.description || "Tìm hiểu vật tư, công dụng và lưu ý an toàn từ thông tin cửa hàng công khai."}</span></div><Link href="/shop/support">Liên hệ hỗ trợ <ArrowRight size={18}/></Link></section>
  </div></main>
}
