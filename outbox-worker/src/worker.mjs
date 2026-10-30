import { connectWorker } from "./mongo-runtime.mjs"
import { MongoOutbox } from "./mongo-outbox.mjs"

async function main() {
  const runtime = await connectWorker()
  const worker = new MongoOutbox(runtime.client, runtime.database)
  let stopping = false, processed = 0, errors = 0
  const once = process.argv.includes("--once")
  process.on("SIGINT", () => { stopping = true })
  process.on("SIGTERM", () => { stopping = true })
  try {
    while (!stopping) {
      const event = await worker.claimOne()
      if (event) {
        processed++
        const result = await worker.deliver(event)
        if (result.status !== "published") errors++
        process.stdout.write(JSON.stringify(result) + "\n")
        if (once && processed >= 100) break
      } else if (once) break
      else await new Promise(resolve => setTimeout(resolve, 5000))
    }
    process.stdout.write(JSON.stringify({ processed, errors }) + "\n")
    if (errors) process.exitCode = 1
  } finally { await runtime.client.close() }
}

try { await main() } catch { process.stderr.write("NOSQL_WORKER_STOPPED: configuration, connection or delivery failure\n"); process.exitCode = 1 }
