<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import VerifiedBadges from '@/components/ui/VerifiedBadges.vue'
import { api, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { contributionKindLabels } from '@/features/experiences/labels'
import type { Contribution, PublicUser } from '@/types/api'

const props = defineProps<{ username: string }>()
const res = useAsync(async () => {
  try {
    return (await api.get<{ data: PublicUser; contributions: Contribution[] | { data: Contribution[] } }>(`/riders/${encodeURIComponent(props.username)}`)).data
  } catch (e) {
    if (statusOf(e) === 404) return null
    throw e
  }
})
const rider = computed(() => res.data.value?.data)
const items = computed(() => {
  const c = res.data.value?.contributions
  return Array.isArray(c) ? c : (c?.data ?? [])
})
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <StatusPanel v-if="res.loading.value" state="loading" title="Loading profile" />
    <StatusPanel v-else-if="res.error.value" state="error" :message="res.error.value" @retry="res.run" />
    <template v-else-if="!rider">
      <h1 class="display text-5xl">Rider not found</h1>
      <StatusPanel class="mt-6" state="empty" title="No rider has that username" />
    </template>
    <template v-else>
      <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">{{ rider.display_name }}</h1>
      <p class="mt-2 text-muted">@{{ rider.username }}<template v-if="rider.home_city"> · {{ rider.home_city }}</template> · joined {{ formatDay(rider.joined) }}</p>
      <p class="mt-3"><VerifiedBadges :claims="rider.verified" /></p>
      <p v-if="rider.bio" class="prose-serif mt-6 max-w-prose">{{ rider.bio }}</p>
      <h2 class="display mt-14 text-3xl">Published contributions</h2>
      <p v-if="!items.length" class="mt-4 text-sm text-muted">Nothing published yet.</p>
      <ul v-else class="mt-4 grid border-t border-line">
        <li v-for="c in items" :key="c.id" class="border-b border-line py-4">
          <RouterLink :to="`/experiences/${c.id}`" class="link font-semibold">{{ c.title }}</RouterLink>
          <p class="text-sm text-muted">{{ contributionKindLabels[c.kind] }} · observed {{ formatDay(c.observed_on) }}</p>
        </li>
      </ul>
    </template>
  </div>
</template>
