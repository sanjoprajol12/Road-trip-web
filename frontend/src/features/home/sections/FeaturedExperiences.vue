<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight } from 'lucide-vue-next'
import { formatDay } from '@/lib/format'
import type { Contribution } from '@/types/api'
import type { FeaturedData } from '../types'

defineProps<{ s: FeaturedData<Contribution>; id: string }>()
</script>

<template>
  <section v-if="s.items.length" class="border-t border-line bg-surface" :aria-labelledby="`exp-${id}`">
    <div class="wrap py-20">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <h2 :id="`exp-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">{{ s.heading }}</h2>
        <RouterLink to="/experiences" class="link inline-flex items-center gap-1.5 font-semibold">{{ s.link_label ?? 'All experiences' }} <ArrowRight class="size-4" aria-hidden="true" /></RouterLink>
      </div>
      <ul class="mt-8 grid gap-6 md:grid-cols-3">
        <li v-for="c in s.items" :key="c.id">
          <RouterLink :to="`/experiences/${c.id}`" class="grid gap-2 rounded-md border border-line bg-paper p-5 hover:border-line-strong">
            <span class="display text-xl">{{ c.title }}</span>
            <!-- A report's date is always shown: it describes that day, not today. -->
            <span class="text-sm text-muted">Ridden {{ formatDay(c.observed_on) }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>
