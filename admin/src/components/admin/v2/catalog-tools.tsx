"use client"

import type { ReactNode } from "react"
import { useAdminV2View } from "./view-context"

export function CatalogTools({ children }: { children: ReactNode }) {
  const view = useAdminV2View()
  return view ? <details className="av2-catalog-tools" open={view.screen === "categories"}><summary>Quản lý vật tư, nhóm và đơn vị tính</summary>{children}</details> : <>{children}</>
}
