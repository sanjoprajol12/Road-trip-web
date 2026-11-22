<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, CalendarClock, MapPin, PenLine } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import SourceList from '@/components/ui/SourceList.vue'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { ageInWords, formatDay } from '@/lib/format'
import { setPageMeta } from '@/lib/site'
import { useAuth } from '@/features/authentication/useAuth'
import SaveButton from '@/features/saved/components/SaveButton.vue'
import AddToTrip from '@/features/rides/components/AddToTrip.vue'
import DestinationMap, { type MapPoint } from '@/features/explore/components/DestinationMap.vue'
import PlainText from '@/features/content/components/PlainText.vue'
import { placeCategories, statusLabels } from '../categories'
import { getPlace, suggestCorrection } from '../services/places'

const props = defineProps<{ slug: string }>()
const { user } = useAuth()
const res = useAsync(() => getPlace(props.slug))
watch(() => props.slug, () => void res.run())
const p = computed(() => res.data.value?.data ?? null)
watch(p, (x) => x && setPageMeta(x.name, x.summary))
const nearby = computed(() => res.data.value?.nearby ?? null)
const points = computed<MapPoint[]>(() => [
  ...(nearby.value?.places ?? []).map((n) => ({ name: n.name, position: n.position, kind: 'place' as const, link: `/places/${n.slug}` })),
  ...(nearby.value?.destinations ?? []).map((n) => ({ name: n.name, position: n.position, kind: 'destination' as const, link: `/explore/${n.slug}` })),
])
const km = (n: number) => `${n.toLocaleString('en-IN')} km`

