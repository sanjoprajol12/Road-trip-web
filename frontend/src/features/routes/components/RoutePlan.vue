<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeftRight, Fuel, TriangleAlert } from 'lucide-vue-next'
import type { Segment } from '@/types/api'
import { surfaceLabels } from '@/features/experiences/labels'
import { distanceCheck, longestFuelGap, planRoute } from '@/lib/routePlan'
import { serviceKinds } from '../serviceKinds'

// Planning table built only from recorded data: stretch distances, staff time estimates, surfaces, recorded services.
const props = defineProps<{ segments: Segment[]; routeKm: number | null; startName: string | null; endName: string | null }>()
const reversed = ref(false)
const rows = computed(() => planRoute(props.segments, reversed.value))
const gap = computed(() => longestFuelGap(rows.value))
const fuelStretches = computed(() => rows.value.filter((r) => r.hasFuel).length)
const check = computed(() => distanceCheck(rows.value, props.routeKm))
const last = computed(() => rows.value[rows.value.length - 1])
const km = (n: number | null) => (n === null ? '–' : `${n.toLocaleString('en-IN')} km`)
const h = (n: number | null) => (n === null ? '–' : `${n.toLocaleString('en-IN')} h`)
</script>

<template>
  <section class="mt-12" aria-labelledby="plan-title">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 id="plan-title" class="display text-3xl">Plan this ride</h2>
        <p class="mt-2 max-w-2xl text-sm text-muted">Built from what is recorded for each stretch. Times are estimates written up with the route, not live travel times. A dash means nothing is recorded.</p>
      </div>
      <button type="button" class="btn btn-quiet" :aria-pressed="reversed" @click="reversed = !reversed"><ArrowLeftRight class="size-4" aria-hidden="true" /> {{ reversed ? `Riding from ${endName ?? 'the end'}` : `Ride it in reverse` }}</button>
    </div>

    <div class="mt-6 grid gap-3 md:grid-cols-3">
      <p class="rounded-md border border-line bg-surface p-4 text-sm"><span class="label block text-muted">Recorded distance</span><span class="display tabular mt-1 block text-2xl">{{ km(last?.cumKm ?? null) }}</span><template v-if="last?.cumKm === null">Some stretches have no recorded distance.</template></p>
      <p class="rounded-md border border-line bg-surface p-4 text-sm"><span class="label block text-muted">Riding time (estimate)</span><span class="display tabular mt-1 block text-2xl">{{ h(last?.cumHours ?? null) }}</span><template v-if="last && last.cumHours === null">Not every stretch has a time estimate, so no total is shown.</template></p>
      <div class="rounded-md border border-line bg-surface p-4 text-sm">
        <p class="label flex items-center gap-1.5 text-muted"><Fuel class="size-4" aria-hidden="true" /> Fuel planning</p>
        <template v-if="gap">
          <p class="mt-1"><span class="font-semibold">Up to {{ km(gap.km) }}</span> with no recorded fuel station<template v-if="fuelStretches"> ({{ gap.from }} to {{ gap.to }})</template>.</p>
          <p class="mt-1 text-muted"><template v-if="!fuelStretches">No fuel station is recorded on this route. </template><template v-if="!gap.complete">Some stretches have no distance, so it may be further. </template>Stations not listed here may exist; fill up early and carry a margin.</p>
        </template>
        <p v-else class="mt-1 text-muted">No stretch distances are recorded, so fuel gaps cannot be worked out.</p>
      </div>
    </div>
    <p v-if="check.mismatch" class="mt-3 flex gap-2 text-sm"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" /> The stretches add up to {{ km(check.sum) }}, but the route total says {{ km(routeKm) }}. Treat both as approximate.</p>

    <!-- Phones: one card per stretch (no sideways scrolling). Wider screens: the same rows as a table. -->
    <ol class="mt-6 grid gap-3 md:hidden" :aria-label="`Stretches in riding order${reversed ? ', reversed' : ''}`">
      <li v-for="row in rows" :key="row.segment.id" class="rounded-md border border-line bg-surface p-3 text-sm">
        <p class="font-semibold">{{ row.from }} to {{ row.to }}</p>
        <p class="tabular mt-1">{{ km(row.km) }} · {{ km(row.cumKm) }} from the start · {{ h(row.hours) }}</p>
        <p class="mt-1 text-muted">{{ surfaceLabels[row.segment.surface] }} · <template v-if="!row.segment.services.length">no services recorded</template><template v-else>{{ row.segment.services.map((x) => serviceKinds[x.kind].label).join(', ') }}</template></p>
      </li>
    </ol>
    <div class="mt-6 hidden overflow-x-auto md:block">
      <table class="w-full min-w-[40rem] border-collapse text-left text-sm">
        <caption class="sr-only">Stretches in riding order{{ reversed ? ', reversed' : '' }}</caption>
        <thead><tr class="border-b border-line-strong text-xs tracking-wide text-muted uppercase"><th scope="col" class="py-2 pr-3">Stretch</th><th scope="col" class="py-2 pr-3">Distance</th><th scope="col" class="py-2 pr-3">From start</th><th scope="col" class="py-2 pr-3">Time</th><th scope="col" class="py-2 pr-3">Surface</th><th scope="col" class="py-2">Services</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.segment.id" class="border-b border-line align-top">
            <th scope="row" class="py-2 pr-3 font-semibold">{{ row.from }} to {{ row.to }}</th>
            <td class="tabular py-2 pr-3">{{ km(row.km) }}</td>
            <td class="tabular py-2 pr-3">{{ km(row.cumKm) }}</td>
            <td class="tabular py-2 pr-3">{{ h(row.hours) }}</td>
            <td class="py-2 pr-3">{{ surfaceLabels[row.segment.surface] }}</td>
            <td class="py-2">
              <span v-if="!row.segment.services.length" class="text-muted">None recorded</span>
              <span v-for="svc in row.segment.services" :key="svc.id" class="mr-3 inline-flex items-center gap-1"><component :is="serviceKinds[svc.kind].icon" class="size-4 text-info" aria-hidden="true" />{{ serviceKinds[svc.kind].label }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
