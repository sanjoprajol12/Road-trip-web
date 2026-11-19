<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight, ArrowUpRight } from 'lucide-vue-next'
import PlacePhoto from '@/components/ui/PlacePhoto.vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import type { FeaturedData } from '../types'
import type { Destination } from '@/types/api'

defineProps<{ s: FeaturedData<Destination>; id: string }>()
</script>

<template>
    <section class="border-t border-line bg-surface" :aria-labelledby="`dest-${id}`">
      <div class="wrap py-20 sm:py-24">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <h2 :id="`dest-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">{{ s.heading }}</h2>
          <RouterLink to="/explore" class="link inline-flex items-center gap-1.5 font-semibold">
            {{ s.link_label ?? 'All destinations' }} <ArrowRight class="size-4" aria-hidden="true" />
          </RouterLink>
        </div>

        <StatusPanel
          v-if="!s.items.length"
          class="mt-8"
          state="empty"
          title="No destinations are published yet"
          message="Destinations appear here once the editorial team has checked and published them."
        />
        <div v-else class="mt-10 grid gap-8 md:grid-cols-[1.35fr_1fr]">
          <RouterLink
            v-for="d in s.items.slice(0, 1)"
            :key="d.id"
            v-reveal
            :to="`/explore/${d.slug}`"
            class="group relative block aspect-4/3 overflow-hidden rounded-md bg-night"
          >
            <PlacePhoto
              v-if="d.photo_path"
              :base="d.photo_path"
              :alt="d.photo_alt ?? ''"
              sizes="(min-width: 768px) 55vw, 100vw"
              class="transition-transform duration-700 ease-out-quint group-hover:scale-[1.03]"
            />
            <div class="absolute inset-0 bg-[linear-gradient(0deg,rgb(17_18_21/0.85),rgb(17_18_21/0.35)_45%,transparent_70%)]" />
            <div class="on-night absolute inset-x-0 bottom-0 p-6 text-night-text">
              <p class="label text-marigold">{{ [d.district, d.province].filter(Boolean).join(', ') }}</p>
              <p class="display mt-2 text-5xl">{{ d.name }}</p>
            </div>
          </RouterLink>
          <ul class="grid content-start border-t border-line">
            <li v-for="(d, i) in s.items.slice(1, 7)" :key="d.id" v-reveal="i" class="border-b border-line">
              <RouterLink :to="`/explore/${d.slug}`" class="group flex items-center gap-4 py-5">
                <span class="label tabular w-6 text-muted">{{ String(i + 2).padStart(2, '0') }}</span>
                <span class="grid flex-1 gap-0.5">
                  <span class="display text-2xl">{{ d.name }}</span>
                  <span class="text-sm text-muted">{{ [d.district, d.province].filter(Boolean).join(', ') }}</span>
                </span>
                <ArrowUpRight class="size-5 text-muted transition-colors group-hover:text-ink" aria-hidden="true" />
              </RouterLink>
            </li>
          </ul>
        </div>
      </div>
    </section>
</template>
