<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { CalendarPlus, Users } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { api } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { eventStatusLabels, eventTime } from '../labels'
import type { Paginated, RideEvent } from '@/types/api'

const { user } = useAuth()
const category = ref<'' | 'ride' | 'gathering'>('')
const events = useAsync(async () => (await api.get<Paginated<RideEvent>>('/events', { params: { category: category.value || undefined } })).data.data)
watch(category, () => void events.run())
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
