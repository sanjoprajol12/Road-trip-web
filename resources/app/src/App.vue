<script setup lang="ts">
import { RouterView } from 'vue-router'
import SiteHeader from '@/components/navigation/SiteHeader.vue'
import SiteFooter from '@/components/navigation/SiteFooter.vue'
</script>

<template>
  <div class="flex min-h-svh flex-col">
    <SiteHeader />
    <main id="main" tabindex="-1" class="flex-1 outline-none">
      <RouterView v-slot="{ Component, route }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="route.path" />
        </Transition>
      </RouterView>
    </main>
    <SiteFooter />
  </div>
</template>
