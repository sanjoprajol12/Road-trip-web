<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { setPageMeta } from '@/lib/site'
import { listContent, listPosts } from '../services/content'
import CmsBanners from '../components/CmsBanners.vue'

// Blog: newest first, filters by category, tag and author in the address (shareable), search, pages of 12.
const route = useRoute()
const router = useRouter()
const query = computed(() => ({ category: route.query.category as string | undefined, tag: route.query.tag as string | undefined, author: route.query.author as string | undefined, q: route.query.q as string | undefined, page: Number(route.query.page ?? 1) }))
const posts = useAsync(() => listPosts(query.value))
const categories = useAsync(() => listContent('blog_category'))
const tags = useAsync(() => listContent('blog_tag'))
watch(query, () => void posts.run())
const q = ref(query.value.q ?? '')
const go = (patch: Record<string, string | number | undefined>) => router.push({ query: { ...route.query, page: undefined, ...patch } })
onMounted(() => setPageMeta('Blog'))
</script>

<template>
  <div class="pt-24 pb-24">
    <div class="wrap">
      <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Blog</h1>
      <div class="mt-8 flex flex-wrap items-end gap-3">
        <form class="flex items-end gap-2" role="search" @submit.prevent="go({ q: q || undefined })">
          <div class="field"><label for="b-q">Search posts</label><input id="b-q" v-model="q" type="search" class="input w-64" /></div>
          <button type="submit" class="btn btn-quiet" aria-label="Search"><Search class="size-4" aria-hidden="true" /></button>
        </form>
        <div class="field"><label for="b-c">Category</label><select id="b-c" class="input" :value="query.category ?? ''" @change="go({ category: ($event.target as HTMLSelectElement).value || undefined })"><option value="">All</option><option v-for="c in categories.data.value ?? []" :key="c.id" :value="c.slug">{{ c.title }}</option></select></div>
        <div class="field"><label for="b-t">Tag</label><select id="b-t" class="input" :value="query.tag ?? ''" @change="go({ tag: ($event.target as HTMLSelectElement).value || undefined })"><option value="">All</option><option v-for="t in tags.data.value ?? []" :key="t.id" :value="t.slug">{{ t.title }}</option></select></div>
        <button v-if="query.author" type="button" class="btn btn-quiet" @click="go({ author: undefined })">Clear author filter</button>
      </div>
    </div>
    <CmsBanners placement="blog" />
    <div class="wrap">
      <StatusPanel v-if="posts.loading.value && !posts.data.value" class="mt-8" state="loading" title="Loading posts" />
      <StatusPanel v-else-if="posts.error.value" class="mt-8" state="error" :message="posts.error.value" @retry="posts.run" />
      <StatusPanel v-else-if="!posts.data.value?.data.length" class="mt-8" state="empty" title="No posts here yet" message="Try another category or tag." />
      <ul v-else class="mt-8 grid gap-8 md:grid-cols-2 lg:grid-cols-3" aria-label="Posts">
        <li v-for="p in posts.data.value.data" :key="p.id" class="grid content-start gap-2">
          <img v-if="p.media" :src="p.media.url" :alt="p.media.alt" :width="p.media.width" :height="p.media.height" loading="lazy" class="aspect-[16/10] w-full rounded-md object-cover" />
          <p class="label text-muted"><template v-if="p.data.category && typeof p.data.category !== 'string'">{{ p.data.category.title }} · </template>{{ formatDay(p.published_at) }}</p>
          <h2 class="text-xl font-bold"><RouterLink :to="`/blog/${p.slug}`" class="link">{{ p.title }}</RouterLink></h2>
          <p v-if="p.summary" class="text-muted">{{ p.summary }}</p>
        </li>
      </ul>
      <nav v-if="(posts.data.value?.meta.last_page ?? 1) > 1" class="mt-10 flex items-center gap-3 text-sm" aria-label="Pages">
        <button type="button" class="btn btn-quiet" :disabled="query.page <= 1" @click="go({ page: query.page - 1 })">Newer</button>
        <span>Page {{ posts.data.value?.meta.current_page }} of {{ posts.data.value?.meta.last_page }}</span>
        <button type="button" class="btn btn-quiet" :disabled="query.page >= (posts.data.value?.meta.last_page ?? 1)" @click="go({ page: query.page + 1 })">Older</button>
      </nav>
    </div>
  </div>
</template>
