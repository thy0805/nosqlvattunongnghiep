"use client"

import { useEffect, useRef, useState } from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { ArrowLeft, ArrowUpRight, Bell, Check, ChevronDown, ChevronRight, Leaf, LogOut, Menu, Moon, Search, Sun, X } from "lucide-react"
import { logout, type Profile } from "@/lib/api"
import { useNotificationFeed } from "@/components/admin/notification-feed"
import { adminSections, destinationAllowed, destinationHref, findDestination } from "./navigation"
import { AdminIcon } from "./primitives"
import "./shell.css"
import { AdminV2ViewProvider } from "./view-context"

const preferencesKey = "agrovia.admin.v2.preferences"

export function AdminShellV2({ profile, children }: { profile: Profile; children: React.ReactNode }) {
  const params = useSearchParams()
  const router = useRouter()
  const screen = params.get("screen") ?? "dashboard"
  const tab = params.get("tab") ?? ""
  const current = findDestination(screen, tab)
  const [style, setStyle] = useState("agrovia")
  const [theme, setTheme] = useState("light")
  const [collapsed, setCollapsed] = useState(false)
  const [ready, setReady] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [portalContainer, setPortalContainer] = useState<HTMLDivElement | null>(null)
  const [mobile, setMobile] = useState(false)
  const [openGroupKey, setOpenGroupKey] = useState<string | null>(() => current?.group.key ?? null)
  const [query, setQuery] = useState("")
  const [searchOpen, setSearchOpen] = useState(false)
  const [bellOpen, setBellOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [signingOut, setSigningOut] = useState(false)
  const [logoutError, setLogoutError] = useState("")
  const menuButton = useRef<HTMLButtonElement>(null)
  const sidebar = useRef<HTMLElement>(null)
  const search = useRef<HTMLInputElement>(null)
  const searchArea = useRef<HTMLDivElement>(null)
  const bellArea = useRef<HTMLDivElement>(null)
  const accountArea = useRef<HTMLDivElement>(null)
  const { result, loading, error } = useNotificationFeed()
  const unread = result?.unread_count ?? 0
  const matches = adminSections.flatMap(group => group.items.map(item => ({ group, item }))).filter(({ item }) => item.label.toLocaleLowerCase("vi").normalize("NFD").replace(/[\u0300-\u036f]/g, "").includes(query.trim().toLocaleLowerCase("vi").normalize("NFD").replace(/[\u0300-\u036f]/g, "")))

  useEffect(() => {
    setOpenGroupKey(findDestination(screen, tab)?.group.key ?? null)
  }, [screen, tab])

  useEffect(() => {
    const media = window.matchMedia("(max-width: 1023px)")
    function sync() { setMobile(media.matches) }
    sync()
    media.addEventListener("change", sync)
    return () => media.removeEventListener("change", sync)
  }, [])

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(preferencesKey) ?? "null")
      if (saved?.style === "classic" || saved?.style === "agrovia") setStyle(saved.style)
      if (saved?.theme === "light" || saved?.theme === "dark") setTheme(saved.theme)
      if (typeof saved?.collapsed === "boolean") setCollapsed(saved.collapsed)
    } catch {}
    setReady(true)
  }, [])

  useEffect(() => {
    if (ready) try { localStorage.setItem(preferencesKey, JSON.stringify({ style, theme, collapsed })) } catch {}
  }, [style, theme, collapsed, ready])

  useEffect(() => {
    function closeOutside(event: PointerEvent) {
      if (!searchArea.current?.contains(event.target as Node)) setSearchOpen(false)
      if (!bellArea.current?.contains(event.target as Node)) setBellOpen(false)
      if (!accountArea.current?.contains(event.target as Node)) setAccountOpen(false)
    }
    function keyboard(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault()
        search.current?.focus()
        setSearchOpen(true)
      }
      if (event.key === "Escape") { setSearchOpen(false); setBellOpen(false); setAccountOpen(false) }
    }
    document.addEventListener("pointerdown", closeOutside)
    document.addEventListener("keydown", keyboard)
    return () => { document.removeEventListener("pointerdown", closeOutside); document.removeEventListener("keydown", keyboard) }
  }, [])

  useEffect(() => {
    if (!drawer) return
    const previous = document.activeElement as HTMLElement | null
    const first = sidebar.current?.querySelector<HTMLElement>("button, a[href]")
    first?.focus()
    function keyboard(event: KeyboardEvent) {
      if (!window.matchMedia("(max-width: 1023px)").matches) return
      if (event.key === "Escape") { setDrawer(false); menuButton.current?.focus() }
      if (event.key !== "Tab") return
      const elements = Array.from(sidebar.current?.querySelectorAll<HTMLElement>('button:not([disabled]), a[href]') ?? []).filter(el => el.getClientRects().length)
      const first = elements[0], last = elements.at(-1)
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
    }
    function resize() { if (window.innerWidth >= 1024) setDrawer(false) }
    document.addEventListener("keydown", keyboard)
    window.addEventListener("resize", resize)
    return () => { document.removeEventListener("keydown", keyboard); window.removeEventListener("resize", resize); previous?.focus() }
  }, [drawer])

  function closeNavigation() { setDrawer(false); setSearchOpen(false); setQuery(""); setBellOpen(false); setAccountOpen(false) }

  async function signOut() {
    if (signingOut) return
    setSigningOut(true)
    setLogoutError("")
    try { await logout(); router.replace("/login"); router.refresh() }
    catch (caught) { setLogoutError(caught instanceof Error ? caught.message : "Không đăng xuất được."); setSigningOut(false) }
  }

  return <AdminV2ViewProvider value={{ screen, tab, portalContainer }}><div className="av2-root" data-style={style} data-theme={theme} data-collapsed={collapsed} data-drawer={drawer}>
    <a href="#av2-content" className="av2-skip">Đến nội dung</a>
    {drawer && <button className="av2-overlay" aria-label="Đóng menu" onClick={() => setDrawer(false)} />}
    <aside ref={sidebar} id="av2-sidebar" className="av2-sidebar" aria-label="Menu Agrovia V2">
      <div className="av2-brand-row"><Link href="/admin/v2" className="av2-brand" onClick={closeNavigation} title="Agrovia — Vụ Xanh"><span className="av2-brand-icon"><Leaf size={25} /></span><span className="av2-brand-name">Agrovia<small>VỤ XANH · ADMIN</small></span></Link><button className="av2-icon-button av2-close" aria-label="Đóng menu" onClick={() => setDrawer(false)}><X size={20} /></button></div>
      <div className="av2-nav-label">KHÔNG GIAN QUẢN TRỊ <span>V2</span></div>
      <nav aria-label="Điều hướng Admin V2" className="av2-nav">
        {adminSections.map(group => <section className="av2-nav-group" key={group.key}>
          <button className={`av2-nav-parent ${current?.group.key === group.key ? "av2-parent-current" : ""}`} title={group.label} aria-expanded={!mobile && collapsed ? false : openGroupKey === group.key} aria-controls={`av2-group-${group.key}`} onClick={() => { if (collapsed && !mobile) setCollapsed(false); setOpenGroupKey(value => collapsed && !mobile ? group.key : value === group.key ? null : group.key) }}>
            <AdminIcon name={group.icon} /><span className="av2-nav-text">{group.label}</span><ChevronDown size={15} className="av2-nav-chevron" />
          </button>
          <div className="av2-nav-items" id={`av2-group-${group.key}`} hidden={openGroupKey !== group.key}>
            {group.items.map(item => {
              const active = (item.screen === screen && (item.tab ?? "") === tab) || (item.screen === "products" && ["product-form", "categories"].includes(screen))
              const allowed = destinationAllowed(item.screen, profile, item.tab)
              return allowed ? <Link prefetch={false} key={item.label} href={destinationHref(item)} className={`av2-nav-item ${active ? "av2-active" : ""}`} aria-current={active ? "page" : undefined} onClick={closeNavigation}><span>{item.label}</span>{active && <span className="av2-active-dot" />}</Link> : <span className="av2-nav-item av2-restricted" key={item.label} aria-disabled="true" title="Ngoài quyền tài khoản hiện tại"><span>{item.label}</span><span className="av2-lock-label">Khóa</span></span>
            })}
          </div>
        </section>)}
      </nav>
      <div className="av2-sidebar-bottom"><Link href="/admin" className="av2-legacy-link" title="Về Admin hiện tại"><ArrowLeft size={17} /><span>Về giao diện hiện tại</span></Link><p>Quản trị Agrovia — Vụ Xanh<br />Dữ liệu theo quyền truy cập</p></div>
    </aside>
    <div className="av2-workspace">
      <header className="av2-topbar">
        <button ref={menuButton} type="button" className="av2-icon-button av2-menu-button" aria-label="Thu gọn hoặc mở menu" aria-controls="av2-sidebar" aria-expanded={mobile ? drawer : !collapsed} onClick={() => { if (mobile) setDrawer(value => !value); else setCollapsed(value => !value) }}><Menu size={21} /></button>
        <div ref={searchArea} className="av2-search"><Search size={19} /><input ref={search} value={query} onChange={event => { setQuery(event.target.value); setSearchOpen(true) }} onFocus={() => setSearchOpen(true)} aria-label="Tìm menu quản trị" aria-expanded={searchOpen} aria-controls="av2-search-results" placeholder="Tìm trang quản trị..." onKeyDown={event => { if (event.key === "Escape") setSearchOpen(false) }} /><kbd>Ctrl K</kbd>
          {searchOpen && <div id="av2-search-results" className="av2-search-results"><p>{query ? `${matches.length} mục phù hợp` : "Điều hướng nhanh"}</p>{matches.length ? matches.map(({ group, item }) => destinationAllowed(item.screen, profile, item.tab) ? <Link prefetch={false} key={item.label} href={destinationHref(item)} onClick={closeNavigation}><AdminIcon name={group.icon} size={16} /><span>{item.label}<small>{group.label}</small></span><ChevronRight size={15} /></Link> : <div key={item.label} className="av2-search-restricted">{item.label}<small>Không có quyền truy cập</small></div>) : <div className="av2-search-empty">Không tìm thấy. Thử tên mục khác.</div>}</div>}
        </div>
        <div className="av2-header-actions"><div className="av2-style-switch" aria-label="Phong cách Admin V2"><button aria-pressed={style === "classic"} onClick={() => setStyle("classic")}>Classic</button><button aria-pressed={style === "agrovia"} onClick={() => setStyle("agrovia")}>Agrovia</button></div><button className="av2-icon-button av2-theme-button" aria-label={theme === "light" ? "Chuyển sang tối" : "Chuyển sang sáng"} onClick={() => setTheme(value => value === "light" ? "dark" : "light")}>{theme === "light" ? <Moon size={19} /> : <Sun size={19} />}</button>
          <div ref={bellArea} className="av2-bell-wrap"><button className="av2-icon-button" aria-label="Thông báo nội bộ" aria-expanded={bellOpen} onClick={() => setBellOpen(value => !value)}><Bell size={19} />{unread > 0 && <span className="av2-unread">{unread > 99 ? "99+" : unread}</span>}</button>{bellOpen && <div className="av2-notification-popover"><strong>Thông báo nội bộ</strong><p>{loading ? "Đang tải..." : error || `${unread} thông báo chưa đọc`}</p>{!error && result?.data.slice(0, 3).map(item => <div className="av2-notification-item" key={item.id}><span>{item.message}</span>{item.status === "read" && <Check size={15} aria-label="Đã đọc" />}</div>)}<Link prefetch={false} href="/admin/v2?screen=notifications">Mở cảnh báo & thông báo <ArrowUpRight size={15} /></Link></div>}</div>
          <div ref={accountArea} className="av2-account-wrap"><button type="button" className="av2-header-user av2-account-toggle" aria-label={`Tài khoản ${profile.user.username}`} aria-expanded={accountOpen} aria-controls="av2-account-menu" onClick={() => setAccountOpen(value => !value)}><span className="av2-avatar">{profile.user.username.slice(0, 2).toUpperCase()}</span><span>{profile.user.username}<small>{profile.branches.length} chi nhánh được phân quyền</small></span><ChevronDown size={15} /></button>
            {accountOpen && <div id="av2-account-menu" className="av2-account-menu"><div className="av2-account-summary"><strong>{profile.user.username}</strong><small>{profile.branches.length} chi nhánh được phân quyền</small></div><Link href="/admin/v2?screen=profile" onClick={closeNavigation}>Hồ sơ tài khoản <ArrowUpRight size={16} /></Link><Link href="/shop" onClick={closeNavigation}>Xem cửa hàng <Leaf size={16} /></Link><button type="button" disabled={signingOut} onClick={() => void signOut()}><LogOut size={17} />{signingOut ? "Đang đăng xuất…" : "Đăng xuất"}</button></div>}
          </div>
        </div>
      </header>
      <main id="av2-content" className="av2-content" tabIndex={-1}>
        <nav className="av2-breadcrumb" aria-label="Breadcrumb"><Link href="/admin/v2">Admin V2</Link><ChevronRight size={14} /><span>{current?.group.label ?? "Điều hướng"}</span><ChevronRight size={14} /><span aria-current="page">{current?.item.label ?? "Không tìm thấy"}</span></nav>
        {logoutError && <p role="alert" className="av2-notice">{logoutError}</p>}
        {children}
      </main>
      <footer className="av2-footer"><span>Agrovia — Vụ Xanh</span><span>Admin V2 · <Link href="/admin">Mở Admin hiện tại</Link></span></footer>
    </div>
    <div ref={setPortalContainer} className="av2-portal-host" />
  </div></AdminV2ViewProvider>
}
