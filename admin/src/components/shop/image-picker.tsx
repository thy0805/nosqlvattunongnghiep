"use client"
import {useState} from "react"
import {shopMediaUrl,shopUpload} from "@/lib/shop-api"

export function ImagePicker({chain,purpose,value,onChange,onBusy}:{chain:string;purpose:string;value:string[];onChange:(value:string[])=>void;onBusy?:(busy:boolean)=>void}){
 const[busy,setBusy]=useState(false),[error,setError]=useState("")
 async function upload(files:File[]){if(busy)return;setBusy(true);onBusy?.(true);setError("");const urls=[...value];try{if(files.length+value.length>5)throw Error("Tối đa 5 ảnh cho mỗi hồ sơ.");for(const file of files){if(file.size>2*1024*1024)throw Error("Mỗi ảnh tối đa2 MiB.");urls.push(await shopUpload(chain,purpose,file));onChange([...urls])}}catch(e){setError(e instanceof Error?e.message:"Chưa tải được ảnh.")}finally{setBusy(false);onBusy?.(false)}}
 return <section><label>Ảnh đính kèm (JPEG/PNG/WebP, tối đa 5 ảnh)<input disabled={busy||value.length>=5} type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={e=>{const files=Array.from(e.target.files??[]);e.target.value="";void upload(files)}}/></label>{busy&&<p role="status">Đang tải ảnh…</p>}{error&&<p role="alert" className="ng-notice error">{error}</p>}<div className="ng-thumbs">{value.map((src,i)=><div key={src}><a href={shopMediaUrl(src)} target="_blank" rel="noreferrer"><img src={shopMediaUrl(src)} alt={`Ảnh đính kèm ${i+1}`}/></a><button type="button" disabled={busy} className="ng-text-button" onClick={()=>onChange(value.filter((_,index)=>index!==i))}>Bỏ khỏi hồ sơ</button></div>)}</div></section>
}
