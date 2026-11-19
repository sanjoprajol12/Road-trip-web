<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, Flag, Pencil, TriangleAlert } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import VerifiedBadges from '@/components/ui/VerifiedBadges.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { ageInWords, formatDay } from '@/lib/format'
import { contributionKindLabels, contributionStatusLabels, surfaceLabels } from '../labels'
import { getContribution, reportContribution } from '../services/contributions'

const props = defineProps<{ id: string }>()
const { user } = useAuth()
const item = useAsync(async () => {
  try {
    return await getContribution(props.id)
  } catch (e) {
    if (statusOf(e) === 404 || statusOf(e) === 403) return null
    throw e
  }
})
const c = computed(() => item.data.value)
const details = computed(() => {
  const d = c.value?.details ?? {}
  return [
    ['Motorcycle', d.motorcycle && `${d.motorcycle}${d.engine_cc ? ` (${d.engine_cc} cc)` : ''}`],
    ['Trip length', d.duration_days && `${d.duration_days} ${d.duration_days === 1 ? 'day' : 'days'}`],
    ['Approximate spend', d.expenses_npr && `NPR ${Number(d.expenses_npr).toLocaleString('en-IN')}`],
    ['Surface', d.surface && surfaceLabels[d.surface]],
    ['Places visited', d.places],
    ['Problems', d.problems],
    ['What they wish they had known', d.wish_known],
    ['Lessons', d.lessons],
  ].filter(([, v]) => v) as [string, string][]
})

const reporting = ref(false)
const reason = ref('')
const reportMsg = ref<string | null>(null)
async function report() {
  try {
    reportMsg.value = await reportContribution(props.id, reason.value)
    reporting.value = false
  } catch (e) {
    reportMsg.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/experiences" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All experiences</RouterLink>
    <StatusPanel v-if="item.loading.value" class="mt-6" state="loading" title="Loading" />
    <StatusPanel v-else-if="item.error.value" class="mt-6" state="error" :message="item.error.value" @retry="item.run" />
    <template v-else-if="!c">
      <h1 class="display mt-8 text-5xl">Not available</h1>
      <StatusPanel class="mt-6" state="empty" title="This experience is not published" message="It may be waiting for review, or it was removed." />
    </template>
    <article v-else class="mt-8 grid gap-12 lg:grid-cols-[1fr_20rem]">
      <div class="min-w-0">
        <p class="label text-muted">{{ contributionKindLabels[c.kind] }}</p>
        <h1 class="display mt-3 text-[clamp(2.5rem,1.8rem+3vw,4.5rem)]">{{ c.title }}</h1>
        <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <RouterLink v-if="c.author?.username" :to="`/riders/${c.author.username}`" class="link font-semibold">{{ c.author.display_name }}</RouterLink>
          <span v-else class="font-semibold">{{ c.author?.display_name }}</span>
          <VerifiedBadges :claims="c.author?.verified ?? []" />
        </p>
        <!-- The rider's own words, never edited by staff. -->
        <p class="prose-serif mt-8 max-w-prose whitespace-pre-line">{{ c.body }}</p>
        <div v-if="c.media?.length" class="mt-8 grid gap-4 sm:grid-cols-2">
          <figure v-for="m in c.media" :key="m.id">
            <img :src="m.url" :alt="m.alt" :width="m.width" :height="m.height" loading="lazy" class="h-auto w-full rounded-md" />
          </figure>
        </div>
        <dl v-if="details.length" class="mt-10 grid gap-4 border-t border-line pt-6 sm:grid-cols-2">
          <div v-for="[k, v] in details" :key="k"><dt class="label text-muted">{{ k }}</dt><dd class="mt-1 whitespace-pre-line">{{ v }}</dd></div>
        </dl>
      </div>
      <aside class="grid content-start gap-4 text-sm" aria-label="About this report">
        <div class="rounded-md border border-line bg-surface p-4">
          <p class="font-semibold">Observed {{ formatDay(c.observed_on) }}</p>
          <p class="text-muted">{{ ageInWords(c.observed_on) }}. Shared {{ formatDay(c.submitted_at) }}.</p>
          <p class="mt-3"><span class="font-semibold">{{ contributionStatusLabels[c.status] }}.</span> Publication means it passed review, not that conditions are the same today.</p>
          <a v-if="c.source_url" :href="c.source_url" rel="noopener noreferrer" target="_blank" class="link mt-2 inline-block">Official source</a>
        </div>
        <p v-if="c.status === 'outdated'" class="flex gap-2 rounded-md border border-warning/40 bg-surface p-3"><TriangleAlert class="size-4 shrink-0 text-warning" aria-hidden="true" /> Marked outdated. Kept for history.</p>
        <div v-if="c.segment || c.route || c.destination" class="rounded-md border border-line p-4">
          <p class="label text-muted">Where</p>
          <RouterLink v-if="c.route" :to="`/routes/${c.route.slug}`" class="link mt-1 block">{{ c.route.name }}</RouterLink>
          <p v-if="c.segment" class="text-muted">{{ c.segment.start_name }} to {{ c.segment.end_name }}</p>
          <RouterLink v-if="c.destination" :to="`/explore/${c.destination.slug}`" class="link mt-1 block">{{ c.destination.name }}</RouterLink>
        </div>
        <RouterLink v-if="user && user.id === c.author?.id && c.status === 'pending_review'" :to="`/experiences/${c.id}/edit`" class="btn btn-quiet justify-self-start"><Pencil class="size-4" aria-hidden="true" /> Edit while it waits for review</RouterLink>
        <template v-if="user && user.id !== c.author?.id && c.status !== 'pending_review'">
          <button v-if="!reporting" type="button" class="link inline-flex items-center gap-1.5 justify-self-start" @click="reporting = true"><Flag class="size-4" aria-hidden="true" /> Report a problem</button>
          <form v-else class="grid gap-2" @submit.prevent="report">
            <label for="reason" class="font-semibold">What is wrong?</label>
            <textarea id="reason" v-model="reason" class="input" rows="3" maxlength="1000" placeholder="For example: the bridge was rebuilt in March." />
            <button type="submit" class="btn btn-quiet justify-self-start" :disabled="reason.trim().length < 10">Send to moderators</button>
          </form>
        </template>
        <p v-if="reportMsg" role="status">{{ reportMsg }}</p>
      </aside>
    </article>
  </div>
</template>
