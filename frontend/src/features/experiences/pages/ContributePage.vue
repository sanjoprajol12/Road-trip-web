<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CircleCheck, ImagePlus } from 'lucide-vue-next'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { contributionKindLabels } from '../labels'
import type { Contribution, ContributionKind } from '@/types/api'
import { getContribution, saveContribution, uploadPhoto } from '../services/contributions'
import { getRoute, listRoutes } from '@/features/routes/services/routes'
import { listDestinations } from '@/features/explore/services/destinations'

// Without an id: a new contribution. With an id: the author edits it while it still waits for review.
const props = defineProps<{ id?: string }>()
const route = useRoute()
const destinations = useAsync(() => listDestinations())
const routes = useAsync(() => listRoutes())
const routeSlug = ref(String(route.query.route ?? ''))
const routeDetail = useAsync(async () => (routeSlug.value ? await getRoute(routeSlug.value) : null))

const today = new Date().toISOString().slice(0, 10)
const form = reactive({
  kind: (route.query.segment ? 'road_report' : '') as ContributionKind | '',
  title: '',
  body: '',
  observed_on: '',
  destination_id: '',
  route_segment_id: String(route.query.segment ?? ''),
  details: { motorcycle: '', engine_cc: '', duration_days: '', expenses_npr: '', surface: '', problems: '', lessons: '', wish_known: '', places: '' },
})
// Progressive form: only the questions that matter for this kind of report.
const needsSegment = computed(() => form.kind === 'road_report')
const isTrip = computed(() => form.kind === 'trip')
const errors = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const busy = ref(false)
const created = ref<Contribution | null>(null)
const editing = computed(() => !!props.id)
const loadError = ref<string | null>(null)
const locked = ref(false)

