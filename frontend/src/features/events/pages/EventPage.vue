<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, Lock, Users } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import VerifiedBadges from '@/components/ui/VerifiedBadges.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { formatDay } from '@/lib/format'
import { eventStatusLabels, eventTime, registrationLabels } from '../labels'
import { getEvent, joinOrLeave } from '../services/events'

const props = defineProps<{ id: string }>()
const { user } = useAuth()
const ev = useAsync(async () => {
  try {
    return await getEvent(props.id)
  } catch (e) {
    if (statusOf(e) === 403 || statusOf(e) === 404) return null
    throw e
  }
})
const e = computed(() => ev.data.value)
const open = computed(() => e.value && ['published', 'full'].includes(e.value.status))
const joined = computed(() => e.value?.my_registration && ['approved', 'pending', 'waitlisted'].includes(e.value.my_registration))
const busy = ref(false)
const message = ref<string | null>(null)
const failure = ref<string | null>(null)

async function act(action: 'join' | 'leave') {
  busy.value = true
  message.value = failure.value = null
  try {
    const data = await joinOrLeave(props.id, action)
    message.value = action === 'leave' ? 'You are no longer registered.' : data.status === 'approved' ? 'You are going.' : data.status === 'pending' ? 'Request sent. The organizer will decide.' : 'The event is full. You are on the waitlist and move up automatically if a place frees up.'
    await ev.run()
  } catch (err) {
    failure.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/events" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> All events</RouterLink>
    <StatusPanel v-if="ev.loading.value" class="mt-6" state="loading" title="Loading event" />
    <StatusPanel v-else-if="ev.error.value" class="mt-6" state="error" :message="ev.error.value" @retry="ev.run" />
    <template v-else-if="!e">
      <h1 class="display mt-8 text-5xl">Event not available</h1>
      <StatusPanel class="mt-6" state="empty" title="It does not exist, or it is private" message="Private events are visible only to the organizer and invited riders." />
    </template>
    <article v-else class="mt-8 grid gap-12 lg:grid-cols-[1fr_22rem]">
      <div class="min-w-0">
        <p class="label text-muted">
          {{ e.category === 'ride' ? 'Ride' : 'Gathering' }} · {{ e.subtype }}
          <span v-if="e.visibility === 'private'" class="inline-flex items-center gap-1"><Lock class="size-3" aria-hidden="true" /> Private</span>
        </p>
        <h1 class="display mt-3 text-[clamp(2.5rem,1.8rem+3vw,4.5rem)]">{{ e.title }}</h1>
        <p v-if="['postponed', 'cancelled'].includes(e.status)" class="mt-4 rounded-md border border-warning/40 bg-surface px-4 py-3 font-semibold" role="alert">This event is {{ eventStatusLabels[e.status].toLowerCase() }}.</p>
        <p class="prose-serif mt-8 max-w-prose whitespace-pre-line">{{ e.description }}</p>
        <section v-if="e.summary" class="mt-8"><h2 class="font-semibold">How it went</h2><p class="mt-2 whitespace-pre-line">{{ e.summary }}</p></section>
        <section v-if="e.updates?.length" class="mt-10" aria-labelledby="upd">
          <h2 id="upd" class="display text-2xl">Updates from the organizer</h2>
          <ol class="mt-4 grid gap-3">
            <li v-for="u in e.updates" :key="u.id" class="rounded-md border border-line bg-surface p-4 text-sm">
              <p class="label text-muted">{{ u.kind === 'update' ? 'Update' : u.kind }} · {{ formatDay(u.created_at) }}</p>
              <p class="mt-1 whitespace-pre-line">{{ u.body }}</p>
            </li>
          </ol>
        </section>
      </div>
      <aside class="grid content-start gap-4 text-sm" aria-label="Event details">
        <dl class="grid gap-3 rounded-md border border-line bg-surface p-4">
          <div><dt class="label text-muted">Starts</dt><dd>{{ eventTime(e.starts_at) }}</dd></div>
          <div><dt class="label text-muted">Ends</dt><dd>{{ eventTime(e.ends_at) }}</dd></div>
          <div><dt class="label text-muted">Meeting point</dt><dd>{{ e.meeting_point }}</dd></div>
          <div v-if="e.route"><dt class="label text-muted">Route</dt><dd><RouterLink :to="`/routes/${e.route.slug}`" class="link">{{ e.route.name }}</RouterLink></dd></div>
          <div><dt class="label text-muted">Riders</dt><dd class="flex items-center gap-1.5"><Users class="size-4" aria-hidden="true" />{{ e.participant_count }}<template v-if="e.capacity"> of {{ e.capacity }} places</template> · {{ eventStatusLabels[e.status] }}</dd></div>
          <div><dt class="label text-muted">Joining</dt><dd>{{ e.join_method === 'instant' ? 'Join instantly while places last' : 'The organizer approves each request' }}<template v-if="e.waitlist_enabled">, with a waitlist</template></dd></div>
          <div><dt class="label text-muted">Organizer</dt><dd><RouterLink v-if="e.organizer.username" :to="`/riders/${e.organizer.username}`" class="link font-semibold">{{ e.organizer.display_name }}</RouterLink><span v-else>{{ e.organizer.display_name }}</span><br /><VerifiedBadges :claims="e.organizer.verified" /></dd></div>
        </dl>
        <p v-if="e.my_registration" class="font-semibold">Your status: {{ registrationLabels[e.my_registration] }}</p>
        <p v-if="message" class="text-success" role="status">{{ message }}</p>
        <p v-if="failure" class="text-danger" role="alert">{{ failure }}</p>
        <template v-if="e.is_organizer">
          <RouterLink :to="`/events/${e.id}/manage`" class="btn btn-primary">Manage this event</RouterLink>
        </template>
        <template v-else-if="!user">
          <RouterLink :to="{ path: '/sign-in', query: { next: `/events/${e.id}` } }" class="btn btn-primary">Sign in to join</RouterLink>
        </template>
        <template v-else-if="open">
          <button v-if="joined" type="button" class="btn btn-quiet" :disabled="busy" @click="act('leave')">{{ e.my_registration === 'pending' ? 'Withdraw request' : 'Leave event' }}</button>
          <button v-else-if="e.my_registration === 'invited'" type="button" class="btn btn-primary" :disabled="busy" @click="act('join')">Accept invitation</button>
          <button v-else type="button" class="btn btn-primary" :disabled="busy" @click="act('join')">{{ e.join_method === 'instant' ? 'Join this event' : 'Request to join' }}</button>
          <button v-if="e.my_registration === 'invited'" type="button" class="btn btn-quiet" :disabled="busy" @click="act('leave')">Decline invitation</button>
        </template>
      </aside>
    </article>
  </div>
</template>
