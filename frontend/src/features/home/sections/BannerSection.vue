<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import type { BannerData } from '../types'

defineProps<{ s: BannerData; id: string }>()
</script>

<template>
  <section class="wrap py-12" :aria-labelledby="`banner-${id}`">
    <div class="grid overflow-hidden rounded-md border border-line bg-surface md:grid-cols-[1.2fr_1fr]">
      <div class="grid content-center gap-3 p-8">
        <h2 :id="`banner-${id}`" class="display text-3xl">{{ s.heading }}</h2>
        <p v-if="s.text" class="text-muted">{{ s.text }}</p>
        <SiteLink v-if="s.cta?.to" :to="s.cta.to" class="btn btn-primary justify-self-start">{{ s.cta.label }} <ArrowRight class="size-4" aria-hidden="true" /></SiteLink>
      </div>
      <figure v-if="s.image" class="relative min-h-48">
        <img :src="s.image.url" :alt="s.image.alt" :width="s.image.width" :height="s.image.height" loading="lazy" class="absolute inset-0 size-full object-cover" />
        <figcaption class="absolute right-2 bottom-2 rounded bg-night/70 px-2 py-0.5 text-xs text-night-text">{{ s.image.credit }}</figcaption>
      </figure>
    </div>
  </section>
</template>
