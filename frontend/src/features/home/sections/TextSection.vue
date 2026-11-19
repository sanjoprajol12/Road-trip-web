<script setup lang="ts">
import type { TextData } from '../types'

defineProps<{ s: TextData; id: string }>()
</script>

<template>
  <section class="wrap py-16 sm:py-20" :aria-labelledby="`text-${id}`">
    <h2 :id="`text-${id}`" v-reveal class="display text-[clamp(2rem,1.5rem+2vw,3.25rem)]">{{ s.heading }}</h2>
    <p v-for="(para, i) in s.body.split(/\n{2,}/)" :key="i" class="mt-4 max-w-2xl whitespace-pre-line text-muted">{{ para }}</p>
  </section>
</template>
