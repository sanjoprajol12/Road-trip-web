<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { CalendarClock, CircleCheck, Download } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { useAuth } from '@/features/authentication/useAuth'
import { contributionKindLabels } from '@/features/experiences/labels'
import type { ContributionKind } from '@/types/api'
import {
  cancelDeletion,
  deletionSummary,
  downloadExport,
  offerHandover,
  requestDeletion,
  withdrawHandover,
  type DeletionChoice,
} from '../services/account'

const { refresh } = useAuth()
const summary = useAsync(() => deletionSummary())
const scheduled = computed(() => summary.data.value?.request ?? null)
const eligible = computed(() => (summary.data.value?.contributions ?? []).filter((c) => c.eligible))
const alwaysDeleted = computed(() => (summary.data.value?.contributions ?? []).filter((c) => !c.eligible))
const handoverEvents = computed(() => (summary.data.value?.events ?? []).filter((e) => e.outcome === 'transfer_or_cancel'))
const groupTrips = computed(() => (summary.data.value?.trips ?? []).filter((t) => t.outcome === 'transfer_or_delete'))
const otherItems = computed(() => (summary.data.value?.events ?? []).filter((e) => e.outcome === 'delete').length + (summary.data.value?.trips ?? []).filter((t) => t.outcome === 'delete').length)
const deletionDate = computed(() => new Date(Date.now() + (summary.data.value?.cooling_off_days ?? 30) * 86_400_000).toISOString())

const choiceText: Record<DeletionChoice, { label: string; help: string }> = {
  delete: { label: 'Delete', help: 'Removed from the site, with its photos.' },
  anonymise: { label: 'Keep anonymously', help: 'Stays on the site under "Former rider". Your name and profile link are removed; the text and photos stay.' },
  anonymise_without_photos: { label: 'Keep anonymously, remove photos', help: 'Stays under "Former rider", without its photos.' },
}

