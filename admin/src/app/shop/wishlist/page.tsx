"use client"
import { useEffect,useState } from "react"
import Link from "next/link"
import { shopRequest,type ShopProduct } from "@/lib/shop-api"
import { useCart } from "@/components/shop/cart-context"
import { ProductCard } from "@/components/shop/product-card"
import { ShopStatus,Message } from "@/components/shop/shop-shared"
export default function WishlistPage(){
 const {chainId,customer,ready}=useCart(),[items,setItems]=useState<(ShopProduct&{unavailable?:boolean})[]>([]),[error,setError]=useState("")
 useEffect(()=>{if(chainId&&customer)void shopRequest<typeof items>(`/api/shop/${chainId}/wishlist`).then(setItems).catch(e=>setError(e.message))},[chainId,customer?.id])
 return <main className="page-shell ng-page"><p className="ng-eyebrow">LƯU CHO MÙA TỚI</p><h1>Vật tư yêu thích</h1><ShopStatus/><Message error>{error}</Message>{ready&&!customer?<div className="ng-empty"><h2>Đăng nhập để xem danh sách của bạn</h2><Link className="ng-button" href="/shop/account?next=/shop/wishlist">Đăng nhập</Link></div>:<><div className="ng-product-grid">{items.map(p=><div key={p.id}>{p.unavailable?<div className="ng-panel">Sản phẩm #{p.id} không còn được công khai.</div>:<ProductCard product={p}/>}<button className="ng-text-button" onClick={()=>void shopRequest<typeof items>(`/api/shop/${chainId}/wishlist`,"DELETE",{product_id:p.id}).then(setItems).catch(e=>setError(e.message))}>Bỏ yêu thích</button></div>)}</div>{customer&&!items.length&&<div className="ng-empty">Chưa có vật tư được lưu. <Link href="/shop/products">Khám phá danh mục →</Link></div>}</>}</main>
}
