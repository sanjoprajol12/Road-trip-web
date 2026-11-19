<script setup lang="ts">
import { onMounted } from 'vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { setPageMeta } from '@/lib/site'
import { listContent } from '../services/content'

const team = useAsync(() => listContent('team_member'))
onMounted(() => setPageMeta('Team'))
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">The team</h1>
    <StatusPanel v-if="team.loading.value && !team.data.value" class="mt-8" state="loading" title="Loading" />
    <StatusPanel v-else-if="team.error.value" class="mt-8" state="error" :message="team.error.value" @retry="team.run" />
    <StatusPanel v-else-if="!team.data.value?.length" class="mt-8" state="empty" title="No team members published yet" message="" />
    <ul v-else class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3" aria-label="Team members">
      <li v-for="m in team.data.value" :key="m.id" class="grid content-start gap-2">
        <img v-if="m.media" :src="m.media.url" :alt="m.media.alt" :width="m.media.width" :height="m.media.height" loading="lazy" class="aspect-square w-full max-w-xs rounded-md object-cover" />
        <h2 class="text-xl font-bold">{{ m.title }}</h2>
        <p class="label text-muted">{{ m.data.role }}</p>
        <p v-if="m.summary" class="text-muted">{{ m.summary }}</p>
      </li>
    </ul>
  </div>
</template>
