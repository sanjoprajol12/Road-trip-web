<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { eventStatusLabels, registrationLabels } from '../labels'
import type { EventStatus, Registration } from '@/types/api'
import { changeEventStatus, decideRegistration, getEvent, inviteRider, listRegistrations, postEventUpdate } from '../services/events'

const props = defineProps<{ id: string }>()
const ev = useAsync(() => getEvent(props.id))
const regs = useAsync(() => listRegistrations<Registration>(props.id))
const msg = ref<string | null>(null)
const err = ref<string | null>(null)
async function run(fn: () => Promise<unknown>, ok: string) {
  msg.value = err.value = null
  try {
    await fn()
    msg.value = ok
    await Promise.all([ev.run(), regs.run()])
  } catch (e) {
    err.value = errorMessage(e)
  }
}
function confirmRemove(r: Registration) {
  if (window.confirm(`Remove ${r.rider.display_name}? Their place goes to the next rider on the waitlist, and they are told.`)) void decide(r, 'declined')
}
const decide = (r: Registration, status: string) => run(() => decideRegistration(props.id, r.id, status), `${r.rider.display_name}: ${registrationLabels[status as keyof typeof registrationLabels]}.`)
const invitee = ref('')
const invite = () => run(() => inviteRider(props.id, invitee.value.trim()), `Invited ${invitee.value}.`).then(() => (invitee.value = ''))
const update = ref('')
const postUpdate = () => run(() => postEventUpdate(props.id, update.value), 'Update posted and participants notified.').then(() => (update.value = ''))

const nextStatuses: Record<EventStatus, EventStatus[]> = { draft: ['published', 'cancelled'], published: ['postponed', 'cancelled', 'completed'], full: ['postponed', 'cancelled', 'completed'], postponed: ['published', 'cancelled'], cancelled: [], completed: [] }
const target = ref<EventStatus | ''>('')
const statusMessage = ref('')
const newStart = ref('')
const newEnd = ref('')
const needsMessage = computed(() => target.value === 'postponed' || target.value === 'cancelled')
const changeStatus = () =>
  run(() => changeEventStatus(props.id, { status: target.value, message: statusMessage.value || undefined, summary: target.value === 'completed' ? statusMessage.value || undefined : undefined,
    ...(target.value === 'postponed' && newStart.value ? { starts_at: new Date(newStart.value).toISOString(), ends_at: newEnd.value ? new Date(newEnd.value).toISOString() : undefined } : {}) }), 'Status changed.').then(() => {
    target.value = ''
    statusMessage.value = ''
    newStart.value = newEnd.value = ''
  })
