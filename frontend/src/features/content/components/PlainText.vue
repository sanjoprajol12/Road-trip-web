<script setup lang="ts">
import { computed } from 'vue'

// CMS text is plain text: an empty line starts a new paragraph. Rendered as text, never as HTML.
const props = defineProps<{ text: string | null | undefined }>()
const paragraphs = computed(() => (props.text ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean))
</script>

<template>
  <div class="grid gap-4">
    <p v-for="(p, i) in paragraphs" :key="i" class="whitespace-pre-line">{{ p }}</p>
  </div>
</template>
