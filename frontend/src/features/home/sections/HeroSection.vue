<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { ArrowRight } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import { heroesOnScreen } from '@/lib/site'
import type { HeroData } from '../types'

const props = defineProps<{ s: HeroData }>()
onMounted(() => heroesOnScreen.value++)
onBeforeUnmount(() => heroesOnScreen.value--)
// Bundled photos come in fixed widths; a library image is one re-encoded file.
const bg = computed(() => props.s.background)
const src = computed(() => (bg.value.kind === 'bundled' ? `${bg.value.base}-${bg.value.widths.includes(1920) ? 1920 : bg.value.widths.at(-1)}.webp` : bg.value.url))
const srcset = computed(() => (bg.value.kind === 'bundled' ? bg.value.widths.map((w) => `${(bg.value as { base: string }).base}-${w}.webp ${w}w`).join(', ') : undefined))
const credit = computed(() => props.s.photo_credit || (bg.value.kind === 'media' ? bg.value.credit : null))
</script>

<template>
    <section class="on-night relative isolate flex min-h-[max(36rem,92svh)] items-end overflow-hidden bg-night text-night-text">
      <img
        :src="src"
        :srcset="srcset"
        sizes="100vw"
        :alt="s.background.kind === 'media' ? (s.background.alt ?? '') : ''"
        fetchpriority="high"
        class="hero-photo absolute inset-0 -z-10 size-full object-cover object-[68%_center]"
      />
      <!-- Legibility scrim behind the words only. -->
      <div class="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgb(17_18_21/0.82)_0%,rgb(17_18_21/0.45)_45%,transparent_75%),linear-gradient(0deg,rgb(17_18_21/0.7)_0%,transparent_45%)]" />
      <!-- On narrow screens the words cover the bright part of the photo too. -->
      <div class="absolute inset-0 -z-10 bg-night/45 md:hidden" />

      <div class="wrap pt-28 pb-14 sm:pb-20">
        <p v-if="s.eyebrow" class="hero-in label text-marigold" style="--d: 0ms">{{ s.eyebrow }}</p>
        <h1 class="display mt-5 max-w-[11ch] text-[clamp(3rem,1.6rem+5vw,6.25rem)]">
          <span v-for="(line, i) in s.lines" :key="line" class="block overflow-hidden pb-[0.06em]">
            <span class="hero-line block" :style="{ '--d': `${120 + i * 110}ms` }">{{ line }}</span>
          </span>
        </h1>
        <p v-if="s.lead" class="hero-in lead mt-7 max-w-xl text-night-text/85" style="--d: 520ms">{{ s.lead }}</p>
        <div v-if="s.primary_cta?.to || s.secondary_cta?.to" class="hero-in mt-9 flex flex-wrap gap-3" style="--d: 640ms">
          <SiteLink v-if="s.primary_cta?.to" :to="s.primary_cta.to" class="btn btn-primary">
            {{ s.primary_cta.label }}
            <ArrowRight class="size-4" aria-hidden="true" />
          </SiteLink>
          <SiteLink v-if="s.secondary_cta?.to" :to="s.secondary_cta.to" class="btn btn-quiet">{{ s.secondary_cta.label }}</SiteLink>
        </div>
      </div>
      <p v-if="credit" class="absolute right-4 bottom-4 hidden text-xs text-night-muted sm:block">{{ credit }}</p>
    </section>
</template>

<style scoped>
.hero-photo {
  animation: hero-settle 1800ms var(--ease-out-quint) both;
}
.hero-line {
  animation: hero-rise 900ms var(--ease-out-quint) both;
  animation-delay: var(--d);
}
.hero-in {
  animation: hero-fade 800ms var(--ease-out-quint) both;
  animation-delay: var(--d);
}
@keyframes hero-settle {
  from {
    transform: scale(1.07);
    opacity: 0.4;
  }
}
@keyframes hero-rise {
  from {
    transform: translateY(105%);
  }
}
@keyframes hero-fade {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
}

</style>
