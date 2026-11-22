<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Pencil, Trash2 } from 'lucide-vue-next'
import type { Trip } from '@/types/api'
import { addPart, removePart, updatePart } from '../services/trips'
import type { RunChange } from './types'

const props = defineProps<{ trip: Trip; canEdit: boolean; run: RunChange }>()
const sections = { motorcycle: 'Motorcycle', personal: 'Personal', trip: 'This trip' } as const
type Item = NonNullable<Trip['checklist']>[number]
const item = reactive({ label: '', section: 'motorcycle' as Item['section'] })
const editing = ref<string | null>(null)
const edit = reactive({ label: '', section: 'motorcycle' as Item['section'] })
const items = computed(() => props.trip.checklist ?? [])
const done = computed(() => items.value.filter((c) => c.done).length)
const add = async () => (await props.run(() => addPart(props.trip.id, 'checklist', item))) && (item.label = '')
const toggle = (id: string, value: boolean) => props.run(() => updatePart(props.trip.id, 'checklist', id, { done: value }))
function startEdit(c: Item) {
  editing.value = c.id
  Object.assign(edit, { label: c.label, section: c.section })
}
const save = async (id: string) => (await props.run(() => updatePart(props.trip.id, 'checklist', id, edit))) && (editing.value = null)
const remove = (c: Item) => props.run(() => removePart(props.trip.id, 'checklist', c.id))
</script>

<template>
  <section aria-labelledby="chk">
    <h2 id="chk" class="display text-3xl">Checklist</h2>
    <p class="mt-2 text-sm" :class="items.length ? '' : 'text-muted'" data-testid="checklist-progress">{{ items.length ? `${done} of ${items.length} done` : 'No items yet. Add what to check or pack before the ride.' }}</p>
    <div v-for="(label, sec) in sections" :key="sec" class="mt-4">
      <h3 class="label text-muted">{{ label }}</h3>
      <ul v-if="items.some((x) => x.section === sec)" class="mt-2 grid gap-1" :aria-label="`${label} checklist`">
        <li v-for="c in items.filter((x) => x.section === sec)" :key="c.id" class="text-sm">
          <form v-if="editing === c.id" class="grid gap-2 sm:grid-cols-[1fr_10rem_auto] sm:items-end" @submit.prevent="save(c.id)">
            <div class="field"><label :for="`cl-${c.id}`" class="text-xs">Item</label><input :id="`cl-${c.id}`" v-model="edit.label" class="input" maxlength="200" /></div>
            <div class="field"><label :for="`cs-${c.id}`" class="text-xs">Section</label><select :id="`cs-${c.id}`" v-model="edit.section" class="input"><option v-for="(l, s) in sections" :key="s" :value="s">{{ l }}</option></select></div>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary min-h-9 px-3 text-sm">Save</button><button type="button" class="btn btn-quiet min-h-9 px-3 text-sm" @click="editing = null">Cancel</button></div>
          </form>
          <div v-else class="flex items-center gap-2">
            <label class="flex min-h-9 flex-1 items-center gap-2"><input type="checkbox" class="accent-crimson" :checked="c.done" :disabled="!canEdit" @change="toggle(c.id, ($event.target as HTMLInputElement).checked)" /><span :class="c.done ? 'text-muted line-through' : ''">{{ c.label }}</span></label>
            <template v-if="canEdit">
              <button type="button" class="grid size-9 place-items-center text-muted hover:text-ink" :aria-label="`Edit ${c.label}`" @click="startEdit(c)"><Pencil class="size-4" aria-hidden="true" /></button>
              <button type="button" class="grid size-9 place-items-center text-muted hover:text-danger" :aria-label="`Remove ${c.label}`" @click="remove(c)"><Trash2 class="size-4" aria-hidden="true" /></button>
            </template>
          </div>
        </li>
      </ul>
      <p v-else class="mt-2 text-sm text-muted">Nothing here yet.</p>
    </div>
    <form v-if="canEdit" class="mt-4 grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end" @submit.prevent="add">
      <div class="field"><label for="cl">Item</label><input id="cl" v-model="item.label" class="input" maxlength="200" placeholder="For example: spare clutch cable" /></div>
      <div class="field"><label for="cs">Section</label><select id="cs" v-model="item.section" class="input"><option v-for="(l, s) in sections" :key="s" :value="s">{{ l }}</option></select></div>
      <button type="submit" class="btn btn-quiet" :disabled="item.label.trim().length < 2">Add</button>
    </form>
  </section>
</template>
