<script setup lang="ts">
defineProps<{ title: string; intro?: string }>()
</script>

<template>
  <div class="grid min-h-svh lg:grid-cols-[1fr_1fr]">
    <div class="wrap flex max-w-xl flex-col justify-center pt-28 pb-16 lg:mx-0 lg:ml-auto lg:pr-16">
      <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">{{ title }}</h1>
      <p v-if="intro" class="lead mt-4 text-muted">{{ intro }}</p>
      <div class="mt-10">
        <slot />
      </div>
    </div>
    <figure class="relative hidden overflow-hidden bg-night lg:block">
      <img
        src="/images/photos/bandipur-bazaar-1280.webp"
        alt="The stone-paved main street of Bandipur bazaar"
        class="absolute inset-0 size-full object-cover"
      />
      <figcaption class="absolute right-4 bottom-4 text-xs text-night-text/80">Bandipur. Photo: Maesi64, CC0</figcaption>
    </figure>
  </div>
</template>
