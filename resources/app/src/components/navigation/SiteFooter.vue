<script setup lang="ts">
import { RouterLink } from 'vue-router'
import BrandMark from './BrandMark.vue'
</script>

<template>
  <footer class="on-night border-t border-night-line bg-night text-night-text">
    <div class="wrap grid gap-10 py-14 md:grid-cols-[1.4fr_1fr]">
      <div class="grid content-start gap-4">
        <RouterLink to="/" class="flex items-center gap-2.5 text-night-text">
          <BrandMark class="size-7" />
          <span class="display text-2xl leading-none">My Ride Nepal</span>
        </RouterLink>
        <p class="lead max-w-md text-night-muted">Plan better. Ride prepared. Learn from real journeys.</p>
      </div>
      <div class="grid content-start gap-3 text-sm text-night-muted">
        <p>
          <span class="font-semibold text-night-text">Not an emergency service.</span>
          My Ride Nepal does not dispatch help or track riders. In an emergency, contact local emergency services directly.
        </p>
        <p>Rider reports describe the day they were made. Check current conditions locally before you ride.</p>
      </div>
    </div>
    <div class="border-t border-night-line">
      <p class="wrap py-5 text-xs text-night-muted">
        Photos: Igor Ovsyannykov, Maesi64, Sagar Dani, scvishnu7 and phuchche, all CC0 via Wikimedia Commons.
      </p>
    </div>
  </footer>
</template>
