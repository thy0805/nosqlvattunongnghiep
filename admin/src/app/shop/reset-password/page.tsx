"use client";

import {FormEvent,useEffect,useState} from "react";
import Link from "next/link";
import {shopRequest} from "@/lib/shop-api";

export default function ResetPassword(){
 const [link,setLink]=useState({email:"",token:""});const [busy,setBusy]=useState(false);const [message,setMessage]=useState("");const [error,setError]=useState("");const [done,setDone]=useState(false);
 useEffect(()=>{const p=new URLSearchParams(window.location.hash.slice(1));setLink({email:p.get("email")??"",token:p.get("token")??""});window.history.replaceState(null,"",window.location.pathname)},[]);
 async function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();setBusy(true);setError("");const f=new FormData(e.currentTarget);try{const r=await shopRequest<{message:string}>("/api/shop/account/reset","POST",{...link,password:f.get("password"),password_confirmation:f.get("password_confirmation")});setMessage(r.message);setDone(true);setLink({email:"",token:""})}catch(e){setError(e instanceof Error?e.message:"Không thể đặt lại mật khẩu.")}finally{setBusy(false)}}
 return <main className="page-shell ng-page"><p className="ng-eyebrow">TÀI KHOẢN KHÁCH AGROVIA</p><h1>Đặt lại mật khẩu</h1><section className="ng-panel">{error&&<p role="alert">{error}</p>}{message&&<p role="status">{message}</p>}{!done&&link.token&&link.email?<form className="ng-form" onSubmit={e=>void submit(e)}><p>Liên kết chỉ sử dụng một lần, có hiệu lực 30 phút.</p><label>Mật khẩu mới<input name="password" type="password" required minLength={10} maxLength={128} autoComplete="new-password"/></label><label>Nhập lại mật khẩu mới<input name="password_confirmation" type="password" required minLength={10} maxLength={128} autoComplete="new-password"/></label><button className="ng-button" disabled={busy}>{busy?"Đang xử lý…":"Đặt lại mật khẩu"}</button></form>:!done&&<p>Hãy mở liên kết trong email của cửa hàng. Nếu liên kết hết hạn, gửi yêu cầu mới tại trang tài khoản.</p>}<Link href="/shop/account">Quay lại đăng nhập →</Link></section></main>
}
