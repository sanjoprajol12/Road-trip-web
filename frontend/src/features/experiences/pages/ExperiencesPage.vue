<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { MessageSquarePlus, Search } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import VerifiedBadges from '@/components/ui/VerifiedBadges.vue'

import { useAsync } from '@/lib/useAsync'
import { ageInWords, formatDay } from '@/lib/format'
import { contributionKindLabels, contributionStatusLabels } from '../labels'
import type { ContributionKind } from '@/types/api'
import { searchContributions } from '../services/contributions'

const route = useRoute()
const router = useRouter()
const q = ref(String(route.query.q ?? ''))
const kind = ref<ContributionKind | ''>((route.query.kind as ContributionKind) ?? '')
const destination = ref(String(route.query.destination ?? ''))
const page = ref(1)

const list = useAsync(async () =>
  searchContributions({ q: q.value || undefined, kind: kind.value || undefined, destination: destination.value || undefined, page: page.value }),
)
// Filters live in the URL so a filtered list can be shared.
watch([kind, page], () => {
  void router.replace({ query: { q: q.value || undefined, kind: kind.value || undefined, destination: destination.value || undefined } })
  void list.run()
})
function search() {
  page.value = 1
  void router.replace({ query: { q: q.value || undefined, kind: kind.value || undefined, destination: destination.value || undefined } })
  void list.run()
}
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div>
        <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Rider experiences</h1>
        <p class="lead mt-5 max-w-xl text-muted">Trips, road reports and lessons from riders who were there. Each one carries the day it was observed.</p>
      </div>
      <RouterLink to="/contribute" class="btn btn-primary"><MessageSquarePlus class="size-4" aria-hidden="true" /> Share yours</RouterLink>
    </div>

    <form class="mt-10 grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end" role="search" @submit.prevent="search">
      <div class="field">
        <label for="xq">Search</label>
        <div class="relative">
          <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
          <input id="xq" v-model="q" type="search" class="input pl-9" placeholder="Title or text" />
        </div>
      </div>
      <div class="field">
        <label for="xkind">Kind</label>
        <select id="xkind" v-model="kind" class="input">
          <option value="">All kinds</option>
          <option v-for="(label, k) in contributionKindLabels" :key="k" :value="k">{{ label }}</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quiet">Search</button>
    </form>
    <p v-if="destination" class="mt-3 text-sm">
      In {{ destination }} · <button type="button" class="link" @click="(destination = ''), search()">show everywhere</button>
    </p>

    <section class="mt-8" aria-label="Experiences">
      <StatusPanel v-if="list.loading.value" state="loading" title="Loading experiences" />
      <StatusPanel v-else-if="list.error.value" state="error" :message="list.error.value" @retry="list.run" />
      <StatusPanel v-else-if="!list.data.value?.data.length" state="empty" title="Nothing published here yet" message="Rider experiences appear after moderators review them.">
        <RouterLink to="/contribute" class="btn btn-quiet">Share yours</RouterLink>
      </StatusPanel>
      <template v-else>
        <ul class="grid border-t border-line">
          <li v-for="c in list.data.value.data" :key="c.id" class="border-b border-line py-6">
            <p class="label text-muted">{{ contributionKindLabels[c.kind] }}<template v-if="c.destination || c.route"> · {{ c.destination?.name ?? c.route?.name }}</template></p>
            <RouterLink :to="`/experiences/${c.id}`" class="display mt-2 block text-3xl hover:underline">{{ c.title }}</RouterLink>
            <p class="mt-2 line-clamp-2 max-w-prose text-muted">{{ c.body }}</p>
            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <span>Observed {{ formatDay(c.observed_on) }} ({{ ageInWords(c.observed_on) }})</span>
              <span class="text-muted">by {{ c.author?.display_name }}</span>
              <VerifiedBadges :claims="c.author?.verified ?? []" />
              <span v-if="c.status !== 'published'" class="font-semibold" :class="c.status === 'outdated' ? 'text-warning' : 'text-success'">{{ contributionStatusLabels[c.status] }}</span>
            </p>
          </li>
        </ul>
        <nav v-if="(list.data.value.meta?.last_page ?? 1) > 1" class="mt-6 flex items-center gap-3 text-sm" aria-label="Pages">
          <button type="button" class="btn btn-quiet min-h-9" :disabled="page <= 1" @click="page--">Newer</button>
          <span class="tabular text-muted">Page {{ page }} of {{ list.data.value.meta?.last_page }}</span>
          <button type="button" class="btn btn-quiet min-h-9" :disabled="page >= (list.data.value.meta?.last_page ?? 1)" @click="page++">Older</button>
        </nav>
      </template>
    </section>
  </div>
</template>