const started = computed(() => ev.data.value && new Date(ev.data.value.starts_at) < new Date())
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink :to="`/events/${id}`" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> Event page</RouterLink>
    <StatusPanel v-if="ev.loading.value" class="mt-6" state="loading" title="Loading" />
    <StatusPanel v-else-if="ev.error.value || !ev.data.value" class="mt-6" state="error" :message="ev.error.value" @retry="ev.run" />
    <template v-else>
      <h1 class="display mt-6 text-[clamp(2.5rem,1.8rem+3vw,4rem)]">{{ ev.data.value.title }}</h1>
      <p class="mt-2 text-muted">Status: <span class="font-semibold text-ink">{{ eventStatusLabels[ev.data.value.status] }}</span> · {{ ev.data.value.participant_count }} going<template v-if="ev.data.value.capacity"> of {{ ev.data.value.capacity }}</template></p>
      <p v-if="msg" class="mt-4 text-success" role="status">{{ msg }}</p>
      <p v-if="err" class="mt-4 text-danger" role="alert">{{ err }}</p>

      <div class="mt-10 grid gap-12 lg:grid-cols-[1fr_22rem]">
        <section aria-labelledby="ppl">
          <h2 id="ppl" class="display text-2xl">Participants</h2>
          <StatusPanel v-if="regs.loading.value" state="loading" title="Loading participants" />
          <StatusPanel v-else-if="regs.error.value" state="error" :message="regs.error.value" @retry="regs.run" />
          <p v-else-if="!regs.data.value?.length" class="mt-4 text-sm text-muted">Nobody yet.</p>
          <ul v-else class="mt-4 grid border-t border-line text-sm">
            <li v-for="r in regs.data.value" :key="r.id" class="flex flex-wrap items-center gap-3 border-b border-line py-3">
              <span class="flex-1 font-semibold">{{ r.rider.display_name }}<span v-if="r.rider.username" class="font-normal text-muted"> @{{ r.rider.username }}</span></span>
              <span>{{ registrationLabels[r.status] }}</span>
              <template v-if="r.status === 'pending' || r.status === 'waitlisted'">
                <button type="button" class="btn btn-quiet min-h-9 px-3" @click="decide(r, 'approved')">Approve</button>
                <button type="button" class="btn btn-quiet min-h-9 px-3" @click="decide(r, 'declined')">Decline</button>
              </template>
              <button v-if="!started && r.status === 'approved'" type="button" class="btn btn-quiet min-h-9 px-3" :aria-label="`Remove ${r.rider.display_name} from the event`" @click="confirmRemove(r)">Remove</button>
              <template v-if="started && ['approved', 'attended', 'did_not_attend'].includes(r.status)">
                <button type="button" class="btn btn-quiet min-h-9 px-3" :aria-pressed="r.status === 'attended'" @click="decide(r, 'attended')">Attended</button>
                <button type="button" class="btn btn-quiet min-h-9 px-3" :aria-pressed="r.status === 'did_not_attend'" @click="decide(r, 'did_not_attend')">Did not attend</button>
              </template>
            </li>
          </ul>
        </section>

        <aside class="grid content-start gap-8 text-sm">
          <RouterLink v-if="['draft', 'published', 'postponed', 'full'].includes(ev.data.value.status)" :to="`/events/${id}/edit`" class="btn btn-quiet">Edit details</RouterLink>
          <form v-if="nextStatuses[ev.data.value.status].length" class="grid gap-3" @submit.prevent="changeStatus">
            <h2 class="font-semibold">Change status</h2>
            <select v-model="target" class="input" aria-label="New status">
              <option value="" disabled>Choose</option>
              <option v-for="s in nextStatuses[ev.data.value.status]" :key="s" :value="s">{{ s === 'published' ? 'Publish' : eventStatusLabels[s] }}</option>
            </select>
            <textarea v-if="target && target !== 'published'" v-model="statusMessage" class="input" rows="3" :aria-label="target === 'completed' ? 'Summary of how it went' : 'Message to participants'" :placeholder="target === 'completed' ? 'How it went (optional)' : 'Tell participants why (required)'" />
            <div v-if="target === 'postponed'" class="grid gap-2 sm:grid-cols-2">
              <div class="field"><label for="pp-start" class="text-xs">New start (optional)</label><input id="pp-start" v-model="newStart" type="datetime-local" class="input" /></div>
              <div class="field"><label for="pp-end" class="text-xs">New end</label><input id="pp-end" v-model="newEnd" type="datetime-local" class="input" :required="!!newStart" /></div>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="!target || (needsMessage && statusMessage.trim().length < 10)">Apply</button>
          </form>
          <form class="grid gap-3" @submit.prevent="invite">
            <h2 class="font-semibold">Invite a rider</h2>
            <input v-model="invitee" class="input" placeholder="Their username" aria-label="Username to invite" autocapitalize="none" />
            <button type="submit" class="btn btn-quiet" :disabled="invitee.trim().length < 3">Send invitation</button>
          </form>
          <form class="grid gap-3" @submit.prevent="postUpdate">
            <h2 class="font-semibold">Post an update</h2>
            <textarea v-model="update" class="input" rows="3" aria-label="Update for participants" />
            <button type="submit" class="btn btn-quiet" :disabled="update.trim().length < 5">Post and notify</button>
          </form>
        </aside>
      </div>
    </template>
  </div>
</template>
