<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { useAuth } from '@/features/authentication/useAuth'
import { addPart, listTrips } from '../services/trips'

// "Add to a trip" for a service, destination or place: trips this rider owns or edits; the server checks the role again
// and refuses the same service or place twice on one trip.
const props = defineProps<{ kind: 'service' | 'destination' | 'place'; id: string; name: string; note?: string | null }>()
const { user } = useAuth()
// shortcut: each picker loads the rider's trips itself; share one cached list if pages show many pickers.
const trips = useAsync(async () => (user.value ? (await listTrips()).filter((t) => t.my_role !== 'viewer') : []))
const pick = ref('')
const msg = ref<{ ok: boolean; text: string } | null>(null)
async function add() {
  const trip = trips.data.value?.find((t) => t.id === pick.value)
  if (!trip) return
  try {
    await addPart(trip.id, 'stops', { name: props.name.slice(0, 120), note: props.note ?? null, ...({ service: { service_point_id: props.id }, destination: { destination_id: props.id }, place: { place_id: props.id } })[props.kind] })
    msg.value = { ok: true, text: `Added to ${trip.title}.` }
  } catch (e) {
    msg.value = { ok: false, text: errorMessage(e) }
  }
}
</script>

<template>
  <div v-if="user" class="grid gap-1">
    <p v-if="trips.error.value" class="text-xs text-danger">Your trips could not be loaded.</p>
    <p v-else-if="trips.data.value && !trips.data.value.length" class="text-xs text-muted"><RouterLink to="/rides" class="link">Create a trip</RouterLink> to add stops.</p>
    <div v-else-if="trips.data.value" class="flex flex-wrap items-end gap-2">
      <div class="field">
        <label :for="`trip-${kind}-${id}`" class="text-xs">Add to a trip</label>
        <select :id="`trip-${kind}-${id}`" v-model="pick" class="input min-h-9 py-1 text-sm"><option value="" disabled>Choose a trip</option><option v-for="t in trips.data.value" :key="t.id" :value="t.id">{{ t.title }}</option></select>
      </div>
      <button type="button" class="btn btn-quiet min-h-9 px-3 text-sm" :disabled="!pick" :aria-label="`Add ${name} to the chosen trip`" @click="add"><Plus class="size-4" aria-hidden="true" /> Add</button>
    </div>
    <p v-if="msg" :role="msg.ok ? 'status' : 'alert'" class="text-xs" :class="msg.ok ? '' : 'text-danger'">{{ msg.text }}</p>
  </div>
</template>
