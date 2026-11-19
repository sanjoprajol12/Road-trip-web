// Requirement progress from the two requirement tables (owner brief "progress accountability", session 15).
// Reads docs/audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md (sections A–H, X, N1–N6) and
// docs/architecture/REQUIREMENTS_GAP_ANALYSIS.md (N7–N9). Owner-decision tables (Part 4) are not requirements.
// Usage: node scripts/requirements-progress.mjs [--rows]
import { readFileSync } from 'node:fs'

const root = new URL('..', import.meta.url)
const read = (p) => readFileSync(new URL(p, root), 'utf8').split('\n')

function rowsOf(lines, sectionPattern) {
  const out = []
  let section = null
  for (const line of lines) {
    if (line.startsWith('#')) section = sectionPattern.test(line) ? line.replace(/^#+\s*/, '') : null
    const m = section && line.match(/^\| ([A-Z][\w-]*) \| (.*?) \| (.*?) \|/)
    if (m && m[1] !== 'ID') out.push({ id: m[1], title: m[2], recorded: m[3], section })
  }
  return out
}

const rows = [
  ...rowsOf(read('docs/audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md'), /^## ([A-HX]|N[1-6])\./),
  ...rowsOf(read('docs/architecture/REQUIREMENTS_GAP_ANALYSIS.md'), /^### N[789]\./),
]

// Recorded audit status -> owner brief category. "Complete, tested" means automated tests recorded by the session
// that built it; "under-tested" and "unable to verify" mean verification is incomplete.
const OWNER = /\b(D[1-7]|AR[1-5]|Q\d+)\b/
function category(r) {
  const s = r.recorded
  if (/^Not applicable/i.test(s)) return 'NA'
  if (/^Blocked|^Suggestion only/i.test(s)) return 'B'
  if (/^Complete, tested/i.test(s)) return 'CV'
  if (/^Complete, under-tested|^Unable to verify|^Implemented/i.test(s)) return 'IV'
  if (/^Partial/i.test(s)) return 'P'
  if (/^(Missing|Documented, not built)/i.test(s)) return OWNER.test(s) ? 'B' : 'NI'
  throw new Error(`Unknown status for ${r.id}: ${s}`)
}
const WEIGHT = { CV: 1, IV: 0.75, P: 0.4, NI: 0, B: 0 }

// Feature areas: explicit lists first, then section defaults. Every requirement belongs to exactly one area.
const explicit = {
  'Places and points of interest': ['A4', 'L4', 'R2', 'R3', 'R9', 'R12', 'R13'],
  'Saved items and services': ['F8', 'R10', 'SV1', 'SV2', 'SV3'],
  'Notifications and communication': ['X10', 'CM15', 'D8', 'D10', 'G2'],
  'Media and storage': ['CM12', 'S9', 'L7'],
  'Security, privacy and account lifecycle': ['G19', 'H11', 'X1', 'X2', 'X3', 'X11', 'G15'],
  'Deployment and production readiness': ['X8'],
  'Technical quality (accessibility, browsers, performance)': ['X4', 'X5', 'X6', 'X7', 'X9', 'X12'],
}
const bySection = [
  [/^A\./, 'Destinations and exploration'], [/^N4\./, 'Destinations and exploration'],
  [/^B\./, 'Routes and route information'], [/^N5\./, 'Explore Along My Route'],
  [/^C\./, 'Rider experiences and moderation'], [/^H\./, 'Rider experiences and moderation'],
  [/^D\./, 'Events and organized rides'], [/^E\./, 'Preparation and safety'], [/^F\./, 'My Rides and private trip planning'],
  [/^G\./, 'Authentication and rider accounts'], [/^N3\./, 'Staff portal, roles and permissions'], [/^N9\./, 'Staff portal, roles and permissions'],
  [/^N2\./, 'CMS and public content'], [/^N7\./, 'CMS and public content'], [/^N1\./, 'Site settings and branding'],
  [/^N6\./, 'Saved items and services'], [/^N8\./, 'Deployment and production readiness'], [/^X\./, 'Technical quality (accessibility, browsers, performance)'],
]
const areaOf = (r) => Object.entries(explicit).find(([, ids]) => ids.includes(r.id))?.[0] ?? bySection.find(([re]) => re.test(r.section))?.[1] ?? 'Other'

const ids = rows.map((r) => r.id)
const dup = ids.filter((id, i) => ids.indexOf(id) !== i)
if (dup.length) throw new Error(`Duplicate requirement ids: ${dup.join(', ')}`)

const all = rows.map((r) => ({ ...r, cat: category(r), area: areaOf(r) }))
const applicable = all.filter((r) => r.cat !== 'NA')
const count = (list, c) => list.filter((r) => r.cat === c).length
const pct = (n, d) => (d ? ((100 * n) / d).toFixed(1) : '0.0')
const coverage = (list) => list.reduce((s, r) => s + WEIGHT[r.cat], 0)

const n = applicable.length
console.log(`Requirements: ${all.length} rows, ${n} applicable (${all.length - n} not applicable)`)
console.log(`Complete and verified ${count(applicable, 'CV')}, implemented/verification incomplete ${count(applicable, 'IV')}, partial ${count(applicable, 'P')}, not implemented ${count(applicable, 'NI')}, blocked by owner ${count(applicable, 'B')}`)
console.log(`A. Verified completion: ${count(applicable, 'CV')} / ${n} = ${pct(count(applicable, 'CV'), n)}%`)
console.log(`B. Implementation coverage: ${coverage(applicable).toFixed(2)} / ${n} = ${pct(coverage(applicable), n)}% (weights CV 1, IV 0.75, P 0.4, NI 0, B 0)`)
console.log('\nArea | Complete and verified | Applicable | Verified % | Coverage %')
const areas = [...new Set(all.map((r) => r.area))].sort()
for (const a of areas) {
  const list = applicable.filter((r) => r.area === a)
  console.log(`${a} | ${count(list, 'CV')} | ${list.length} | ${pct(count(list, 'CV'), list.length)} | ${pct(coverage(list), list.length)}`)
}
if (process.argv.includes('--rows')) for (const r of all) console.log(`${r.id}\t${r.cat}\t${r.area}\t${r.recorded.slice(0, 80)}`)
