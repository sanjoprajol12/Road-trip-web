<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import type { SlidesData } from '../types'

// A slider that never moves on its own for people who ask for reduced motion, and always has a pause control.
const props = defineProps<{ s: SlidesData; id: string }>()
const index = ref(0)
const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
const playing = ref(!reduced && props.s.items.length > 1)
const current = computed(() => props.s.items[index.value])
const go = (step: number) => (index.value = (index.value + step + props.s.items.length) % props.s.items.length)
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => (timer = setInterval(() => playing.value && go(1), 7000)))
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section v-if="current" class="wrap py-12" :aria-labelledby="`slides-${id}`" aria-roledescription="carousel">
    <h2 :id="`slides-${id}`" :class="s.heading ? 'display mb-6 text-3xl' : 'sr-only'">{{ s.heading ?? 'Highlights' }}</h2>
    <div class="relative overflow-hidden rounded-md bg-night text-night-text">
      <img :src="current.image.url" :alt="current.image.alt" :width="current.image.width" :height="current.image.height" class="aspect-[16/7] w-full object-cover opacity-80" />
      <div class="on-night absolute inset-x-0 bottom-0 grid gap-2 bg-[linear-gradient(0deg,rgb(17_18_21/0.85),transparent)] p-6" aria-live="polite">
        <p class="display text-3xl">{{ current.heading }}</p>
        <p v-if="current.text" class="max-w-xl text-night-muted">{{ current.text }}</p>
        <SiteLink v-if="current.cta?.to" :to="current.cta.to" class="btn btn-primary justify-self-start">{{ current.cta.label }}</SiteLink>
        <p class="text-xs text-night-muted">{{ current.image.credit }}</p>
      </div>
    </div>
    <div v-if="s.items.length > 1" class="mt-3 flex items-center gap-2">
      <button type="button" class="btn btn-quiet min-h-9 px-3" aria-label="Previous slide" @click="go(-1)"><ChevronLeft class="size-4" aria-hidden="true" /></button>
      <button type="button" class="btn btn-quiet min-h-9 px-3" :aria-label="playing ? 'Pause slides' : 'Play slides'" @click="playing = !playing">
        <component :is="playing ? Pause : Play" class="size-4" aria-hidden="true" />
      </button>
      <button type="button" class="btn btn-quiet min-h-9 px-3" aria-label="Next slide" @click="go(1)"><ChevronRight class="size-4" aria-hidden="true" /></button>
      <span class="tabular text-sm text-muted">{{ index + 1 }} / {{ s.items.length }}</span>
    </div>
  </section>
</template>
