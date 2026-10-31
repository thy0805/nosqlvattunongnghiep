import { runtimeEnvironment } from "@/lib/runtime-environment"
import { nosqlServerApiUrl } from "@/lib/server-origin"
import { cookies } from "next/headers"
import { redirect } from "next/navigation"
import type { Profile } from "@/lib/api"

export default async function ReportsLayout({ children }: { children: React.ReactNode }) {
  const session = (await cookies()).get(runtimeEnvironment().sessionCookie)
  if (!session) redirect("/login")
  let response: Response
  try {
    response = await fetch(`${nosqlServerApiUrl()}/api/me`, {
      headers: {
        Accept: "application/json",
        Origin: runtimeEnvironment().web,
        Cookie: `${runtimeEnvironment().sessionCookie}=${encodeURIComponent(session.value)}`,
      },
      cache: "no-store",
    })
  } catch {
    return <p role="alert" className="p-8 text-sm text-red-800">Không kết nối được máy chủ xác thực.</p>
  }
  if (response.status === 401) redirect("/login")
  if (!response.ok) return <p role="alert" className="p-8 text-sm text-red-800">Không xác minh được quyền xem báo cáo.</p>
  const profile = await response.json() as Profile
  if (!profile.user.can_view_reports) redirect("/admin")
  return children
}
