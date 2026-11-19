<script setup lang="ts">
import { computed, onMounted } from 'vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import { setPageMeta } from '@/lib/site'
import { listContent } from '../services/content'
import PlainText from '../components/PlainText.vue'

// Frequently asked questions from the CMS, grouped by their group name. Native <details>: keyboard and screen-reader friendly.
const faqs = useAsync(() => listContent('faq'))
const groups = computed(() => {
  const map = new Map<string, typeof faqs.data.value>()
  for (const f of faqs.data.value ?? []) {
    const g = typeof f.data.category === 'string' && f.data.category ? f.data.category : 'General'
    map.set(g, [...(map.get(g) ?? []), f])
  }
  return [...map.entries()]
})
onMounted(() => setPageMeta('Questions and answers'))
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Questions and answers</h1>
    <StatusPanel v-if="faqs.loading.value && !faqs.data.value" class="mt-8" state="loading" title="Loading" />
    <StatusPanel v-else-if="faqs.error.value" class="mt-8" state="error" :message="faqs.error.value" @retry="faqs.run" />
    <StatusPanel v-else-if="!groups.length" class="mt-8" state="empty" title="No questions published yet" message="Use the contact page if you need an answer." />
    <section v-for="[name, items] in groups" v-else :key="name" class="mt-10 max-w-3xl" :aria-labelledby="`faq-${name}`">
      <h2 :id="`faq-${name}`" class="display text-3xl">{{ name }}</h2>
      <details v-for="f in items" :key="f.id" class="border-b border-line py-4">
        <summary class="cursor-pointer font-semibold">{{ f.title }}</summary>
        <PlainText class="mt-3 text-muted" :text="f.body" />
      </details>
    </section>
    <p class="mt-10 text-sm">Still stuck? <RouterLink to="/contact" class="link">Contact the team</RouterLink>.</p>
  </div>
</template>
