<script setup lang="ts">
import SaveButton from '@/features/saved/components/SaveButton.vue'
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, TriangleAlert } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import VerifiedBadges from '@/components/ui/VerifiedBadges.vue'
import { statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { ageInWords, formatDay } from '@/lib/format'
import { contributionKindLabels, contributionStatusLabels, surfaceLabels } from '../labels'
import { getContribution } from '../services/contributions'
import ContributionActions from '../components/ContributionActions.vue'
import SourceList from '@/components/ui/SourceList.vue'

const props = defineProps<{ id: string }>()
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

</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/experiences" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All experiences</RouterLink>
    <!-- Refreshes after a rider action keep the page (and its messages) on screen. -->
    <StatusPanel v-if="item.loading.value && !c" class="mt-6" state="loading" title="Loading" />
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
        <section v-if="c.author_correction" class="mt-6 max-w-prose border-l-2 border-warning pl-4" aria-label="Correction by the author">
          <p class="label text-muted">Correction by the author, {{ formatDay(c.author_corrected_at) }}<template v-if="c.correction_pending_review"> (waiting for review, only you see it)</template></p>
          <p class="mt-2 whitespace-pre-line">{{ c.author_correction }}</p>
        </section>
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
          <p v-if="c.last_confirmed_on" class="mt-2">Riders or moderators said it was still accurate on {{ formatDay(c.last_confirmed_on) }} ({{ ageInWords(c.last_confirmed_on) }}).</p>
          <p class="mt-3"><span class="font-semibold">{{ contributionStatusLabels[c.status] }}.</span> Publication means it passed review, not that conditions are the same today.</p>
          <a v-if="c.source_url && !c.sources?.length" :href="c.source_url" rel="noopener noreferrer" target="_blank" class="link mt-2 inline-block">Official source</a>
        </div>
        <SourceList :sources="c.sources" />
        <p v-if="c.status === 'outdated'" class="flex gap-2 rounded-md border border-warning/40 bg-surface p-3"><TriangleAlert class="size-4 shrink-0 text-warning" aria-hidden="true" /> Marked outdated. Kept for history.</p>
        <div v-if="c.segment || c.route || c.destination" class="rounded-md border border-line p-4">
          <p class="label text-muted">Where</p>
          <RouterLink v-if="c.route" :to="`/routes/${c.route.slug}`" class="link mt-1 block">{{ c.route.name }}</RouterLink>
          <p v-if="c.segment" class="text-muted">{{ c.segment.start_name }} to {{ c.segment.end_name }}</p>
          <RouterLink v-if="c.destination" :to="`/explore/${c.destination.slug}`" class="link mt-1 block">{{ c.destination.name }}</RouterLink>
        </div>
        <SaveButton v-if="['published', 'community_corroborated', 'officially_sourced', 'outdated'].includes(c.status)" class="justify-self-start" type="contribution" :id="c.id" :name="c.title" />
        <ContributionActions :c="c" @changed="item.run" />
      </aside>
    </article>
  </div>
</template>
