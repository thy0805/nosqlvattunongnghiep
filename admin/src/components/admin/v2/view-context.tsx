"use client"

import { createContext, useContext, type ReactNode } from "react"

type AdminV2View = { screen: string; tab: string; portalContainer: HTMLElement | null }
const ViewContext = createContext<AdminV2View | null>(null)

export function AdminV2ViewProvider({ value, children }: { value: AdminV2View; children: ReactNode }) {
  return <ViewContext.Provider value={value}>{children}</ViewContext.Provider>
}

export function useAdminV2View() { return useContext(ViewContext) }
