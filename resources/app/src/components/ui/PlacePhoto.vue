<script setup lang="ts">
// Bundled photos live at <base>-640.webp / <base>-1280.webp (see public/images/photos/LICENSES.md).
// shortcut: only bundled paths; Supabase Storage URLs arrive with the media milestone.
defineProps<{ base: string; alt: string; sizes?: string; eager?: boolean }>()
</script>

<template>
  <img
    :src="`${base}-1280.webp`"
    :srcset="`${base}-640.webp 640w, ${base}-1280.webp 1280w`"
    :sizes="sizes ?? '(min-width: 768px) 50vw, 100vw'"
    :alt="alt"
    :loading="eager ? 'eager' : 'lazy'"
    decoding="async"
    class="size-full object-cover"
  />
</template>
