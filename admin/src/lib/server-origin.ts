import { runtimeEnvironment } from "./runtime-environment"

export function nosqlServerApiUrl(): string {
  const expected = runtimeEnvironment().api
  if (process.env.API_URL !== expected) throw new Error("NOSQL_SERVER_API_ORIGIN_REJECTED")
  return expected
}
