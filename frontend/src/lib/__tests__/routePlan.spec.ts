import { describe, expect, it } from 'vitest'
import type { Segment } from '@/types/api'
import { distanceCheck, longestFuelGap, planRoute } from '../routePlan'

const seg = (position: number, km: number | null, hours: number | null, fuel = false): Segment => ({
  id: `s${position}`, position, start_name: `P${position - 1}`, end_name: `P${position}`, start: null, end: null, distance_km: km,
  estimated_hours: hours, surface: 'paved', notes: null, path: null,
  services: fuel ? [{ id: `f${position}`, kind: 'fuel', name: 'Pump', notes: null, position: null, last_confirmed_on: null }] : [],
})

describe('planRoute', () => {
  it('adds distances and times in riding order and stops a sum once a value is missing', () => {
    const rows = planRoute([seg(2, 30, null), seg(1, 20, 1), seg(3, 10, 0.5)])
    expect(rows.map((r) => [r.from, r.to, r.cumKm, r.cumHours])).toEqual([['P0', 'P1', 20, 1], ['P1', 'P2', 50, null], ['P2', 'P3', 60, null]])
  })
  it('reverses the order and the stretch ends', () => {
    const rows = planRoute([seg(1, 20, 1), seg(2, 30, 2)], true)
    expect(rows.map((r) => [r.from, r.to, r.cumKm, r.cumHours])).toEqual([['P2', 'P1', 30, 2], ['P1', 'P0', 50, 3]])
  })
  it('keeps the cumulative distance unknown after a stretch without a distance', () => {
    expect(planRoute([seg(1, null, 1), seg(2, 10, 1)]).map((r) => r.cumKm)).toEqual([null, null])
  })
})

describe('longestFuelGap', () => {
  it('counts the stretches holding stations in full (conservative)', () => {
    // fuel on stretch 2 and 5: runs are 1-2 (40), 2-5 (30+20+10+25=85), 5-6 (25+15=40)
    const rows = planRoute([seg(1, 10, null), seg(2, 30, null, true), seg(3, 20, null), seg(4, 10, null), seg(5, 25, null, true), seg(6, 15, null)])
    expect(longestFuelGap(rows)).toEqual({ km: 85, from: 'P1', to: 'P5', complete: true })
  })
  it('covers the whole route when no fuel is recorded, and flags unknown distances', () => {
    expect(longestFuelGap(planRoute([seg(1, 10, null), seg(2, null, null)]))).toEqual({ km: 10, from: 'P0', to: 'P2', complete: false })
    expect(longestFuelGap(planRoute([seg(1, null, null)]))).toBeNull()
  })
})

describe('distanceCheck', () => {
  it('flags a stated total more than 5 % away from the stretches', () => {
    const rows = planRoute([seg(1, 40, null), seg(2, 60, null)])
    expect(distanceCheck(rows, 100)).toEqual({ sum: 100, mismatch: false })
    expect(distanceCheck(rows, 120)).toEqual({ sum: 100, mismatch: true })
    expect(distanceCheck(planRoute([seg(1, null, null)]), 50)).toEqual({ sum: null, mismatch: false })
  })
})

describe('edge cases (B4, B5, E4)', () => {
  it('rounds sums to one decimal without floating-point noise', () => {
    const rows = planRoute([seg(1, 0.1, 0.1), seg(2, 0.2, 0.2)])
    expect([rows[1]!.cumKm, rows[1]!.cumHours]).toEqual([0.3, 0.3])
  })
  it('handles a single stretch and an empty route', () => {
    expect(planRoute([seg(1, 12.5, 0.5)]).map((r) => [r.cumKm, r.cumHours])).toEqual([[12.5, 0.5]])
    expect(planRoute([])).toEqual([])
    expect(longestFuelGap([])).toBeNull()
    expect(distanceCheck([], 100)).toEqual({ sum: null, mismatch: false })
  })
  it('with fuel on every stretch, the longest gap is the largest pair of neighbouring stretches', () => {
    const rows = planRoute([seg(1, 10, null, true), seg(2, 50, null, true), seg(3, 20, null, true)])
    expect(longestFuelGap(rows)).toEqual({ km: 70, from: 'P1', to: 'P3', complete: true }) // stretches 2 + 3 = 50 + 20
  })
  it('counts the open ends of the route when fuel is only on the first or last stretch', () => {
    expect(longestFuelGap(planRoute([seg(1, 10, null, true), seg(2, 30, null), seg(3, 20, null)]))).toEqual({ km: 60, from: 'P0', to: 'P3', complete: true })
    expect(longestFuelGap(planRoute([seg(1, 10, null), seg(2, 30, null), seg(3, 20, null, true)]))).toEqual({ km: 60, from: 'P0', to: 'P3', complete: true })
  })
  it('never flags a mismatch without a usable stated total', () => {
    const rows = planRoute([seg(1, 40, null)])
    expect(distanceCheck(rows, null)).toEqual({ sum: 40, mismatch: false })
    expect(distanceCheck(rows, 40.5)).toEqual({ sum: 40, mismatch: false }) // within 5 %
  })
})
