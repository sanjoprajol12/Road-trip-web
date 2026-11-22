<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Navigation, Search } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'

import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { listRoutes } from '../services/routes'

// Filters live in the address, so a filtered list can be shared. The API applies them; empty values are left out.
const route = useRoute()
const router = useRouter()
const provinces = ['Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim']
const query = computed(() => Object.fromEntries(['q', 'province', 'max_km', 'surface', 'has_track'].map((k) => [k, (route.query[k] as string) || undefined])))
const form = reactive({ q: query.value.q ?? '', province: query.value.province ?? '', max_km: query.value.max_km ?? '', surface: query.value.surface ?? '', has_track: query.value.has_track === '1' })
const routes = useAsync(() => listRoutes(query.value))
watch(query, () => void routes.run())
const apply = () => router.push({ query: Object.fromEntries(Object.entries({ ...form, has_track: form.has_track ? '1' : '' }).filter(([, v]) => v !== '')) })
const filtered = computed(() => Object.values(query.value).some(Boolean))
function clear() {
  Object.assign(form, { q: '', province: '', max_km: '', surface: '', has_track: false })
  void router.push({ query: {} })
}
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Routes</h1>
    <p class="lead mt-5 max-w-xl text-muted">Each route is broken into stretches you can check on their own, with the reports riders filed for each one.</p>
    <RouterLink to="/journey" class="btn btn-quiet mt-6"><Navigation class="size-4" aria-hidden="true" /> Explore along my route</RouterLink>

    <form class="mt-8 grid gap-3 rounded-md border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_8rem_1fr_auto_auto] lg:items-end" role="search" aria-label="Filter routes" @submit.prevent="apply">
      <div class="field"><label for="rf-q">Search routes</label><input id="rf-q" v-model="form.q" type="search" class="input" /></div>
      <div class="field"><label for="rf-p">Province</label><select id="rf-p" v-model="form.province" class="input"><option value="">Any</option><option v-for="p in provinces" :key="p" :value="p">{{ p }}</option></select></div>
      <div class="field"><label for="rf-km">Up to (km)</label><input id="rf-km" v-model="form.max_km" type="number" min="1" class="input" /></div>
      <div class="field"><label for="rf-s">Surface</label><select id="rf-s" v-model="form.surface" class="input"><option value="">Any</option><option value="paved_only">Paved all the way</option><option value="rough">Includes gravel or dirt</option></select></div>
      <label class="flex min-h-11 items-center gap-2 text-sm"><input v-model="form.has_track" type="checkbox" /> With a recorded track</label>
      <div class="flex gap-2"><button type="submit" class="btn btn-primary"><Search class="size-4" aria-hidden="true" /> Show</button><button v-if="filtered" type="button" class="btn btn-quiet" @click="clear">Clear</button></div>
      <p class="text-xs text-muted sm:col-span-2 lg:col-span-6">Surface and distance come from what is recorded for each route; routes with no recorded total are left out of a distance filter.</p>
    </form>

    <section class="mt-10" aria-label="Routes">
      <StatusPanel v-if="routes.loading.value" state="loading" title="Loading routes" />
      <StatusPanel v-else-if="routes.error.value" state="error" title="Routes could not be loaded" :message="routes.error.value" @retry="routes.run" />
      <StatusPanel v-else-if="!routes.data.value?.length && filtered" state="empty" title="No routes match these filters" message="Try fewer filters." />
      <StatusPanel v-else-if="!routes.data.value?.length" state="empty" title="No routes are published yet" message="Routes appear here once the editorial team has checked and published them." />
      <ol v-else class="border-b border-line">
        <li v-for="(r, i) in routes.data.value" :key="r.id" v-reveal="Math.min(i, 4)" class="border-t border-line">
          <RouterLink :to="`/routes/${r.slug}`" class="group grid gap-2 py-6 md:grid-cols-[3rem_1fr_14rem] md:items-center md:gap-8">
            <span class="label tabular text-muted">{{ String(i + 1).padStart(2, '0') }}</span>
            <span class="min-w-0">
              <span class="display block text-[clamp(2rem,1.5rem+2vw,3.5rem)] decoration-2 underline-offset-[0.12em] group-hover:underline">{{ r.name }}</span>
              <span class="mt-1 block text-sm text-muted">
                <template v-if="r.start || r.end">{{ r.start?.name ?? '?' }} to {{ r.end?.name ?? '?' }}</template>
                <template v-if="r.distance_km"> · <span class="tabular">{{ r.distance_km }} km</span></template>
                <template v-if="r.estimated_hours"> · about {{ r.estimated_hours }} h riding</template>
              </span>
            </span>
            <span class="text-sm" :class="r.last_confirmed_on ? '' : 'text-muted'">
              {{ r.last_confirmed_on ? `Confirmed ${formatDay(r.last_confirmed_on)}` : 'Never confirmed' }}
              <span v-if="r.is_dev_seed" class="ml-1 rounded-sm border border-warning/50 px-1.5 py-0.5 text-xs font-semibold text-warning">Development seed</span>
            </span>
          </RouterLink>
        </li>
      </ol>
    </section>
  </div>
</template>
