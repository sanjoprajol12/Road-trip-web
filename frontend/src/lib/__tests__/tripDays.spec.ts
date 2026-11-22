import { describe, expect, it } from 'vitest'
import type { Trip } from '@/types/api'
import { tripDays } from '../tripDays'

type Stop = NonNullable<Trip['stops']>[number]
let n = 0
const stop = (day: number | null, o: Partial<Stop> = {}): Stop => ({
  id: `s${++n}`, position: n, day, name: `Stop ${n}`, destination_id: null, route_id: null, service_point_id: null, place_id: null, route_segment_id: null, route_part: null,
  overnight: false, note: null, place: null, destination: null, service: null, route: null, stretch: null, checkpoint: null, tracks: [], linked_unavailable: false, ...o,
})
const stretch = (day: number | null, km: number | null, hours: number | null) =>
  stop(day, { route_part: 'stretch', stretch: { from: 'a', to: 'b', distance_km: km, estimated_hours: hours, surface: 'paved', has_track: false } })

describe('tripDays', () => {
  it('groups by day with dates and sums only recorded values per day', () => {
    const t = tripDays([stretch(1, 80, 3), stretch(1, null, 1.5), stop(1, { overnight: true, name: 'Beni' }), stretch(2, 40, 2)], '2027-03-30', '2027-04-01')
    expect(t.days.map((d) => [d.day, d.date])).toEqual([[1, '2027-03-30'], [2, '2027-03-31']])
    expect(t.days[0]!.totals).toEqual({ parts: 2, km: 80, hours: 4.5, withoutKm: 1, withoutHours: 0 })
    expect(t.days[0]!.overnight.map((s) => s.name)).toEqual(['Beni'])
    expect(t.length).toBe(3)
  })
  it('names nights with no place to stay, up to the last trip day', () => {
    const t = tripDays([stop(1, { overnight: true }), stop(3)], '2027-01-01', '2027-01-04')
    expect(t.nightsWithoutStay).toEqual([2, 3])
  })
  it('uses the highest day when the trip has no dates, and gives no dates', () => {
    const t = tripDays([stop(2), stop(null)], null, null)
    expect(t.nightsWithoutStay).toEqual([1])
    expect(t.days[0]!.date).toBeNull()
    expect(t.undated).toBe(1)
    expect(t.length).toBeNull()
  })
  it('flags days after the end date and a list order that runs backwards', () => {
    const t = tripDays([stop(2), stop(1), stop(5)], '2027-01-01', '2027-01-02')
    expect(t.beyondTrip).toEqual([5])
    expect(t.outOfOrder).toBe(true)
    expect(tripDays([stop(1), stop(null), stop(2)], null, null).outOfOrder).toBe(false)
  })
  it('has nothing to say about a trip without stops', () => {
    expect(tripDays([], null, null)).toEqual({ days: [], length: null, nightsWithoutStay: [], undated: 0, beyondTrip: [], outOfOrder: false })
  })
})
