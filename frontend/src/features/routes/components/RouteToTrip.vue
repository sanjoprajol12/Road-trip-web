<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeftRight, Plus, Route as RouteIcon } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { surfaceLabels } from '@/features/experiences/labels'
import { pickRows, selectionTotals, stopKey, type PickRow } from '@/lib/tripRoute'
import type { RideRoute } from '@/types/api'
import { addRouteParts, createTrip, getTrip, listTrips, type RouteSelection } from '@/features/rides/services/trips'

// Add the whole route, or chosen stretches and checkpoints, to a trip the rider owns or edits. The API checks role,
// publication and duplicates again; this only shows what is recorded and what is already on the chosen trip.
const props = defineProps<{ route: RideRoute }>()
const { user } = useAuth()
const NEW = '__new'
const trips = useAsync(async () => (user.value ? (await listTrips()).filter((t) => t.my_role !== 'viewer') : []))
const pick = ref('')
const title = ref(props.route.name.slice(0, 120))
const reversed = ref(false)
const chosen = ref<string[]>([])
const busy = ref(false)
const msg = ref<{ ok: boolean; text: string; tripId?: string } | null>(null)
const onTrip = ref<{ keys: Set<string>; whole: boolean; stretches: boolean }>({ keys: new Set(), whole: false, stretches: false })

watch(() => trips.data.value, (list) => { if (list && !pick.value) pick.value = list[0]?.id ?? NEW })
// What the chosen trip already holds from this route, so those items are shown as added instead of failing later.
async function loadTrip() {
  onTrip.value = { keys: new Set(), whole: false, stretches: false }
  if (!pick.value || pick.value === NEW) return
  try {
    const stops = ((await getTrip(pick.value)).stops ?? []).filter((s) => s.route_id === props.route.id)
    onTrip.value = { keys: new Set(stops.map(stopKey).filter((k): k is string => !!k)), whole: stops.some((s) => s.route_part === 'route'), stretches: stops.some((s) => s.route_part === 'stretch') }
  } catch {
    // Not fatal: the API still refuses duplicates with a message.
  }
}
watch(pick, loadTrip)

const rows = computed(() => pickRows(props.route.segments ?? [], reversed.value))
const added = (r: PickRow) => onTrip.value.keys.has(r.key) || (r.type === 'stretch' && onTrip.value.whole)
const picked = computed(() => rows.value.filter((r) => chosen.value.includes(r.key)))
const totals = computed(() => selectionTotals(picked.value.flatMap((r) => (r.type === 'stretch' ? [r.segment] : []))))
const km = (n: number) => `${n.toLocaleString('en-IN')} km`
const missing = (r: PickRow) => r.type === 'stretch'
  ? [r.segment.distance_km === null && 'no distance recorded', r.segment.estimated_hours === null && 'no time estimate', !r.segment.path && 'no recorded track'].filter(Boolean).join(', ')
  : ''

async function submit(body: Omit<RouteSelection, 'route_id'>) {
  busy.value = true
  msg.value = null
  try {
    let id = pick.value
    let name = trips.data.value?.find((t) => t.id === id)?.title ?? ''
    if (id === NEW) {
      const trip = (await createTrip(title.value.trim())).data
      ;[id, name] = [trip.id, trip.title]
      await trips.run()
      pick.value = id
    }
    const trip = await addRouteParts(id, { route_id: props.route.id, reversed: reversed.value, ...body })
    const count = trip.stops?.filter((s) => s.route_id === props.route.id).length ?? 0
    msg.value = { ok: true, text: body.whole ? `Added ${props.route.name} to ${name}.` : `Added ${picked.value.length} ${picked.value.length === 1 ? 'stop' : 'stops'} to ${name} (${count} from this route now).`, tripId: id }
    chosen.value = []
    await loadTrip()
  } catch (e) {
    const f = fieldErrors(e)
    msg.value = { ok: false, text: Object.values(f)[0] ?? errorMessage(e) }
  } finally {
    busy.value = false
  }
}
const addWhole = () => submit({ whole: true })
const addPicked = () => submit({
  segment_ids: picked.value.flatMap((r) => (r.type === 'stretch' ? [r.segment.id] : [])),
  checkpoints: picked.value.flatMap((r) => (r.type === 'checkpoint' ? [{ segment_id: r.segment_id, at: r.at }] : [])),
})
const canSubmit = computed(() => !busy.value && !!pick.value && (pick.value !== NEW || title.value.trim().length >= 2))
</script>

