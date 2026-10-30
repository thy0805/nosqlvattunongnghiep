"use client"
import { useEffect,useState } from "react"
import Link from "next/link"
import { shopRequest,type ShopProduct } from "@/lib/shop-api"
import { useCart } from "@/components/shop/cart-context"
import { SupportInbox } from "@/components/shop/support-inbox"
import { ProductCard } from "@/components/shop/product-card"
import { ShopStatus,Message } from "@/components/shop/shop-shared"
type Help={provider_status:string;message:string;products:ShopProduct[];policies:{slug:string;title:string;body:string}[]}
export default function SupportPage(){
 const {chainId,customer,home}=useCart(),[result,setResult]=useState<Help|null>(null),[error,setError]=useState(""),[query,setQuery]=useState("")
 useEffect(()=>{if(!chainId)return;let active=true;shopRequest<Help>(`/api/shop/${chainId}/assistant?search=${encodeURIComponent(query)}`).then(r=>{if(active)setResult(r)}).catch(e=>{if(active)setError(e.message)});return()=>{active=false}},[chainId,query])
 return <main className="page-shell ng-page"><p className="ng-eyebrow">ĐỒNG HÀNH CÙNG NGƯỜI LÀM NÔNG</p><h1>Tìm vật tư & hỗ trợ</h1><ShopStatus/><Message error>{error}</Message><div className="ng-assistant-note"><span className="ng-badge-inline">AI chưa kết nối</span><p>{result?.message??"Trợ lý AI đang chờ cấu hình. Các công cụ tìm vật tư và chính sách vẫn hoạt động."}</p></div><form className="ng-search ng-support-search" onSubmit={e=>{e.preventDefault();setQuery(String(new FormData(e.currentTarget).get("search")??""))}}><input name="search" placeholder="Tìm theo tên vật tư hoặc công dụng" aria-label="Tìm vật tư hỗ trợ"/><button>Tìm kiếm</button></form><div className="ng-product-grid">{result?.products.map(p=><ProductCard product={p} key={p.id}/>)}</div>{result&&!result.products.length&&<div className="ng-empty">Không tìm thấy sản phẩm phù hợp. Thử mô tả ngắn hơn hoặc liên hệ cửa hàng.</div>}{customer&&chainId?<SupportInbox chain={chainId} branches={home?.branches??[]}/>:<p className="ng-panel"><Link href="/shop/account">Đăng nhập để gửi yêu cầu và xem thông báo riêng →</Link></p>}<section className="ng-section"><h2>Chính sách cửa hàng</h2><div className="ng-two-columns">{result?.policies.map(p=><Link className="ng-panel" href={`/shop/policies/${p.slug}`} key={p.slug}><h3>{p.title}</h3><p className="ng-clamp">{p.body}</p><span>Đọc chính sách →</span></Link>)}</div></section></main>
}
