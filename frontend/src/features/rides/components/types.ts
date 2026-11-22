import type { Trip } from '@/types/api'

/** Runs one trip change; resolves true when it succeeded (the page shows the error otherwise). */
export type RunChange = (fn: () => Promise<{ data: { data: Trip } }>) => Promise<boolean>
export type Stop = NonNullable<Trip['stops']>[number]
