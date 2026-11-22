<script setup lang="ts">
import type { FreshnessData } from '../types'

defineProps<{ s: FreshnessData; id: string }>()
</script>

<template>
    <section class="on-night texture-contours bg-night text-night-text" :aria-labelledby="`fresh-${id}`">
      <div class="wrap grid gap-12 py-20 sm:py-28 md:grid-cols-2">
        <div>
          <h2 :id="`fresh-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">
            {{ s.heading }}
          </h2>
          <p v-if="s.text" v-reveal="1" class="lead mt-6 max-w-lg text-night-muted">{{ s.text }}</p>
        </div>

        <!-- Anatomy of a report stamp: a legend, not a real report. -->
        <figure v-if="s.legend.length" v-reveal="2" class="self-center">
          <div class="rounded-md border border-night-line p-6">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
              <div v-for="l in s.legend" :key="l.term" class="grid gap-1">
                <dt class="label text-marigold">{{ l.term }}</dt>
                <dd class="text-night-muted">{{ l.detail }}</dd>
              </div>
            </dl>
          </div>
          <figcaption v-if="s.caption" class="mt-3 text-xs text-night-muted">{{ s.caption }}</figcaption>
        </figure>
      </div>
    </section>
</template>
