<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ArrowLeftRight, MapPin, Navigation } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import RouteMap from '@/features/routes/components/RouteMap.vue'
import { serviceKinds } from '@/features/routes/serviceKinds'
import { listDestinations } from '@/features/explore/services/destinations'
import SaveButton from '@/features/saved/components/SaveButton.vue'
import AddToTrip from '@/features/rides/components/AddToTrip.vue'
import type { ServicePoint } from '@/types/api'
import { findJourneys, type Journey, type JourneyPlace } from '../services/journeys'

// Explore Along My Route, curated mode: start, destination, the published routes between them, and what lies near.
const route = useRoute()
const router = useRouter()
const places = useAsync(listDestinations)
const from = ref(String(route.query.from ?? ''))
const to = ref(String(route.query.to ?? ''))
const within = ref(Number(route.query.within ?? 10))
const result = ref<Journey | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const choice = ref(0)
const filter = ref<string>('all')

async function search() {
  if (!from.value || !to.value || from.value === to.value) return
  void router.replace({ query: { from: from.value, to: to.value, within: within.value } })
  loading.value = true
  error.value = null
  try {
    result.value = await findJourneys(from.value, to.value, within.value)
    choice.value = 0
  } catch (e) {
    result.value = null
    error.value = statusOf(e) === 404 ? 'One of these places is not published.' : errorMessage(e)
  } finally {
    loading.value = false
  }
}
if (from.value && to.value) void search()
watch(within, () => result.value && void search())
const swap = () => ([from.value, to.value] = [to.value, from.value])

