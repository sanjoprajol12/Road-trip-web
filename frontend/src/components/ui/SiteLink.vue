<script setup lang="ts">
import { RouterLink } from 'vue-router'

// Links managed in the staff portal: internal paths stay in the app, https links open as normal external links.
// The server only accepts "/path" or "https://…", so nothing else can reach here.
defineProps<{ to: string }>()
</script>

<template>
  <RouterLink v-if="to.startsWith('/')" :to="to"><slot /></RouterLink>
  <a v-else :href="to" rel="noopener noreferrer"><slot /></a>
</template>
