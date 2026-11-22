<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Plus, Search } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { placeCategories } from '../categories'
import { listPlaces, type PlaceCategory } from '../services/places'

// Places of interest: search, category filter and pages, all kept in the address so a search can be shared.
const route = useRoute()
const router = useRouter()
const query = computed(() => ({ q: (route.query.q as string) || undefined, category: (route.query.category as string) || undefined, page: Number(route.query.page ?? 1) }))
const places = useAsync(() => listPlaces(query.value))
watch(query, () => void places.run())
const q = ref(query.value.q ?? '')
const go = (patch: Record<string, string | number | undefined>) => router.push({ query: { ...route.query, page: undefined, ...patch } })
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Places of interest</h1>
    <p class="lead mt-4 max-w-2xl text-muted">Viewpoints, lakes, temples and other places worth a stop. Each shows where its information comes from and when it was last confirmed.</p>
    <RouterLink to="/places/suggest" class="btn btn-quiet mt-6"><Plus class="size-4" aria-hidden="true" /> Suggest a place</RouterLink>

    <div class="mt-8 flex flex-wrap items-end gap-3">
      <form class="flex items-end gap-2" role="search" @submit.prevent="go({ q: q || undefined })">
        <div class="field"><label for="p-q">Search places</label><input id="p-q" v-model="q" type="search" class="input w-64" /></div>
        <button type="submit" class="btn btn-quiet" aria-label="Search"><Search class="size-4" aria-hidden="true" /></button>
      </form>
      <div class="field"><label for="p-c">Category</label>
        <select id="p-c" class="input" :value="query.category ?? ''" @change="go({ category: ($event.target as HTMLSelectElement).value || undefined })">
          <option value="">All categories</option><option v-for="(c, k) in placeCategories" :key="k" :value="k">{{ c.label }}</option>
        </select></div>
    </div>

    <StatusPanel v-if="places.loading.value && !places.data.value" class="mt-8" state="loading" title="Loading places" />
    <StatusPanel v-else-if="places.error.value" class="mt-8" state="error" :message="places.error.value" @retry="places.run" />
    <StatusPanel v-else-if="!places.data.value?.data.length" class="mt-8" state="empty" title="No places found" message="Try another search or category, or suggest a place you know." />
    <ul v-else class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3" aria-label="Places">
      <li v-for="p in places.data.value.data" :key="p.id" class="grid content-start gap-2 rounded-md border border-line bg-surface p-4">
        <img v-if="p.image" :src="p.image.url" :alt="p.image.alt" :width="p.image.width" :height="p.image.height" loading="lazy" class="aspect-[16/10] w-full rounded object-cover" />
        <p class="label flex items-center gap-1.5 text-muted"><component :is="placeCategories[p.category as PlaceCategory].icon" class="size-4" aria-hidden="true" /> {{ placeCategories[p.category as PlaceCategory].label }}<template v-if="p.destination"> · {{ p.destination.name }}</template></p>
        <h2 class="text-lg font-bold"><RouterLink :to="`/places/${p.slug}`" class="link">{{ p.name }}</RouterLink></h2>
        <p v-if="p.summary" class="text-sm text-muted">{{ p.summary }}</p>
        <p class="text-xs text-muted">{{ p.last_confirmed_on ? `Last confirmed ${formatDay(p.last_confirmed_on)}` : 'Never confirmed' }}<template v-if="p.is_dev_seed"> · development sample, not checked</template></p>
      </li>
    </ul>
    <nav v-if="(places.data.value?.meta?.last_page ?? 1) > 1" class="mt-10 flex items-center gap-3 text-sm" aria-label="Pages">
      <button type="button" class="btn btn-quiet" :disabled="query.page <= 1" @click="go({ page: query.page - 1 })">Previous</button>
      <span>Page {{ places.data.value?.meta?.current_page }} of {{ places.data.value?.meta?.last_page }}</span>
      <button type="button" class="btn btn-quiet" :disabled="query.page >= (places.data.value?.meta?.last_page ?? 1)" @click="go({ page: query.page + 1 })">Next</button>
    </nav>
  </div>
</template>
