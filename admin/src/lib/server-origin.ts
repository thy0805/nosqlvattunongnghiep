export function nosqlServerApiUrl(): string {
  const url = process.env.API_URL
  if (url !== "http://nosql.localhost:8010") throw new Error("NOSQL_SERVER_API_ORIGIN_REJECTED")
  return url
}
