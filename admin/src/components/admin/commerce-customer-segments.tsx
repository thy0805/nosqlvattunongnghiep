"use client"
import { useEffect,useState } from "react"
import { shopRequest } from "@/lib/shop-api"
import { Message } from "@/components/shop/shop-shared"

type Segment={customer_id:string;name:string;email:string;is_vip:boolean;revision:number;history:{is_vip:boolean;reason:string;at:string}[]}
export function CommerceCustomerSegments({chain}:{chain:string}){
 const[rows,setRows]=useState<Segment[]>([]),[error,setError]=useState(""),[busy,setBusy]=useState(false)
 const load=()=>shopRequest<Segment[]>(`/api/shop-admin/${chain}/marketing/customers`).then(setRows)
 useEffect(()=>{setRows([]);if(chain)void load().catch(e=>setError(e.message))},[chain])
 async function save(e:React.FormEvent<HTMLFormElement>,row:Segment){e.preventDefault();if(busy)return;setBusy(true);setError("");const f=new FormData(e.currentTarget);try{await shopRequest(`/api/shop-admin/${chain}/marketing/customers/${row.customer_id}`,"PUT",{is_vip:f.get("is_vip")==="on",revision:row.revision,reason:String(f.get("reason"))});await load()}catch(e){setError(e instanceof Error?e.message:"Chưa lưu được phân nhóm.")}finally{setBusy(false)}}
 return <section className="ng-panel"><h2>Khách hàng & nhóm VIP</h2><p>Chỉ chủ chuỗi điều chỉnh khách đã có giao dịch hoặc yêu cầu với chuỗi. Phân nhóm áp dụng riêng cửa hàng này.</p><Message error>{error}</Message>{rows.length===0&&<p>Chưa có khách hàng thuộc phạm vi cửa hàng.</p>}{rows.map(row=><form key={row.customer_id+":"+row.revision} className="ng-form" onSubmit={e=>void save(e,row)}><h3>{row.name} · #{row.customer_id}</h3><p>{row.email}</p><label className="ng-checkbox"><input name="is_vip" type="checkbox" defaultChecked={row.is_vip}/>Ưu đãi dành cho khách VIP</label><label>Lý do thay đổi<input name="reason" required maxLength={1000}/></label><button className="ng-button" disabled={busy}>Lưu phân nhóm</button>{row.history.length>0&&<details><summary>Lịch sử gần đây</summary>{row.history.map((h,i)=><p key={i}>{new Date(h.at).toLocaleString("vi-VN")} · {h.is_vip?"VIP":"Thông thường"} · {h.reason}</p>)}</details>}</form>)}</section>
}
