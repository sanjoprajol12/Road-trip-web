// Refuses a deployment build whose vercel.json still sends /api to a placeholder or a local address
// (idea from the ERP portal's production API URL guard). Run by vercel.json's buildCommand.
import { readFileSync } from 'node:fs'

const config = JSON.parse(readFileSync(new URL('../vercel.json', import.meta.url), 'utf8'))
const targets = (config.rewrites ?? []).filter((r) => /^\/(api|sanctum)\//.test(r.source)).map((r) => r.destination)
const bad = targets.filter((d) => !d.startsWith('https://') || /API_ORIGIN_NOT_SET|localhost|127\.0\.0\.1/.test(d))
if (targets.length < 2 || bad.length) {
  console.error(`vercel.json must rewrite /api and /sanctum to the production API over https. Problem: ${bad.join(', ') || 'rewrites missing'}`)
  process.exit(1)
}
console.log(`Deploy config ok: /api and /sanctum go to ${new URL(targets[0]).origin}`)