const correcting = ref(false)
const correction = ref('')
const msg = ref<string | null>(null)
async function sendCorrection() {
  if (!p.value) return
  try {
    msg.value = await suggestCorrection(p.value.id, { body: correction.value })
    correcting.value = false
    correction.value = ''
  } catch (e) {
    msg.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/places" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All places</RouterLink>
    <StatusPanel v-if="res.loading.value && !res.data.value" class="mt-6" state="loading" title="Loading" />
    <StatusPanel v-else-if="res.error.value" class="mt-6" state="error" :message="res.error.value" @retry="res.run" />
    <template v-else-if="!p">
      <h1 class="display mt-8 text-5xl">Place not found</h1>
      <StatusPanel class="mt-6" state="empty" title="Nothing is published at this address" message="It may be waiting for review, or it was removed." />
    </template>
    <article v-else class="mt-8 grid gap-12 lg:grid-cols-[1fr_20rem]">
      <div class="min-w-0">
        <p class="label flex items-center gap-1.5 text-muted"><component :is="placeCategories[p.category].icon" class="size-4" aria-hidden="true" /> {{ placeCategories[p.category].label }}</p>
        <h1 class="display mt-3 text-[clamp(2.5rem,1.8rem+3vw,4.5rem)] [overflow-wrap:anywhere]">{{ p.name }}</h1>
        <p v-if="p.status && p.status !== 'published'" class="mt-3 rounded-md border border-warning/40 bg-surface p-3 text-sm" role="status">Only you can see this: {{ statusLabels[p.status] }}.</p>
        <p v-if="p.summary" class="lead mt-4 text-muted">{{ p.summary }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <SaveButton v-if="p.status === undefined || p.status === 'published'" type="place" :id="p.id" :name="p.name" />
          <AddToTrip v-if="user && (p.status === undefined || p.status === 'published')" kind="place" :id="p.id" :name="p.name" :note="p.destination?.name ?? null" />
          <RouterLink v-if="user && (p.status === undefined || p.status === 'published')" :to="{ path: '/contribute', query: { place: p.slug } }" class="btn btn-quiet"><PenLine class="size-4" aria-hidden="true" /> Share an experience here</RouterLink>
        </div>
        <figure v-if="p.image" class="mt-8 grid gap-2">
          <img :src="p.image.url" :alt="p.image.alt" :width="p.image.width" :height="p.image.height" class="h-auto w-full rounded-md" />
          <figcaption class="text-xs text-muted">{{ p.image.credit }}</figcaption>
        </figure>
        <PlainText class="prose-serif mt-8 max-w-prose" :text="p.description" />
        <div v-if="p.gallery.length" class="mt-8 grid gap-4 sm:grid-cols-2">
          <figure v-for="(g, i) in p.gallery" :key="i" class="grid gap-1">
            <img :src="g.url" :alt="g.alt" :width="g.width" :height="g.height" loading="lazy" class="h-auto w-full rounded-md" />
            <figcaption class="text-xs text-muted">{{ g.credit }}</figcaption>
          </figure>
        </div>

        <h2 class="display mt-12 text-3xl">On the map</h2>
        <DestinationMap class="mt-4" :center="p.position" :name="p.name" :points="points" />
        <p class="mt-2 text-sm text-muted"><MapPin class="inline size-4" aria-hidden="true" /> {{ p.position[0] }}, {{ p.position[1] }} (latitude, longitude)</p>

        <section v-if="nearby" class="mt-10" aria-labelledby="near-title">
          <h2 id="near-title" class="display text-3xl">Nearby</h2>
          <p class="mt-2 text-sm text-muted">Straight-line distance from {{ p.name }}, not riding distance.</p>
          <h3 class="mt-6 font-semibold">Places within {{ nearby.radius_km.places }} km</h3>
          <ul v-if="nearby.places.length" class="mt-2 grid border-t border-line text-sm" aria-label="Nearby places">
            <li v-for="n in nearby.places" :key="n.slug" class="flex justify-between gap-3 border-b border-line py-2"><RouterLink :to="`/places/${n.slug}`" class="link font-semibold">{{ n.name }}</RouterLink><span class="tabular text-muted">{{ placeCategories[n.category].label }} · {{ km(n.distance_km) }}</span></li>
          </ul>
          <p v-else class="mt-2 text-sm text-muted">None recorded.</p>
          <h3 class="mt-6 font-semibold">Destinations within {{ nearby.radius_km.destinations }} km</h3>
          <ul v-if="nearby.destinations.length" class="mt-2 grid border-t border-line text-sm" aria-label="Nearby destinations">
            <li v-for="n in nearby.destinations" :key="n.slug" class="flex justify-between gap-3 border-b border-line py-2"><RouterLink :to="`/explore/${n.slug}`" class="link font-semibold">{{ n.name }}</RouterLink><span class="tabular text-muted">{{ km(n.distance_km) }}</span></li>
          </ul>
          <p v-else class="mt-2 text-sm text-muted">None recorded.</p>
        </section>

        <section class="mt-10" aria-labelledby="exp-title">
          <h2 id="exp-title" class="display text-3xl">Rider experiences</h2>
          <ul v-if="res.data.value?.experiences.length" class="mt-4 grid border-t border-line text-sm">
            <li v-for="c in res.data.value.experiences" :key="c.id" class="border-b border-line py-3"><RouterLink :to="`/experiences/${c.id}`" class="link font-semibold">{{ c.title }}</RouterLink> <span class="text-muted">· ridden {{ formatDay(c.observed_on) }}</span></li>
          </ul>
          <p v-else class="mt-4 text-sm text-muted">No published rider experiences for this place yet.</p>
        </section>
      </div>

      <aside class="grid content-start gap-4 text-sm" aria-label="About this information">
        <div class="flex gap-3 rounded-md border border-line bg-surface p-4">
          <CalendarClock class="mt-0.5 size-5 shrink-0 text-muted" aria-hidden="true" />
          <p v-if="p.last_confirmed_on"><span class="font-semibold">Last confirmed {{ formatDay(p.last_confirmed_on) }}</span> <span class="text-muted">({{ ageInWords(p.last_confirmed_on) }})</span></p>
          <p v-else><span class="font-semibold">Never confirmed.</span> <span class="text-muted">Check locally before relying on it.</span></p>
        </div>
        <p v-if="p.suggested_by_rider" class="text-muted">Suggested by a rider and reviewed by moderators. Review means it passed checks, not that every detail is current.</p>
        <p v-if="p.is_dev_seed" class="rounded-md border border-warning/40 p-3"><span class="font-semibold">Development sample.</span> Not checked travel information.</p>
        <RouterLink v-if="p.destination?.published" :to="`/explore/${p.destination.slug}`" class="link font-semibold">Part of {{ p.destination.name }}</RouterLink>
        <SourceList :sources="p.sources" />
        <template v-if="user && (p.status === undefined || p.status === 'published')">
          <button v-if="!correcting" type="button" class="link justify-self-start" @click="correcting = true">Suggest a correction</button>
          <form v-else class="grid gap-2" @submit.prevent="sendCorrection">
            <label for="corr" class="font-semibold">What should change?</label>
            <textarea id="corr" v-model="correction" class="input" rows="3" maxlength="2000" placeholder="For example: the path is closed since the monsoon." />
            <button type="submit" class="btn btn-quiet justify-self-start" :disabled="correction.trim().length < 10">Send to moderators</button>
          </form>
        </template>
        <p v-if="msg" role="status">{{ msg }}</p>
      </aside>
    </article>
  </div>
</template>
