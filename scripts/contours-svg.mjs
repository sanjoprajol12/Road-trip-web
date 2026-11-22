// Generates frontend/public/images/textures/contours.svg: abstract topographic contour lines (decorative, not map data).
// Deterministic: a fixed set of peaks, marching squares, segments joined into polylines. Every fifth level is an index line.
// Run: node scripts/contours-svg.mjs
import { writeFileSync, mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const W = 1200, H = 800, STEP = 6, LEVELS = 26
// [x, y, height, spread x, spread y]: a long ridge running across the top right plus foothills, like the hills below a range.
const PEAKS = [
  [930, 170, 1.0, 230, 140], [1110, 300, 0.75, 170, 160], [760, 90, 0.7, 190, 110],
  [560, 260, 0.45, 170, 130], [980, 560, 0.5, 210, 150], [300, 520, 0.35, 220, 170], [640, 700, 0.3, 180, 120],
]
const h = (x, y) => PEAKS.reduce((s, [px, py, a, sx, sy]) => s + a * Math.exp(-(((x - px) / sx) ** 2 + ((y - py) / sy) ** 2)), 0)
  + 0.04 * Math.sin(x / 61) * Math.cos(y / 47) // a little irregularity so lines are not perfect ellipses

const cols = Math.ceil(W / STEP) + 1, rows = Math.ceil(H / STEP) + 1
const grid = Array.from({ length: rows }, (_, j) => Array.from({ length: cols }, (_, i) => h(i * STEP, j * STEP)))
const max = Math.max(...grid.flat())

const key = (p) => `${p[0].toFixed(1)},${p[1].toFixed(1)}`
function contours(level) {
  const segs = []
  const lerp = (a, b, va, vb) => { const t = (level - va) / (vb - va); return [a[0] + t * (b[0] - a[0]), a[1] + t * (b[1] - a[1])] }
  for (let j = 0; j < rows - 1; j++) for (let i = 0; i < cols - 1; i++) {
    const c = [[i * STEP, j * STEP], [(i + 1) * STEP, j * STEP], [(i + 1) * STEP, (j + 1) * STEP], [i * STEP, (j + 1) * STEP]]
    const v = [grid[j][i], grid[j][i + 1], grid[j + 1][i + 1], grid[j + 1][i]]
    const pts = []
    for (let e = 0; e < 4; e++) {
      const a = e, b = (e + 1) % 4
      if ((v[a] >= level) !== (v[b] >= level)) pts.push(lerp(c[a], c[b], v[a], v[b]))
    }
    if (pts.length === 2) segs.push(pts)
    else if (pts.length === 4) segs.push([pts[0], pts[1]], [pts[2], pts[3]]) // saddle: either pairing reads fine as decoration
  }
  // Join segments that share endpoints into polylines.
  const ends = new Map()
  segs.forEach((s, n) => s.forEach((p) => { const k = key(p); ends.set(k, [...(ends.get(k) ?? []), n]) }))
  const used = new Set(), lines = []
  for (let n = 0; n < segs.length; n++) {
    if (used.has(n)) continue
    used.add(n)
    const line = [...segs[n]]
    for (const forward of [true, false]) {
      for (;;) {
        const tip = forward ? line[line.length - 1] : line[0]
        const next = (ends.get(key(tip)) ?? []).find((m) => !used.has(m))
        if (next === undefined) break
        used.add(next)
        const [a, b] = segs[next]
        const other = key(a) === key(tip) ? b : a
        forward ? line.push(other) : line.unshift(other)
      }
    }
    if (line.length > 4) lines.push(line.filter((_, k) => k % 2 === 0 || k === line.length - 1)) // halve points; curves stay smooth at this step
  }
  return lines
}

const paths = []
for (let l = 1; l <= LEVELS; l++) {
  const d = contours((l / (LEVELS + 1)) * max).map((ln) => 'M' + ln.map((p) => `${Math.round(p[0])} ${Math.round(p[1])}`).join('L')).join('')
  if (d) paths.push(`<path d="${d}" stroke-width="${l % 5 === 0 ? 2 : 1}"/>`)
}
const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMaxYMin slice"><g fill="none" stroke="#000" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke">${paths.join('')}</g></svg>\n`
const out = join(dirname(fileURLToPath(import.meta.url)), '../frontend/public/images/textures/contours.svg')
mkdirSync(dirname(out), { recursive: true })
writeFileSync(out, svg)
console.log(`${out}: ${paths.length} levels, ${(svg.length / 1024).toFixed(1)} KB`)
