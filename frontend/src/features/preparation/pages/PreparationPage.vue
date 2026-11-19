<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { BadgeCheck, TriangleAlert } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'

import { useAsync } from '@/lib/useAsync'
import type { Guide } from '@/types/api'
import { categoryLabels } from '../labels'
import { listGuides } from '../services/guides'

const guides = useAsync(() => listGuides())
const grouped = computed(() => {
  const g = new Map<string, Guide[]>()
  for (const x of guides.data.value ?? []) g.set(x.category, [...(g.get(x.category) ?? []), x])
  return [...g.entries()]
})
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Preparation and safety</h1>
    <p class="lead mt-5 max-w-2xl text-muted">Guides with their sources. Mechanical, medical and road-safety guidance is published only after a verified expert in that field reviewed it.</p>
    <div class="mt-8 flex max-w-2xl gap-3 rounded-md border border-warning/40 bg-surface p-4 text-sm" role="note">
      <TriangleAlert class="size-5 shrink-0 text-warning" aria-hidden="true" />
      <p><span class="font-semibold">My Ride Nepal is not an emergency service.</span> It does not dispatch help or track riders. In an emergency, contact local emergency services directly. Keep your <RouterLink to="/account#emergency" class="link">emergency contacts</RouterLink> and a <RouterLink to="/rides" class="link">trip checklist</RouterLink> ready before you ride.</p>
    </div>

    <section class="mt-12" aria-label="Guides">
      <StatusPanel v-if="guides.loading.value" state="loading" title="Loading guides" />
      <StatusPanel v-else-if="guides.error.value" state="error" :message="guides.error.value" @retry="guides.run" />
      <StatusPanel v-else-if="!guides.data.value?.length" state="empty" title="No guides are published yet" message="Guides appear after the editorial team, and an expert where needed, have checked them." />
      <div v-else class="grid gap-12">
        <section v-for="[cat, list] in grouped" :key="cat" :aria-label="categoryLabels[cat as Guide['category']]">
          <h2 class="display text-3xl">{{ categoryLabels[cat as Guide['category']] }}</h2>
          <ul class="mt-4 grid border-t border-line">
            <li v-for="g in list" :key="g.id" class="border-b border-line">
              <RouterLink :to="`/preparation/${g.slug}`" class="flex flex-wrap items-baseline justify-between gap-2 py-4">
                <span class="font-semibold">{{ g.title }}</span>
                <span v-if="g.expert_reviewed" class="inline-flex items-center gap-1 text-xs font-semibold text-success"><BadgeCheck class="size-3.5" aria-hidden="true" /> Expert reviewed</span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>
    </section>
  </div>
</template>
