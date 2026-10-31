import { runtimeEnvironment } from "@/lib/runtime-environment"
import { NextResponse, type NextRequest } from "next/server"

export function proxy(request: NextRequest) {
  const session = request.cookies.get(runtimeEnvironment().sessionCookie)
  if (!session) return NextResponse.redirect(new URL("/login", request.url))
  return NextResponse.next()
}

export const config = {
  matcher: "/admin/:path*",
}
