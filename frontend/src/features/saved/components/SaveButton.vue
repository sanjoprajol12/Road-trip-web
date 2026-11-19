<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Bookmark, BookmarkCheck } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import type { SavedType } from '../services/saved'
import { useSaved } from '../useSaved'

// Save / remove from saved. Guests get a sign-in link that brings them back here.
const props = defineProps<{ type: SavedType; id: string; name: string; compact?: boolean }>()
const { isSaved, toggle, user } = useSaved()
const route = useRoute()
const busy = ref(false)
const error = ref<string | null>(null)
async function click() {
  busy.value = true
  error.value = null
  try {
    await toggle(props.type, props.id)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <span class="inline-flex flex-col gap-1">
    <button v-if="user" type="button" class="inline-flex items-center gap-1.5 text-sm font-semibold" :class="compact ? 'link' : 'btn btn-quiet'" :aria-pressed="isSaved(type, id)" :aria-label="`${isSaved(type, id) ? 'Remove' : 'Save'} ${name}`" :disabled="busy" @click="click">
      <BookmarkCheck v-if="isSaved(type, id)" class="size-4 text-crimson" aria-hidden="true" />
      <Bookmark v-else class="size-4" aria-hidden="true" />
      <span v-if="!compact">{{ isSaved(type, id) ? 'Saved' : 'Save' }}</span>
    </button>
    <RouterLink v-else-if="!compact" :to="{ path: '/sign-in', query: { next: route.fullPath } }" class="btn btn-quiet text-sm"><Bookmark class="size-4" aria-hidden="true" /> Sign in to save</RouterLink>
    <span v-if="error" role="alert" class="text-xs text-danger">{{ error }}</span>
  </span>
</template>
