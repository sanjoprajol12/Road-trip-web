<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Bookmark, MapPin, Route as RouteIcon, ScrollText, Trash2 } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { serviceKinds } from '@/features/routes/serviceKinds'
import AddToTrip from '@/features/rides/components/AddToTrip.vue'
import type { ServicePoint } from '@/types/api'
import { listSaved, unsaveItem, type SavedItem, type SavedType } from '../services/saved'

const tabs: [SavedType | 'all', string][] = [['all', 'Everything'], ['service', 'Services'], ['destination', 'Destinations'], ['route', 'Routes'], ['contribution', 'Experiences']]
const type = ref<SavedType | 'all'>('all')
const kind = ref('')
const saved = useAsync(() => listSaved({ type: type.value === 'all' ? undefined : type.value, kind: type.value === 'service' && kind.value ? kind.value : undefined }))
watch([type, kind], () => void saved.run())
watch(type, () => (kind.value = ''))
const items = computed(() => saved.data.value ?? [])

const canAddToTrip = (i: SavedItem) => i.type === 'service' || i.type === 'destination'
const tripKind = (i: SavedItem) => (i.type === 'service' ? 'service' : 'destination')
const msg = ref<string | null>(null)
async function remove(i: SavedItem) {
  try {
    await unsaveItem(i.type, i.id)
    msg.value = `${i.name} was removed from your saved list.`
    await saved.run()
  } catch (e) {
    msg.value = errorMessage(e)
  }
}
const iconFor = (i: SavedItem) => (i.type === 'service' ? (serviceKinds[i.kind as ServicePoint['kind']]?.icon ?? MapPin) : i.type === 'route' ? RouteIcon : i.type === 'contribution' ? ScrollText : MapPin)
</script>

<template>
  <div class="wrap pt-24 pb-24">
    <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Saved</h1>
    <p class="mt-3 max-w-2xl text-muted">Services, places, routes and experiences you kept for later. Details can change: check the confirmed date before you rely on one.</p>

    <div class="mt-8 flex flex-wrap gap-2" role="group" aria-label="Show">
      <button v-for="[v, l] in tabs" :key="v" type="button" class="btn" :class="type === v ? 'btn-primary' : 'btn-quiet'" :aria-pressed="type === v" @click="type = v">{{ l }}</button>
    </div>
    <div v-if="type === 'service'" class="field mt-4 max-w-xs">
      <label for="kind">Kind of service</label>
      <select id="kind" v-model="kind" class="input"><option value="">All services</option><option v-for="(k, v) in serviceKinds" :key="v" :value="v">{{ k.label }}</option></select>
    </div>
    <p v-if="msg" class="mt-4 text-sm" role="status">{{ msg }}</p>

    <StatusPanel v-if="saved.loading.value && !saved.data.value" class="mt-8" state="loading" title="Loading your saved list" />
    <StatusPanel v-else-if="saved.error.value" class="mt-8" state="error" :message="saved.error.value" @retry="saved.run" />
    <div v-else-if="!items.length" class="mt-8">
      <StatusPanel state="empty" title="Nothing saved here yet" message="Use the Save button on a destination, a route, a service or an experience." />
      <RouterLink to="/explore" class="link mt-4 inline-flex items-center gap-1.5 text-sm font-semibold"><Bookmark class="size-4" aria-hidden="true" /> Explore destinations</RouterLink>
    </div>
    <ul v-else class="mt-8 grid border-t border-line">
      <li v-for="i in items" :key="`${i.type}-${i.id}`" class="grid gap-3 border-b border-line py-5 md:grid-cols-[1fr_auto] md:items-center">
        <div class="flex gap-3">
          <component :is="iconFor(i)" class="mt-1 size-5 shrink-0 text-muted" aria-hidden="true" />
          <div>
            <p class="label text-muted">{{ i.type === 'service' ? (serviceKinds[i.kind as ServicePoint['kind']]?.label ?? 'Service') : tabs.find(([v]) => v === i.type)?.[1].replace(/s$/, '') }}</p>
            <RouterLink v-if="i.link" :to="i.link" class="link text-lg font-semibold">{{ i.name }}</RouterLink>
            <p v-else class="text-lg font-semibold">{{ i.name }}</p>
            <p v-if="i.place" class="text-sm text-muted">{{ i.place }}</p>
            <p class="text-xs text-muted">{{ i.last_confirmed_on ? `Last confirmed ${formatDay(i.last_confirmed_on)}` : 'Never confirmed' }} · saved {{ formatDay(i.saved_at) }}</p>
          </div>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <AddToTrip v-if="canAddToTrip(i)" :kind="tripKind(i)" :id="i.id" :name="i.name" :note="i.place" />
          <button type="button" class="btn btn-quiet min-h-9 px-3 text-sm" :aria-label="`Remove ${i.name} from saved`" @click="remove(i)"><Trash2 class="size-4" aria-hidden="true" /> Remove</button>
        </div>
      </li>
    </ul>
  </div>
</template>
