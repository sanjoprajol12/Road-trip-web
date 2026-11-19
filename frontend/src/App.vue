<script setup lang="ts">
import { computed } from 'vue'
import { RouterView } from 'vue-router'
import { Wrench } from 'lucide-vue-next'
import { site } from '@/lib/site'
import { endPreview, previewToken } from '@/lib/preview'
import SiteHeader from '@/components/navigation/SiteHeader.vue'
import SiteFooter from '@/components/navigation/SiteFooter.vue'
import NoticeBar from '@/components/navigation/NoticeBar.vue'

// Same rule as the API: during maintenance only a staff preview link shows the site (riders never bypass it).
const closed = computed(() => !!site.value?.maintenance.enabled && !previewToken.value)
function leavePreview() {
  endPreview()
  window.location.reload()
}
</script>

<template>
  <div class="flex min-h-svh flex-col">
    <p v-if="previewToken" class="bg-marigold px-4 py-2 text-center text-sm font-semibold text-ink" role="status">
      Staff preview: you are seeing the site as it is behind maintenance mode.
      <button type="button" class="ml-2 underline" @click="leavePreview">End preview</button>
    </p>
    <NoticeBar />
    <SiteHeader />
    <main id="main" tabindex="-1" class="flex-1 outline-none">
      <section v-if="closed" class="wrap grid max-w-xl gap-4 pt-36 pb-24" role="status" aria-labelledby="mt-title">
        <Wrench class="size-8 text-muted" aria-hidden="true" />
        <h1 id="mt-title" class="display text-5xl">Back soon</h1>
        <p class="lead text-muted">{{ site?.maintenance.message }}</p>
      </section>
      <RouterView v-else v-slot="{ Component, route }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="route.path" />
        </Transition>
      </RouterView>
    </main>
    <SiteFooter />
  </div>
</template>