// ---------------------------------------------------------------- export
const exportPassword = ref('')
const exportBusy = ref(false)
const exportError = ref<string | null>(null)
const exported = ref(false)
async function exportData() {
  exportBusy.value = true
  exportError.value = null
  exported.value = false
  try {
    const blob = await downloadExport(exportPassword.value)
    const url = URL.createObjectURL(blob)
    const a = Object.assign(document.createElement('a'), { href: url, download: `my-ride-nepal-data-${new Date().toISOString().slice(0, 10)}.zip` })
    document.body.append(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
    exportPassword.value = ''
    exported.value = true
  } catch (e) {
    exportError.value = fieldErrors(e).password ?? errorMessage(e)
  } finally {
    exportBusy.value = false
  }
}

// ---------------------------------------------------------------- request
const formOpen = ref(false)
const choices = reactive<Record<string, DeletionChoice | undefined>>({})
const understood = ref(false)
const password = ref('')
const touched = ref(false)
const busy = ref(false)
const requestError = ref<string | null>(null)
const missing = computed(() => eligible.value.filter((c) => !choices[c.id]).length)

async function submitRequest() {
  touched.value = true
  requestError.value = null
  if (missing.value || !understood.value || !password.value) return
  busy.value = true
  try {
    await requestDeletion(password.value, Object.fromEntries(Object.entries(choices).filter(([, v]) => v)) as Record<string, DeletionChoice>)
    password.value = ''
    formOpen.value = false
    await Promise.all([summary.run(), refresh()])
  } catch (e) {
    const errs = fieldErrors(e)
    requestError.value = errs.password ?? errs.choices ?? errs.confirm ?? errorMessage(e)
  } finally {
    busy.value = false
  }
}

// ---------------------------------------------------------------- cancel
const cancelPassword = ref('')
const cancelBusy = ref(false)
const cancelError = ref<string | null>(null)
const cancelled = ref<string | null>(null)
async function cancel() {
  cancelBusy.value = true
  cancelError.value = null
  try {
    cancelled.value = await cancelDeletion(cancelPassword.value)
    cancelPassword.value = ''
    await Promise.all([summary.run(), refresh()])
  } catch (e) {
    cancelError.value = fieldErrors(e).password ?? errorMessage(e)
  } finally {
    cancelBusy.value = false
  }
}

// ---------------------------------------------------------------- handovers (after the request)
const offerTo = reactive<Record<string, string>>({})
const offerError = reactive<Record<string, string | null>>({})
const offerBusy = ref<string | null>(null)
async function offer(type: 'event' | 'trip', id: string) {
  offerBusy.value = id
  offerError[id] = null
  try {
    await offerHandover(type, id, (offerTo[id] ?? '').trim())
    offerTo[id] = ''
    await summary.run()
  } catch (e) {
    const errs = fieldErrors(e)
    offerError[id] = errs.username ?? errs.subject_id ?? errorMessage(e)
  } finally {
    offerBusy.value = null
  }
}
async function withdraw(subjectId: string, offerId: string) {
  offerBusy.value = subjectId
  try {
    await withdrawHandover(offerId)
    await summary.run()
  } catch (e) {
    offerError[subjectId] = errorMessage(e)
  } finally {
    offerBusy.value = null
  }
}
const kindLabel = (k: string) => contributionKindLabels[k as ContributionKind] ?? k
</script>

<template>
  <section id="delete" class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="delete-title">
    <h2 id="delete-title" class="display text-3xl">Your data</h2>

    <h3 class="mt-6 font-semibold">Download your data</h3>
    <p class="mt-1 text-sm text-muted">
      One ZIP file with your profile, contributions and their photos, trips, events, places, saved items, emergency contacts and notifications. It is made when you ask
      and is not kept on our servers.
    </p>
    <form class="mt-4 flex flex-wrap items-end gap-3" novalidate @submit.prevent="exportData">
      <div class="field">
        <label for="export_password">Your password</label>
        <input id="export_password" v-model="exportPassword" type="password" class="input sm:w-64" autocomplete="current-password" :aria-invalid="!!exportError" aria-describedby="export-err" />
      </div>
      <button type="submit" class="btn btn-quiet" :disabled="exportBusy || !exportPassword" :aria-busy="exportBusy">
        <Download class="size-4" aria-hidden="true" /> {{ exportBusy ? 'Preparing…' : 'Download my data' }}
      </button>
    </form>
    <p v-if="exportError" id="export-err" class="feedback-in field-error mt-2" role="alert">{{ exportError }}</p>
    <p v-if="exported" class="feedback-in mt-2 flex items-center gap-1.5 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> Your download has started.</p>

    <h3 class="mt-12 font-semibold">Account deletion</h3>
    <p v-if="cancelled" class="feedback-in mt-3 flex items-center gap-1.5 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> {{ cancelled }}</p>
    <StatusPanel v-if="summary.loading.value && !summary.data.value" state="loading" title="Loading your account details" />
    <StatusPanel v-else-if="summary.error.value" state="error" :message="summary.error.value" @retry="summary.run" />

    <!-- In the cooling-off -->
    <template v-else-if="scheduled">
      <div class="mt-3 rounded-md border border-warning/40 bg-surface px-4 py-4 text-sm" role="status">
        <p class="flex items-center gap-2 font-semibold"><CalendarClock class="size-4" aria-hidden="true" /> Your account will be deleted on {{ formatDay(scheduled.run_after) }}.</p>
        <p class="mt-2">
          Until then you can read, download your data and hand over your events and trips, but you cannot post, confirm, join or be invited. Signing in does not cancel
          the deletion: only the button below does.
        </p>
      </div>

      <form class="mt-5 flex flex-wrap items-end gap-3" novalidate @submit.prevent="cancel">
        <div class="field">
          <label for="cancel_password">Your password</label>
          <input id="cancel_password" v-model="cancelPassword" type="password" class="input sm:w-64" autocomplete="current-password" :aria-invalid="!!cancelError" aria-describedby="cancel-err" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="cancelBusy || !cancelPassword" :aria-busy="cancelBusy">{{ cancelBusy ? 'Cancelling…' : 'Cancel deletion' }}</button>
      </form>
      <p v-if="cancelError" id="cancel-err" class="feedback-in field-error mt-2" role="alert">{{ cancelError }}</p>

      <template v-if="handoverEvents.length || groupTrips.length">
        <h4 class="mt-10 font-semibold">Hand over before {{ formatDay(scheduled.run_after) }}</h4>
        <p class="mt-1 text-sm text-muted">
          Nothing changes hands unless the other rider accepts. Without an accepted handover, events are cancelled and their riders told; group trips are deleted and their
          members told.
        </p>
        <ul class="mt-4 grid gap-4">
          <li v-for="e in handoverEvents" :key="e.id" class="rounded-md border border-line p-4">
            <p class="font-semibold text-balance wrap-anywhere">{{ e.title }}</p>
            <p class="text-sm text-muted">Event on {{ formatDay(e.starts_at) }} · <span class="tabular-nums">{{ e.participants }}</span> {{ e.participants === 1 ? 'rider' : 'riders' }} taking part</p>
            <p v-if="e.offer?.status === 'offered'" class="mt-2 text-sm">
              Offered to {{ e.offer.to.display_name }}, waiting for an answer.
              <button type="button" class="link ml-1" :disabled="offerBusy === e.id" @click="withdraw(e.id, e.offer.id)">{{ offerBusy === e.id ? 'Withdrawing…' : 'Withdraw' }}</button>
            </p>
            <template v-else>
              <p v-if="e.offer?.status === 'declined'" class="mt-2 text-sm">{{ e.offer.to.display_name }} declined.</p>
              <form class="mt-3 flex flex-wrap items-end gap-3" novalidate @submit.prevent="offer('event', e.id)">
                <div class="field">
                  <label :for="`to-${e.id}`">Username of an approved event organizer</label>
                  <input :id="`to-${e.id}`" v-model="offerTo[e.id]" class="input sm:w-64" autocapitalize="none" :aria-describedby="`to-err-${e.id}`" />
                </div>
                <button type="submit" class="btn btn-quiet" :disabled="offerBusy === e.id || !offerTo[e.id]" :aria-busy="offerBusy === e.id">{{ offerBusy === e.id ? 'Offering…' : 'Offer the event' }}</button>
              </form>
            </template>
            <p v-if="offerError[e.id]" :id="`to-err-${e.id}`" class="feedback-in field-error mt-2" role="alert">{{ offerError[e.id] }}</p>
          </li>
          <li v-for="t in groupTrips" :key="t.id" class="rounded-md border border-line p-4">
            <p class="font-semibold text-balance wrap-anywhere">{{ t.title }}</p>
            <p class="text-sm text-muted">Group trip · <span class="tabular-nums">{{ t.members.length }}</span> other {{ t.members.length === 1 ? 'member' : 'members' }}</p>
            <p v-if="t.offer?.status === 'offered'" class="mt-2 text-sm">
              Offered to {{ t.offer.to.display_name }}, waiting for an answer.
              <button type="button" class="link ml-1" :disabled="offerBusy === t.id" @click="withdraw(t.id, t.offer.id)">{{ offerBusy === t.id ? 'Withdrawing…' : 'Withdraw' }}</button>
            </p>
            <template v-else>
              <p v-if="t.offer?.status === 'declined'" class="mt-2 text-sm">{{ t.offer.to.display_name }} declined.</p>
              <form class="mt-3 flex flex-wrap items-end gap-3" novalidate @submit.prevent="offer('trip', t.id)">
                <div class="field">
                  <label :for="`to-${t.id}`">Member to offer it to</label>
                  <select :id="`to-${t.id}`" v-model="offerTo[t.id]" class="input sm:w-64">
                    <option value="" disabled>Choose a member</option>
                    <option v-for="m in t.members.filter((x) => x.username)" :key="m.username!" :value="m.username!">{{ m.display_name }} ({{ m.role }})</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-quiet" :disabled="offerBusy === t.id || !offerTo[t.id]" :aria-busy="offerBusy === t.id">{{ offerBusy === t.id ? 'Offering…' : 'Offer the trip' }}</button>
              </form>
            </template>
            <p v-if="offerError[t.id]" class="feedback-in field-error mt-2" role="alert">{{ offerError[t.id] }}</p>
          </li>
        </ul>
      </template>
    </template>

    <!-- Not requested -->
    <template v-else-if="summary.data.value">
      <p class="mt-1 text-sm text-muted">
        Your account is deleted {{ summary.data.value.cooling_off_days }} days after you ask. Nothing is removed before then, and you can cancel at any time until that day.
      </p>
      <button v-if="!formOpen" type="button" class="btn btn-quiet mt-4" @click="formOpen = true">Delete my account</button>

      <form v-else class="mt-5 grid gap-6 rounded-md border border-line bg-surface p-5" novalidate @submit.prevent="submitRequest">
        <div class="text-sm">
          <p class="font-semibold">What happens</p>
          <ul class="mt-2 grid list-disc gap-1 pl-5">
            <li>Today: you are signed out on other devices, your profile is hidden, and you can no longer post, confirm, join events or be invited. Your data stays.</li>
            <li>On {{ formatDay(deletionDate) }}: your account, profile, trips you own alone, saved items, emergency contacts and notifications are deleted, and each contribution is handled as you choose below.</li>
            <li>Until then you can download your data and cancel. Signing in alone does not cancel.</li>
          </ul>
        </div>

        <fieldset v-for="c in eligible" :key="c.id" class="grid gap-2">
          <legend class="text-sm">
            <span class="font-semibold wrap-anywhere">{{ c.title }}</span>
            <span class="text-muted"> · {{ kindLabel(c.kind) }}<template v-if="c.photos"> · <span class="tabular-nums">{{ c.photos }}</span> {{ c.photos === 1 ? 'photo' : 'photos' }}</template></span>
          </legend>
          <label v-for="(t, value) in choiceText" :key="value" class="flex cursor-pointer gap-3 rounded-sm border border-line bg-paper p-3 has-[:checked]:border-ink">
            <input v-model="choices[c.id]" type="radio" :name="`choice-${c.id}`" :value="value" class="mt-1 accent-crimson" />
            <span>
              <span class="block font-semibold">{{ t.label }}</span>
              <span class="block text-sm text-muted">{{ t.help }}</span>
            </span>
          </label>
          <p v-if="c.open_case" class="text-sm text-muted">Moderators are reviewing a report about this one: a restricted copy is kept for at most 30 days after the deletion, then removed.</p>
          <p v-if="touched && !choices[c.id]" class="field-error">Choose what happens to this contribution.</p>
        </fieldset>
        <p v-if="eligible.length" class="text-sm">
          Removing your name does not change what the text or photos show. If they would still identify you (your name, face or number plate), choose Delete.
        </p>
        <p v-if="alwaysDeleted.length" class="text-sm text-muted">
          <span class="tabular-nums">{{ alwaysDeleted.length }}</span> {{ alwaysDeleted.length === 1 ? 'contribution that is not public is' : 'contributions that are not public are' }}
          always deleted (waiting for review, rejected or flagged).
        </p>
        <p v-if="handoverEvents.length || groupTrips.length" class="text-sm">
          You organize <span class="tabular-nums">{{ handoverEvents.length }}</span> upcoming {{ handoverEvents.length === 1 ? 'event' : 'events' }} with riders and own
          <span class="tabular-nums">{{ groupTrips.length }}</span> group {{ groupTrips.length === 1 ? 'trip' : 'trips' }}. After you ask, you can offer each one to another
          rider. Nobody takes over without accepting; otherwise events are cancelled and group trips deleted, and everyone taking part is told.
        </p>
        <p v-if="otherItems" class="text-sm text-muted"><span class="tabular-nums">{{ otherItems }}</span> past or solo {{ otherItems === 1 ? 'item is' : 'items are' }} deleted with the account.</p>

        <label class="flex cursor-pointer items-start gap-3 text-sm">
          <input v-model="understood" type="checkbox" class="mt-1 accent-crimson" />
          <span>I understand that my account will be deleted on {{ formatDay(deletionDate) }} unless I cancel before then.</span>
        </label>
        <p v-if="touched && !understood" class="field-error -mt-4">Confirm that you understand.</p>
        <div class="field">
          <label for="delete_password">Your password</label>
          <input id="delete_password" v-model="password" type="password" class="input sm:w-64" autocomplete="current-password" :aria-invalid="touched && !password" />
          <p v-if="touched && !password" class="field-error">Enter your password.</p>
        </div>
        <p v-if="touched && missing" class="feedback-in field-error" role="alert"><span class="tabular-nums">{{ missing }}</span> {{ missing === 1 ? 'contribution still needs' : 'contributions still need' }} a choice.</p>
        <p v-if="requestError" role="alert" class="feedback-in rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ requestError }}</p>
        <div class="flex flex-wrap items-center gap-3">
          <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Scheduling…' : 'Schedule the deletion' }}</button>
          <button type="button" class="btn btn-quiet" @click="formOpen = false">Keep my account</button>
        </div>
      </form>
    </template>
  </section>
</template>
