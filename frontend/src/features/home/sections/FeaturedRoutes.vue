<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight, ArrowUpRight } from 'lucide-vue-next'
import type { RideRoute } from '@/types/api'
import type { FeaturedData } from '../types'

defineProps<{ s: FeaturedData<RideRoute>; id: string }>()
</script>

<template>
  <section v-if="s.items.length" class="wrap py-20" :aria-labelledby="`routes-${id}`">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <h2 :id="`routes-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">{{ s.heading }}</h2>
      <RouterLink to="/routes" class="link inline-flex items-center gap-1.5 font-semibold">{{ s.link_label ?? 'All routes' }} <ArrowRight class="size-4" aria-hidden="true" /></RouterLink>
    </div>
    <ul class="mt-8 grid border-t border-line">
      <li v-for="r in s.items" :key="r.id" class="border-b border-line">
        <RouterLink :to="`/routes/${r.slug}`" class="group flex items-center gap-4 py-5">
          <span class="grid flex-1 gap-0.5">
            <span class="display text-2xl">{{ r.name }}</span>
            <span v-if="r.distance_km" class="tabular text-sm text-muted">{{ r.distance_km }} km</span>
          </span>
          <ArrowUpRight class="size-5 text-muted transition-colors group-hover:text-ink" aria-hidden="true" />
        </RouterLink>
      </li>
    </ul>
  </section>
</template>
