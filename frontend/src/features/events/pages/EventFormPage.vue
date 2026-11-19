<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { getEvent, saveEvent } from '../services/events'
import { listRoutes } from '@/features/routes/services/routes'

const props = defineProps<{ id?: string }>()
const router = useRouter()
const routes = useAsync(() => listRoutes())
const local = (iso: string) => new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)
const form = reactive({ category: 'ride', subtype: '', title: '', description: '', starts_at: '', ends_at: '', meeting_point: '', route_id: '', visibility: 'public', join_method: 'instant', capacity: '' as string | number, waitlist_enabled: false })
const loadError = ref<string | null>(null)
if (props.id) {
  getEvent(props.id).then((e) => {
    Object.assign(form, { category: e.category, subtype: e.subtype, title: e.title, description: e.description, starts_at: local(e.starts_at), ends_at: local(e.ends_at), meeting_point: e.meeting_point, visibility: e.visibility, join_method: e.join_method, capacity: e.capacity ?? '', waitlist_enabled: e.waitlist_enabled })
  }).catch((e) => (loadError.value = errorMessage(e)))
}
const errors = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const busy = ref(false)

async function save() {
  busy.value = true
  errors.value = {}
  error.value = null
  const body = { ...form, capacity: form.capacity === '' ? null : Number(form.capacity), route_id: form.route_id || null, starts_at: form.starts_at ? new Date(form.starts_at).toISOString() : '', ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : '' }
  try {
    const data = await saveEvent(props.id, body)
    await router.push(`/events/${data.data.id}/manage`)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="wrap max-w-3xl pt-28 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">{{ id ? 'Edit event' : 'Host an event' }}</h1>
    <p class="mt-3 text-sm text-muted">Saved as a draft first. Riders see it only after you publish it.</p>
    <p v-if="loadError" role="alert" class="mt-6 text-danger">{{ loadError }}</p>
    <form class="mt-8 grid gap-6" novalidate @submit.prevent="save">
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
      <div class="grid gap-6 sm:grid-cols-[12rem_1fr]">
        <div class="field"><label for="cat">Kind</label><select id="cat" v-model="form.category" class="input"><option value="ride">Ride</option><option value="gathering">Gathering</option></select></div>
        <div class="field"><label for="sub">Type</label><input id="sub" v-model="form.subtype" class="input" maxlength="40" :placeholder="form.category === 'ride' ? 'For example: weekend ride' : 'For example: maintenance workshop'" :aria-invalid="!!errors.subtype" /><p v-if="errors.subtype" class="field-error">{{ errors.subtype }}</p></div>
      </div>
      <div class="field"><label for="title">Title</label><input id="title" v-model="form.title" class="input" maxlength="140" :aria-invalid="!!errors.title" /><p v-if="errors.title" class="field-error">{{ errors.title }}</p></div>
      <div class="field"><label for="desc">Description</label><textarea id="desc" v-model="form.description" class="input" rows="6" maxlength="10000" :aria-invalid="!!errors.description" /><p v-if="errors.description" class="field-error">{{ errors.description }}</p></div>
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="field"><label for="start">Starts</label><input id="start" v-model="form.starts_at" type="datetime-local" class="input" :aria-invalid="!!errors.starts_at" /><p v-if="errors.starts_at" class="field-error">{{ errors.starts_at }}</p></div>
        <div class="field"><label for="end">Ends</label><input id="end" v-model="form.ends_at" type="datetime-local" class="input" :aria-invalid="!!errors.ends_at" /><p v-if="errors.ends_at" class="field-error">{{ errors.ends_at }}</p></div>
      </div>
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="field"><label for="meet">Meeting point</label><input id="meet" v-model="form.meeting_point" class="input" maxlength="200" :aria-invalid="!!errors.meeting_point" /><p v-if="errors.meeting_point" class="field-error">{{ errors.meeting_point }}</p></div>
        <div class="field"><label for="route">Route <span class="font-normal text-muted">(optional)</span></label><select id="route" v-model="form.route_id" class="input"><option value="">None</option><option v-for="r in routes.data.value ?? []" :key="r.id" :value="r.id">{{ r.name }}</option></select></div>
      </div>
      <fieldset class="grid gap-6 sm:grid-cols-3">
        <legend class="mb-2 text-sm font-semibold">Who can join</legend>
        <div class="field"><label for="vis">Visibility</label><select id="vis" v-model="form.visibility" class="input"><option value="public">Public</option><option value="private">Private (invited riders only)</option></select></div>
        <div class="field"><label for="jm">Joining</label><select id="jm" v-model="form.join_method" class="input"><option value="instant">Instant</option><option value="approval">I approve each request</option></select></div>
        <div class="field"><label for="cap">Places <span class="font-normal text-muted">(empty = no limit)</span></label><input id="cap" v-model="form.capacity" type="number" min="1" max="1000" class="input" :aria-invalid="!!errors.capacity" /><p v-if="errors.capacity" class="field-error">{{ errors.capacity }}</p></div>
        <label class="flex items-center gap-2 text-sm sm:col-span-3"><input v-model="form.waitlist_enabled" type="checkbox" class="accent-crimson" /> Keep a waitlist when full (riders move up automatically)</label>
      </fieldset>
      <button type="submit" class="btn btn-primary justify-self-start" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : id ? 'Save changes' : 'Save draft' }}</button>
    </form>
  </div>
</template>
