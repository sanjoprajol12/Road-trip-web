<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Pencil, Trash2 } from 'lucide-vue-next'
import { useAuth } from '@/features/authentication/useAuth'
import type { Trip } from '@/types/api'
import { addPart, removePart, updatePart } from '../services/trips'
import type { RunChange } from './types'

// Planned and spent costs, entered by members. Totals add only entered amounts; nothing is booked or paid here.
const props = defineProps<{ trip: Trip; canEdit: boolean; run: RunChange }>()
const { user } = useAuth()
const categories = ['fuel', 'lodging', 'food', 'permits', 'repairs', 'other'] as const
type Expense = NonNullable<Trip['expenses']>[number]
const blank = () => ({ label: '', category: 'fuel', amount_npr: '' as string | number, status: 'planned' as Expense['status'], paid_by: user.value?.id ?? '' })
const draft = reactive(blank())
const editing = ref<string | null>(null)
const edit = reactive(blank())
const npr = (n: number) => `NPR ${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
const memberName = (id: string | null) => props.trip.members?.find((m) => m.id === id)?.display_name ?? 'a former member'
const body = (f: ReturnType<typeof blank>) => ({ label: f.label, category: f.category, status: f.status, amount_npr: f.amount_npr === '' ? null : Number(f.amount_npr), paid_by: f.status === 'spent' && f.paid_by ? f.paid_by : null })
async function add() {
  const sent = body(draft)
  // Clear the form only if nothing new was typed while saving.
  if ((await props.run(() => addPart(props.trip.id, 'expenses', sent))) && draft.label === sent.label) Object.assign(draft, blank())
}
function startEdit(x: Expense) {
  editing.value = x.id
  Object.assign(edit, { label: x.label, category: x.category, amount_npr: x.amount_npr ?? '', status: x.status, paid_by: x.paid_by ?? user.value?.id ?? '' })
}
const save = async (id: string) => (await props.run(() => updatePart(props.trip.id, 'expenses', id, body(edit)))) && (editing.value = null)
const remove = (x: Expense) => window.confirm(`Remove ${x.label} from the budget?`) && props.run(() => removePart(props.trip.id, 'expenses', x.id))
const b = computed(() => props.trip.budget ?? { planned_npr: 0, spent_npr: 0, planned_without_amount: 0 })
const planned = computed(() => props.trip.expenses?.filter((x) => x.status === 'planned') ?? [])
const spent = computed(() => props.trip.expenses?.filter((x) => x.status === 'spent') ?? [])
</script>

<template>
  <section aria-labelledby="bud">
    <h2 id="bud" class="display text-3xl">Budget</h2>
    <p class="mt-2 text-sm text-muted">All amounts in Nepali rupees (NPR), as members enter them. Nothing is booked or paid here.</p>
    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
      <div class="rounded-md border border-line bg-surface p-4 text-sm">
        <dt class="label text-muted">Planned</dt>
        <dd class="display tabular mt-1 text-2xl" data-testid="budget-planned">{{ npr(b.planned_npr) }}</dd>
        <dd v-if="b.planned_without_amount" class="mt-1 text-muted">{{ b.planned_without_amount }} planned {{ b.planned_without_amount === 1 ? 'item has' : 'items have' }} no amount yet, so this total is incomplete.</dd>
      </div>
      <div class="rounded-md border border-line bg-surface p-4 text-sm">
        <dt class="label text-muted">Spent</dt>
        <dd class="display tabular mt-1 text-2xl" data-testid="budget-spent">{{ npr(b.spent_npr) }}</dd>
      </div>
    </dl>
    <p v-if="!trip.expenses?.length" class="mt-4 text-sm text-muted">No costs yet. Add what you expect to spend, then mark items as spent on the ride.</p>
    <template v-for="[title, rows] in ([['Planned', planned], ['Spent', spent]] as const)" :key="title">
      <h3 v-if="rows.length" class="label mt-6 text-muted">{{ title }}</h3>
      <ul v-if="rows.length" class="mt-2 grid border-t border-line text-sm" :aria-label="`${title} costs`">
        <li v-for="x in rows" :key="x.id" class="border-b border-line py-2">
          <form v-if="editing === x.id" class="grid gap-2 sm:grid-cols-[1fr_8rem_8rem_7rem]" @submit.prevent="save(x.id)">
            <div class="field"><label :for="`xl-${x.id}`" class="text-xs">Item</label><input :id="`xl-${x.id}`" v-model="edit.label" class="input" maxlength="120" /></div>
            <div class="field"><label :for="`xc-${x.id}`" class="text-xs">Category</label><select :id="`xc-${x.id}`" v-model="edit.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select></div>
            <div class="field"><label :for="`xa-${x.id}`" class="text-xs">Amount (NPR)</label><input :id="`xa-${x.id}`" v-model="edit.amount_npr" type="number" min="0" step="1" class="input" /></div>
            <div class="field"><label :for="`xs-${x.id}`" class="text-xs">Status</label><select :id="`xs-${x.id}`" v-model="edit.status" class="input"><option value="planned">Planned</option><option value="spent">Spent</option></select></div>
            <div v-if="edit.status === 'spent'" class="field sm:col-span-2"><label :for="`xp-${x.id}`" class="text-xs">Paid by</label><select :id="`xp-${x.id}`" v-model="edit.paid_by" class="input"><option v-for="m in trip.members" :key="m.id" :value="m.id">{{ m.display_name }}</option></select></div>
            <div class="flex gap-2 sm:col-span-4"><button type="submit" class="btn btn-primary min-h-9 px-3 text-sm">Save</button><button type="button" class="btn btn-quiet min-h-9 px-3 text-sm" @click="editing = null">Cancel</button></div>
          </form>
          <div v-else class="flex items-center gap-2">
            <span class="flex-1">{{ x.label }} <span class="text-muted">· {{ x.category }}<template v-if="x.status === 'spent'"> · paid by {{ memberName(x.paid_by) }}</template></span></span>
            <span class="tabular" :class="x.amount_npr === null ? 'text-muted' : ''">{{ x.amount_npr === null ? 'amount not known yet' : npr(x.amount_npr) }}</span>
            <template v-if="canEdit">
              <button type="button" class="grid size-9 place-items-center text-muted hover:text-ink" :aria-label="`Edit ${x.label}`" @click="startEdit(x)"><Pencil class="size-4" aria-hidden="true" /></button>
              <button type="button" class="grid size-9 place-items-center text-muted hover:text-danger" :aria-label="`Remove ${x.label}`" @click="remove(x)"><Trash2 class="size-4" aria-hidden="true" /></button>
            </template>
          </div>
        </li>
      </ul>
    </template>
    <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_8rem_8rem_7rem_auto] sm:items-end" @submit.prevent="add">
      <div class="field"><label for="el">Item</label><input id="el" v-model="draft.label" class="input" maxlength="120" /></div>
      <div class="field"><label for="ec">Category</label><select id="ec" v-model="draft.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select></div>
      <div class="field"><label for="ea">Amount (NPR)</label><input id="ea" v-model="draft.amount_npr" type="number" min="0" step="1" class="input" :placeholder="draft.status === 'planned' ? 'if known' : ''" /></div>
      <div class="field"><label for="es">Status</label><select id="es" v-model="draft.status" class="input"><option value="planned">Planned</option><option value="spent">Spent</option></select></div>
      <button type="submit" class="btn btn-quiet" :disabled="draft.label.trim().length < 2 || (draft.status === 'spent' && draft.amount_npr === '')">Add</button>
      <div v-if="draft.status === 'spent'" class="field sm:col-span-2"><label for="ep">Paid by</label><select id="ep" v-model="draft.paid_by" class="input"><option v-for="m in trip.members" :key="m.id" :value="m.id">{{ m.display_name }}</option></select></div>
    </form>
  </section>
</template>
