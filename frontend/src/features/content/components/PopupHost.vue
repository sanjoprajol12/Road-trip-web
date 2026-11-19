<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { X } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import { useAsync } from '@/lib/useAsync'
import { listContent, type CmsItem } from '../services/content'
import PlainText from './PlainText.vue'

// One live CMS popup at a time, as a native modal dialog (focus moves in, Escape closes, focus returns).
// "Seen" is remembered per visitor (localStorage) or per visit (sessionStorage); storage may be unavailable.
const route = useRoute()
const popups = useAsync(() => listContent('popup'))
const el = ref<HTMLDialogElement | null>(null)
const store = (p: CmsItem) => (p.data.frequency === 'session' ? sessionStorage : localStorage)
const seen = (p: CmsItem) => {
  try {
    return store(p).getItem(`mrn.popup.${p.id}`) === '1'
  } catch {
    return false
  }
}
const dismissed = ref(new Set<string>())
const current = computed(() => (popups.data.value ?? []).find((p) => !dismissed.value.has(p.id) && !seen(p) && (p.data.show_on !== 'home' || route.path === '/')) ?? null)
watch(current, async (p) => {
  await nextTick()
  if (p && el.value && !el.value.open) el.value.showModal()
})
function close() {
  const p = current.value
  if (!p) return
  try {
    store(p).setItem(`mrn.popup.${p.id}`, '1')
  } catch {
    /* not remembered: it may show again on the next page load */
  }
  el.value?.close()
  dismissed.value = new Set([...dismissed.value, p.id])
}
</script>

<template>
  <dialog v-if="current" ref="el" class="popup m-auto w-[min(32rem,calc(100vw-2rem))] rounded-md border border-line bg-surface p-6 text-ink backdrop:bg-night/50" aria-labelledby="popup-title" @cancel.prevent="close">
    <div class="flex items-start justify-between gap-4">
      <h2 id="popup-title" class="display text-2xl">{{ current.title }}</h2>
      <button type="button" class="btn btn-quiet min-h-9 px-2" aria-label="Close" @click="close"><X class="size-4" aria-hidden="true" /></button>
    </div>
    <PlainText class="mt-3 text-sm" :text="current.body" />
    <SiteLink v-if="current.data.cta?.to" :to="current.data.cta.to" class="btn btn-primary mt-5" @click="close">{{ current.data.cta.label }}</SiteLink>
  </dialog>
</template>
