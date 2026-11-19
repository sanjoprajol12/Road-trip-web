<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ArrowLeft, Moon, Trash2 } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, statusOf } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import type { Trip } from '@/types/api'
import { addMember as addTripMember, addPart, deleteTrip as destroyTrip, getTrip, removeMember as dropMember, removePart, setMemberRole, updatePart, updateTrip } from '../services/trips'
import { listDestinations } from '@/features/explore/services/destinations'

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

async function call(fn: () => Promise<{ data: { data: Trip } }>) {
  err.value = null
  try {
    trip.data.value = (await fn()).data.data
  } catch (e) {
    err.value = errorMessage(e)
  }
}

const details = reactive({ title: '', starts_on: '', ends_on: '', motorcycle: '', notes: '' })
watch(t, (v) => v && Object.assign(details, { title: v.title, starts_on: v.starts_on ?? '', ends_on: v.ends_on ?? '', motorcycle: v.motorcycle ?? '', notes: v.notes ?? '' }), { immediate: true })
const saveDetails = () => call(() => updateTrip(props.id, { ...details, starts_on: details.starts_on || null, ends_on: details.ends_on || null }))

const stop = reactive({ name: '', day: '', destination_id: '', overnight: false })
const addStop = () => call(() => addPart(props.id, 'stops', { name: stop.name, day: stop.day ? Number(stop.day) : null, destination_id: stop.destination_id || null, overnight: stop.overnight })).then(() => Object.assign(stop, { name: '', day: '', destination_id: '', overnight: false }))
const expense = reactive({ label: '', category: 'fuel', amount_npr: '' })
const addExpense = () => call(() => addPart(props.id, 'expenses', { ...expense, amount_npr: Number(expense.amount_npr), paid_by: user.value?.id })).then(() => Object.assign(expense, { label: '', amount_npr: '' }))
const item = reactive({ label: '', section: 'motorcycle' })
const addItem = () => call(() => addPart(props.id, 'checklist', item)).then(() => (item.label = ''))
const toggle = (id: string, done: boolean) => call(() => updatePart(props.id, 'checklist', id, { done }))
const remove = (part: string, id: string) => call(() => removePart(props.id, part, id))
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
const sections = { motorcycle: 'Motorcycle', personal: 'Personal', trip: 'This trip' } as const
const byCategory = computed(() => {
  const m: Record<string, number> = {}
  for (const x of t.value?.expenses ?? []) m[x.category] = (m[x.category] ?? 0) + x.amount_npr
  return Object.entries(m)
})
const npr = (n: number) => `NPR ${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
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
            <ol v-if="t.stops?.length" class="mt-4 grid border-l-2 border-line-strong">
              <li v-for="s in t.stops" :key="s.id" class="relative flex items-start gap-3 pb-5 pl-5">
                <span class="absolute top-1.5 -left-[6px] size-2.5 rounded-full bg-crimson" aria-hidden="true" />
                <div class="flex-1">
                  <p class="font-semibold"><template v-if="s.day">Day {{ s.day }}: </template>{{ s.name }}<Moon v-if="s.overnight" class="ml-1.5 inline size-4 text-muted" aria-label="overnight stop" /></p>
                  <p v-if="s.note" class="text-sm text-muted">{{ s.note }}</p>
                </div>
                <button v-if="canEdit" type="button" class="p-1 text-muted hover:text-danger" :aria-label="`Remove ${s.name}`" @click="remove('stops', s.id)"><Trash2 class="size-4" aria-hidden="true" /></button>
              </li>
            </ol>
            <p v-else class="mt-4 text-sm text-muted">No stops yet.</p>
            <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_5rem_12rem_auto] sm:items-end" @submit.prevent="addStop">
              <div class="field"><label for="sn">Stop</label><input id="sn" v-model="stop.name" class="input" maxlength="120" /></div>
              <div class="field"><label for="sd">Day</label><input id="sd" v-model="stop.day" type="number" min="1" max="365" class="input" /></div>
              <div class="field"><label for="sdest">Destination</label><select id="sdest" v-model="stop.destination_id" class="input"><option value="">None</option><option v-for="d in destinations.data.value ?? []" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
              <button type="submit" class="btn btn-quiet" :disabled="stop.name.trim().length < 2">Add</button>
              <label class="flex items-center gap-2 text-sm sm:col-span-4"><input v-model="stop.overnight" type="checkbox" class="accent-crimson" /> Overnight stop</label>
            </form>
          </section>

          <section aria-labelledby="bud">
            <h2 id="bud" class="display text-3xl">Budget</h2>
            <p class="mt-2 text-sm text-muted">Estimates you enter. Total: <span class="tabular font-semibold text-ink">{{ npr(t.expense_total_npr ?? 0) }}</span><template v-if="byCategory.length"> ({{ byCategory.map(([c, v]) => `${c} ${npr(v)}`).join(', ') }})</template></p>
            <ul v-if="t.expenses?.length" class="mt-4 grid border-t border-line text-sm">
              <li v-for="x in t.expenses" :key="x.id" class="flex items-center gap-3 border-b border-line py-2">
                <span class="flex-1">{{ x.label }} <span class="text-muted">· {{ x.category }}</span></span>
                <span class="tabular">{{ npr(x.amount_npr) }}</span>
                <button v-if="canEdit" type="button" class="p-1 text-muted hover:text-danger" :aria-label="`Remove ${x.label}`" @click="remove('expenses', x.id)"><Trash2 class="size-4" aria-hidden="true" /></button>
              </li>
            </ul>
            <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_9rem_9rem_auto] sm:items-end" @submit.prevent="addExpense">
              <div class="field"><label for="el">Item</label><input id="el" v-model="expense.label" class="input" maxlength="120" /></div>
              <div class="field"><label for="ec">Category</label><select id="ec" v-model="expense.category" class="input"><option v-for="c in ['fuel', 'lodging', 'food', 'permits', 'repairs', 'other']" :key="c" :value="c">{{ c }}</option></select></div>
              <div class="field"><label for="ea">Amount (NPR)</label><input id="ea" v-model="expense.amount_npr" type="number" min="0" step="1" class="input" /></div>
              <button type="submit" class="btn btn-quiet" :disabled="!expense.label || expense.amount_npr === ''">Add</button>
            </form>
          </section>

          <section aria-labelledby="chk">
            <h2 id="chk" class="display text-3xl">Checklist</h2>
            <div v-for="(label, sec) in sections" :key="sec" class="mt-4">
              <h3 class="label text-muted">{{ label }}</h3>
              <ul class="mt-2 grid gap-1">
                <li v-for="c in (t.checklist ?? []).filter((x) => x.section === sec)" :key="c.id" class="flex items-center gap-3 text-sm">
                  <label class="flex flex-1 items-center gap-2"><input type="checkbox" class="accent-crimson" :checked="c.done" :disabled="!canEdit" @change="toggle(c.id, ($event.target as HTMLInputElement).checked)" /><span :class="c.done ? 'text-muted line-through' : ''">{{ c.label }}</span></label>
                  <button v-if="canEdit" type="button" class="p-1 text-muted hover:text-danger" :aria-label="`Remove ${c.label}`" @click="remove('checklist', c.id)"><Trash2 class="size-4" aria-hidden="true" /></button>
                </li>
              </ul>
            </div>
            <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end" @submit.prevent="addItem">
              <div class="field"><label for="cl">Item</label><input id="cl" v-model="item.label" class="input" maxlength="200" placeholder="For example: spare clutch cable" /></div>
              <div class="field"><label for="cs">Section</label><select id="cs" v-model="item.section" class="input"><option v-for="(l, s) in sections" :key="s" :value="s">{{ l }}</option></select></div>
              <button type="submit" class="btn btn-quiet" :disabled="item.label.trim().length < 2">Add</button>
            </form>
          </section>
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
            <div class="field"><label for="tn">Notes</label><textarea id="tn" v-model="details.notes" class="input" rows="4" :disabled="!canEdit" /></div>
            <button v-if="canEdit" type="submit" class="btn btn-quiet">Save details</button>
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
              <button type="submit" class="btn btn-quiet col-span-2" :disabled="member.username.trim().length < 3">Add member</button>
            </form>
          </section>
          <button v-if="isOwner" type="button" class="link justify-self-start text-danger" @click="deleteTrip">Delete this trip</button>
        </aside>
      </div>
    </article>
  </div>
</template>