const current = computed(() => result.value?.data[choice.value] ?? null)
// Rider's direction: a route recorded the other way is shown with its stretches reversed.
const segments = computed(() => (current.value ? (current.value.reversed ? [...(current.value.route.segments ?? [])].reverse() : current.value.route.segments ?? []) : []))
const kinds = computed(() => [...new Set(current.value?.places.map((p) => p.kind) ?? [])])
const shown = computed(() => (current.value?.places ?? []).filter((p) => filter.value === 'all' || p.kind === filter.value))
const labelOf = (kind: string) => (kind === 'destination' ? 'Destination' : (serviceKinds[kind as ServicePoint['kind']]?.label ?? kind))
const iconOf = (kind: string) => serviceKinds[kind as ServicePoint['kind']]?.icon ?? MapPin
const distanceText = (p: JourneyPlace) => (p.on_route ? 'On this route' : `${p.distance_km.toLocaleString('en-IN')} km from the route line (straight line, not riding distance)`)
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <p class="label text-muted">Explore along my route</p>
    <h1 class="display mt-3 text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Plan the ride, see what is on the way</h1>
    <p class="mt-4 max-w-2xl text-muted">Choose where you start and where you are going. You see the routes we have recorded between them and the services and places near each one. Distances are measured in a straight line from the recorded route, so a real detour is longer. Nothing here is live road information.</p>

    <form class="mt-8 grid gap-4 rounded-md border border-line bg-surface p-5 md:grid-cols-[1fr_auto_1fr_10rem_auto] md:items-end" @submit.prevent="search">
      <div class="field"><label for="j-from">Start</label><select id="j-from" v-model="from" class="input"><option value="" disabled>Choose a place</option><option v-for="d in places.data.value ?? []" :key="d.slug" :value="d.slug">{{ d.name }}</option></select></div>
      <button type="button" class="btn btn-quiet min-h-11 px-3" aria-label="Swap start and destination" @click="swap"><ArrowLeftRight class="size-4" aria-hidden="true" /></button>
      <div class="field"><label for="j-to">Destination</label><select id="j-to" v-model="to" class="input"><option value="" disabled>Choose a place</option><option v-for="d in places.data.value ?? []" :key="d.slug" :value="d.slug">{{ d.name }}</option></select></div>
      <div class="field"><label for="j-within">Look within</label><select id="j-within" v-model.number="within" class="input"><option :value="5">5 km</option><option :value="10">10 km</option><option :value="25">25 km</option><option :value="50">50 km</option></select></div>
      <button type="submit" class="btn btn-primary" :disabled="!from || !to || from === to || loading"><Navigation class="size-4" aria-hidden="true" /> Show routes</button>
      <p v-if="from && from === to" class="text-sm text-danger md:col-span-5" role="alert">Choose two different places.</p>
    </form>
    <StatusPanel v-if="places.error.value" class="mt-6" state="error" :message="places.error.value" @retry="places.run" />

    <StatusPanel v-if="loading && !result" class="mt-8" state="loading" title="Looking for routes" />
    <StatusPanel v-else-if="error" class="mt-8" state="error" :message="error" @retry="search" />
    <template v-else-if="result">
      <StatusPanel v-if="!result.data.length" class="mt-8" state="empty" :title="`No recorded route from ${result.from.name} to ${result.to.name} yet`" message="We only show routes that have been written up and reviewed. Try nearby places, or browse all routes." />
      <RouterLink v-if="!result.data.length" to="/routes" class="link mt-4 inline-block text-sm font-semibold">Browse all routes</RouterLink>
      <template v-else-if="current">
        <div v-if="result.data.length > 1" class="mt-8 flex flex-wrap gap-2" role="group" aria-label="Choose a route">
          <button v-for="(j, i) in result.data" :key="j.route.id" type="button" class="btn" :class="i === choice ? 'btn-primary' : 'btn-quiet'" :aria-pressed="i === choice" @click="(choice = i), (filter = 'all')">{{ j.route.name }}</button>
        </div>
        <section class="mt-8 grid gap-8 lg:grid-cols-[1fr_24rem]" :aria-label="current.route.name">
          <div class="grid content-start gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h2 class="display text-3xl"><RouterLink :to="`/routes/${current.route.slug}`" class="link">{{ current.route.name }}</RouterLink></h2>
              <SaveButton type="route" :id="current.route.id" :name="current.route.name" />
            </div>
            <p class="text-sm text-muted">
              <template v-if="current.route.distance_km">{{ current.route.distance_km }} km recorded</template><template v-if="current.route.distance_km && current.route.estimated_hours"> · </template><template v-if="current.route.estimated_hours">about {{ current.route.estimated_hours }} h riding, as written up</template>.
              {{ current.route.last_confirmed_on ? `Route details confirmed ${formatDay(current.route.last_confirmed_on)}.` : 'Route details never confirmed against a source.' }}
              <template v-if="current.reversed"> Recorded in the other direction: the stretches are listed in your riding order.</template>
            </p>
            <RouteMap :key="current.route.id" :segments="segments" :nearby="current.places.filter((p) => !p.on_route)" />
            <ol class="grid gap-1 text-sm" aria-label="Stretches in riding order">
              <li v-for="s in segments" :key="s.id">{{ current.reversed ? `${s.end_name} to ${s.start_name}` : `${s.start_name} to ${s.end_name}` }}<span v-if="s.distance_km" class="text-muted"> · {{ s.distance_km }} km</span></li>
            </ol>
          </div>
          <div class="grid content-start gap-4">
            <h3 class="text-xl font-bold">Along the way</h3>
            <div class="flex flex-wrap gap-2" role="group" aria-label="Filter places">
              <button type="button" class="btn min-h-9 px-3 text-sm" :class="filter === 'all' ? 'btn-primary' : 'btn-quiet'" :aria-pressed="filter === 'all'" @click="filter = 'all'">All</button>
              <button v-for="k in kinds" :key="k" type="button" class="btn min-h-9 px-3 text-sm" :class="filter === k ? 'btn-primary' : 'btn-quiet'" :aria-pressed="filter === k" @click="filter = k">{{ labelOf(k) }}</button>
            </div>
            <p v-if="!current.places.length" class="text-sm text-muted">No published services or places recorded within {{ result.within_km }} km of this route yet.</p>
            <ul v-else class="grid border-t border-line text-sm" aria-label="Places along the route">
              <li v-for="p in shown" :key="`${p.type}-${p.id}`" class="grid gap-1 border-b border-line py-3">
                <span class="flex items-center gap-2 font-semibold">
                  <component :is="iconOf(p.kind)" class="size-4 shrink-0 text-info" aria-hidden="true" />
                  <RouterLink v-if="p.link" :to="p.link" class="link">{{ p.name }}</RouterLink><span v-else>{{ p.name }}</span>
                  <span class="label font-normal text-muted">{{ labelOf(p.kind) }}</span>
                  <SaveButton class="ml-auto" :type="p.type" :id="p.id" :name="p.name" compact />
                </span>
                <span :class="p.on_route ? 'font-semibold' : 'text-muted'">{{ distanceText(p) }}<template v-if="p.near"> · near {{ p.near }}</template></span>
                <span v-if="p.notes" class="text-muted">{{ p.notes }}</span>
                <AddToTrip :kind="p.type" :id="p.id" :name="p.name" :note="p.near ? `Near ${p.near}` : null" />
                <span class="text-xs text-muted">{{ p.last_confirmed_on ? `Last confirmed ${formatDay(p.last_confirmed_on)}` : 'Never confirmed' }}. Check locally before relying on it.</span>
              </li>
            </ul>
            <p class="text-xs text-muted">Saved places also appear under <RouterLink to="/saved" class="link">Saved</RouterLink>.</p>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>
