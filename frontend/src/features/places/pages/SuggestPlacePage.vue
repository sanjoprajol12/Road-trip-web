<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Send } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { placeCategories, statusLabels } from '../categories'
import LocationPicker from '../components/LocationPicker.vue'
import { myPlaces, suggestPlace, updateSuggestion, type Place, type PlaceBody } from '../services/places'

// Riders suggest places; every suggestion waits for a moderator. A suggestion sent back for changes can be edited here.
const mine = useAsync(() => myPlaces())
const blank = (): PlaceBody => ({ name: '', category: '', summary: null, description: '', latitude: '', longitude: '', observed_on: null })
const form = reactive<PlaceBody>(blank())
const editing = ref<string | null>(null)
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const duplicate = ref<{ slug: string; name: string } | null>(null)
const sent = ref<string | null>(null)
const busy = ref(false)

function edit(p: Place) {
  Object.assign(form, { name: p.name, category: p.category, summary: p.summary, description: p.description ?? '', latitude: p.position[0], longitude: p.position[1], observed_on: p.observed_on })
  editing.value = p.id
  sent.value = null
}
async function submit() {
  busy.value = true
  errors.value = {}
  failed.value = null
  duplicate.value = null
  try {
    const body = { ...form, summary: form.summary || null, observed_on: form.observed_on || null }
    if (editing.value) await updateSuggestion(editing.value, body)
    else await suggestPlace(body)
    sent.value = 'Thanks. Your suggestion is waiting for review. You can follow its status below.'
    Object.assign(form, blank())
    editing.value = null
    await mine.run()
  } catch (e) {
    errors.value = fieldErrors(e)
    duplicate.value = (e as { response?: { data?: { duplicate?: { slug: string; name: string } | null } } }).response?.data?.duplicate ?? null
    if (!Object.keys(errors.value).length) failed.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="wrap grid gap-12 pt-24 pb-24 lg:grid-cols-[1fr_22rem]">
    <section aria-labelledby="sp-title">
      <h1 id="sp-title" class="display text-[clamp(2.5rem,1.8rem+3vw,4rem)]">{{ editing ? 'Edit your suggestion' : 'Suggest a place' }}</h1>
      <p class="mt-3 max-w-2xl text-muted">A viewpoint, lake, temple or hidden spot other riders should know about. Moderators check every suggestion before it is shown. Only describe what you saw yourself.</p>
      <p v-if="sent" class="mt-6 rounded-md border border-line bg-surface p-4 font-semibold" role="status">{{ sent }}</p>
      <form class="mt-6 grid max-w-2xl gap-4" novalidate @submit.prevent="submit">
        <p v-if="failed" class="text-sm text-danger" role="alert">{{ failed }}</p>
        <p v-if="duplicate" class="text-sm" role="alert">It may already be listed: <RouterLink :to="`/places/${duplicate.slug}`" class="link font-semibold">{{ duplicate.name }}</RouterLink>. Suggest a correction there instead.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field"><label for="sp-name">Name</label><input id="sp-name" v-model="form.name" class="input" maxlength="120" :aria-invalid="!!errors.name" aria-describedby="sp-name-e" /><p v-if="errors.name" id="sp-name-e" class="field-error">{{ errors.name }}</p></div>
          <div class="field"><label for="sp-cat">Category</label><select id="sp-cat" v-model="form.category" class="input" :aria-invalid="!!errors.category"><option value="" disabled>Choose</option><option v-for="(c, k) in placeCategories" :key="k" :value="k">{{ c.label }}</option></select><p v-if="errors.category" class="field-error">{{ errors.category }}</p></div>
        </div>
        <div class="field"><label for="sp-sum">Short summary <span class="font-normal text-muted">(optional)</span></label><input id="sp-sum" v-model="form.summary" class="input" maxlength="300" /></div>
        <div class="field"><label for="sp-desc">What is there, and how do you reach it?</label><textarea id="sp-desc" v-model="form.description" class="input" rows="5" maxlength="5000" :aria-invalid="!!errors.description" aria-describedby="sp-desc-e" /><p v-if="errors.description" id="sp-desc-e" class="field-error">{{ errors.description }}</p></div>
        <fieldset class="grid gap-3">
          <legend class="font-semibold">Where is it?</legend>
          <LocationPicker v-model:lat="form.latitude" v-model:lng="form.longitude" />
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="field"><label for="sp-lat">Latitude</label><input id="sp-lat" v-model="form.latitude" type="number" step="0.000001" class="input" :aria-invalid="!!errors.latitude" /><p v-if="errors.latitude" class="field-error">{{ errors.latitude }}</p></div>
            <div class="field"><label for="sp-lng">Longitude</label><input id="sp-lng" v-model="form.longitude" type="number" step="0.000001" class="input" :aria-invalid="!!errors.longitude" /><p v-if="errors.longitude" class="field-error">{{ errors.longitude }}</p></div>
          </div>
        </fieldset>
        <div class="field sm:w-56"><label for="sp-obs">When were you there? <span class="font-normal text-muted">(optional)</span></label><input id="sp-obs" v-model="form.observed_on" type="date" class="input" /><p v-if="errors.observed_on" class="field-error">{{ errors.observed_on }}</p></div>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary" :disabled="busy"><Send class="size-4" aria-hidden="true" /> {{ editing ? 'Send again for review' : 'Send for review' }}</button>
          <button v-if="editing" type="button" class="btn btn-quiet" @click="(editing = null), Object.assign(form, blank())">Cancel</button>
        </div>
      </form>
    </section>

    <aside aria-labelledby="mine-title">
      <h2 id="mine-title" class="text-xl font-bold">Your suggestions</h2>
      <StatusPanel v-if="mine.loading.value && !mine.data.value" class="mt-4" state="loading" title="Loading" />
      <StatusPanel v-else-if="mine.error.value" class="mt-4" state="error" :message="mine.error.value" @retry="mine.run" />
      <p v-else-if="!mine.data.value?.length" class="mt-4 text-sm text-muted">None yet.</p>
      <ul v-else class="mt-4 grid border-t border-line text-sm" aria-label="Your suggestions">
        <li v-for="p in mine.data.value" :key="p.id" class="grid gap-1 border-b border-line py-3">
          <RouterLink :to="`/places/${p.slug}`" class="link font-semibold">{{ p.name }}</RouterLink>
          <span><span class="font-semibold">{{ statusLabels[p.status ?? 'pending_review'] }}</span> <span class="text-muted">· {{ placeCategories[p.category].label }}<template v-if="p.observed_on"> · seen {{ formatDay(p.observed_on) }}</template></span></span>
          <span v-if="p.moderation_note && ['changes_requested', 'rejected'].includes(p.status ?? '')" class="text-muted">Moderator: {{ p.moderation_note }}</span>
          <button v-if="p.status === 'changes_requested' || p.status === 'pending_review'" type="button" class="link justify-self-start" @click="edit(p)">Edit</button>
        </li>
      </ul>
    </aside>
  </div>
</template>
