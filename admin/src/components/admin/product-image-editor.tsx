"use client"

import { useState } from "react"
import { apiUrl, ApiError } from "@/lib/api"
import { ProductImage } from "@/components/shop/product-image"

export function ProductImageEditor({ chain, initialImages, onBusyChange, saveLabel = "Lưu trưng bày" }: { chain: string; initialImages: string[]; onBusyChange: (value: boolean) => void; saveLabel?: string }) {
  const [images, setImages] = useState(initialImages)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState("")

  async function upload(files: File[]) {
    if (busy || !files.length) return
    setError("")
    if (images.length + files.length > 10) { setError("Tối đa 10 ảnh cho một sản phẩm."); return }
    if (files.some(file => !["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024)) { setError("Chọn ảnh JPG, PNG hoặc WebP, tối đa 5 MB mỗi ảnh."); return }
    setBusy(true); onBusyChange(true)
    try {
      const csrf = await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } })
      if (!csrf.ok) throw new Error("Không khởi tạo được phiên tải ảnh.")
      for (const file of files) {
        const token = document.cookie.split("; ").find(cookie => cookie.startsWith("XSRF-TOKEN="))?.slice(11)
        const body = new FormData(); body.set("file", file)
        const response = await fetch(`${apiUrl}/api/shop-admin/${chain}/product-images`, { method: "POST", credentials: "include", headers: { Accept: "application/json", ...(token ? { "X-XSRF-TOKEN": decodeURIComponent(token) } : {}) }, body })
        const result = await response.json().catch(() => null)
        if (!response.ok) throw new ApiError(result?.errors?.file?.[0] ?? result?.message ?? "Không tải được ảnh.", response.status)
        setImages(current => [...current, result.data.url])
      }
    } catch (caught) { setError(caught instanceof Error ? caught.message : "Không tải được ảnh.") }
    finally { setBusy(false); onBusyChange(false) }
  }

  return <section className="ng-panel"><h3>Ảnh sản phẩm trên Shop</h3><p className="ng-muted">Tải ảnh từ máy, chọn ảnh chính rồi bấm {saveLabel}. Tối đa 10 ảnh JPG/PNG/WebP, 5 MB mỗi ảnh. Ảnh được lưu riêng trên máy chủ; sản phẩm lưu danh sách ảnh trong Shop.</p><input type="hidden" name="images" value={images.join("\n")}/><label>Tải ảnh từ máy<input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={busy || images.length >= 10} onChange={event => { const files = Array.from(event.target.files ?? []); event.target.value = ""; void upload(files) }}/></label>{busy && <p role="status">Đang tải ảnh…</p>}{error && <p role="alert" className="ng-notice error">{error}</p>}<div className="ng-button-row">{images.map((url, index) => <article key={url + index} className="ng-panel" style={{ width: 180, maxWidth: "100%" }}><div className="aspect-square overflow-hidden rounded-lg bg-white [&_img]:size-full [&_img]:object-contain [&_svg]:size-12"><ProductImage images={[url]} alt={`Ảnh sản phẩm ${index + 1}`}/></div><p>{index === 0 ? "Ảnh chính" : `Ảnh ${index + 1}`}</p><button type="button" disabled={busy || index === 0} className="ng-text-button" onClick={() => setImages(current => [current[index], ...current.filter((_, position) => position !== index)])}>Đặt ảnh chính</button><button type="button" disabled={busy} className="ng-text-button" onClick={() => setImages(current => current.filter((_, position) => position !== index))}>Bỏ khỏi sản phẩm</button></article>)}</div>{!images.length && <p>Chưa chọn ảnh. Tải ảnh từ máy để thêm.</p>}<p className="ng-muted">Bỏ ảnh chỉ thay danh sách khi lưu, không xóa tệp ảnh hoặc dữ liệu lịch sử. Ảnh của sản phẩm công khai có thể được khách xem.</p></section>
}
