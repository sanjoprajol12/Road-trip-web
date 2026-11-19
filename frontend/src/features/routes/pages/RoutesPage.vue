<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { Navigation } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'

import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { listRoutes } from '../services/routes'

const routes = useAsync(() => listRoutes())
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Routes</h1>
    <p class="lead mt-5 max-w-xl text-muted">Each route is broken into stretches you can check on their own, with the reports riders filed for each one.</p>
    <RouterLink to="/journey" class="btn btn-quiet mt-6"><Navigation class="size-4" aria-hidden="true" /> Explore along my route</RouterLink>

    <section class="mt-10" aria-label="Routes">
      <StatusPanel v-if="routes.loading.value" state="loading" title="Loading routes" />
      <StatusPanel v-else-if="routes.error.value" state="error" title="Routes could not be loaded" :message="routes.error.value" @retry="routes.run" />
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
