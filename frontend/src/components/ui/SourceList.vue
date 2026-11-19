<script setup lang="ts">
import type { Source } from '@/types/api'
import { formatDay } from '@/lib/format'

// Citations staff attached to a record. Renders nothing when there are none.
defineProps<{ sources?: Source[] }>()
</script>

<template>
  <div v-if="sources?.length" class="rounded-md border border-line p-4 text-sm">
    <p class="label text-muted">Sources</p>
    <ul class="mt-2 grid gap-2">
      <li v-for="s in sources" :key="s.id">
        <a v-if="s.url" :href="s.url" rel="noopener noreferrer" target="_blank" class="link font-semibold">{{ s.title }}</a>
        <span v-else class="font-semibold">{{ s.title }}</span>
        <span v-if="s.publisher" class="text-muted">, {{ s.publisher }}</span>
        <span v-if="s.accessed_on" class="block text-xs text-muted">Accessed {{ formatDay(s.accessed_on) }}</span>
      </li>
    </ul>
  </div>
</template>
