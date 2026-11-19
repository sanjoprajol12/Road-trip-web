<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import { useAsync } from '@/lib/useAsync'
import { listContent } from '../services/content'

// Promotional banners scheduled in the CMS for this placement. Nothing renders when none are live or loading fails.
const props = defineProps<{ placement: 'home' | 'blog' }>()
const banners = useAsync(() => listContent('banner', { placement: props.placement }))
</script>

<template>
  <section v-for="b in banners.data.value ?? []" :key="b.id" class="wrap py-8" :aria-labelledby="`cms-banner-${b.id}`">
    <div class="grid overflow-hidden rounded-md border border-line bg-surface md:grid-cols-[1.2fr_1fr]">
      <div class="grid content-center gap-3 p-8">
        <h2 :id="`cms-banner-${b.id}`" class="display text-3xl">{{ b.title }}</h2>
        <p v-if="b.summary" class="text-muted">{{ b.summary }}</p>
        <SiteLink v-if="b.data.cta?.to" :to="b.data.cta.to" class="btn btn-primary justify-self-start">{{ b.data.cta.label }} <ArrowRight class="size-4" aria-hidden="true" /></SiteLink>
      </div>
      <figure v-if="b.media" class="relative min-h-48">
        <img :src="b.media.url" :alt="b.media.alt" :width="b.media.width" :height="b.media.height" loading="lazy" class="absolute inset-0 size-full object-cover" />
        <figcaption class="absolute right-2 bottom-2 rounded bg-night/70 px-2 py-0.5 text-xs text-night-text">{{ b.media.credit }}</figcaption>
      </figure>
    </div>
  </section>
</template>
