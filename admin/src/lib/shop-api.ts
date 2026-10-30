"use client"

import { apiFetch, apiUrl, ApiError } from "@/lib/api"
export type ShopProduct = { id: string; slug: string; chain_id: string; code: string; name: string; category_id: string; category_name: string; unit: string; base_price: string; price: string; tax_rate: string; in_stock: boolean; available_quantity: string; images: string[]; brand: string; origin: string; packaging: string; active_ingredient: string | null; description: string; uses: string; instructions: string; storage: string; safety: string }
export type ShopCustomer = { id: string; name: string; email: string; phone: string | null; birthday: string | null }
export type ShopCart = { items: { product_id: string; quantity: string; price_changed?:boolean; saved_unit_price?:string; product?: ShopProduct; line_total?: string; unavailable: boolean }[]; subtotal: string; is_authenticated: boolean }
export type ShopHome = { name: string; description: string; contact: string; categories: { id: string; name: string; image?: string }[]; branches: { id: string; name: string; address: string; warehouse_id: string }[]; banners: { id: string; image: string; title: string; href: string }[]; products: ShopProduct[]; bestselling?: ShopProduct[]; offers?: ShopProduct[] }
export async function shopRequest<T>(path: string, method = "GET", value?: unknown): Promise<T> {
  if (method !== "GET") {
    const csrf = await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } })
    if (!csrf.ok) throw new ApiError("Không khởi tạo được phiên an toàn.", csrf.status)
  }
  const result = await apiFetch<{ data: T }>(path, { method, ...(value === undefined ? {} : { body: JSON.stringify(value) }) })
  return result.data
}
export function shopMediaUrl(value:string){return /^\/api\/shop-media\/[1-9][0-9]*$/.test(value)?`${apiUrl}${value}`:value}
export async function shopUpload(chain:string,purpose:string,file:File):Promise<string>{
 const csrf=await fetch(`${apiUrl}/sanctum/csrf-cookie`,{credentials:"include",headers:{Accept:"application/json"}})
 if(!csrf.ok)throw new ApiError("Không khởi tạo được phiên tải ảnh.",csrf.status)
 const body=new FormData();body.set("purpose",purpose);body.set("file",file)
 const token=document.cookie.split("; ").find(c=>c.startsWith("XSRF-TOKEN="))?.slice(11)
 const response=await fetch(`${apiUrl}/api/shop/${chain}/uploads`,{method:"POST",credentials:"include",headers:{Accept:"application/json",...(token?{"X-XSRF-TOKEN":decodeURIComponent(token)}:{})},body})
 const result=await response.json().catch(()=>null)
 if(!response.ok)throw new ApiError(result?.message??"Không tải được ảnh.",response.status)
 return result.data.url
}
export function shopMoney(value: string) {
  if (!/^\d+(?:\.\d+)?$/.test(value)) return value
  const [whole, fraction = "00"] = value.split(".")
  return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ".")}${fraction !== "00" ? `,${fraction}` : ""} ₫`
}
export function quantityAdd(a: string, b: string) {
  const parse = (s: string) => { if (!/^\d+(?:\.\d{1,3})?$/.test(s)) throw new Error("Số lượng tối đa 3 chữ số thập phân."); const [w, f = ""] = s.split("."); return BigInt(w) * BigInt(1000) + BigInt(f.padEnd(3, "0")) }
  const sum = parse(a) + parse(b)
  return `${sum / BigInt(1000)}.${String(sum % BigInt(1000)).padStart(3, "0")}`
}
