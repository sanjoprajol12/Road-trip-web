<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { CalendarPlus, Users } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'

import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { eventStatusLabels, eventTime, registrationLabels } from '../labels'
import { listEvents, myEvents } from '../services/events'

const { user } = useAuth()
const category = ref<'' | 'ride' | 'gathering'>('')
const events = useAsync(() => listEvents(category.value || undefined))
watch(category, () => void events.run())
// The rider's own events: ones they host (drafts included) and ones they joined, were invited to or wait for.
const mine = useAsync(async () => (user.value ? await myEvents() : null))
watch(user, () => void mine.run())
const canHost = () => user.value?.verified.some((v) => v.kind === 'event_organizer')
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div>
        <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">Events and rides</h1>
        <p class="lead mt-5 max-w-xl text-muted">Group rides and rider gatherings, hosted by verified organizers.</p>
      </div>
      <RouterLink v-if="canHost()" to="/events/new" class="btn btn-primary"><CalendarPlus class="size-4" aria-hidden="true" /> Host an event</RouterLink>
      <RouterLink v-else-if="user" to="/account#verify-title" class="link text-sm">Want to host? Apply as an organizer</RouterLink>
    </div>

    <section v-if="user" class="mt-12" aria-labelledby="mine-title">
      <h2 id="mine-title" class="display text-3xl">Your events</h2>
      <StatusPanel v-if="mine.loading.value" state="loading" title="Loading your events" />
      <StatusPanel v-else-if="mine.error.value" state="error" :message="mine.error.value" @retry="mine.run" />
      <p v-else-if="!mine.data.value?.organizing.length && !mine.data.value?.participating.length" class="mt-3 text-sm text-muted">You are not hosting or registered for any event yet.</p>
      <div v-else class="mt-4 grid gap-8 md:grid-cols-2">
        <div v-for="[key, title] in ([['organizing', 'Hosting'], ['participating', 'Taking part']] as const)" :key="key">
          <h3 class="label text-muted">{{ title }}</h3>
          <p v-if="!mine.data.value?.[key].length" class="mt-2 text-sm text-muted">None.</p>
          <ul v-else class="mt-2 grid border-t border-line text-sm" :aria-label="title">
            <li v-for="e in mine.data.value?.[key]" :key="e.id" class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line py-3">
              <span>
                <RouterLink :to="key === 'organizing' ? `/events/${e.id}/manage` : `/events/${e.id}`" class="link font-semibold">{{ e.title }}</RouterLink>
                <span class="block text-xs text-muted">{{ eventTime(e.starts_at) }} · {{ eventStatusLabels[e.status] }}</span>
              </span>
              <span v-if="key === 'participating' && e.my_registration" class="font-semibold">{{ registrationLabels[e.my_registration] }}</span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <h2 v-if="user" class="display mt-14 text-3xl">Upcoming public events</h2>
    <div class="mt-8 flex gap-2" role="group" aria-label="Show">
      <button v-for="[v, l] in [['', 'Everything'], ['ride', 'Rides'], ['gathering', 'Gatherings']]" :key="v" type="button" class="btn min-h-9 px-3 text-sm" :class="category === v ? 'bg-ink text-surface' : 'btn-quiet'" :aria-pressed="category === v" @click="category = v as typeof category">{{ l }}</button>
    </div>

    <section class="mt-6" aria-label="Upcoming events">
      <StatusPanel v-if="events.loading.value" state="loading" title="Loading events" />
      <StatusPanel v-else-if="events.error.value" state="error" :message="events.error.value" @retry="events.run" />
      <StatusPanel v-else-if="!events.data.value?.length" state="empty" title="No upcoming public events" message="Verified organizers publish rides and gatherings here." />
      <ul v-else class="grid border-t border-line">
        <li v-for="e in events.data.value" :key="e.id" class="border-b border-line">
          <RouterLink :to="`/events/${e.id}`" class="group grid gap-2 py-6 md:grid-cols-[14rem_1fr_10rem] md:items-baseline md:gap-8">
            <span class="tabular text-sm font-semibold">{{ eventTime(e.starts_at) }}</span>
            <span>
              <span class="label text-muted">{{ e.category === 'ride' ? 'Ride' : 'Gathering' }} · {{ e.subtype }}</span>
              <span class="display mt-1 block text-3xl group-hover:underline">{{ e.title }}</span>
              <span class="text-sm text-muted">Hosted by {{ e.organizer.display_name }} · meet at {{ e.meeting_point }}</span>
            </span>
            <span class="flex items-center gap-1.5 text-sm"><Users class="size-4 text-muted" aria-hidden="true" />{{ e.participant_count }}<template v-if="e.capacity"> / {{ e.capacity }}</template> · {{ eventStatusLabels[e.status] }}</span>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
