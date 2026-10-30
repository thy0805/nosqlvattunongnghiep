"use client"

import type { ReactNode } from "react"
import { useAdminV2View } from "./view-context"

export function LegacyPageIntro({ children, className }: { children: ReactNode; className?: string }) {
  const view = useAdminV2View()
  return view ? null : <div className={className}>{children}</div>
}
