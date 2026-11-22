<script setup lang="ts">
import { computed } from 'vue'
import { Moon, TriangleAlert } from 'lucide-vue-next'
import { formatDay } from '@/lib/format'
import { tripDays } from '@/lib/tripDays'
import type { Trip } from '@/types/api'

// Day-by-day view of the same stops: recorded distance and time per day, nights with no place to stay, ordering slips.
const props = defineProps<{ trip: Trip }>()
const plan = computed(() => tripDays(props.trip.stops ?? [], props.trip.starts_on, props.trip.ends_on))
const n = (x: number) => x.toLocaleString('en-IN')
const lastDay = computed(() => plan.value.length ?? plan.value.days[plan.value.days.length - 1]?.day ?? 0)
const list = (xs: number[]) => xs.join(', ')
</script>

<template>
  <section v-if="plan.days.length" class="mt-8" aria-labelledby="days-title">
    <h3 id="days-title" class="text-xl font-bold">Day by day</h3>
    <ul v-if="plan.outOfOrder || plan.beyondTrip.length || plan.nightsWithoutStay.length || plan.undated" class="mt-3 grid gap-1 text-sm" aria-label="Planning notes">
      <li v-if="plan.nightsWithoutStay.length" class="flex gap-2"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" /> No overnight stop recorded for the {{ plan.nightsWithoutStay.length === 1 ? 'night' : 'nights' }} after day {{ list(plan.nightsWithoutStay) }}.</li>
      <li v-if="plan.beyondTrip.length" class="flex gap-2"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" /> Day {{ list(plan.beyondTrip) }} is after the trip's last day ({{ formatDay(trip.ends_on) }}).</li>
      <li v-if="plan.outOfOrder" class="flex gap-2"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" /> The itinerary lists a later day before an earlier one. Move the stops if that is not intended.</li>
      <li v-if="plan.undated" class="flex gap-2 text-muted">{{ plan.undated }} {{ plan.undated === 1 ? 'stop has' : 'stops have' }} no day, so {{ plan.undated === 1 ? 'it is' : 'they are' }} in no daily total.</li>
    </ul>
    <ol class="mt-4 grid gap-3 md:grid-cols-2" aria-label="Days">
      <li v-for="d in plan.days" :key="d.day" class="rounded-md border border-line bg-surface p-4 text-sm">
        <p class="tabular font-semibold">Day {{ d.day }}<span v-if="d.date" class="font-normal text-muted"> · {{ formatDay(d.date) }}</span></p>
        <p class="mt-1 text-muted">{{ d.stops.map((s) => s.name).join(' · ') }}</p>
        <p class="tabular mt-2">
          <template v-if="d.totals.parts">{{ n(d.totals.km) }} km recorded<template v-if="d.totals.hours">, about {{ n(d.totals.hours) }} h riding (estimate)</template><template v-if="d.totals.withoutKm || d.totals.withoutHours"><span class="text-muted">; recorded values only<template v-if="d.totals.withoutKm">, {{ d.totals.withoutKm }} without a distance</template><template v-if="d.totals.withoutHours">, {{ d.totals.withoutHours }} without a time estimate</template></span></template>.</template>
          <span v-else class="text-muted">No route or stretch with a recorded distance on this day.</span>
        </p>
        <p class="mt-2 flex items-center gap-1.5">
          <Moon class="size-4 shrink-0 text-muted" aria-hidden="true" />
          <template v-if="d.overnight.length">Overnight: {{ d.overnight.map((s) => s.name).join(', ') }}</template>
          <span v-else-if="d.day < lastDay" class="text-warning">No overnight stop recorded</span>
          <span v-else class="text-muted">Last day: no overnight stop needed</span>
        </p>
      </li>
    </ol>
  </section>
</template>
