import { MongoClient } from "mongodb"
import { readFile } from "node:fs/promises"
import { createHash } from "node:crypto"

export const databases = new Set(["nosql186_web_dev", "nosql186_web_test_c00_20261016_f72e93"])

export function settings(env = process.env) {
  const database = env.MONGODB_DATABASE
  if (!databases.has(database) || !env.MONGODB_URI) throw new Error("NOSQL_WORKER_CONFIG_REQUIRED")
  let uri
  try { uri = new URL(env.MONGODB_URI) } catch { throw new Error("NOSQL_WORKER_URI_INVALID") }
  if (uri.protocol !== "mongodb:" || !["localhost", "127.0.0.1"].includes(uri.hostname)
    || uri.port !== "27028" || uri.pathname !== `/${database}` || uri.username !== "nosql186_worker"
    || !uri.password || uri.hash || uri.searchParams.get("authSource") !== database
    || uri.searchParams.get("replicaSet") !== "nosql186_rs") throw new Error("NOSQL_WORKER_NAMESPACE_DENIED")
  const allowed = { authSource: database, replicaSet: "nosql186_rs", readPreference: "primary", retryWrites: "true" }
  const seen = new Set()
  for (const [key, value] of uri.searchParams) {
    if (seen.has(key) || allowed[key] !== value) throw new Error("NOSQL_WORKER_URI_OPTION_DENIED")
    seen.add(key)
  }
  for (const key of ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DATABASE_URL", "DB_URL"]) {
    if (env[key]) throw new Error("NOSQL_WORKER_SQL_CONFIG_DENIED")
  }
  return { uri: env.MONGODB_URI, database }
}

export async function connectWorker(env = process.env) {
  const config = settings(env)
  const client = new MongoClient(config.uri, {
    serverSelectionTimeoutMS: 2500, connectTimeoutMS: 2500, socketTimeoutMS: 15000,
    readPreference: "primary", writeConcern: { w: "majority", j: true, wtimeoutMS: 10000 },
  })
  try {
    await client.connect()
    const hello = await client.db(config.database).command({ hello: 1 })
    if (hello.setName !== "nosql186_rs" || hello.isWritablePrimary !== true
      || hello.hosts?.length !== 1 || hello.hosts[0] !== "localhost:27028" || hello.me !== "localhost:27028") throw new Error("NOSQL_WORKER_TOPOLOGY_DENIED")
    const marker = await client.db(config.database).collection("coordination_guards").findOne({ _id: "schema:v1" })
    const hash = createHash("sha256").update(await readFile(new URL("../../api-laravel/database/mongodb/manifest.json", import.meta.url))).digest("hex")
    assertMarker(marker, hash)
    return { client, database: client.db(config.database), config }
  } catch (error) { await client.close(); throw error }
}

export function assertMarker(marker, hash) {
  if (!marker || marker.schema_version !== 1 || marker.manifest_sha256 !== hash) throw new Error("NOSQL_WORKER_SCHEMA_DENIED")
}