<template>
  <section class="mt-12" aria-labelledby="to-trip-title">
    <h2 id="to-trip-title" class="display text-3xl">Add to a trip</h2>
    <p class="mt-2 max-w-2xl text-sm text-muted">Add the whole route, or only the stretches and checkpoints you plan to ride. They go to the end of the trip's itinerary in riding order; you can reorder them on the trip. Distances and times are the recorded ones.</p>

    <p v-if="!user" class="mt-6 text-sm"><RouterLink :to="{ path: '/sign-in', query: { next: `/routes/${route.slug}` } }" class="link font-semibold">Sign in</RouterLink> to add this route to one of your trips.</p>
    <StatusPanel v-else-if="trips.loading.value && !trips.data.value" class="mt-6" state="loading" title="Loading your trips" />
    <StatusPanel v-else-if="trips.error.value" class="mt-6" state="error" message="Your trips could not be loaded." @retry="trips.run" />
    <div v-else class="mt-6 grid gap-5 rounded-md border border-line bg-surface p-4 sm:p-5">
      <div class="grid gap-3 sm:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] sm:items-end">
        <div class="field">
          <label for="rt-trip">Trip</label>
          <select id="rt-trip" v-model="pick" class="input">
            <option v-for="t in trips.data.value ?? []" :key="t.id" :value="t.id">{{ t.title }}</option>
            <option :value="NEW">A new trip</option>
          </select>
        </div>
        <div v-if="pick === NEW" class="field"><label for="rt-title">New trip title</label><input id="rt-title" v-model="title" class="input" maxlength="120" /></div>
      </div>
      <p v-if="trips.data.value && !trips.data.value.length" class="-mt-2 text-xs text-muted">You have no trip you can edit yet, so a new one will be created.</p>

      <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="btn btn-primary" :disabled="!canSubmit || onTrip.whole || onTrip.stretches" @click="addWhole"><RouteIcon class="size-4" aria-hidden="true" /> Add the whole route</button>
        <button type="button" class="btn btn-quiet" :aria-pressed="reversed" @click="reversed = !reversed"><ArrowLeftRight class="size-4" aria-hidden="true" /> {{ reversed ? 'Riding in reverse' : 'Ride it in reverse' }}</button>
        <p v-if="onTrip.whole" class="text-sm text-muted">The whole route is already on this trip.</p>
        <p v-else-if="onTrip.stretches" class="text-sm text-muted">Some stretches are already on this trip; add the others below.</p>
      </div>

      <fieldset v-if="rows.length" class="grid gap-2">
        <legend class="text-sm font-semibold">Or choose stretches and checkpoints, in riding order</legend>
        <ul class="grid border-t border-line">
          <li v-for="r in rows" :key="r.key" class="border-b border-line py-2" :class="r.type === 'stretch' ? 'pl-6' : ''">
            <label class="flex min-h-9 items-start gap-3 text-sm" :class="added(r) ? 'text-muted' : ''">
              <input v-model="chosen" type="checkbox" class="mt-1 accent-crimson" :value="r.key" :disabled="added(r)" />
              <span class="grid gap-0.5">
                <span><span class="font-semibold">{{ r.name }}</span> <span class="text-muted">{{ r.type === 'stretch' ? 'stretch' : 'checkpoint' }}</span><template v-if="added(r)"> · already on this trip</template></span>
                <span v-if="r.type === 'stretch'" class="tabular text-muted">
                  <template v-if="r.segment.distance_km !== null">{{ km(r.segment.distance_km) }}</template><template v-if="r.segment.distance_km !== null && r.segment.estimated_hours !== null"> · </template><template v-if="r.segment.estimated_hours !== null">about {{ r.segment.estimated_hours }} h (estimate)</template><template v-if="r.segment.distance_km !== null || r.segment.estimated_hours !== null"> · </template>{{ surfaceLabels[r.segment.surface] }}
                </span>
                <span v-if="missing(r)" class="text-xs text-muted">Missing: {{ missing(r) }}</span>
              </span>
            </label>
          </li>
        </ul>
      </fieldset>
      <p v-else class="text-sm text-muted">No stretches are recorded for this route yet, so only the whole route can be added.</p>

      <div v-if="rows.length" class="grid gap-3 sm:flex sm:flex-wrap sm:items-center">
        <button type="button" class="btn btn-quiet" :disabled="!canSubmit || !picked.length" @click="addPicked"><Plus class="size-4" aria-hidden="true" /> Add {{ picked.length || '' }} selected</button>
        <p class="text-sm" aria-live="polite">
          <template v-if="!picked.length">Nothing selected.</template>
          <template v-else>
            {{ picked.length }} selected<template v-if="totals.parts">: {{ km(totals.km) }} recorded<template v-if="totals.hours">, about {{ totals.hours }} h riding (estimate)</template></template>.
            <span v-if="totals.withoutKm || totals.withoutHours" class="text-muted">
              Totals cover recorded values only<template v-if="totals.withoutKm">; {{ totals.withoutKm }} without a distance</template><template v-if="totals.withoutHours">; {{ totals.withoutHours }} without a time estimate</template>.
            </span>
          </template>
        </p>
      </div>
      <p v-if="msg" :role="msg.ok ? 'status' : 'alert'" class="text-sm" :class="msg.ok ? '' : 'text-danger'">{{ msg.text }} <RouterLink v-if="msg.ok && msg.tripId" :to="`/rides/${msg.tripId}`" class="link font-semibold">Open the trip</RouterLink></p>
    </div>
  </section>
</template>
