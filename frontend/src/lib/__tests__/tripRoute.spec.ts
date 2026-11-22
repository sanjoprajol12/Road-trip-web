import { describe, expect, it } from 'vitest'
import type { Segment, Trip } from '@/types/api'
import { pickRows, selectionTotals, stopKey, tripRouteTotals } from '../tripRoute'

const seg = (position: number, km: number | null, hours: number | null): Segment => ({
  id: `s${position}`, position, start_name: `P${position - 1}`, end_name: `P${position}`, start: null, end: null, distance_km: km,
  estimated_hours: hours, surface: 'paved', notes: null, path: null, services: [],
})
type Stop = NonNullable<Trip['stops']>[number]
const stop = (part: Stop['route_part'], v: { distance_km: number | null; estimated_hours: number | null } | null): Stop => ({
  id: 'x', position: 1, day: null, name: 'x', destination_id: null, route_id: 'r', service_point_id: null, place_id: null, route_segment_id: 's1', route_part: part,
  overnight: false, note: null, place: null, destination: null, service: null, checkpoint: null, tracks: [], linked_unavailable: v === null,
  route: part === 'route' && v ? { slug: 'r', name: 'R', stretches: 1, without_distance: 0, without_time: 0, without_track: 1, ...v } : null,
  stretch: part === 'stretch' && v ? { from: 'a', to: 'b', surface: 'paved', has_track: false, ...v } : null,
})

describe('pickRows', () => {
  it('interleaves checkpoints and stretches in riding order, whatever order the segments come in', () => {
    expect(pickRows([seg(2, 1, 1), seg(1, 1, 1)]).map((r) => r.name)).toEqual(['P0', 'P0 to P1', 'P1', 'P1 to P2', 'P2'])
  })
  it('reverses the order and the stretch names, keeping the stored checkpoint keys', () => {
    const rows = pickRows([seg(1, 1, 1), seg(2, 1, 1)], true)
    expect(rows.map((r) => r.name)).toEqual(['P2', 'P2 to P1', 'P1', 'P1 to P0', 'P0'])
    expect(rows.map((r) => r.key)).toEqual(['checkpoint_end:s2', 'stretch:s2', 'checkpoint_start:s2', 'stretch:s1', 'checkpoint_start:s1'])
  })
  it('gives nothing for a route without stretches', () => {
    expect(pickRows([])).toEqual([])
  })
  it('matches the key of a stop already on the trip', () => {
    expect(stopKey({ route_part: 'checkpoint_start', route_segment_id: 's2' })).toBe('checkpoint_start:s2')
    expect(stopKey({ route_part: 'route', route_segment_id: null })).toBeNull()
  })
})

describe('totals', () => {
  it('sums recorded values and counts the missing ones instead of guessing', () => {
    expect(selectionTotals([seg(1, 10.25, 1), seg(2, null, 0.5), seg(3, 4.1, null)])).toEqual({ parts: 3, km: 14.4, hours: 1.5, withoutKm: 1, withoutHours: 1 })
  })
  it('counts whole routes and stretches, skips checkpoints, and treats a removed route as unknown', () => {
    const t = tripRouteTotals([stop('route', { distance_km: 100, estimated_hours: 5 }), stop('stretch', { distance_km: 20, estimated_hours: null }), stop('checkpoint_start', null), stop('stretch', null)])
    expect(t).toEqual({ parts: 3, km: 120, hours: 5, withoutKm: 1, withoutHours: 2 })
  })
  it('is empty for a trip without route stops', () => {
    expect(tripRouteTotals([])).toEqual({ parts: 0, km: 0, hours: 0, withoutKm: 0, withoutHours: 0 })
  })
})
