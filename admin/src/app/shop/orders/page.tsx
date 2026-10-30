"use client"
import {useEffect,useState} from "react"
import Link from "next/link"
import {useCart} from "@/components/shop/cart-context"
import {ShopStatus,Message} from "@/components/shop/shop-shared"
import {shopMoney,shopRequest} from "@/lib/shop-api"
import {orderLabels,type OnlineOrder} from "@/lib/shop-orders"
export default function OrdersPage(){const{chainId,customer,ready}=useCart(),[orders,setOrders]=useState<OnlineOrder[]>([]),[error,setError]=useState("");useEffect(()=>{if(chainId&&customer)void shopRequest<OnlineOrder[]>(`/api/shop/${chainId}/orders`).then(setOrders).catch(e=>setError(e.message))},[chainId,customer?.id]);return <main className="page-shell ng-page"><p className="ng-eyebrow">THEO DÕI MÙA VỤ</p><h1>Đơn hàng của tôi</h1><ShopStatus/><Message error>{error}</Message>{ready&&!customer?<Link className="ng-button" href="/shop/account?next=/shop/orders">Đăng nhập</Link>:<>{orders.map(o=><Link className="ng-panel ng-order-card" key={o.id} href={`/shop/orders/${o.id}`}><div><strong>{o.order_no}</strong><p>{new Date(o.created_at).toLocaleString("vi-VN")} · {o.branch_name}</p><span>{orderLabels[o.status]??o.status}</span></div><div><strong>{shopMoney(o.total)}</strong><p>Còn phải trả {shopMoney(o.remaining_amount)}</p><span>Xem chi tiết →</span></div></Link>)}{customer&&!orders.length&&<div className="ng-empty">Chưa có đơn hàng. <Link href="/shop/products">Chọn vật tư →</Link></div>}</>}</main>}
