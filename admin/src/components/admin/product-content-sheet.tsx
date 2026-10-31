"use client"

import { useEffect, useState } from "react"
import Image from "next/image"
import { CatalogManager } from "./catalog-manager"
import { ProductShopEditor } from "./product-shop-editor"
import { CatalogShopImages } from "./catalog-product-image"
import { ExternalLink, RotateCw } from "lucide-react"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/admin/v2/sheet"
import { apiFetch, type CatalogProduct, type CatalogResponse, type ProductContent } from "@/lib/api"

type Form = { usage_instructions: string; additional_info: string; images: string }
const empty: Form = { usage_instructions: "", additional_info: "", images: "" }

export function ProductContentSheet({ product, canManage, onClose, onShopSaved, source, onProductSaved }: { product: CatalogProduct | null; canManage: boolean; onClose: () => void; onShopSaved?: () => void; source?: CatalogResponse | null; onProductSaved?: (product: CatalogProduct) => void }) {
  const [content, setContent] = useState<ProductContent | null>(null)
  const [form, setForm] = useState<Form>(empty)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState("")
  const [success, setSuccess] = useState("")
  const [reload, setReload] = useState(0)
  const [shopBusy, setShopBusy] = useState(false)
  const [generalBusy, setGeneralBusy] = useState(false)

  useEffect(() => {
    if (!product) return
    const controller = new AbortController()
    const productId = product.id
    async function load() {
      setLoading(true)
      setError("")
      setSuccess("")
      try {
        const response = await apiFetch<{ data: ProductContent | null }>(`/api/catalog/products/${productId}/content`, { signal: controller.signal })
        if (controller.signal.aborted) return
        setContent(response.data)
        setForm({
          usage_instructions: response.data?.usage_instructions ?? "",
          additional_info: response.data?.additional_info ?? "",
          images: response.data?.images.join("\n") ?? "",
        })
      } catch (caught) {
        if (!controller.signal.aborted) setError(caught instanceof Error ? caught.message : "Không tải được nội dung vật tư.")
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }
    void load()
    return () => controller.abort()
  }, [product?.id, reload])

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!product || saving || shopBusy || generalBusy) return
    setSaving(true)
    setError("")
    setSuccess("")
    try {
      const response = await apiFetch<{ data: ProductContent }>(`/api/catalog/products/${product.id}/content`, {
        method: "PUT",
        body: JSON.stringify({
          usage_instructions: form.usage_instructions.trim() || null,
          additional_info: form.additional_info.trim() || null,
          images: form.images.split("\n").map((value) => value.trim()).filter(Boolean),
        }),
      })
      setContent(response.data)
      setSuccess("Đã lưu nội dung vật tư vào MongoDB.")
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Không lưu được nội dung vật tư.")
    } finally {
      setSaving(false)
    }
  }

  const readOnlyDetails = product?.shop_details?.filter(detail => !detail.can_manage) ?? []
  const readOnlyImages = product?.shop_images?.filter(images => !product.shop_details?.some(detail => detail.chain_id === images.chain_id && detail.can_manage)) ?? []

  const fieldClass = "mt-2 w-full rounded-xl border border-black/10 bg-white px-3 py-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-[#274f3a]"

  return (
    <Sheet open={product !== null} onOpenChange={(open) => { if (!open && !saving && !shopBusy && !generalBusy) onClose() }}>
      <SheetContent side="right" showCloseButton={!saving && !shopBusy && !generalBusy} className="data-[side=right]:w-full data-[side=right]:sm:w-[38rem] data-[side=right]:sm:max-w-none overflow-y-auto bg-[#fbfaf5] p-6">
        <SheetTitle className="pr-8 text-2xl tracking-tight">{product?.name ?? "Nội dung vật tư"}</SheetTitle>
        <SheetDescription className="mt-2">Sửa đúng sản phẩm này, không cần tìm lại SKU. Lưu riêng từng phần: thông tin chung và giá; ảnh, mô tả và hiển thị Shop theo chuỗi.</SheetDescription>
        {product && <>
          <p className="mt-3 text-sm text-muted-foreground">{product.code} · Đang hoạt động: {product.is_active ? "Có" : "Không"}. Đây là trạng thái danh mục, không phải còn hàng hay đã công khai trên Shop.</p>
          {canManage && source ? <CatalogManager key={`general-${product.id}`} source={source} embeddedProduct={product} disabled={shopBusy || saving} onBusyChange={setGeneralBusy} onSaved={() => onShopSaved?.()} onProductSaved={onProductSaved}/> : <section className="mt-5 rounded-xl border border-border bg-card p-4 text-sm text-card-foreground"><h3 className="font-semibold">Thông tin chung & giá · chỉ xem</h3><p className="mt-2">Giá cơ sở: {product.sale_price} VND · Thuế: {product.tax_rate === null ? "Chưa cấu hình" : `${product.tax_rate}%`}.</p><p className="mt-2 text-muted-foreground">Chỉ tài khoản được chỉ định quản trị danh mục chung mới sửa tên, giá và trạng thái. Quyền sửa Shop theo chuỗi được kiểm riêng bên dưới.</p></section>}
          <ProductShopEditor key={product.id} product={product} disabled={generalBusy || saving} onBusyChange={setShopBusy} onSaved={onShopSaved}/>
          <p className="mt-3 text-xs text-muted-foreground">Mỗi nút lưu chỉ cập nhật phần tương ứng. Nếu một phần báo lỗi, các phần đã lưu trước đó vẫn được giữ; không coi là đã lưu toàn bộ sản phẩm.</p>
          {readOnlyDetails.length > 0 && <section className="mt-6 space-y-5">
            <h3 className="font-semibold">Thông tin sản phẩm trên Shop · chỉ xem</h3>
            {readOnlyDetails.map(detail => <article key={detail.chain_id} className="space-y-3 rounded-xl border border-border bg-card p-4 text-card-foreground">
              <p className="font-medium">{detail.chain_name} · {detail.published ? "Đã bật trưng bày" : "Chưa bật trưng bày"}</p>
              <p className="text-sm text-muted-foreground">Shop chỉ hiển thị khi vật tư, nhóm, đơn vị và thuế cũng hợp lệ.</p>
              {([["brand", "Thương hiệu"], ["origin", "Xuất xứ"], ["packaging", "Quy cách"], ["description", "Giới thiệu"], ["uses", "Công dụng"], ["instructions", "Hướng dẫn cho khách"], ["storage", "Bảo quản"], ["safety", "An toàn"]] as const).map(([key, label]) => <section key={key}><h4 className="text-sm font-semibold">{label}</h4><p className="whitespace-pre-wrap text-sm leading-6">{detail[key] || "Chưa bổ sung."}</p></section>)}
            </article>)}
          </section>}
          {readOnlyImages.some(source => source.images.length > 0) && <CatalogShopImages product={{ ...product, shop_images: readOnlyImages }}/>} 
        </>}

        <details className="mt-8 border-t border-black/10 pt-5"><summary className="cursor-pointer font-semibold">Tài liệu catalog dùng chung (không phải mô tả Shop)</summary><p className="mt-3 text-sm text-muted-foreground">Hướng dẫn và liên kết tham khảo của danh mục nội bộ. Lưu ở đây không thay ảnh hay nội dung khách xem trên Shop.</p>
        {loading && <p role="status" className="mt-8 text-sm text-muted-foreground">Đang tải nội dung…</p>}
        {error && <div role="alert" className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p>{error}</p><button type="button" onClick={() => setReload((value) => value + 1)} className="mt-3 inline-flex items-center gap-2 font-semibold"><RotateCw className="size-4" />Thử lại</button></div>}
        {!loading && !error && !content && <p className="mt-6 rounded-xl border border-black/10 bg-white p-4 text-sm text-muted-foreground">Chưa có tài liệu catalog riêng. Xem thông tin Shop ở phía trên.</p>}
        {!loading && !error && content && <div className="mt-6 space-y-5">
          <section><h3 className="text-sm font-semibold">Hướng dẫn sử dụng</h3><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-muted-foreground">{content.usage_instructions || "Chưa có hướng dẫn."}</p></section>
          <section><h3 className="text-sm font-semibold">Thông tin bổ sung</h3><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-muted-foreground">{content.additional_info || "Chưa có thông tin bổ sung."}</p></section>
          <section><h3 className="text-sm font-semibold">Hình ảnh</h3>{content.images.length ? <div className="mt-3 grid grid-cols-2 gap-3">{content.images.map((url) => <a key={url} href={url} target="_blank" rel="noopener noreferrer" className="group relative overflow-hidden rounded-xl border border-black/10 bg-white"><Image src={url} alt={`Hình vật tư ${product?.name}`} width={480} height={320} unoptimized className="aspect-[3/2] w-full object-cover" /><span className="absolute bottom-2 right-2 rounded-full bg-white/90 p-1.5 text-foreground"><ExternalLink className="size-4" /></span></a>)}</div> : <p className="mt-2 text-sm text-muted-foreground">Chưa có hình ảnh.</p>}</section>
        </div>}
        {canManage && !loading && !error && <form onSubmit={save} className="mt-8 space-y-5 border-t border-black/10 pt-6">
          <div><h3 className="font-semibold">Chỉnh tài liệu catalog</h3><p className="mt-1 text-sm text-muted-foreground">Chỉ tài khoản quản trị danh mục chung có quyền lưu.</p></div>
          <label className="block text-sm font-medium">Hướng dẫn sử dụng<textarea maxLength={10000} rows={5} value={form.usage_instructions} onChange={(event) => setForm((current) => ({ ...current, usage_instructions: event.target.value }))} className={fieldClass} /></label>
          <label className="block text-sm font-medium">Thông tin bổ sung<textarea maxLength={10000} rows={4} value={form.additional_info} onChange={(event) => setForm((current) => ({ ...current, additional_info: event.target.value }))} className={fieldClass} /></label>
          <label className="block text-sm font-medium">Ảnh tham khảo catalog, mỗi dòng một URL<textarea rows={4} value={form.images} onChange={(event) => setForm((current) => ({ ...current, images: event.target.value }))} placeholder="https://..." className={fieldClass} /><span className="mt-1 block text-xs font-normal text-muted-foreground">Tối đa 12 ảnh, chỉ chấp nhận liên kết HTTP hoặc HTTPS.</span></label>
          {success && <p role="status" className="text-sm text-green-800">{success}</p>}
          <button type="submit" disabled={saving || shopBusy || generalBusy} className="min-h-11 rounded-full bg-[#274f3a] px-6 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Đang lưu…" : "Lưu nội dung"}</button>
        </form>}
        </details>
      </SheetContent>
    </Sheet>
  )
}
