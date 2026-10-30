"use client"
import Link from "next/link"
import { useState } from "react"
import { useRouter } from "next/navigation"
import { shopRequest, type ShopProduct } from "@/lib/shop-api"
import { useCart } from "./cart-context"
import { Message } from "./shop-shared"
export function ProductPurchase({product}:{product:ShopProduct}) {
 const {add,busy,customer,chainId}=useCart(),router=useRouter()
 const [qty,setQty]=useState("1.000"),[message,setMessage]=useState(""),[error,setError]=useState(false)
 async function put(buy=false){try{await add(product.id,qty);setError(false);setMessage("Đã thêm vào giỏ hàng.");if(buy)router.push("/shop/cart")}catch(e){setError(true);setMessage(e instanceof Error?e.message:"Không thêm được.")}}
 return <div className="ng-purchase"><label>Số lượng ({product.unit})<input aria-label="Số lượng mua" value={qty} onChange={e=>setQty(e.target.value)} inputMode="decimal"/></label><div className="ng-button-row"><button className="ng-button" disabled={busy||!product.in_stock} onClick={()=>void put()}>Thêm vào giỏ</button><button className="ng-button outline" disabled={busy||!product.in_stock} onClick={()=>void put(true)}>Mua ngay</button></div>{customer?<button className="ng-text-button" onClick={()=>void shopRequest(`/api/shop/${chainId}/wishlist`,"POST",{product_id:product.id}).then(()=>{setError(false);setMessage("Đã lưu vào yêu thích.")}).catch(e=>{setError(true);setMessage(e.message)})}>♡ Lưu vào yêu thích</button>:<Link href="/shop/account?next=/shop/wishlist">Đăng nhập để lưu yêu thích</Link>}<Message error={error}>{message}</Message></div>
}
