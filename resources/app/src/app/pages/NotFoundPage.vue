<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="wrap grid min-h-[70svh] content-center gap-6 pt-28 pb-20">
    <p class="label tabular text-crimson">404</p>
    <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Wrong turn</h1>
    <p class="lead max-w-lg text-muted">There is nothing at this address. It may have moved, or the link was mistyped.</p>
    <div class="flex flex-wrap gap-3">
      <RouterLink to="/" class="btn btn-primary">Back to the start</RouterLink>
      <RouterLink to="/explore" class="btn btn-quiet">Explore destinations</RouterLink>
    </div>
  </div>
</template>
