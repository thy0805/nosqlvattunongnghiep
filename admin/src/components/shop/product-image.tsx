"use client"

import { useState } from "react"
import { Package } from "lucide-react"
import { shopMediaUrl } from "@/lib/shop-api"

export function ProductImage({ images, alt, loading }: { images: string[]; alt: string; loading?: "lazy" | "eager" }) {
 const [failed, setFailed] = useState<string[]>([])
 const source = [...new Set(images.filter(Boolean))].find(src => !failed.includes(src))
 return source ? <img src={shopMediaUrl(source)} alt={alt} loading={loading} onError={() => setFailed(previous => previous.includes(source) ? previous : [...previous, source])}/> : <Package size={60} aria-label="Chưa có ảnh sản phẩm"/>
}
