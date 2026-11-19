<script setup lang="ts">
import type { LayersData } from '../types'

defineProps<{ s: LayersData; id: string }>()
</script>

<template>
    <section class="wrap py-20 sm:py-28" :aria-labelledby="`layers-${id}`">
      <div class="grid gap-6 md:grid-cols-[1fr_1.3fr] md:items-end">
        <h2 :id="`layers-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">
          {{ s.heading }}
        </h2>
        <p v-if="s.intro" v-reveal="1" class="lead max-w-xl text-muted">{{ s.intro }}</p>
      </div>

      <ol v-reveal:draw class="layers relative mt-16 grid gap-10 md:grid-cols-4 md:gap-6">
        <li v-for="(layer, i) in s.items" :key="layer.name" v-reveal="i" class="relative grid content-start gap-3 pl-10 md:pt-12 md:pl-0">
          <span
            class="absolute top-1 left-0 grid size-6 place-items-center rounded-full border-2 border-crimson bg-paper md:top-0"
            aria-hidden="true"
          >
            <span class="size-2 rounded-full bg-crimson" />
          </span>
          <span class="label tabular text-muted">0{{ i + 1 }}</span>
          <h3 class="display text-3xl">{{ layer.name }}</h3>
          <p class="max-w-xs text-[0.9375rem] leading-relaxed text-muted">{{ layer.text }}</p>
        </li>
      </ol>
    </section>
</template>

<style scoped>
/* The route line joining the four layers draws itself in once the list is revealed. */
.layers::before {
  content: '';
  position: absolute;
  left: 11px;
  top: 0.5rem;
  bottom: 0.5rem;
  width: 2px;
  background: var(--color-line-strong);
  transform-origin: top;
  transform: scaleY(0);
  transition: transform 1400ms var(--ease-in-out) 150ms;
}
.layers.is-visible::before {
  transform: none;
}
@media (min-width: 768px) {
  .layers::before {
    left: 0;
    right: 0;
    top: 11px;
    bottom: auto;
    width: auto;
    height: 2px;
    transform-origin: left;
    transform: scaleX(0);
  }
  .layers.is-visible::before {
    transform: none;
  }
}
</style>
