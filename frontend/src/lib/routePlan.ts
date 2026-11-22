import type { Segment } from '@/types/api'

/**
 * Plain arithmetic over recorded route data for planning: stretches in riding order, cumulative distance and time,
 * and gaps between stretches with a recorded fuel station. Nothing is estimated here: missing numbers stay missing.
 */
export interface PlanRow {
  segment: Segment
  from: string
  to: string
  km: number | null
  /** Distance from the start to the end of this stretch; null once any earlier stretch has no recorded distance. */
  cumKm: number | null
  hours: number | null
  /** Null once any stretch so far has no recorded time (a partial sum would mislead). */
  cumHours: number | null
  hasFuel: boolean
}
export interface FuelGap { km: number; from: string; to: string; complete: boolean }

const round1 = (n: number) => Math.round(n * 10) / 10

export function planRoute(segments: Segment[], reversed = false): PlanRow[] {
  const ordered = [...segments].sort((a, b) => a.position - b.position)
  if (reversed) ordered.reverse()
  let cumKm: number | null = 0
  let cumHours: number | null = 0
  return ordered.map((s) => {
    const km = s.distance_km ?? null
    const hours = s.estimated_hours ?? null
    cumKm = cumKm !== null && km !== null ? round1(cumKm + km) : null
    cumHours = cumHours !== null && hours !== null ? round1(cumHours + hours) : null
    return {
      segment: s, from: reversed ? s.end_name : s.start_name, to: reversed ? s.start_name : s.end_name,
      km, cumKm, hours, cumHours, hasFuel: s.services.some((x) => x.kind === 'fuel'),
    }
  })
}

/**
 * The longest run with no recorded fuel station, counted conservatively: a station can be anywhere on its stretch, so
 * the stretches holding stations at either end of a run count in full. Null when no stretch has a recorded distance.
 */
export function longestFuelGap(rows: PlanRow[]): FuelGap | null {
  let best: FuelGap | null = null
  let start = 0
  for (let i = 0; i <= rows.length; i++) {
    if (i === rows.length || rows[i]!.hasFuel) {
      const end = Math.min(i, rows.length - 1)
      const run = rows.slice(start, end + 1)
      const known = run.filter((r) => r.km !== null)
      if (run.length && known.length) {
        const gap = { km: round1(known.reduce((t, r) => t + r.km!, 0)), from: run[0]!.from, to: run[run.length - 1]!.to, complete: known.length === run.length }
        if (!best || gap.km > best.km) best = gap
      }
      start = i
    }
  }
  return best
}

/** Sum of recorded stretch distances, and whether it disagrees with the route's own total by more than 5 %. */
export function distanceCheck(rows: PlanRow[], routeKm: number | null) {
  const sum = rows.every((r) => r.km !== null) && rows.length ? round1(rows.reduce((t, r) => t + r.km!, 0)) : null
  return { sum, mismatch: sum !== null && routeKm !== null && Math.abs(sum - routeKm) > routeKm * 0.05 }
}
