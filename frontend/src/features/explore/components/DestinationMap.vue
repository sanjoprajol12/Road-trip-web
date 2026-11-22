<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Map as LeafletMap } from 'leaflet'
import { addTiles } from '@/lib/mapTiles'

// Markers (and recorded route tracks) on one map. Every marker and line is also in the lists on the page, so the map
// is a visual aid, not the only way to the information. Lines are drawn only from imported tracks, never guessed.
/** `link`: an internal page; selecting the marker or line opens it (the same link is in the page's lists). */
export interface MapPoint { name: string; position: [number, number]; kind: 'here' | 'service' | 'destination' | 'place' | 'checkpoint'; link?: string }
export interface MapLine { name: string; path: [number, number][]; link?: string }
const props = defineProps<{ center: [number, number] | null; name: string; points: MapPoint[]; lines?: MapLine[] }>()
const el = ref<HTMLDivElement | null>(null)
const failed = ref(false)
let map: LeafletMap | null = null
const colour = { here: '#a51d2d', service: '#285a8c', destination: '#17181b', place: '#8a5a00', checkpoint: '#2f6b3a' }
const router = useRouter()

onMounted(async () => {
  if (!el.value) return
  try {
    const L = (await import('leaflet')).default
    await import('leaflet/dist/leaflet.css')
    map = L.map(el.value, { scrollWheelZoom: false, zoomAnimation: !matchMedia('(prefers-reduced-motion: reduce)').matches })
    addTiles(L, map)
    const bounds = L.latLngBounds(props.center ? [props.center] : [])
    // Lines first, so markers stay on top of them.
    for (const line of props.lines ?? []) {
      const pl = L.polyline(line.path, { color: '#5c5850', weight: 4, opacity: 0.8 }).addTo(map).bindTooltip(line.name, { sticky: true })
      if (line.link) pl.on('click', () => router.push(line.link!))
      for (const p of line.path) bounds.extend(p)
    }
    for (const p of props.points) {
      const marker = L.circleMarker(p.position, { radius: p.kind === 'service' ? 5 : 7, color: colour[p.kind], fillColor: '#faf9f7', fillOpacity: 1, weight: 2 }).addTo(map).bindTooltip(p.name)
      if (p.link) marker.on('click', () => router.push(p.link!))
      bounds.extend(p.position)
    }
    if (props.center) L.circleMarker(props.center, { radius: 9, color: colour.here, fillColor: colour.here, fillOpacity: 0.9, weight: 2 }).addTo(map).bindTooltip(props.name)
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [32, 32], maxZoom: 12 })
    else map.setView([28.2, 84.1], 7)
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <figure class="grid gap-2">
    <div ref="el" class="isolate h-72 rounded-md border border-line bg-surface md:h-96" role="img" :aria-label="`Map of ${name}. The lists on this page have the same information.`" />
    <figcaption class="text-xs text-muted">
      <template v-if="failed">The map could not load. The lists on this page have the same information.</template>
      <template v-else><template v-if="center">Crimson: {{ name }}. </template>Blue: services. Brown: places of interest. Dark: destinations.<template v-if="points.some((p) => p.kind === 'checkpoint')"> Green: route checkpoints.</template><template v-if="lines?.length"> Grey lines: recorded route tracks.</template> Select a marker or line to open it. Positions as recorded; check locally.</template>
    </figcaption>
  </figure>
</template>
