<script setup lang="ts">
import { watch } from 'vue'
import { Info } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { setPageMeta } from '@/lib/site'
import { getContent } from '../services/content'
import PlainText from '../components/PlainText.vue'

// A custom page from the CMS (/pages/:slug): summary, text and ordered blocks, all plain text.
const props = defineProps<{ slug: string }>()
const page = useAsync(() => getContent('page', props.slug))
watch(() => props.slug, () => void page.run())
watch(page.data, (p) => p && setPageMeta(p.seo_title || p.title, p.seo_description || p.summary))
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <StatusPanel v-if="page.loading.value && !page.data.value" state="loading" title="Loading" />
    <StatusPanel v-else-if="page.error.value" state="error" :message="page.error.value" @retry="page.run" />
    <template v-else-if="!page.data.value">
      <h1 class="display text-5xl">Page not found</h1>
      <StatusPanel class="mt-6" state="empty" title="Nothing is published at this address" message="It may have moved or been removed." />
    </template>
    <article v-else class="max-w-3xl">
      <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">{{ page.data.value.title }}</h1>
      <p v-if="page.data.value.summary" class="lead mt-5 text-muted">{{ page.data.value.summary }}</p>
      <figure v-if="page.data.value.media" class="mt-8 grid gap-2">
        <img :src="page.data.value.media.url" :alt="page.data.value.media.alt" :width="page.data.value.media.width" :height="page.data.value.media.height" class="h-auto w-full rounded-md" />
        <figcaption class="text-xs text-muted">{{ page.data.value.media.credit }}</figcaption>
      </figure>
      <PlainText class="prose-serif mt-8 max-w-prose" :text="page.data.value.body" />
      <template v-for="(b, i) in page.data.value.data.blocks ?? []" :key="i">
        <h2 v-if="b.kind === 'heading'" class="display mt-10 text-3xl">{{ b.text }}</h2>
        <p v-else-if="b.kind === 'callout'" class="mt-6 flex max-w-prose gap-2 rounded-md border border-line bg-surface p-4"><Info class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" /><span class="whitespace-pre-line">{{ b.text }}</span></p>
        <PlainText v-else class="prose-serif mt-4 max-w-prose" :text="b.text" />
      </template>
    </article>
  </div>
</template>
