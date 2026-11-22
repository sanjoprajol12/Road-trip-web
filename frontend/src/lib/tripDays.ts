import type { Trip } from '@/types/api'
import { tripRouteTotals, type Totals } from './tripRoute'

type Stop = NonNullable<Trip['stops']>[number]

export interface DayPlan { day: number; date: string | null; stops: Stop[]; totals: Totals; overnight: Stop[] }
export interface TripDays {
  days: DayPlan[]
  /** Stops without a day: not in any daily total. */
  undated: number
  /** Nights (day numbers) before the last day with no stop marked overnight. */
  nightsWithoutStay: number[]
  /** Day numbers after the trip's end date. */
  beyondTrip: number[]
  /** The list order puts a later day before an earlier one somewhere. */
  outOfOrder: boolean
  /** Number of days from the dates, or null when either date is missing. */
  length: number | null
}

const DAY = 86_400_000
const parse = (d: string) => Date.UTC(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10))
const iso = (ms: number) => new Date(ms).toISOString().slice(0, 10)

/** Groups stops by their day number. Totals come only from recorded route and stretch values (see tripRouteTotals). */
export function tripDays(stops: Stop[], startsOn: string | null, endsOn: string | null): TripDays {
  const length = startsOn && endsOn ? Math.round((parse(endsOn) - parse(startsOn)) / DAY) + 1 : null
  const numbers = [...new Set(stops.flatMap((s) => (s.day ? [s.day] : [])))].sort((a, b) => a - b)
  const days = numbers.map((day) => {
    const own = stops.filter((s) => s.day === day)
    return { day, date: startsOn ? iso(parse(startsOn) + (day - 1) * DAY) : null, stops: own, totals: tripRouteTotals(own), overnight: own.filter((s) => s.overnight) }
  })
  const last = length ?? numbers[numbers.length - 1] ?? 0
  const nightsWithoutStay: number[] = []
  for (let d = 1; d < last; d++) if (!stops.some((s) => s.day === d && s.overnight)) nightsWithoutStay.push(d)
  const inOrder = stops.flatMap((s) => (s.day ? [s.day] : []))
  return {
    days, length, nightsWithoutStay,
    undated: stops.filter((s) => !s.day).length,
    beyondTrip: length === null ? [] : numbers.filter((d) => d > length),
    outOfOrder: inOrder.some((d, i) => i > 0 && d < inOrder[i - 1]!),
  }
}
