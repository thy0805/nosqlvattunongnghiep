"use client"

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react"
import { ApiError } from "@/lib/api"
import { quantityAdd, shopRequest, type ShopCart, type ShopCustomer, type ShopHome } from "@/lib/shop-api"

type ShopState = { chains: { id: string; name: string }[]; chainId: string; selectChain: (id: string) => void; home: ShopHome | null; customer: ShopCustomer | null; cart: ShopCart; ready: boolean; busy: boolean; error: string; refresh: () => Promise<void>; refreshCustomer: () => Promise<void>; add: (id: string, quantity: string) => Promise<void>; update: (id: string, quantity: string) => Promise<void>; remove: (id: string) => Promise<void> }
const empty: ShopCart = { items: [], subtotal: "0.00", is_authenticated: false }
const ShopContext = createContext<ShopState | null>(null)
export function CartProvider({ children }: { children: React.ReactNode }) {
  const [chains, setChains] = useState<ShopState["chains"]>([])
  const [chainId, selectChain] = useState("")
  const [home, setHome] = useState<ShopHome | null>(null)
  const [customer, setCustomer] = useState<ShopCustomer | null>(null)
  const [cart, setCart] = useState<ShopCart>(empty)
  const [ready, setReady] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState("")
  const lock = useRef(false)
  const refreshCustomer = useCallback(async () => {
    try { setCustomer(await shopRequest<ShopCustomer>("/api/shop/account/profile")) }
    catch (e) { if (e instanceof ApiError && e.status === 401) setCustomer(null); else throw e }
  }, [])
  useEffect(() => {
    let active = true
    shopRequest<ShopState["chains"]>("/api/shop/chains").then(data => {
      if (!active) return
      setChains(data)
      const chosen = localStorage.getItem("nonggia-chain")
      selectChain(data.find(c => c.id === chosen)?.id ?? data[0]?.id ?? "")
      if (!data.length) setReady(true)
    }).catch(e => { if (active) { setError(e.message); setReady(true) } })
    return () => { active = false }
  }, [])
  const refresh = useCallback(async () => {
    if (!chainId) return
    const [h, c] = await Promise.all([shopRequest<ShopHome>(`/api/shop/${chainId}/home`), shopRequest<ShopCart>(`/api/shop/${chainId}/cart`), refreshCustomer()])
    setHome(h); setCart(c)
  }, [chainId, refreshCustomer])
  useEffect(() => {
    if (!chainId) return
    localStorage.setItem("nonggia-chain", chainId)
    setReady(false); setError("")
    refresh().catch(e => setError(e.message)).finally(() => setReady(true))
  }, [chainId, refresh])
  async function change(id: string, qty: string, append = false) {
    if (lock.current) throw new Error("Giỏ hàng đang được cập nhật.")
    lock.current = true; setBusy(true); setError("")
    try {
      const fresh = await shopRequest<ShopCart>(`/api/shop/${chainId}/cart`)
      const previous = fresh.items.find(i => i.product_id === id)?.quantity ?? "0.000"
      const quantity = append ? quantityAdd(previous, qty) : qty
      const items = fresh.items.filter(i => i.product_id !== id).map(({ product_id, quantity }) => ({ product_id, quantity }))
      if (quantity !== "0" && quantity !== "0.000") items.push({ product_id: id, quantity })
      setCart(await shopRequest<ShopCart>(`/api/shop/${chainId}/cart`, "PUT", { items }))
    } catch (e) { setError(e instanceof Error ? e.message : "Không cập nhật được giỏ."); throw e }
    finally { lock.current = false; setBusy(false) }
  }
  return <ShopContext.Provider value={{ chains, chainId, selectChain, home, customer, cart, ready, busy, error, refresh, refreshCustomer, add: (id, q) => change(id, q, true), update: (id, q) => change(id, q), remove: id => change(id, "0.000") }}>{children}</ShopContext.Provider>
}
export function useCart() { const value = useContext(ShopContext); if (!value) throw new Error("Thiếu ngữ cảnh Shop."); return value }
