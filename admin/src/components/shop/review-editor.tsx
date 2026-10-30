"use client"
import {useEffect,useRef,useState} from "react"
import {shopRequest} from "@/lib/shop-api"
import {ImagePicker} from "./image-picker"

type Review={id:string;order_id:string;rating:number;body:string;images?:string[];status:string;revision?:number;edit_count?:number;created_at:string}
function ReviewForm({row,chain,product,reload}:{row:Review;chain:string;product:string;reload:()=>Promise<void>}){
 const[images,setImages]=useState(row.images??[]),[uploading,setUploading]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState("")
 const request=useRef<{body:string;key:string}|null>(null),editable=Date.now()-Date.parse(row.created_at)<=7*86400000&&(row.edit_count??0)<3
 async function save(e:React.FormEvent<HTMLFormElement>){e.preventDefault();if(busy||uploading)return;setBusy(true);setError("");const f=new FormData(e.currentTarget),body={rating:Number(f.get("rating")),body:String(f.get("body")),images,revision:row.revision??0},text=JSON.stringify(body),key=request.current?.body===text?request.current.key:crypto.randomUUID();request.current={body:text,key};try{await shopRequest(`/api/shop/${chain}/products/${product}/reviews/${row.id}`,"PUT",{...body,request_key:key});request.current=null;await reload()}catch(e){setError(e instanceof Error?e.message:"Chưa cập nhật được đánh giá.")}finally{setBusy(false)}}
 return <article className="ng-panel"><h3>Đánh giá của bạn · Đơn #{row.order_id}</h3><p>{row.status==="published"?"Đã công khai":row.status==="rejected"?"Chưa được chấp nhận":"Chờ kiểm duyệt"} · Đã sửa {row.edit_count??0}/3 lần</p>{error&&<p role="alert" className="ng-notice error">{error}</p>}{editable?<form className="ng-form" onSubmit={e=>void save(e)}><label>Mức hài lòng<select name="rating" defaultValue={row.rating}>{[5,4,3,2,1].map(n=><option key={n} value={n}>{n}/5</option>)}</select></label><label>Nội dung<textarea name="body" defaultValue={row.body} required maxLength={3000}/></label><ImagePicker chain={chain} purpose="review" value={images} onChange={setImages} onBusy={setUploading}/><button className="ng-button outline" disabled={busy||uploading}>Lưu và gửi duyệt lại</button></form>:<p>{row.rating}/5 · {row.body}</p>}</article>
}
export function ReviewEditor({chain,product,refreshToken}:{chain:string;product:string;refreshToken:number}){
 const[rows,setRows]=useState<Review[]>([]),[error,setError]=useState("")
 async function load(){setRows(await shopRequest<Review[]>(`/api/shop/${chain}/products/${product}/reviews`))}
 useEffect(()=>{void load().catch(e=>setError(e.message))},[chain,product,refreshToken])
 return <section><h3>Đánh giá đã gửi</h3><p className="ng-muted">Có thể chỉnh tối đa3 lần trong7 ngày kể từ khi gửi. Nội dung sửa phải được cửa hàng duyệt lại.</p>{error&&<p role="alert" className="ng-notice error">{error}</p>}{rows.map(row=><ReviewForm key={row.id+":"+(row.revision??0)} row={row} chain={chain} product={product} reload={load}/>)}</section>
}
