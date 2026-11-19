<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, BadgeCheck } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { formatDay } from '@/lib/format'
import { expertReview, getGuide } from '../services/guides'

const props = defineProps<{ slug: string }>()
const { user } = useAuth()
const guide = useAsync(async () => {
  try {
    return await getGuide(props.slug)
  } catch (e) {
    if (statusOf(e) === 404 || statusOf(e) === 403) return null
    throw e
  }
})
const g = computed(() => guide.data.value)
const scope: Record<string, string> = { mechanical: 'mechanical', medical: 'medical', emergency: 'medical', road_safety: 'road_safety' }
const canReview = computed(() => g.value && !g.value.expert_reviewed && user.value?.verified.some((v) => v.kind === 'expert_reviewer' && v.scope === scope[g.value!.category]))
const reviewMsg = ref<string | null>(null)
async function review() {
  try {
    await expertReview(g.value!.id)
    reviewMsg.value = 'Thank you. Staff can now publish this guide.'
    await guide.run()
  } catch (e) {
    reviewMsg.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/preparation" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All guides</RouterLink>
    <StatusPanel v-if="guide.loading.value" class="mt-6" state="loading" title="Loading guide" />
    <StatusPanel v-else-if="guide.error.value" class="mt-6" state="error" :message="guide.error.value" @retry="guide.run" />
    <StatusPanel v-else-if="!g" class="mt-6" state="empty" title="This guide is not published" />
    <article v-else class="mt-8 max-w-3xl">
      <h1 class="display text-[clamp(2.5rem,1.8rem+3vw,4.5rem)]">{{ g.title }}</h1>
      <p v-if="g.status !== 'published'" class="mt-3 font-semibold text-warning">Draft: not yet published.</p>
      <p v-if="g.expert_reviewed" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-success"><BadgeCheck class="size-4" aria-hidden="true" /> Reviewed by a verified expert {{ formatDay(g.expert_reviewed_at) }}</p>
      <div class="prose-serif mt-8 whitespace-pre-line">{{ g.body }}</div>
      <section class="mt-10 border-t border-line pt-6" aria-labelledby="src"><h2 id="src" class="font-semibold">Sources</h2><p class="mt-2 text-sm whitespace-pre-line">{{ g.sources }}</p></section>
      <div v-if="canReview" class="mt-8 rounded-md border border-line bg-surface p-4 text-sm">
        <p>You are a verified expert in this field. Confirm only if the guidance is correct and safe as written.</p>
        <button type="button" class="btn btn-primary mt-3" @click="review">I have reviewed this guide</button>
      </div>
      <p v-if="reviewMsg" class="mt-3 text-sm" role="status">{{ reviewMsg }}</p>
    </article>
  </div>
</template>
