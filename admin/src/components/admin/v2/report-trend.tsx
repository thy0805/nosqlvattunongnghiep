"use client"

import { FinancialTrend } from "./report-charts"

type Row = { bucket: string; metrics: { net_sales: string | null } }

export function ReportTrend({ rows }: { rows: Row[] }) {
  return <FinancialTrend rows={rows}/>
}
