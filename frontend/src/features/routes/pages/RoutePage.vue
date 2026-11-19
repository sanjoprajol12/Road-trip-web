<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, CalendarClock, MessageSquarePlus } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import RouteMap from '../components/RouteMap.vue'
import { serviceKinds } from '../serviceKinds'
import { statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { ageInWords, formatDay } from '@/lib/format'
import { surfaceLabels } from '@/features/experiences/labels'
import { getRoute } from '../services/routes'
import { listContributions } from '@/features/experiences/services/contributions'

const props = defineProps<{ slug: string }>()
const route = useAsync(async () => {
  try {
    return await getRoute(props.slug)
  } catch (e) {
    if (statusOf(e) === 404) return null
    throw e
  }
})
const reports = useAsync(() => listContributions({ route: props.slug, kind: 'road_report', per_page: 50 }))
const reportsFor = (segmentId: string) => (reports.data.value ?? []).filter((c) => c.segment?.id === segmentId)
const r = computed(() => route.data.value)
watch(r, (v) => v && (document.title = `${v.name} | My Ride Nepal`))
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/routes" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All routes</RouterLink>
    <StatusPanel v-if="route.loading.value" class="mt-6" state="loading" title="Loading route" />
    <StatusPanel v-else-if="route.error.value" class="mt-6" state="error" :message="route.error.value" @retry="route.run" />
    <template v-else-if="!r">
      <h1 class="display mt-8 text-5xl">Route not found</h1>
      <StatusPanel class="mt-6" state="empty" title="Nothing is published at this address" message="It may have been renamed or unpublished." />
    </template>
    <article v-else>
      <header class="mt-8 grid gap-6 border-b border-line pb-10 lg:grid-cols-[1.4fr_1fr] lg:items-end">
        <div>
          <p class="label text-muted"><template v-if="r.start || r.end">{{ r.start?.name ?? '?' }} to {{ r.end?.name ?? '?' }}</template></p>
          <h1 class="display mt-3 text-[clamp(3rem,2rem+4vw,6rem)]">{{ r.name }}</h1>
          <p v-if="r.summary" class="lead mt-4 max-w-xl text-muted">{{ r.summary }}</p>
        </div>
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div><dt class="label text-muted">Distance</dt><dd class="display tabular mt-1 text-3xl">{{ r.distance_km ?? '–' }}<span v-if="r.distance_km" class="text-base"> km</span></dd></div>
          <div><dt class="label text-muted">Riding time</dt><dd class="display tabular mt-1 text-3xl">{{ r.estimated_hours ?? '–' }}<span v-if="r.estimated_hours" class="text-base"> h</span></dd></div>
          <div class="col-span-2 flex gap-2 rounded-md border border-line bg-surface p-3">
            <CalendarClock class="size-4 shrink-0 text-muted" aria-hidden="true" />
            <span v-if="r.last_confirmed_on">Route details confirmed {{ formatDay(r.last_confirmed_on) }} ({{ ageInWords(r.last_confirmed_on) }}). Not live conditions.</span>
            <span v-else>Route details never confirmed against a source. Check conditions locally.</span>
          </div>
        </dl>
      </header>

      <section class="mt-10" aria-label="Map"><RouteMap :segments="r.segments ?? []" /></section>

      <section class="mt-12" aria-labelledby="seg-title">
        <h2 id="seg-title" class="display text-3xl">Stretch by stretch</h2>
        <p class="mt-2 max-w-2xl text-sm text-muted">Every report shows the day the rider rode that stretch. Old reports are history, not a forecast.</p>
        <p v-if="!r.segments?.length" class="mt-6 text-sm text-muted">No segments recorded for this route yet.</p>
        <ol class="mt-6 grid gap-0 border-l-2 border-line-strong">
          <li v-for="s in r.segments" :key="s.id" class="relative pb-10 pl-6">
            <span class="absolute top-1 -left-[7px] size-3 rounded-full border-2 border-crimson bg-paper" aria-hidden="true" />
            <h3 class="font-semibold">{{ s.position }}. {{ s.start_name }} to {{ s.end_name }}</h3>
            <p class="text-sm text-muted">
              Surface recorded: {{ surfaceLabels[s.surface] }}<template v-if="s.distance_km"> · <span class="tabular">{{ s.distance_km }} km</span></template>
            </p>
            <p v-if="s.notes" class="prose-serif mt-2 max-w-prose">{{ s.notes }}</p>
            <ul v-if="s.services.length" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="Services along this stretch">
              <li v-for="svc in s.services" :key="svc.id" class="flex items-center gap-1.5">
                <component :is="serviceKinds[svc.kind].icon" class="size-4 text-info" aria-hidden="true" /><span class="sr-only">{{ serviceKinds[svc.kind].label }}:</span>{{ svc.name }}
                <span class="text-xs text-muted">({{ svc.last_confirmed_on ? `seen ${formatDay(svc.last_confirmed_on)}` : 'never confirmed' }})</span>
              </li>
            </ul>
            <div class="mt-4">
              <p v-if="reports.loading.value" class="text-sm text-muted" role="status">Loading reports…</p>
              <ul v-else-if="reportsFor(s.id).length" class="grid gap-2" aria-label="Rider reports for this stretch">
                <li v-for="c in reportsFor(s.id)" :key="c.id" class="rounded-md border border-line bg-surface p-3 text-sm">
                  <RouterLink :to="`/experiences/${c.id}`" class="link font-semibold">{{ c.title }}</RouterLink>
                  <p class="text-xs text-muted">Ridden {{ formatDay(c.observed_on) }} ({{ ageInWords(c.observed_on) }}) by {{ c.author?.display_name }}<template v-if="c.status === 'outdated'"> · marked outdated</template></p>
                </li>
              </ul>
              <p v-else class="text-sm text-muted">No rider reports for this stretch yet.</p>
              <RouterLink :to="{ path: '/contribute', query: { segment: s.id, route: r.slug } }" class="link mt-2 inline-flex items-center gap-1.5 text-sm font-semibold">
                <MessageSquarePlus class="size-4" aria-hidden="true" /> Report this stretch
              </RouterLink>
            </div>
          </li>
        </ol>
      </section>
    </article>
  </div>
</template>
