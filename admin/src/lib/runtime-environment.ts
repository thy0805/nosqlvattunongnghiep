export function runtimeEnvironment() {
  const api = process.env.NEXT_PUBLIC_API_URL
  const web = process.env.NEXT_PUBLIC_WEB_URL
  if (api === "http://nosql.localhost:8010" && web === "http://nosql.localhost:3310") return { api, web, sessionCookie: "nosql186_api_session" }
  if (api === "http://localhost:8020" && web === "http://localhost:3320") return { api, web, sessionCookie: "nosql186_qa_api_session" }
  throw new Error("NOSQL_WEB_CONFIG_REJECTED")
}
