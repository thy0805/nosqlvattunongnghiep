import { BusinessWorkspace } from "@/components/admin/v2/business-workspace"
import { AdminDashboardContent } from "@/app/admin/page"

export default async function AdminV2Page({ searchParams }: { searchParams: Promise<{ screen?: string; tab?: string }> }) {
  const params = await searchParams
  return <BusinessWorkspace key={`${params.screen}-${params.tab}`} screen={params.screen ?? "dashboard"} tab={params.tab ?? ""} dashboard={!params.screen || params.screen === "dashboard" ? <AdminDashboardContent embedded /> : undefined} />
}
