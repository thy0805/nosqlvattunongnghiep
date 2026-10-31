"use client"

import type { CatalogProduct } from "@/lib/api"
import { ProductImage } from "@/components/shop/product-image"

export function CatalogProductImage({ product }: { product: CatalogProduct }) {
  const images = product.shop_images?.find(source => source.images.length)?.images ?? []
  return <span className="inline-flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-black/10 bg-white [&_img]:size-full [&_img]:object-contain [&_svg]:size-6"><ProductImage key={JSON.stringify(images)} images={images} alt={product.name} loading="lazy"/></span>
}

export function CatalogShopImages({ product }: { product: CatalogProduct }) {
  const sources = product.shop_images?.filter(source => source.images.length) ?? []
  return <section className="mt-5"><h3 className="text-sm font-semibold">Ảnh trưng bày Shop</h3>{sources.length ? sources.map(source => <div key={source.chain_id} className="mt-3"><p className="text-xs text-muted-foreground">Chuỗi #{source.chain_id} · ảnh lấy từ dữ liệu Shop, không phải nội dung catalog</p><div className="mt-2 flex flex-wrap gap-3">{source.images.map(url => <span key={url} className="inline-flex size-24 items-center justify-center overflow-hidden rounded-lg border border-black/10 bg-white [&_img]:size-full [&_img]:object-contain [&_svg]:size-8"><ProductImage key={url} images={[url]} alt={product.name} loading="lazy"/></span>)}</div></div>) : <p className="mt-2 text-sm text-muted-foreground">Chưa có ảnh Shop trong phạm vi được phép xem.</p>}</section>
}
