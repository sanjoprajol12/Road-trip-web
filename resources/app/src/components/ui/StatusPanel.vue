<script setup lang="ts">
import { CircleAlert, Inbox, LoaderCircle } from 'lucide-vue-next'

defineProps<{
  state: 'loading' | 'error' | 'empty'
  title?: string
  message?: string | null
}>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div
    class="flex items-start gap-3 border-t border-line py-6"
    :role="state === 'error' ? 'alert' : 'status'"
    :aria-busy="state === 'loading' || undefined"
  >
    <LoaderCircle v-if="state === 'loading'" class="mt-0.5 size-5 shrink-0 animate-spin text-muted" aria-hidden="true" />
    <CircleAlert v-else-if="state === 'error'" class="mt-0.5 size-5 shrink-0 text-danger" aria-hidden="true" />
    <Inbox v-else class="mt-0.5 size-5 shrink-0 text-muted" aria-hidden="true" />
    <div class="grid gap-1">
      <p class="font-semibold">{{ title ?? (state === 'loading' ? 'Loading' : state === 'error' ? 'Could not load this' : 'Nothing here yet') }}</p>
      <p v-if="message" class="max-w-prose text-sm text-muted">{{ message }}</p>
      <div v-if="state === 'error' || $slots.default" class="mt-2 flex flex-wrap gap-2">
        <button v-if="state === 'error'" type="button" class="btn btn-quiet" @click="$emit('retry')">Try again</button>
        <slot />
      </div>
    </div>
  </div>
</template>
