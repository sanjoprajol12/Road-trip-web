<script setup lang="ts">
import { Info, TriangleAlert } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import { notices } from '@/lib/site'

// Site-wide notices from the staff portal. They are about the website, never live road or safety status.
</script>

<template>
  <div v-if="notices.length" role="region" aria-label="Notices">
    <p v-for="n in notices" :key="n.id" class="flex items-center justify-center gap-2 px-4 py-2 text-center text-sm" :class="n.level === 'warning' ? 'bg-marigold text-ink' : 'bg-surface text-ink'">
      <component :is="n.level === 'warning' ? TriangleAlert : Info" class="size-4 shrink-0" aria-hidden="true" />
      <span>{{ n.body }}</span>
      <SiteLink v-if="n.link_target" :to="n.link_target" class="font-semibold underline">{{ n.link_label }}</SiteLink>
    </p>
  </div>
</template>
