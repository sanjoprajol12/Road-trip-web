import type { Segment, Trip } from '@/types/api'

type Stop = NonNullable<Trip['stops']>[number]

/** One selectable item on the route page: checkpoints and stretches interleaved in riding order. */
export type PickRow =
  | { key: string; type: 'checkpoint'; name: string; segment_id: string; at: 'start' | 'end' }
  | { key: string; type: 'stretch'; name: string; segment: Segment }

/** Checkpoint k is the start of stretch k; the last one is the end of the last stretch (as the API stores them). */
export function pickRows(segments: Segment[], reversed = false): PickRow[] {
  const ordered = [...segments].sort((a, b) => a.position - b.position)
  const rows: PickRow[] = []
  ordered.forEach((s, i) => {
    rows.push({ key: `checkpoint_start:${s.id}`, type: 'checkpoint', name: s.start_name, segment_id: s.id, at: 'start' })
    rows.push({ key: `stretch:${s.id}`, type: 'stretch', name: reversed ? `${s.end_name} to ${s.start_name}` : `${s.start_name} to ${s.end_name}`, segment: s })
    if (i === ordered.length - 1) rows.push({ key: `checkpoint_end:${s.id}`, type: 'checkpoint', name: s.end_name, segment_id: s.id, at: 'end' })
  })
  return reversed ? rows.reverse() : rows
}

/** The same key for a stop already on a trip, so the picker can mark it. */
export const stopKey = (s: Pick<Stop, 'route_part' | 'route_segment_id'>) => (s.route_part && s.route_segment_id ? `${s.route_part}:${s.route_segment_id}` : null)

export interface Totals { parts: number; km: number; hours: number; withoutKm: number; withoutHours: number }
const round1 = (n: number) => Math.round(n * 10) / 10

/** Sums of recorded values; anything without a value is counted, never guessed. */
function total(values: { km: number | null; hours: number | null }[]): Totals {
  const t = { parts: values.length, km: 0, hours: 0, withoutKm: 0, withoutHours: 0 }
  for (const v of values) {
    if (v.km === null) t.withoutKm++
    else t.km = round1(t.km + v.km)
    if (v.hours === null) t.withoutHours++
    else t.hours = round1(t.hours + v.hours)
  }
  return t
}

export const selectionTotals = (segments: Segment[]) => total(segments.map((s) => ({ km: s.distance_km, hours: s.estimated_hours })))

/** Whole routes and stretches in a trip (checkpoints have no length). A stop whose route went away counts as unknown. */
export const tripRouteTotals = (stops: Stop[]) =>
  total(stops.filter((s) => s.route_part === 'route' || s.route_part === 'stretch').map((s) => {
    const v = s.route_part === 'route' ? s.route : s.stretch
    return { km: v?.distance_km ?? null, hours: v?.estimated_hours ?? null }
  }))
