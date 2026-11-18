<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, CalendarClock, TriangleAlert } from 'lucide-vue-next'
import PlacePhoto from '@/components/ui/PlacePhoto.vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { getDestination } from '../services/destinations'
import { useAsync } from '@/lib/useAsync'
import { ageInWords, formatDay } from '@/lib/format'

const props = defineProps<{ slug: string }>()
const { data: result, error, loading, run } = useAsync(() => getDestination(props.slug))
const d = computed(() => result.value?.destination ?? null)
const routes = computed(() => result.value?.routes ?? [])

watch(d, (dest) => {
  if (dest) document.title = `${dest.name} | My Ride Nepal`
})
</script>

<template>
  <div class="pt-16">
    <div class="wrap pt-6">
      <RouterLink to="/explore" class="link inline-flex items-center gap-1.5 text-sm font-semibold">
        <ArrowLeft class="size-4" aria-hidden="true" /> All destinations
      </RouterLink>
    </div>

    <div v-if="loading || error || !d" class="wrap pt-8 pb-24">
      <StatusPanel v-if="loading" state="loading" title="Loading destination" />
      <StatusPanel v-else-if="error" state="error" title="This destination could not be loaded" :message="error" @retry="run" />
      <template v-else>
        <h1 class="display text-5xl">Destination not found</h1>
        <StatusPanel class="mt-6" state="empty" title="Nothing is published at this address" message="It may have been renamed or unpublished.">
          <RouterLink to="/explore" class="btn btn-quiet">Browse destinations</RouterLink>
        </StatusPanel>
      </template>
    </div>

    <article v-else>
      <header class="wrap grid gap-8 pt-8 pb-12 lg:grid-cols-[1fr_1.25fr] lg:items-end">
        <div>
          <p class="label text-muted">{{ [d.district, d.province].filter(Boolean).join(', ') }}</p>
          <h1 class="display mt-3 text-[clamp(3.5rem,2rem+6vw,8rem)]">{{ d.name }}</h1>
          <p v-if="d.summary" class="lead mt-5 max-w-lg text-muted">{{ d.summary }}</p>
        </div>
        <figure v-if="d.photo_path" class="grid gap-2">
          <div class="aspect-[16/10] overflow-hidden rounded-md bg-night">
            <PlacePhoto :base="d.photo_path" :alt="d.photo_alt ?? ''" sizes="(min-width: 1024px) 55vw, 100vw" eager />
          </div>
          <figcaption class="text-xs text-muted">Photo: {{ d.photo_credit }}</figcaption>
        </figure>
      </header>

      <div class="wrap grid gap-12 border-t border-line pt-10 pb-24 lg:grid-cols-[1fr_20rem]">
        <section aria-labelledby="overview-title">
          <h2 id="overview-title" class="display text-3xl">Overview</h2>
          <p v-if="d.overview" class="prose-serif mt-4 max-w-prose whitespace-pre-line">{{ d.overview }}</p>
          <StatusPanel v-else class="mt-4" state="empty" title="No overview yet" message="Nothing has been written about this destination yet." />

          <h2 class="display mt-12 text-3xl">Routes</h2>
          <ul v-if="routes.length" class="mt-4 grid border-t border-line">
            <li v-for="r in routes" :key="r.id" class="border-b border-line">
              <RouterLink :to="`/routes/${r.slug}`" class="flex flex-wrap items-baseline justify-between gap-2 py-4">
                <span class="font-semibold">{{ r.name }}</span>
                <span class="tabular text-sm text-muted"><template v-if="r.distance_km">{{ r.distance_km }} km</template></span>
              </RouterLink>
            </li>
          </ul>
          <p v-else class="mt-4 text-sm text-muted">No routes to {{ d.name }} are published yet.</p>
          <RouterLink :to="`/experiences?destination=${d.slug}`" class="link mt-8 inline-block font-semibold">Rider experiences in {{ d.name }}</RouterLink>
        </section>

        <aside class="grid content-start gap-4" aria-label="Information status">
          <div v-if="d.is_dev_seed" class="flex gap-3 rounded-md border border-warning/40 bg-surface p-4 text-sm" role="note">
            <TriangleAlert class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
            <p>
              <span class="font-semibold">Development seed record.</span>
              Not checked travel information. It will be replaced before launch.
            </p>
          </div>
          <div class="flex gap-3 rounded-md border border-line bg-surface p-4 text-sm">
            <CalendarClock class="mt-0.5 size-5 shrink-0 text-muted" aria-hidden="true" />
            <p v-if="d.last_confirmed_on">
              <span class="font-semibold">Last confirmed {{ formatDay(d.last_confirmed_on) }}</span>
              <span class="text-muted"> ({{ ageInWords(d.last_confirmed_on) }})</span>
            </p>
            <p v-else><span class="font-semibold">Never confirmed.</span> <span class="text-muted">No one has checked this record against a source yet.</span></p>
          </div>
        </aside>
      </div>
    </article>
  </div>
</template>
