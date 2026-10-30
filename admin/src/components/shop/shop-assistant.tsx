"use client"

import { useState } from "react"
import Link from "next/link"
import { MessageCircle, X, Search, ArrowUpRight } from "lucide-react"
import { shopMoney, shopRequest, type ShopProduct } from "@/lib/shop-api"
import { useCart } from "./cart-context"

type Help = { provider_status: string; products: ShopProduct[]; policies: { slug: string; title: string; body: string }[] }
export function ShopAssistant() {
  const { chainId } = useCart()
  const [open, setOpen] = useState(false)
  const [result, setResult] = useState<Help | null>(null)
  const [error, setError] = useState("")
  const [busy, setBusy] = useState(false)
  async function search(form: HTMLFormElement) {
    if (!chainId || busy) return
    setBusy(true); setError("")
    try { setResult(await shopRequest<Help>(`/api/shop/${chainId}/assistant?search=${encodeURIComponent(String(new FormData(form).get("search") ?? ""))}`)) }
    catch (e) { setError(e instanceof Error ? e.message : "Không tải được thông tin hỗ trợ.") }
    finally { setBusy(false) }
  }
  return <aside className="av-assistant">{open && <section className="av-assistant-panel" role="dialog" aria-label="Hỗ trợ Agrovia"><header><div><strong>Agrovia — Vụ Xanh</strong><small>Tìm nhanh vật tư & chính sách</small></div><button aria-label="Đóng hỗ trợ" onClick={() => setOpen(false)}><X size={19}/></button></header><div className="av-assistant-content"><p className="av-ai-status">AI chưa kết nối. Đây là công cụ tra cứu dữ liệu cửa hàng.</p><form onSubmit={e => { e.preventDefault(); void search(e.currentTarget) }}><label htmlFor="av-help-query">Bạn đang tìm vật tư nào?</label><div><input id="av-help-query" name="search" maxLength={120} placeholder="Tên sản phẩm, công dụng…"/><button aria-label="Tra cứu vật tư" disabled={busy || !chainId}><Search size={18}/></button></div></form>{busy && <p role="status">Đang tra cứu…</p>}{error && <p role="alert" className="ng-danger">{error}</p>}{result?.products.map(p => <Link className="av-assistant-product" key={p.id} href={`/shop/products/${p.slug}`} onClick={() => setOpen(false)}>{p.images[0] && <img src={p.images[0]} alt=""/>}<span><strong>{p.name}</strong><small>{shopMoney(p.price)} · {p.in_stock ? "Có hàng" : "Tạm hết"}</small></span><ArrowUpRight size={16}/></Link>)}{result && !result.products.length && <p>Chưa tìm thấy sản phẩm phù hợp. Thử từ khóa khác.</p>}{result?.policies.map(p => <Link className="av-policy-link" key={p.slug} href={`/shop/policies/${p.slug}`} onClick={() => setOpen(false)}>{p.title} <ArrowUpRight size={13}/></Link>)}<Link className="ng-text-button" href="/shop/support" onClick={() => setOpen(false)}>Mở trung tâm hỗ trợ →</Link></div></section>}<button className="av-assistant-toggle" aria-label={open ? "Đóng hỗ trợ Agrovia" : "Mở hỗ trợ Agrovia"} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X size={24}/> : <MessageCircle size={24}/>}</button></aside>
}
