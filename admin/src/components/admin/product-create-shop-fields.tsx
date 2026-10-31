"use client"

import { useEffect, useRef, useState } from "react"
import { apiUrl, ApiError } from "@/lib/api"
import { shopRequest } from "@/lib/shop-api"

type Scope = { chains: { id: string; name: string }[]; assignments: { chain_id: string; role: string }[] }
type DraftImage = { id: number; file: File; preview: string; uploaded?: string }
const details = [["brand", "Thương hiệu", 120], ["origin", "Xuất xứ", 120], ["packaging", "Quy cách", 160], ["description", "Mô tả sản phẩm", 5000], ["uses", "Công dụng", 3000], ["instructions", "Hướng dẫn sử dụng", 5000], ["storage", "Bảo quản", 3000], ["safety", "Lưu ý an toàn", 3000]] as const
type DetailKey = typeof details[number][0]
const emptyDetails = Object.fromEntries(details.map(([key]) => [key, ""])) as Record<DetailKey, string>

export function useProductCreateShop(active: boolean, code: string) {
  const [scope, setScope] = useState<Scope["chains"]>([])
  const [loading, setLoading] = useState(true)
  const [scopeError, setScopeError] = useState("")
  const [reload, setReload] = useState(0)
  const [enabled, setEnabled] = useState(true)
  const [chain, setChain] = useState("")
  const [slug, setSlug] = useState("")
  const [published, setPublished] = useState(false)
  const [text, setText] = useState({ ...emptyDetails })
  const [images, setImages] = useState<DraftImage[]>([])
  const [imageError, setImageError] = useState("")
  const previews = useRef(new Set<string>())
  const sequence = useRef(0)
  const suggestedSlug = code.trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "")

  function reset() {
    for (const url of previews.current) URL.revokeObjectURL(url)
    previews.current.clear()
    setImages([])
    setImageError("")
    setEnabled(true)
    setSlug("")
    setPublished(false)
    setText({ ...emptyDetails })
  }

  useEffect(() => () => { for (const url of previews.current) URL.revokeObjectURL(url) }, [])

  useEffect(() => {
    if (!active) return
    let current = true
    setLoading(true)
    setScopeError("")
    void shopRequest<Scope>("/api/shop-admin/scope").then(result => {
      if (!current) return
      const owned = result.chains.filter(item => result.assignments.some(assignment => assignment.chain_id === item.id && assignment.role === "chain_owner"))
      setScope(owned)
      setChain(value => owned.some(item => item.id === value) ? value : owned[0]?.id ?? "")
    }).catch(caught => { if (current) { setScope([]); setScopeError(caught instanceof Error ? caught.message : "Không tải được quyền thiết lập Shop.") } })
      .finally(() => { if (current) setLoading(false) })
    return () => { current = false }
  }, [active, reload])

  function validate() {
    if (!enabled) return
    if (loading) throw new Error("Đợi tải quyền thiết lập Shop trước khi tạo sản phẩm.")
    if (scopeError) throw new Error("Chưa xác minh được quyền Shop. Hãy tải lại hoặc bỏ chọn thiết lập Shop để chỉ tạo danh mục.")
    if (!scope.some(item => item.id === chain)) throw new Error("Chọn chuỗi được phép quản lý, hoặc bỏ chọn thiết lập Shop để chỉ tạo danh mục.")
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug.trim() || suggestedSlug)) throw new Error("Nhập đường dẫn Shop bằng chữ thường không dấu, số và dấu gạch nối.")
  }

  function addFiles(files: File[]) {
    if (!files.length) return
    setImageError("")
    if (images.length + files.length > 10) { setImageError("Tối đa 10 ảnh cho một sản phẩm."); return }
    if (files.some(file => !["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024)) { setImageError("Chọn ảnh JPG, PNG hoặc WebP, tối đa 5 MB mỗi ảnh."); return }
    const added = files.map(file => {
      const preview = URL.createObjectURL(file)
      previews.current.add(preview)
      return { id: ++sequence.current, file, preview }
    })
    setImages(current => [...current, ...added])
  }

  async function save(productId: string) {
    validate()
    if (!enabled) return
    const urls: string[] = []
    for (const image of images) {
      if (image.uploaded) { urls.push(image.uploaded); continue }
      const csrf = await fetch(`${apiUrl}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } })
      if (!csrf.ok) throw new Error("Không khởi tạo được phiên tải ảnh. Thông tin cơ bản đã được giữ.")
      const token = document.cookie.split("; ").find(cookie => cookie.startsWith("XSRF-TOKEN="))?.slice(11)
      const body = new FormData()
      body.set("file", image.file)
      const response = await fetch(`${apiUrl}/api/shop-admin/${chain}/product-images`, { method: "POST", credentials: "include", headers: { Accept: "application/json", ...(token ? { "X-XSRF-TOKEN": decodeURIComponent(token) } : {}) }, body })
      const result = await response.json().catch(() => null)
      if (!response.ok) throw new ApiError(result?.errors?.file?.[0] ?? result?.message ?? "Không tải được ảnh.", response.status)
      const url = result?.data?.url
      if (typeof url !== "string" || !url.startsWith(`/api/shop-product-images/${chain}/`)) throw new Error("Máy chủ chưa xác nhận đường dẫn ảnh hợp lệ.")
      urls.push(url)
      setImages(current => current.map(item => item.id === image.id ? { ...item, uploaded: url } : item))
    }
    await shopRequest(`/api/shop-admin/${chain}/products/${productId}`, "PUT", {
      slug: slug.trim() || suggestedSlug,
      published,
      images: urls,
      ...Object.fromEntries(details.map(([key]) => [key, text[key].trim() || null])),
    })
  }

  function fields(busy: boolean, created: boolean) {
    const inputClass = "mt-2 w-full min-w-0 rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground"
    return <section className="min-w-0 space-y-4 rounded-xl border border-border bg-card p-4 text-card-foreground">
      <h3 className="font-semibold">Hình ảnh, mô tả & Shop</h3>
      <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={enabled} disabled={busy || created} onChange={event => setEnabled(event.target.checked)} className="mt-1 size-4 shrink-0"/><span>Thiết lập sản phẩm trên Shop<span className="mt-1 block text-xs text-muted-foreground">Chọn ảnh và nhập mô tả tại đây, rồi bấm Tạo sản phẩm một lần. Bỏ chọn nếu chỉ muốn tạo trong danh mục nội bộ.</span></span></label>
      {enabled && <>
        {loading && <p role="status" className="text-sm">Đang tải quyền Shop…</p>}
        {scopeError && <p role="alert" className="text-sm text-red-600">{scopeError}<button type="button" disabled={busy} className="ml-2 underline" onClick={() => setReload(value => value + 1)}>Tải lại quyền</button></p>}
        {!loading && !scopeError && !scope.length && <p role="alert" className="text-sm">Tài khoản chưa có chuỗi được phép quản lý Shop. Có thể bỏ chọn thiết lập Shop để tạo danh mục nội bộ.</p>}
        <fieldset disabled={busy || loading || !scope.length || Boolean(scopeError)} className="min-w-0 space-y-4 disabled:opacity-70">
          <label className="block text-sm font-medium">Shop của chuỗi<select required value={chain} disabled={created} onChange={event => setChain(event.target.value)} className={inputClass}><option value="">Chọn chuỗi</option>{scope.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
          <label className="block text-sm font-medium">Đường dẫn trên Shop<input value={slug} onChange={event => setSlug(event.target.value)} placeholder={suggestedSlug || "Tự lấy từ mã sản phẩm"} pattern="[a-z0-9]+(-[a-z0-9]+)*" maxLength={180} className={inputClass}/><span className="mt-1 block text-xs font-normal text-muted-foreground">Để trống dùng mã sản phẩm đã chuyển thành đường dẫn không dấu.</span></label>
          <label className="block text-sm font-medium">Chọn ảnh từ máy<input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={images.length >= 10} className="mt-2 block w-full min-w-0 max-w-full text-sm" onChange={event => { const files = Array.from(event.target.files ?? []); event.target.value = ""; addFiles(files) }}/></label>
          <p className="text-xs text-muted-foreground">Tối đa 10 ảnh, mỗi ảnh 5 MB. Ảnh mới chỉ được tải lên khi bấm tạo; đóng form trước khi tạo sẽ không tải tệp.</p>
          {imageError && <p role="alert" className="text-sm text-red-600">{imageError}</p>}
          <div className="grid min-w-0 grid-cols-1 gap-3 min-[400px]:grid-cols-2">{images.map((image, index) => <article key={image.id} className="min-w-0 space-y-2 rounded-xl border border-border p-3">
            <img src={image.preview} alt={`Ảnh đã chọn ${index + 1}`} className="aspect-square w-full rounded-lg bg-white object-contain"/>
            <p className="break-all text-xs">{index === 0 ? "Ảnh chính" : `Ảnh ${index + 1}`} · {image.file.name}</p>
            <div className="flex flex-wrap gap-2"><button type="button" disabled={index === 0} className="min-h-10 text-sm underline disabled:opacity-50" onClick={() => setImages(current => [current[index], ...current.filter(item => item.id !== image.id)])}>Đặt ảnh chính</button><button type="button" className="min-h-10 text-sm underline" onClick={() => { URL.revokeObjectURL(image.preview); previews.current.delete(image.preview); setImages(current => current.filter(item => item.id !== image.id)) }}>Bỏ ảnh</button></div>
          </article>)}</div>
          {details.map(([key, label, maxLength]) => <label key={key} className="block text-sm font-medium">{label}<textarea value={text[key]} rows={key === "description" ? 4 : 2} maxLength={maxLength} className={inputClass} onChange={event => setText(current => ({ ...current, [key]: event.target.value }))}/></label>)}
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={published} onChange={event => setPublished(event.target.checked)}/>Công khai ngay trên Shop</label>
          <p className="text-xs text-muted-foreground">Không chọn thì lưu bản nháp Shop. Công khai khác với còn hàng; tồn kho cần nhập qua nghiệp vụ kho, không tăng khi tạo sản phẩm.</p>
        </fieldset>
      </>}
    </section>
  }

  return { reset, validate, save, fields, enabled }
}