onMounted(async () => {
  if (!props.id) return
  try {
    const c = await getContribution(props.id)
    if (c.status !== 'pending_review') {
      locked.value = true
      return
    }
    // The API names the destination by slug; the form needs its id.
    const dests = await listDestinations()
    Object.assign(form, {
      kind: c.kind, title: c.title, body: c.body, observed_on: c.observed_on,
      destination_id: dests.find((d) => d.slug === c.destination?.slug)?.id ?? '',
      route_segment_id: c.segment?.id ?? '',
    })
    for (const [k, v] of Object.entries(c.details ?? {})) (form.details as Record<string, string>)[k] = String(v ?? '')
    if (c.route?.slug) {
      routeSlug.value = c.route.slug
      await routeDetail.run()
    }
  } catch (e) {
    loadError.value = errorMessage(e)
  }
})

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = null
  const d = Object.fromEntries(Object.entries(form.details).filter(([, v]) => v !== '').map(([k, v]) => [k, ['engine_cc', 'duration_days', 'expenses_npr'].includes(k) ? Number(v) : v]))
  try {
    const body = {
      kind: form.kind, title: form.title, body: form.body, observed_on: form.observed_on,
      destination_id: form.destination_id || null, route_segment_id: needsSegment.value ? form.route_segment_id || null : null, details: d,
    }
    created.value = await saveContribution(props.id, body)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Photos are added after the text is saved; the server checks and re-encodes every file.
const photo = ref<File | null>(null)
const alt = ref('')
const uploads = ref<{ url: string; alt: string }[]>([])
const uploadError = ref<string | null>(null)
const uploading = ref(false)
async function upload() {
  if (!created.value || !photo.value) return
  uploading.value = true
  uploadError.value = null
  const fd = new FormData()
  fd.append('photo', photo.value)
  fd.append('alt', alt.value)
  try {
    const data = await uploadPhoto(created.value.id, fd)
    uploads.value.push(data.data)
    photo.value = null
    alt.value = ''
  } catch (e) {
    uploadError.value = errorMessage(e)
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="wrap max-w-3xl pt-28 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">{{ editing ? 'Edit your contribution' : 'Share what you found' }}</h1>
    <p class="lead mt-4 text-muted">{{ editing ? 'You can change it until a moderator reviews it.' : 'Moderators review every contribution before it is published. Your words are published as you wrote them.' }}</p>

    <p v-if="loadError" role="alert" class="mt-10 rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ loadError }}</p>
    <p v-else-if="locked" role="status" class="mt-10 rounded-md border border-line bg-surface px-4 py-3 text-sm">
      This contribution has already been reviewed, so it can no longer be changed. <RouterLink :to="`/experiences/${props.id}`" class="link font-semibold">Back to it</RouterLink>
    </p>

    <div v-else-if="created" class="mt-10 grid gap-6">
      <p class="flex items-center gap-2 text-success" role="status"><CircleCheck class="size-5" aria-hidden="true" /> {{ editing ? 'Changes saved. It is still waiting for review.' : 'Sent for review.' }} You can follow it in <RouterLink to="/account#activity" class="link font-semibold">your account</RouterLink>.</p>
      <form class="grid gap-4 rounded-md border border-line bg-surface p-5" @submit.prevent="upload">
        <h2 class="font-semibold">Add photos (optional, up to 6)</h2>
        <p class="text-sm text-muted">JPEG, PNG or WebP. Location data is removed from every photo.</p>
        <p v-if="uploadError" role="alert" class="text-sm text-danger">{{ uploadError }}</p>
        <div class="field">
          <label for="photo">Photo</label>
          <input id="photo" type="file" accept="image/jpeg,image/png,image/webp" class="text-sm" @change="photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        </div>
        <div class="field">
          <label for="alt">Describe the photo</label>
          <input id="alt" v-model="alt" class="input" maxlength="200" placeholder="For example: mud on the road below the bridge" />
        </div>
        <button type="submit" class="btn btn-quiet justify-self-start" :disabled="!photo || alt.trim().length < 5 || uploading" :aria-busy="uploading"><ImagePlus class="size-4" aria-hidden="true" /> {{ uploading ? 'Uploading…' : 'Add photo' }}</button>
        <div v-if="uploads.length" class="grid grid-cols-3 gap-2"><img v-for="u in uploads" :key="u.url" :src="u.url" :alt="u.alt" class="aspect-4/3 rounded-md object-cover" /></div>
      </form>
    </div>

    <form v-else class="mt-10 grid gap-6" novalidate @submit.prevent="submit">
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
      <div class="field">
        <label for="kind">What are you sharing?</label>
        <select id="kind" v-model="form.kind" class="input sm:w-80" :aria-invalid="!!errors.kind">
          <option value="" disabled>Choose one</option>
          <option v-for="(label, k) in contributionKindLabels" :key="k" :value="k">{{ label }}</option>
        </select>
        <p v-if="errors.kind" class="field-error">{{ errors.kind }}</p>
      </div>

      <template v-if="form.kind">
        <fieldset v-if="needsSegment" class="grid gap-4 sm:grid-cols-2">
          <legend class="mb-2 text-sm font-semibold">Which stretch of road?</legend>
          <div class="field">
            <label for="route">Route</label>
            <select id="route" v-model="routeSlug" class="input" @change="(form.route_segment_id = ''), routeDetail.run()">
              <option value="">Choose a route</option>
              <option v-for="r in routes.data.value ?? []" :key="r.id" :value="r.slug">{{ r.name }}</option>
            </select>
          </div>
          <div class="field">
            <label for="segment">Stretch</label>
            <select id="segment" v-model="form.route_segment_id" class="input" :disabled="!routeDetail.data.value" :aria-invalid="!!errors.route_segment_id">
              <option value="">Choose a stretch</option>
              <option v-for="s in routeDetail.data.value?.segments ?? []" :key="s.id" :value="s.id">{{ s.start_name }} to {{ s.end_name }}</option>
            </select>
            <p v-if="errors.route_segment_id" class="field-error">{{ errors.route_segment_id }}</p>
          </div>
        </fieldset>
        <div v-else class="field">
          <label for="dest">Destination <span class="font-normal text-muted">(optional)</span></label>
          <select id="dest" v-model="form.destination_id" class="input sm:w-80">
            <option value="">None</option>
            <option v-for="d in destinations.data.value ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </div>

        <div class="grid gap-6 sm:grid-cols-[1fr_12rem]">
          <div class="field">
            <label for="title">Title</label>
            <input id="title" v-model="form.title" class="input" maxlength="140" :aria-invalid="!!errors.title" />
            <p v-if="errors.title" class="field-error">{{ errors.title }}</p>
          </div>
          <div class="field">
            <label for="observed">Day you rode it</label>
            <input id="observed" v-model="form.observed_on" type="date" class="input" :max="today" :aria-invalid="!!errors.observed_on" />
            <p v-if="errors.observed_on" class="field-error">{{ errors.observed_on }}</p>
          </div>
        </div>
        <div class="field">
          <label for="body">What you found</label>
          <textarea id="body" v-model="form.body" class="input" rows="7" maxlength="10000" :aria-invalid="!!errors.body" aria-describedby="body-hint" />
          <p id="body-hint" :class="errors.body ? 'field-error' : 'field-hint'">{{ errors.body ?? 'Facts other riders can use. At least 20 characters.' }}</p>
        </div>

        <details class="rounded-md border border-line p-4" :open="isTrip">
          <summary class="cursor-pointer font-semibold">More details (optional)</summary>
          <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="field sm:col-span-2"><label for="moto">Motorcycle</label><input id="moto" v-model="form.details.motorcycle" class="input" maxlength="120" /></div>
            <div class="field"><label for="cc">Engine (cc)</label><input id="cc" v-model="form.details.engine_cc" type="number" min="50" max="2500" class="input" /></div>
            <template v-if="needsSegment">
              <div class="field">
                <label for="surface">Surface you found</label>
                <select id="surface" v-model="form.details.surface" class="input"><option value="">Not sure</option><option value="paved">Paved</option><option value="mixed">Mixed</option><option value="gravel">Gravel</option><option value="dirt">Dirt</option><option value="blocked">Blocked</option></select>
              </div>
            </template>
            <template v-if="isTrip">
              <div class="field"><label for="days">Trip length (days)</label><input id="days" v-model="form.details.duration_days" type="number" min="1" max="365" class="input" /></div>
              <div class="field"><label for="npr">Approximate spend (NPR)</label><input id="npr" v-model="form.details.expenses_npr" type="number" min="0" class="input" /></div>
              <div class="field sm:col-span-3"><label for="places">Places visited</label><textarea id="places" v-model="form.details.places" class="input" rows="2" maxlength="2000" /></div>
            </template>
            <div class="field sm:col-span-3"><label for="problems">Problems you ran into</label><textarea id="problems" v-model="form.details.problems" class="input" rows="2" maxlength="2000" /></div>
            <div class="field sm:col-span-3"><label for="wish">What you wish you had known</label><textarea id="wish" v-model="form.details.wish_known" class="input" rows="2" maxlength="2000" /></div>
            <div class="field sm:col-span-3"><label for="lessons">Lessons</label><textarea id="lessons" v-model="form.details.lessons" class="input" rows="2" maxlength="2000" /></div>
          </div>
        </details>

        <button type="submit" class="btn btn-primary justify-self-start" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : editing ? 'Save changes' : 'Send for review' }}</button>
      </template>
    </form>
  </div>
</template>
