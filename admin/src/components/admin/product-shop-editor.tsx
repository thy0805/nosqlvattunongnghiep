"use client"

import { useEffect, useState } from "react"
import { type CatalogProduct } from "@/lib/api"
import { shopRequest } from "@/lib/shop-api"
import { ProductImageEditor } from "./product-image-editor"

type Scope = { chains: { id: string; name: string }[]; assignments: { chain_id: string; role: string }[] }
type Publication = { product_id: string; slug: string; published: boolean; images: string[] } & Partial<Record<"brand" | "origin" | "packaging" | "description" | "uses" | "instructions" | "storage" | "safety", string>>
const fields = [["brand", "Thương hiệu", 120], ["origin", "Xuất xứ", 120], ["packaging", "Quy cách", 160], ["description", "Giới thiệu sản phẩm", 5000], ["uses", "Công dụng", 3000], ["instructions", "Hướng dẫn sử dụng", 5000], ["storage", "Bảo quản", 3000], ["safety", "Lưu ý an toàn", 3000]] as const

export function ProductShopEditor({ product, onBusyChange, onSaved, disabled = false }: { product: CatalogProduct; onBusyChange: (busy: boolean) => void; onSaved?: () => void; disabled?: boolean }) {
  const [chains, setChains] = useState<Scope["chains"]>([])
  const [chain, setChain] = useState("")
  const [scopeLoading, setScopeLoading] = useState(true)
  const [loaded, setLoaded] = useState<{ chain: string; publication: Publication | null } | null>(null)
  const [error, setError] = useState("")
  const [notice, setNotice] = useState("")
  const [saving, setSaving] = useState(false)
  const [uploadBusy, setUploadBusy] = useState(false)
  const [reload, setReload] = useState(0)
  const busy = saving || uploadBusy
  const ready = loaded?.chain === chain

  useEffect(() => { onBusyChange(busy) }, [busy, onBusyChange])

  useEffect(() => {
    let active = true
    void shopRequest<Scope>("/api/shop-admin/scope").then(scope => {
      if (!active) return
      const owned = scope.chains.filter(value => scope.assignments.some(assignment => assignment.chain_id === value.id && assignment.role === "chain_owner"))
      setChains(owned)
      setChain(owned.find(value => product.shop_details?.some(detail => detail.chain_id === value.id && detail.can_manage))?.id ?? owned[0]?.id ?? "")
    }).catch(caught => { if (active) setError(caught instanceof Error ? caught.message : "Không tải được quyền sửa thông tin Shop.") })
      .finally(() => { if (active) setScopeLoading(false) })
    return () => { active = false }
  }, [product.id])

  useEffect(() => {
    if (!chain) return
    let active = true
    setLoaded(null)
    setError("")
    setNotice("")
    void shopRequest<Publication[]>(`/api/shop-admin/${chain}/products`).then(rows => {
      if (active) setLoaded({ chain, publication: rows.find(row => row.product_id === product.id) ?? null })
    }).catch(caught => { if (active) setError(caught instanceof Error ? caught.message : "Không tải được thông tin Shop.") })
    return () => { active = false }
  }, [chain, product.id, reload])

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (disabled || busy || !ready || !chains.some(value => value.id === chain)) return
    const form = new FormData(event.currentTarget)
    const body = {
      slug: String(form.get("slug") ?? "").trim(),
      published: form.get("published") === "on",
      ...Object.fromEntries(fields.map(([key]) => [key, String(form.get(key) ?? "").trim() || null])),
      images: String(form.get("images") ?? "").split("\n").map(value => value.trim()).filter(Boolean),
    }
    setSaving(true)
    setError("")
    setNotice("")
    try {
      const publication = await shopRequest<Publication>(`/api/shop-admin/${chain}/products/${product.id}`, "PUT", body)
      setLoaded({ chain, publication })
      setNotice("Đã lưu thông tin và ảnh Shop của sản phẩm này.")
      onSaved?.()
    } catch (caught) { setError(caught instanceof Error ? caught.message : "Không lưu được thông tin Shop.") }
    finally { setSaving(false) }
  }

  if (scopeLoading) return <p role="status">Đang tải quyền sửa sản phẩm…</p>
  if (!chains.length) return error ? <p role="alert">{error}</p> : null
  const publication = ready ? loaded.publication : null
  const inputClass = "mt-2 w-full rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground"

  return <section className="mt-6 space-y-4 rounded-xl border border-border bg-card p-4 text-card-foreground">
    <h3 className="font-semibold">Shop theo chuỗi · Hình ảnh & mô tả</h3>
    <p className="text-sm text-muted-foreground">{product.code} · {product.name}. Giá cơ sở và thuế nằm ở phần thông tin chung phía trên; ảnh và mô tả chỉ áp dụng cho chuỗi được chọn.</p>
    {chains.length > 1 ? <label className="block text-sm font-medium">Shop của chuỗi<select className={inputClass} disabled={busy || disabled} value={chain} onChange={event => { setLoaded(null); setChain(event.target.value) }}>{chains.map(value => <option key={value.id} value={value.id}>{value.name}</option>)}</select></label> : <p className="text-sm">Shop của chuỗi: <strong>{chains[0].name}</strong></p>}
    {error && <p role="alert" className="text-sm text-red-600">{error}<button type="button" className="ml-3 underline" disabled={busy || disabled} onClick={() => setReload(value => value + 1)}>Tải lại</button></p>}
    {!ready && !error && <p role="status">Đang tải thông tin sản phẩm…</p>}
    {ready && <form key={`${chain}-${product.id}`} onSubmit={save} className="space-y-4">
      <fieldset disabled={busy || disabled} className="min-w-0 space-y-4 disabled:opacity-70">
        <label className="block text-sm font-medium">Đường dẫn sản phẩm<input name="slug" className={inputClass} required maxLength={180} pattern="[a-z0-9]+(-[a-z0-9]+)*" defaultValue={publication?.slug ?? `vat-tu-${product.id}`}/></label>
        <label className="flex items-center gap-2 text-sm"><input name="published" type="checkbox" defaultChecked={publication?.published}/> Công khai trên Shop</label><p className="text-xs text-muted-foreground">Công khai khác với còn hàng: sản phẩm còn cần danh mục, nhóm, đơn vị và thuế hợp lệ; số lượng mua phụ thuộc tồn khả dụng.</p>

        <div className="space-y-3 [&_.ng-panel]:space-y-3 [&_.ng-panel]:rounded-xl [&_.ng-panel]:border [&_.ng-panel]:border-border [&_.ng-panel]:p-3 [&_.ng-button-row]:flex [&_.ng-button-row]:flex-wrap [&_.ng-button-row]:gap-3 [&_.ng-text-button]:block [&_.ng-text-button]:min-h-10 [&_.ng-text-button]:underline [&_input[type=file]]:block [&_input[type=file]]:max-w-full [&_input[type=file]]:py-3 [&_.ng-muted]:text-sm [&_.ng-muted]:text-muted-foreground">
          <ProductImageEditor chain={chain} initialImages={publication?.images ?? []} onBusyChange={setUploadBusy} saveLabel="Lưu thông tin Shop"/>
        </div>
        {fields.map(([key, label, maxLength]) => <label key={key} className="block text-sm font-medium">{label}<textarea name={key} rows={key === "description" ? 4 : 2} className={inputClass} maxLength={maxLength} defaultValue={publication?.[key] ?? ""}/></label>)}
      </fieldset>
      {notice && <p role="status" className="text-sm">{notice}</p>}
      <button type="submit" disabled={busy || disabled} className="min-h-11 rounded-xl px-5 text-sm font-semibold disabled:opacity-50" style={{ backgroundColor: "var(--av2-accent, #274f3a)", color: "var(--av2-primary-ink, #ffffff)" }}>{saving ? "Đang lưu…" : "Lưu thông tin Shop"}</button>
    </form>}
  </section>
}
