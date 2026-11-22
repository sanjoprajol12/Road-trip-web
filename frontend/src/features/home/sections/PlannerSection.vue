<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ArrowLeftRight, ArrowRight, Fuel, Navigation } from 'lucide-vue-next'
import { planRoute } from '@/lib/routePlan'
import { surfaceLabels } from '@/features/experiences/labels'
import type { PlannerData } from '../types'

// The main feature, working on the homepage: pick two published places and open Explore Along My Route.
// Beside it, one real published route's plan (or, with none published, what a plan contains; never a made-up example).
const props = defineProps<{ s: PlannerData; id: string }>()
const router = useRouter()
const from = ref('')
const to = ref('')
const same = computed(() => !!from.value && from.value === to.value)
const go = () => from.value && to.value && !same.value && router.push({ path: '/journey', query: { from: from.value, to: to.value } })
const swap = () => ([from.value, to.value] = [to.value, from.value])

const rows = computed(() => (props.s.route?.segments?.length ? planRoute(props.s.route.segments) : []))
const last = computed(() => rows.value[rows.value.length - 1])
const shown = computed(() => rows.value.slice(0, 5))
const km = (n: number | null) => (n === null ? '–' : `${n.toLocaleString('en-IN')} km`)
const columns = ['Stretch, checkpoint to checkpoint', 'Recorded distance and distance from the start', 'Riding-time estimate', 'Surface', 'Fuel and other services', 'Longest run without recorded fuel']
</script>

<template>
  <section class="border-y border-line bg-surface" :aria-labelledby="`planner-${id}`">
    <div class="wrap grid gap-12 py-20 sm:py-28 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:items-start">
      <div class="grid content-start gap-6">
        <h2 :id="`planner-${id}`" v-reveal class="display text-[clamp(2.5rem,1.7rem+3.2vw,4.75rem)] text-balance">{{ s.heading }}</h2>
        <p v-if="s.intro" v-reveal="1" class="lead max-w-xl text-muted">{{ s.intro }}</p>

        <form v-if="s.places.length >= 2" class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-4 rounded-md border border-line-strong bg-paper p-5 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]" aria-label="Plan a route" @submit.prevent="go">
          <div class="field"><label :for="`pf-${id}`">Start</label><select :id="`pf-${id}`" v-model="from" class="input"><option value="" disabled>Choose a place</option><option v-for="p in s.places" :key="p.slug" :value="p.slug">{{ p.name }}</option></select></div>
          <button type="button" class="btn btn-quiet min-h-11 px-3" aria-label="Swap start and destination" @click="swap"><ArrowLeftRight class="size-4" aria-hidden="true" /></button>
          <div class="field col-span-2 sm:col-span-1"><label :for="`pt-${id}`">Destination</label><select :id="`pt-${id}`" v-model="to" class="input"><option value="" disabled>Choose a place</option><option v-for="p in s.places" :key="p.slug" :value="p.slug">{{ p.name }}</option></select></div>
          <button type="submit" class="btn btn-primary col-span-2 sm:col-span-3 sm:justify-self-start" :disabled="!from || !to || same"><Navigation class="size-4" aria-hidden="true" /> Show routes and stops</button>
          <p v-if="same" class="feedback-in col-span-2 text-sm text-danger sm:col-span-3" role="alert">Choose two different places.</p>
        </form>
        <p v-else class="text-sm text-muted">Route planning opens once at least two destinations are published. <RouterLink to="/routes" class="link font-semibold">Browse routes</RouterLink></p>
      </div>

      <figure class="grid gap-3" :aria-label="s.route ? `Plan of ${s.route.name}` : 'What a route plan shows'">
        <div class="rounded-md border border-line-strong bg-paper">
          <template v-if="s.route && rows.length">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line px-5 py-4">
              <p class="label text-muted">Plan this ride</p>
              <p class="tabular text-sm">{{ km(last?.cumKm ?? null) }}<template v-if="last?.cumHours !== null && last?.cumHours !== undefined"> · about {{ last.cumHours }} h (estimate)</template></p>
              <h3 class="w-full text-2xl font-bold text-balance"><RouterLink :to="`/routes/${s.route.slug}`" class="link">{{ s.route.name }}</RouterLink></h3>
            </div>
            <ol class="grid" :aria-label="`Stretches of ${s.route.name} in riding order`">
              <li v-for="r in shown" :key="r.segment.id" class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-0.5 border-b border-line px-5 py-3 text-sm last:border-b-0">
                <span class="font-semibold">{{ r.from }} to {{ r.to }}</span>
                <span class="tabular text-right">{{ km(r.km) }}</span>
                <span class="text-muted">{{ surfaceLabels[r.segment.surface] }}<template v-if="r.hours !== null"> · about {{ r.hours }} h</template></span>
                <span class="flex items-center justify-end gap-1 text-muted"><template v-if="r.hasFuel"><Fuel class="size-4 text-info" aria-hidden="true" /> fuel</template><span class="tabular">{{ km(r.cumKm) }} from start</span></span>
              </li>
            </ol>
            <p v-if="rows.length > shown.length" class="tabular border-t border-line px-5 py-3 text-sm text-muted">{{ rows.length - shown.length }} more {{ rows.length - shown.length === 1 ? 'stretch' : 'stretches' }}</p>
          </template>
          <template v-else>
            <p class="label border-b border-line px-5 py-4 text-muted">What every route plan shows</p>
            <ul class="grid">
              <li v-for="c in columns" :key="c" class="border-b border-line px-5 py-3 text-sm last:border-b-0">{{ c }}</li>
            </ul>
          </template>
        </div>
        <figcaption class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
          <span v-if="s.route && rows.length">From the published route data. Times are estimates, not live conditions.</span>
          <span v-else>No route with stretches is published yet; the first one will appear here.</span>
          <RouterLink v-if="s.route && rows.length" :to="`/routes/${s.route.slug}`" class="link inline-flex items-center gap-1 font-semibold text-ink">Open the full plan <ArrowRight class="size-4" aria-hidden="true" /></RouterLink>
        </figcaption>
      </figure>
    </div>
  </section>
</template>
