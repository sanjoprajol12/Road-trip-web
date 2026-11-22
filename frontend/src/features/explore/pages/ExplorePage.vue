<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Search } from 'lucide-vue-next'
import PlacePhoto from '@/components/ui/PlacePhoto.vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { listDestinations } from '../services/destinations'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'

const { data, error, loading, run } = useAsync(listDestinations)
const query = ref('')
const province = ref('')

// shortcut: filters in the browser over every published destination; move to a server query past ~200 rows.
const provinces = computed(() => [...new Set((data.value ?? []).map((d) => d.province).filter((p): p is string => !!p))].sort())
const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  return (data.value ?? []).filter(
    (d) =>
      (!province.value || d.province === province.value) &&
      (!q || [d.name, d.district, d.province].some((v) => v?.toLowerCase().includes(q))),
  )
})
function clear() {
  query.value = ''
  province.value = ''
}
</script>

<template>
  <div class="pt-16">
    <header class="wrap grid gap-8 pt-14 pb-10 md:grid-cols-[1.2fr_1fr] md:items-end">
      <div>
        <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Explore Nepal</h1>
        <p class="lead mt-5 max-w-xl text-muted">
          Where riders go, what they found there, and what to sort out before you set off.
        </p>
        <RouterLink to="/places" class="btn btn-quiet mt-6">Browse places of interest</RouterLink>
      </div>
      <form class="grid gap-3 sm:grid-cols-[1fr_auto]" role="search" @submit.prevent>
        <div class="field">
          <label for="dest-q">Search destinations</label>
          <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <input id="dest-q" v-model="query" type="search" class="input pl-9" placeholder="Name or district" autocomplete="off" />
          </div>
        </div>
        <div class="field">
          <label for="dest-province">Province</label>
          <select id="dest-province" v-model="province" class="input sm:w-44">
            <option value="">All provinces</option>
            <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
      </form>
    </header>

    <section class="wrap pb-24" aria-label="Destinations">
      <StatusPanel v-if="loading" state="loading" title="Loading destinations" />
      <StatusPanel v-else-if="error" state="error" title="Destinations could not be loaded" :message="error" @retry="run" />
      <StatusPanel
        v-else-if="!data?.length"
        state="empty"
        title="No destinations are published yet"
        message="Destinations appear here once the editorial team has checked and published them."
      />
      <StatusPanel v-else-if="!results.length" state="empty" title="No destination matches" message="Try another name, or show every province.">
        <button type="button" class="btn btn-quiet" @click="clear">Clear search</button>
      </StatusPanel>

      <template v-else>
        <p class="border-t border-line pt-4 text-sm text-muted" aria-live="polite">
          {{ results.length }} {{ results.length === 1 ? 'destination' : 'destinations' }}
        </p>
        <!-- An editorial index, not a card grid: the name leads, freshness sits in the row. -->
        <ol class="mt-4 border-b border-line">
          <li v-for="(d, i) in results" :key="d.id" v-reveal="Math.min(i, 4)" class="border-t border-line">
            <RouterLink
              :to="`/explore/${d.slug}`"
              class="group grid grid-cols-[2.5rem_1fr] items-center gap-x-4 gap-y-4 py-6 md:grid-cols-[3rem_1fr_14rem_17rem] md:gap-x-8"
            >
              <span class="label tabular self-start pt-3 text-muted md:self-center md:pt-0">{{ String(i + 1).padStart(2, '0') }}</span>
              <div class="min-w-0">
                <h2 class="display text-[clamp(2.75rem,2rem+3.2vw,5rem)] decoration-2 underline-offset-[0.12em] group-hover:underline">
                  {{ d.name }}
                </h2>
                <p class="mt-2 text-sm text-muted">{{ [d.district, d.province].filter(Boolean).join(', ') }}</p>
              </div>
              <dl class="col-start-2 grid gap-1 text-sm md:col-start-auto">
                <div class="flex gap-1.5">
                  <dt class="sr-only">Information status</dt>
                  <dd v-if="d.last_confirmed_on">Confirmed {{ formatDay(d.last_confirmed_on) }}</dd>
                  <dd v-else class="text-muted">Never confirmed</dd>
                </div>
                <div v-if="d.is_dev_seed">
                  <dt class="sr-only">Record type</dt>
                  <dd class="inline-block rounded-sm border border-warning/50 px-1.5 py-0.5 text-xs font-semibold text-warning">Development seed</dd>
                </div>
              </dl>
              <div class="col-start-2 aspect-16/10 overflow-hidden rounded-md bg-night md:col-start-auto" :class="{ 'max-md:hidden': !d.photo_path }">
                <PlacePhoto
                  v-if="d.photo_path"
                  :base="d.photo_path"
                  :alt="d.photo_alt ?? ''"
                  sizes="(min-width: 768px) 17rem, 90vw"
                  class="transition-transform duration-700 ease-out-quint group-hover:scale-[1.04]"
                />
                <!-- No photo of this exact place yet: say so instead of showing somewhere else. -->
                <p v-else class="on-night texture-contours grid size-full place-items-center p-4 text-center text-sm text-night-muted">No photo of this place yet</p>
              </div>
            </RouterLink>
          </li>
        </ol>
      </template>
    </section>
  </div>
</template>
