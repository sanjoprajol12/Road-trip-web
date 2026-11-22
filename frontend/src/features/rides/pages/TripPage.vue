<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ArrowDown, ArrowLeft, ArrowUp, Moon, Pencil, Trash2 } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import type { ServicePoint, Trip } from '@/types/api'
import { addMember as addTripMember, addPart, cancelInvitation, deleteTrip as destroyTrip, getTrip, removeMember as dropMember, removePart, reorderStops, setMemberRole, updatePart, updateTrip } from '../services/trips'
import { tripRouteTotals } from '@/lib/tripRoute'
import { surfaceLabels } from '@/features/experiences/labels'
import { listDestinations } from '@/features/explore/services/destinations'
import DestinationMap, { type MapLine, type MapPoint } from '@/features/explore/components/DestinationMap.vue'
import TripBudget from '../components/TripBudget.vue'
import TripChecklist from '../components/TripChecklist.vue'
import TripDays from '../components/TripDays.vue'
import { isPlaceCategory, placeCategories } from '@/features/places/categories'
import { serviceKinds } from '@/features/routes/serviceKinds'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { user } = useAuth()
const trip = useAsync(async () => {
  try {
    return await getTrip(props.id)
  } catch (e) {
    if (statusOf(e) === 403 || statusOf(e) === 404) return null
    throw e
  }
})
const destinations = useAsync(() => listDestinations())
const t = computed(() => trip.data.value)
const canEdit = computed(() => t.value?.my_role === 'owner' || t.value?.my_role === 'editor')
const isOwner = computed(() => t.value?.my_role === 'owner')
const err = ref<string | null>(null)

async function call(fn: () => Promise<{ data: { data: Trip } }>): Promise<boolean> {
  err.value = null
  try {
    trip.data.value = (await fn()).data.data
    return true
  } catch (e) {
    err.value = errorMessage(e)
    return false
  }
}

async function cancelInv(id: string) {
  err.value = null
  try {
    await cancelInvitation(props.id, id)
    await trip.run()
  } catch (e) {
    err.value = errorMessage(e)
  }
}

const details = reactive({ title: '', starts_on: '', ends_on: '', motorcycle: '', notes: '' })
watch(t, (v) => v && Object.assign(details, { title: v.title, starts_on: v.starts_on ?? '', ends_on: v.ends_on ?? '', motorcycle: v.motorcycle ?? '', notes: v.notes ?? '' }), { immediate: true })
const detailsSaved = ref(false)
const saveDetails = async () => (detailsSaved.value = await call(() => updateTrip(props.id, { ...details, notes: details.notes || null, motorcycle: details.motorcycle || null, starts_on: details.starts_on || null, ends_on: details.ends_on || null })))

