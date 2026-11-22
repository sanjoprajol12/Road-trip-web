<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Map as LeafletMap, CircleMarker } from 'leaflet'
import { addTiles } from '@/lib/mapTiles'
import { site } from '@/lib/site'

// Click the map to set a position; the latitude/longitude fields stay the keyboard-friendly way to type one.
// Limited to Nepal (the API and the database check it again).
const lat = defineModel<number | string>('lat', { required: true })
const lng = defineModel<number | string>('lng', { required: true })
const el = ref<HTMLDivElement | null>(null)
const failed = ref(false)
let map: LeafletMap | null = null
let marker: CircleMarker | null = null
let L: typeof import('leaflet') | null = null
const round = (n: number) => Math.round(n * 1e6) / 1e6
const current = (): [number, number] | null => (lat.value !== '' && lng.value !== '' ? [Number(lat.value), Number(lng.value)] : null)
function place(p: [number, number]) {
  if (!map || !L) return
  if (marker) marker.setLatLng(p)
  else marker = L.circleMarker(p, { radius: 9, color: '#a51d2d', fillColor: '#a51d2d', fillOpacity: 0.85, weight: 2 }).addTo(map)
}
onMounted(async () => {
  if (!el.value) return
  try {
    L = (await import('leaflet')).default
    await import('leaflet/dist/leaflet.css')
    const m = site.value?.maps
    map = L.map(el.value, { maxBounds: [[26, 80], [31, 89]], maxBoundsViscosity: 1, minZoom: 6, zoomAnimation: !matchMedia('(prefers-reduced-motion: reduce)').matches })
    addTiles(L, map)
    const p = current()
    map.setView(p ?? [m?.default_lat ?? 28.2, m?.default_lng ?? 84.1], p ? 13 : (m?.default_zoom ?? 7))
    if (p) place(p)
    map.on('click', (e) => {
      lat.value = round(e.latlng.lat)
      lng.value = round(e.latlng.lng)
    })
  } catch {
    failed.value = true
  }
})
watch([lat, lng], () => {
  const p = current()
  if (p && p[0] >= 26 && p[0] <= 31 && p[1] >= 80 && p[1] <= 89) place(p)
})
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <div class="grid gap-1">
    <div ref="el" class="isolate h-72 rounded-md border border-line bg-surface" data-testid="location-picker" role="application" aria-label="Map: click to mark the place. You can also type the coordinates below." />
    <p class="text-xs text-muted">{{ failed ? 'The map could not load. Type the coordinates instead.' : 'Click the map where the place is. Zoom in for accuracy.' }}</p>
  </div>
</template>
