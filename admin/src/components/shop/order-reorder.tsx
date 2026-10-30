"use client"
import {useState} from "react"
import {useRouter} from "next/navigation"
import {useCart} from "./cart-context"
import {Message} from "./shop-shared"
import {shopMoney,shopRequest} from "@/lib/shop-api"
type Preview={order_id:string;message:string;items:{product_id:string;name:string;quantity:string;previous_unit_price:string;current_unit_price:string|null;available_quantity:string;available:boolean}[]}
export function OrderReorder({chain,orderId}:{chain:string;orderId:string}){
 const{cart,refresh}=useCart(),router=useRouter(),[preview,setPreview]=useState<Preview|null>(null),[selected,setSelected]=useState<string[]>([]),[busy,setBusy]=useState(false),[error,setError]=useState("")
 async function load(){if(busy)return;setBusy(true);setError("");try{const p=await shopRequest<Preview>(`/api/shop/${chain}/orders/${orderId}/reorder`);setPreview(p);setSelected(p.items.filter(i=>i.available).map(i=>i.product_id))}catch(e){setError(e instanceof Error?e.message:"Không kiểm được đơn cũ.")}finally{setBusy(false)}}
 async function apply(){if(busy||!preview||!selected.length)return;setBusy(true);setError("");try{await shopRequest(`/api/shop/${chain}/cart`,"PUT",{items:preview.items.filter(i=>selected.includes(i.product_id)).map(({product_id,quantity})=>({product_id,quantity}))});await refresh();router.push("/shop/cart")}catch(e){setError(e instanceof Error?e.message:"Không cập nhật được giỏ.")}finally{setBusy(false)}}
 return <section className="ng-panel"><h2>Mua lại từ đơn này</h2><Message error>{error}</Message><button className="ng-button outline" disabled={busy} onClick={()=>void load()}>Kiểm tra sản phẩm, giá và tồn mới</button>{preview&&<><p>{preview.message}</p>{preview.items.map(i=><label key={i.product_id} className="ng-order-line"><input type="checkbox" disabled={busy||!i.available} checked={selected.includes(i.product_id)} onChange={e=>setSelected(v=>e.target.checked?[...v,i.product_id]:v.filter(id=>id!==i.product_id))}/><span>{i.name} × {i.quantity}<br/>Giá cũ {shopMoney(i.previous_unit_price)} · Giá hiện tại {i.current_unit_price===null?"Ngừng bán":shopMoney(i.current_unit_price)}{!i.available&&" · Chưa đủ tồn"}</span></label>)}{cart.items.length>0&&<p role="status">Giỏ hiện có {cart.items.length} sản phẩm. Xác nhận sẽ thay giỏ bằng các sản phẩm được chọn bên trên.</p>}<button className="ng-button" disabled={busy||!selected.length} onClick={()=>void apply()}>{cart.items.length?"Xác nhận thay giỏ bằng lựa chọn này":"Thêm lựa chọn vào giỏ"}</button></>}</section>
}
