"use client"

import { useEffect, useState, type FormEvent } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowLeft, ArrowRight, Eye, EyeOff, Leaf, Moon, Sprout, Sun } from "lucide-react"
import { login } from "@/lib/api"
import "./login.css"

export default function LoginPage() {
  const router = useRouter()
  const [username, setUsername] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const [pending, setPending] = useState(false)
  const [visible, setVisible] = useState(false)
  const [theme, setTheme] = useState("light")

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem("agrovia.admin.v2.preferences") ?? "null")
      if (saved?.theme === "dark") setTheme("dark")
    } catch {}
  }, [])

  function toggleTheme() {
    const next = theme === "light" ? "dark" : "light"
    setTheme(next)
    try {
      const saved = JSON.parse(localStorage.getItem("agrovia.admin.v2.preferences") ?? "null")
      localStorage.setItem("agrovia.admin.v2.preferences", JSON.stringify({ ...saved, theme: next }))
    } catch {}
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setError("")
    try {
      await login(username, password)
      router.replace("/admin/v2")
      router.refresh()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Không đăng nhập được.")
    } finally {
      setPending(false)
    }
  }

  return <main className="ag-auth" data-theme={theme}>
    <section className="ag-auth-main">
      <Link href="/shop" className="ag-auth-back"><ArrowLeft size={17} /> Về cửa hàng Vụ Xanh</Link>
      <div className="ag-auth-form-wrap">
        <Link href="/shop" className="ag-auth-mobile-brand"><Leaf size={24} /> Agrovia <span>VỤ XANH</span></Link>
        <p className="ag-auth-eyebrow">KHÔNG GIAN QUẢN TRỊ</p>
        <h1>Đăng nhập</h1>
        <p className="ag-auth-intro">Chào mừng trở lại! Cùng Vụ Xanh chăm chút từng mùa vụ.</p>
        <form onSubmit={handleSubmit} className="ag-auth-form" aria-busy={pending}>
          <label htmlFor="admin-username">Tên đăng nhập <span>*</span></label>
          <input id="admin-username" name="username" value={username} onChange={event => setUsername(event.target.value)} autoComplete="username" placeholder="Nhập tên đăng nhập" required />
          <label htmlFor="admin-password">Mật khẩu <span>*</span></label>
          <div className="ag-auth-password"><input id="admin-password" name="password" value={password} onChange={event => setPassword(event.target.value)} type={visible ? "text" : "password"} autoComplete="current-password" placeholder="Nhập mật khẩu" required /><button type="button" aria-label={visible ? "Ẩn mật khẩu" : "Hiện mật khẩu"} aria-pressed={visible} onClick={() => setVisible(value => !value)}>{visible ? <EyeOff size={19} /> : <Eye size={19} />}</button></div>
          <p className="ag-auth-help">Quên mật khẩu? Liên hệ người quản lý tài khoản của bạn.</p>
          {error && <p role="alert" className="ag-auth-error">{error}</p>}
          <button type="submit" className="ag-auth-submit" disabled={pending}>{pending ? "Đang đăng nhập…" : "Đăng nhập"}<ArrowRight size={18} /></button>
        </form>
        <details className="ag-auth-account"><summary>Chưa có tài khoản nội bộ?</summary><p>Tài khoản Owner, Manager và Staff được người có quyền quản lý tạo và phân công chi nhánh. Vui lòng liên hệ quản trị viên để được cấp tài khoản.</p></details>
        <div className="ag-auth-customer">Bạn muốn mua hàng? <Link href="/shop/account">Đăng nhập / đăng ký khách hàng <ArrowRight size={14} /></Link></div>
      </div>
      <p className="ag-auth-footer">© Agrovia — Vụ Xanh</p>
    </section>
    <aside className="ag-auth-story" aria-label="Agrovia — Vụ Xanh">
      <div className="ag-auth-grid" aria-hidden="true" />
      <div className="ag-auth-story-content"><div className="ag-auth-emblem"><Sprout size={54} strokeWidth={1.4} /></div><div className="ag-auth-brand">Agrovia<span>VỤ XANH</span></div><h2>Gieo niềm tin.<br />Gặt mùa xanh.</h2><p>Đồng hành cùng cửa hàng,<br />kết nối những mùa vụ tốt lành.</p><span className="ag-auth-story-label"><span /> CÙNG NHAU VUN TRỒNG</span></div>
    </aside>
    <button type="button" className="ag-auth-theme" aria-label={theme === "light" ? "Chuyển sang tối" : "Chuyển sang sáng"} onClick={toggleTheme}>{theme === "light" ? <Moon size={21} /> : <Sun size={21} />}</button>
  </main>
}
