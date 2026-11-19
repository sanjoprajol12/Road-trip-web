<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { Bell } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'

import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { contributionKindLabels, contributionStatusLabels } from '@/features/experiences/labels'
import type { AppNotification } from '@/types/api'
import { markNotificationsRead, myContributions, myNotifications } from '../services/account'

const mine = useAsync(() => myContributions())
const notes = useAsync(() => myNotifications<AppNotification>())

async function markRead() {
  await markNotificationsRead()
  await notes.run()
}

function describe(n: AppNotification): string {
  const d = n.data
  if (d.kind === 'verification_decided') return `Your ${String(d.claim_kind).replace('_', ' ')} claim is now ${d.status}.`
  if (d.kind === 'event_invitation') return `You are invited to ${d.title}.`
  if (d.kind === 'event_cancellation') return `${d.title} was cancelled.`
  if (d.kind === 'event_postponement') return `${d.title} was postponed.`
  if (d.kind === 'event_registration' && d.change === 'promoted') return `A place opened up: you are in for ${d.title}.`
  if (d.kind === 'event_registration' && d.change === 'approved') return `The organizer approved your place on ${d.title}.`
  if (d.kind === 'event_registration' && d.change === 'declined') return `The organizer declined your request for ${d.title}.`
  return `${d.title ?? 'An event'} has an update.`
}
</script>

<template>
  <section id="activity" class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="activity-title">
    <h2 id="activity-title" class="display text-3xl">Notifications</h2>
    <StatusPanel v-if="notes.loading.value" state="loading" title="Loading notifications" />
    <StatusPanel v-else-if="notes.error.value" state="error" :message="notes.error.value" @retry="notes.run" />
    <template v-else>
      <p v-if="!notes.data.value?.data.length" class="mt-4 text-sm text-muted">Nothing yet.</p>
      <ul v-else class="mt-4 grid border-t border-line text-sm">
        <li v-for="n in notes.data.value.data" :key="n.id" class="flex gap-3 border-b border-line py-3" :class="n.read_at ? 'text-muted' : ''">
          <Bell class="mt-0.5 size-4 shrink-0" :class="n.read_at ? '' : 'text-crimson'" aria-hidden="true" />
          <div class="grid flex-1 gap-0.5">
            <RouterLink v-if="n.data.link" :to="n.data.link" class="link">{{ describe(n) }}</RouterLink>
            <span v-else>{{ describe(n) }}</span>
            <span v-if="n.data.note || n.data.body" class="text-muted">“{{ n.data.note ?? n.data.body }}”</span>
            <span class="text-xs text-muted">{{ formatDay(n.created_at) }}<template v-if="!n.read_at"> · new</template></span>
          </div>
        </li>
      </ul>
      <button v-if="notes.data.value?.unread" type="button" class="btn btn-quiet mt-4" @click="markRead">Mark all as read</button>
    </template>

    <h2 class="display mt-14 text-3xl">Your contributions</h2>
    <StatusPanel v-if="mine.loading.value" state="loading" title="Loading contributions" />
    <StatusPanel v-else-if="mine.error.value" state="error" :message="mine.error.value" @retry="mine.run" />
    <template v-else>
      <p v-if="!mine.data.value?.length" class="mt-4 text-sm text-muted">
        You have not shared anything yet. <RouterLink to="/contribute" class="link font-semibold text-ink">Share what you found on the road</RouterLink>
      </p>
      <ul v-else class="mt-4 grid border-t border-line text-sm" aria-label="Your contributions">
        <li v-for="c in mine.data.value" :key="c.id" class="grid gap-1 border-b border-line py-3">
          <div class="flex flex-wrap justify-between gap-2">
            <RouterLink :to="`/experiences/${c.id}`" class="link font-semibold">{{ c.title }}</RouterLink>
            <span class="flex items-center gap-3">
              <RouterLink v-if="c.status === 'pending_review'" :to="`/experiences/${c.id}/edit`" class="link text-sm" :aria-label="`Edit ${c.title}`">Edit</RouterLink>
              <span class="font-semibold">{{ contributionStatusLabels[c.status] }}</span>
            </span>
          </div>
          <span class="text-xs text-muted">{{ contributionKindLabels[c.kind] }} · observed {{ formatDay(c.observed_on) }} · sent {{ formatDay(c.submitted_at) }}</span>
          <span v-if="c.moderation_note" class="rounded-md bg-surface px-3 py-2"><span class="font-semibold">Moderator note:</span> {{ c.moderation_note }}</span>
        </li>
      </ul>
    </template>
  </section>
</template>