const stop = reactive({ name: '', day: '', destination_id: '', overnight: false })
const addStop = async () => (await call(() => addPart(props.id, 'stops', { name: stop.name, day: stop.day ? Number(stop.day) : null, destination_id: stop.destination_id || null, overnight: stop.overnight }))) && Object.assign(stop, { name: '', day: '', destination_id: '', overnight: false })
// Edit a stop in place: name, day, overnight and note (links stay as they are).
const editingStop = ref<string | null>(null)
const stopEdit = reactive({ name: '', day: '' as string | number, overnight: false, note: '' })
function startStopEdit(s: { id: string; name: string; day: number | null; overnight: boolean; note: string | null }) {
  editingStop.value = s.id
  Object.assign(stopEdit, { name: s.name, day: s.day ?? '', overnight: s.overnight, note: s.note ?? '' })
}
const saveStop = async (id: string) => (await call(() => updatePart(props.id, 'stops', id, { name: stopEdit.name, day: stopEdit.day === '' ? null : Number(stopEdit.day), overnight: stopEdit.overnight, note: stopEdit.note || null }))) && (editingStop.value = null)
const remove = (part: string, id: string) => call(() => removePart(props.id, part, id))
type Stop = NonNullable<Trip['stops']>[number]
// Route parts can stand for hours of riding, so removing one asks first.
const removeStop = (s: Stop) => (!s.route_part || window.confirm(`Remove ${s.name} from this trip?`)) && remove('stops', s.id)
function move(i: number, by: -1 | 1) {
  const ids = (t.value?.stops ?? []).map((s) => s.id)
  ;[ids[i], ids[i + by]] = [ids[i + by]!, ids[i]!]
  return call(() => reorderStops(props.id, ids))
}
const routeTotals = computed(() => tripRouteTotals(t.value?.stops ?? []))
const n = (x: number) => x.toLocaleString('en-IN')
const partLabel = { route: 'Whole route', stretch: 'Stretch of', checkpoint_start: 'Checkpoint on', checkpoint_end: 'Checkpoint on' } as const
/** Recorded numbers for a route or stretch stop, and what is not recorded. */
function partFacts(s: Stop): { facts: string; missing: string } {
  const v = s.route_part === 'route' ? s.route : s.stretch
  if (!v) return { facts: '', missing: '' }
  const facts = [v.distance_km !== null && `${n(v.distance_km)} km`, v.estimated_hours !== null && `about ${n(v.estimated_hours)} h (estimate)`, s.stretch && surfaceLabels[s.stretch.surface], s.stretch?.has_track && 'recorded track'].filter(Boolean).join(' · ')
  const missing = s.route
    ? (s.route_part === 'route'
        ? [!s.route.stretches && 'no stretches recorded', s.route.without_distance && `${s.route.without_distance} of ${s.route.stretches} stretches without a distance`, s.route.without_time && `${s.route.without_time} without a time estimate`, s.route.without_track && `${s.route.without_track} without a recorded track`]
        : [s.stretch?.distance_km === null && 'no distance recorded', s.stretch?.estimated_hours === null && 'no time estimate', s.stretch && !s.stretch.has_track && 'no recorded track']
      ).filter(Boolean).join(', ')
    : ''
  return { facts, missing }
}
// Stops with a known position on the map; one marker per position and name (no duplicates), places link to their page.
const mapPoints = computed<MapPoint[]>(() => {
  const seen = new Set<string>()
  const out: MapPoint[] = []
  for (const s of t.value?.stops ?? []) {
    const p: MapPoint | null = s.checkpoint?.position ? { name: s.checkpoint.name, position: s.checkpoint.position, kind: 'checkpoint', link: s.route ? `/routes/${s.route.slug}` : undefined }
      : s.place?.position ? { name: s.place.name, position: s.place.position, kind: 'place', link: `/places/${s.place.slug}` }
      : s.destination?.position ? { name: s.destination.name, position: s.destination.position, kind: 'destination', link: `/explore/${s.destination.slug}` }
      : s.service?.position ? { name: s.service.name, position: s.service.position, kind: 'service' } : null
    const key = p && `${p.name}|${p.position.join(',')}`
    if (p && key && !seen.has(key)) {
      seen.add(key)
      out.push(p)
    }
  }
  return out
})
// Recorded tracks only (imported by staff); stretches without one get no line.
const mapLines = computed<MapLine[]>(() => (t.value?.stops ?? []).flatMap((s) => s.tracks.map((path) => ({ name: `${s.name} (recorded track)`, path, link: s.route ? `/routes/${s.route.slug}` : undefined }))))
const placeLabel = (c: string) => (isPlaceCategory(c) ? placeCategories[c].label : 'Place')
const member = reactive({ username: '', role: 'editor' })
const addMember = () => call(() => addTripMember(props.id, member)).then(() => (member.username = ''))
const setRole = (uid: string, role: string) => call(() => setMemberRole(props.id, uid, role))
async function removeMember(uid: string) {
  const self = uid === user.value?.id
  if (!window.confirm(self ? 'Leave this trip?' : 'Remove this member?')) return
  try {
    await dropMember(props.id, uid)
    if (self) await router.push('/rides')
    else await trip.run()
  } catch (e) {
    err.value = errorMessage(e)
  }
}
async function deleteTrip() {
  if (!window.confirm('Delete this trip for everyone? This cannot be undone.')) return
  try {
    await destroyTrip(props.id)
    await router.push('/rides')
  } catch (e) {
    err.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <RouterLink to="/rides" class="link inline-flex items-center gap-1.5 text-sm font-semibold"><ArrowLeft class="size-4" aria-hidden="true" /> My rides</RouterLink>
    <StatusPanel v-if="trip.loading.value" class="mt-6" state="loading" title="Loading trip" />
    <StatusPanel v-else-if="trip.error.value" class="mt-6" state="error" :message="trip.error.value" @retry="trip.run" />
    <StatusPanel v-else-if="!t" class="mt-6" state="empty" title="Trip not available" message="Only members of a trip can open it." />
    <article v-else class="mt-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="display text-[clamp(2.5rem,1.8rem+3vw,4.5rem)]">{{ t.title }}</h1>
        <p class="text-sm text-muted">You are {{ t.my_role === 'owner' ? 'the owner' : `a ${t.my_role}` }}<template v-if="!canEdit"> (read only)</template></p>
      </div>
      <p v-if="err" class="mt-4 rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger" role="alert">{{ err }}</p>

      <div class="mt-10 grid gap-14 lg:grid-cols-[1fr_22rem]">
        <div class="grid content-start gap-14">
          <section aria-labelledby="it">
            <h2 id="it" class="display text-3xl">Itinerary</h2>
            <p v-if="routeTotals.parts" class="mt-2 text-sm" data-testid="trip-route-totals">
              Routes and stretches in this trip: <span class="tabular font-semibold">{{ n(routeTotals.km) }} km</span> recorded<template v-if="routeTotals.hours">, about <span class="tabular font-semibold">{{ n(routeTotals.hours) }} h</span> riding (estimate)</template>.
              <span class="text-muted"><template v-if="routeTotals.withoutKm || routeTotals.withoutHours">Recorded values only<template v-if="routeTotals.withoutKm">; {{ routeTotals.withoutKm }} without a distance</template><template v-if="routeTotals.withoutHours">; {{ routeTotals.withoutHours }} without a time estimate</template>, so the real figures are higher. </template>Not live travel times.</span>
            </p>
            <ol v-if="t.stops?.length" class="mt-4 grid border-l-2 border-line-strong" aria-label="Stops in order">
              <li v-for="(s, i) in t.stops" :key="s.id" class="relative flex items-start gap-3 pb-5 pl-5">
                <span class="absolute top-1.5 -left-[6px] size-2.5 rounded-full bg-crimson" aria-hidden="true" />
                <form v-if="editingStop === s.id" class="grid flex-1 gap-2 sm:grid-cols-[1fr_5rem]" :aria-label="`Edit ${s.name}`" @submit.prevent="saveStop(s.id)">
                  <div class="field"><label :for="`sn-${s.id}`" class="text-xs">Stop</label><input :id="`sn-${s.id}`" v-model="stopEdit.name" class="input" maxlength="120" /></div>
                  <div class="field"><label :for="`sd-${s.id}`" class="text-xs">Day</label><input :id="`sd-${s.id}`" v-model="stopEdit.day" type="number" min="1" max="365" class="input" /></div>
                  <div class="field sm:col-span-2"><label :for="`so-${s.id}`" class="text-xs">Note (for example where you sleep, or what is not booked yet)</label><input :id="`so-${s.id}`" v-model="stopEdit.note" class="input" maxlength="1000" /></div>
                  <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="stopEdit.overnight" type="checkbox" class="accent-crimson" /> Overnight stop</label>
                  <div class="flex gap-2 sm:col-span-2"><button type="submit" class="btn btn-primary min-h-9 px-3 text-sm" :disabled="stopEdit.name.trim().length < 2">Save</button><button type="button" class="btn btn-quiet min-h-9 px-3 text-sm" @click="editingStop = null">Cancel</button></div>
                </form>
                <div v-else class="flex-1">
                  <p class="font-semibold"><template v-if="s.day">Day {{ s.day }}: </template><RouterLink v-if="s.place && s.place.name === s.name" :to="`/places/${s.place.slug}`" class="link">{{ s.name }}</RouterLink><template v-else>{{ s.name }}</template><Moon v-if="s.overnight" class="ml-1.5 inline size-4 text-muted" aria-label="overnight stop" /></p>
                  <p v-if="s.place" class="text-sm"><RouterLink v-if="s.place.name !== s.name" :to="`/places/${s.place.slug}`" class="link">{{ s.place.name }}</RouterLink><template v-if="s.place.name !== s.name"> · </template><span class="text-muted">{{ placeLabel(s.place.category) }}</span></p>
                  <p v-else-if="s.destination" class="text-sm"><RouterLink :to="`/explore/${s.destination.slug}`" class="link">{{ s.destination.name }}</RouterLink></p>
                  <p v-else-if="s.service" class="text-sm text-muted">{{ serviceKinds[s.service.kind as ServicePoint['kind']]?.label ?? 'Service' }}: {{ s.service.name }}</p>
                  <template v-else-if="s.route_part && s.route">
                    <p class="text-sm"><span class="text-muted">{{ partLabel[s.route_part] }}</span> <RouterLink :to="`/routes/${s.route.slug}`" class="link">{{ s.route.name }}</RouterLink><template v-if="s.checkpoint && !s.checkpoint.position"><span class="text-muted"> · no recorded position</span></template></p>
                    <p v-if="partFacts(s).facts" class="tabular text-sm text-muted">{{ partFacts(s).facts }}</p>
                    <p v-if="partFacts(s).missing" class="text-xs text-muted">Missing: {{ partFacts(s).missing }}</p>
                  </template>
                  <p v-if="s.linked_unavailable" class="text-sm text-muted">{{ s.route_part ? 'This route, stretch or checkpoint is no longer listed, so no route details are shown.' : 'The linked place or service is no longer listed.' }}</p>
                  <p v-if="s.note" class="text-sm text-muted">{{ s.note }}</p>
                </div>
                <div v-if="canEdit && editingStop !== s.id" class="flex shrink-0 flex-wrap items-center justify-end">
                  <button type="button" class="grid size-9 place-items-center text-muted hover:text-ink" :aria-label="`Edit ${s.name}`" @click="startStopEdit(s)"><Pencil class="size-4" aria-hidden="true" /></button>
                  <button type="button" class="grid size-9 place-items-center text-muted hover:text-ink disabled:opacity-30" :disabled="i === 0" :aria-label="`Move ${s.name} up`" @click="move(i, -1)"><ArrowUp class="size-4" aria-hidden="true" /></button>
                  <button type="button" class="grid size-9 place-items-center text-muted hover:text-ink disabled:opacity-30" :disabled="i === t.stops.length - 1" :aria-label="`Move ${s.name} down`" @click="move(i, 1)"><ArrowDown class="size-4" aria-hidden="true" /></button>
                  <button type="button" class="grid size-9 place-items-center text-muted hover:text-danger" :aria-label="`Remove ${s.name}`" @click="removeStop(s)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </div>
              </li>
            </ol>
            <p v-else class="mt-4 text-sm text-muted">No stops yet. Add a <RouterLink to="/routes" class="link">route</RouterLink> or some of its stretches, places from a <RouterLink to="/places" class="link">place page</RouterLink>, a destination or your <RouterLink to="/saved" class="link">saved list</RouterLink>.</p>
            <template v-if="t.stops?.length">
              <DestinationMap v-if="mapPoints.length || mapLines.length" :key="[...mapPoints.map((p) => p.name), mapLines.length].join('|')" class="mt-6" :center="null" :name="`${t.title} stops`" :points="mapPoints" :lines="mapLines" />
              <p v-else class="mt-6 text-sm text-muted">None of these stops has a recorded position or track, so there is no map yet.</p>
              <TripDays :trip="t" />
            </template>
            <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_5rem_12rem_auto] sm:items-end" @submit.prevent="addStop">
              <div class="field"><label for="sn">Stop</label><input id="sn" v-model="stop.name" class="input" maxlength="120" /></div>
              <div class="field"><label for="sd">Day</label><input id="sd" v-model="stop.day" type="number" min="1" max="365" class="input" /></div>
              <div class="field"><label for="sdest">Destination</label><select id="sdest" v-model="stop.destination_id" class="input"><option value="">None</option><option v-for="d in destinations.data.value ?? []" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
              <button type="submit" class="btn btn-quiet" :disabled="stop.name.trim().length < 2">Add</button>
              <label class="flex items-center gap-2 text-sm sm:col-span-4"><input v-model="stop.overnight" type="checkbox" class="accent-crimson" /> Overnight stop</label>
            </form>
          </section>

          <TripBudget :trip="t" :can-edit="canEdit" :run="call" />
          <TripChecklist :trip="t" :can-edit="canEdit" :run="call" />
        </div>

        <aside class="grid content-start gap-10 text-sm">
          <form class="grid gap-3" @submit.prevent="saveDetails">
            <h2 class="font-semibold">Details</h2>
            <div class="field"><label for="tt">Title</label><input id="tt" v-model="details.title" class="input" :disabled="!canEdit" /></div>
            <div class="grid grid-cols-2 gap-3">
              <div class="field"><label for="ts">From</label><input id="ts" v-model="details.starts_on" type="date" class="input" :disabled="!canEdit" /></div>
              <div class="field"><label for="te">To</label><input id="te" v-model="details.ends_on" type="date" class="input" :disabled="!canEdit" /></div>
            </div>
            <div class="field"><label for="tm">Motorcycle</label><input id="tm" v-model="details.motorcycle" class="input" :disabled="!canEdit" /></div>
            <div class="field"><label for="tn">Notes</label><textarea id="tn" v-model="details.notes" class="input" rows="4" maxlength="10000" :disabled="!canEdit" aria-describedby="tn-help" /><p id="tn-help" class="text-xs text-muted">Every member of this trip can read these notes{{ canEdit ? '; clear the field and save to remove them' : '' }}.</p></div>
            <button v-if="canEdit" type="submit" class="btn btn-quiet">Save details</button>
            <p v-if="detailsSaved" class="text-sm" role="status">Details saved.</p>
          </form>

          <section class="grid gap-3" aria-labelledby="mem">
            <h2 id="mem" class="font-semibold">Members</h2>
            <ul class="grid border-t border-line">
              <li v-for="m in t.members" :key="m.id" class="flex items-center gap-2 border-b border-line py-2">
                <span class="flex-1">{{ m.display_name }}<span v-if="m.id === user?.id" class="text-muted"> (you)</span></span>
                <select v-if="isOwner && m.role !== 'owner'" :value="m.role" class="input min-h-8 w-24 px-1 text-xs" :aria-label="`Role of ${m.display_name}`" @change="setRole(m.id, ($event.target as HTMLSelectElement).value)"><option value="editor">editor</option><option value="viewer">viewer</option></select>
                <span v-else class="text-muted">{{ m.role }}</span>
                <button v-if="m.role !== 'owner' && (isOwner || m.id === user?.id)" type="button" class="p-1 text-muted hover:text-danger" :aria-label="m.id === user?.id ? 'Leave this trip' : `Remove ${m.display_name}`" @click="removeMember(m.id)"><Trash2 class="size-4" aria-hidden="true" /></button>
              </li>
            </ul>
            <form v-if="isOwner" class="grid grid-cols-[1fr_6rem] gap-2" @submit.prevent="addMember">
              <input v-model="member.username" class="input" placeholder="Rider's username" aria-label="Username to add" autocapitalize="none" />
              <select v-model="member.role" class="input" aria-label="Role"><option value="editor">editor</option><option value="viewer">viewer</option></select>
              <button type="submit" class="btn btn-quiet col-span-2" :disabled="member.username.trim().length < 3">Send invitation</button>
              <p class="col-span-2 text-xs text-muted">They join only after accepting, within 14 days.</p>
            </form>
            <ul v-if="isOwner && t.invitations?.length" class="grid gap-1 text-sm" aria-label="Invitations waiting for an answer">
              <li v-for="i in t.invitations" :key="i.id" class="flex items-center gap-2">
                <span class="flex-1">{{ i.display_name }} <span class="text-muted">({{ i.role }}, {{ i.expired ? 'expired' : 'waiting' }})</span></span>
                <button type="button" class="p-1 text-muted hover:text-danger" :aria-label="`Cancel the invitation for ${i.display_name}`" @click="cancelInv(i.id)"><Trash2 class="size-4" aria-hidden="true" /></button>
              </li>
            </ul>
          </section>
          <button v-if="isOwner" type="button" class="link justify-self-start text-danger" @click="deleteTrip">Delete this trip</button>
        </aside>
      </div>
    </article>
  </div>
</template>
