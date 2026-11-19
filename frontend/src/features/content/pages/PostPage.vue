<script setup lang="ts">
import { watch } from 'vue'
import { ArrowLeft } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { setPageMeta } from '@/lib/site'
import { getContent } from '../services/content'
import PlainText from '../components/PlainText.vue'

const props = defineProps<{ slug: string }>()
const post = useAsync(() => getContent('post', props.slug))
watch(() => props.slug, () => void post.run())
watch(post.data, (p) => p && setPageMeta(p.seo_title || p.title, p.seo_description || p.summary))
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/blog" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All posts</RouterLink>
    <StatusPanel v-if="post.loading.value && !post.data.value" class="mt-6" state="loading" title="Loading" />
    <StatusPanel v-else-if="post.error.value" class="mt-6" state="error" :message="post.error.value" @retry="post.run" />
    <template v-else-if="!post.data.value">
      <h1 class="display mt-8 text-5xl">Post not found</h1>
      <StatusPanel class="mt-6" state="empty" title="Nothing is published at this address" message="It may have moved or been removed." />
    </template>
    <article v-else class="mt-8 max-w-3xl">
      <p class="label text-muted"><template v-if="post.data.value.data.category && typeof post.data.value.data.category !== 'string'"><RouterLink :to="{ path: '/blog', query: { category: post.data.value.data.category.slug } }" class="link">{{ post.data.value.data.category.title }}</RouterLink> · </template>{{ formatDay(post.data.value.published_at) }}</p>
      <h1 class="display mt-3 text-[clamp(2.5rem,1.8rem+3vw,4.5rem)]">{{ post.data.value.title }}</h1>
      <p v-if="post.data.value.data.author" class="mt-3 text-sm">By <RouterLink :to="{ path: '/blog', query: { author: post.data.value.data.author.slug } }" class="link font-semibold">{{ post.data.value.data.author.title }}</RouterLink></p>
      <p v-if="post.data.value.summary" class="lead mt-5 text-muted">{{ post.data.value.summary }}</p>
      <figure v-if="post.data.value.media" class="mt-8 grid gap-2">
        <img :src="post.data.value.media.url" :alt="post.data.value.media.alt" :width="post.data.value.media.width" :height="post.data.value.media.height" class="h-auto w-full rounded-md" />
        <figcaption class="text-xs text-muted">{{ post.data.value.media.credit }}</figcaption>
      </figure>
      <PlainText class="prose-serif mt-8 max-w-prose" :text="post.data.value.body" />
      <ul v-if="post.data.value.data.tags?.length" class="mt-10 flex flex-wrap gap-2" aria-label="Tags">
        <li v-for="t in post.data.value.data.tags" :key="t.id"><RouterLink :to="{ path: '/blog', query: { tag: t.slug } }" class="btn btn-quiet min-h-9 px-3 text-sm">{{ t.title }}</RouterLink></li>
      </ul>
    </article>
  </div>
</template>
