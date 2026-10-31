"use client"

import Link from "next/link"
import { useState } from "react"
import { Heart, Plus } from "lucide-react"
import { shopMoney, shopRequest, type ShopProduct } from "@/lib/shop-api"
import { useCart } from "./cart-context"
import { ProductImage } from "./product-image"
export function ProductCard({ product }: { product: ShopProduct }) {
 const { add, busy, customer, chainId } = useCart()
 const [message, setMessage] = useState("")
 async function wish() { if (!customer) { location.href = "/shop/account?next=/shop/wishlist"; return } try { await shopRequest(`/api/shop/${chainId}/wishlist`, "POST", { product_id: product.id }); setMessage("Đã lưu vào yêu thích.") } catch(e) { setMessage(e instanceof Error ? e.message : "Không lưu được.") } }
 return <article className="ng-product"><div className="ng-product-image"><Link href={`/shop/products/${product.slug}`}><ProductImage images={product.images} alt={product.name} loading="lazy"/></Link><button aria-label={`Yêu thích ${product.name}`} onClick={() => void wish()}><Heart size={18}/></button>{product.base_price !== product.price && <span className="ng-badge">Ưu đãi</span>}</div><div className="ng-product-body"><p className="ng-eyebrow">{product.category_name}</p><Link href={`/shop/products/${product.slug}`} className="ng-product-name">{product.name}</Link><p className="ng-product-code">{product.code} · {product.packaging}</p><p className="ng-price">{shopMoney(product.price)} <small>/ {product.unit}</small></p>{product.base_price !== product.price && <del>{shopMoney(product.base_price)}</del>}<div className="ng-product-bottom"><span className={product.in_stock ? "ng-stock" : "ng-muted"}>{product.in_stock ? "Có hàng" : "Tạm hết hàng"}</span><button className="ng-add" disabled={busy || !product.in_stock} aria-label={`Thêm ${product.name} vào giỏ`} onClick={() => void add(product.id, "1.000").then(() => setMessage("Đã thêm vào giỏ.")).catch(e => setMessage(e.message))}><Plus size={16}/><span>Thêm giỏ</span></button></div>{message && <p role="status" className="ng-small-message">{message}</p>}</div></article>
}
