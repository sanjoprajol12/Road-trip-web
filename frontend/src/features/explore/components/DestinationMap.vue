<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { Map as LeafletMap } from 'leaflet'
import { addTiles } from '@/lib/mapTiles'

// The destination, its services and nearby places on one map. Every marker is also in the lists on the page,
// so the map is a visual aid, not the only way to get the information.
export interface MapPoint { name: string; position: [number, number]; kind: 'here' | 'service' | 'destination' }
const props = defineProps<{ center: [number, number]; name: string; points: MapPoint[] }>()
const el = ref<HTMLDivElement | null>(null)
const failed = ref(false)
let map: LeafletMap | null = null
const colour = { here: '#a51d2d', service: '#285a8c', destination: '#17181b' }

onMounted(async () => {
  if (!el.value) return
  try {
    const L = (await import('leaflet')).default
    await import('leaflet/dist/leaflet.css')
    map = L.map(el.value, { scrollWheelZoom: false, zoomAnimation: !matchMedia('(prefers-reduced-motion: reduce)').matches }).setView(props.center, 11)
    addTiles(L, map)
    const bounds = L.latLngBounds([props.center])
    for (const p of props.points) {
      L.circleMarker(p.position, { radius: p.kind === 'service' ? 5 : 7, color: colour[p.kind], fillColor: '#faf9f7', fillOpacity: 1, weight: 2 }).addTo(map).bindTooltip(p.name)
      bounds.extend(p.position)
    }
    L.circleMarker(props.center, { radius: 9, color: colour.here, fillColor: colour.here, fillOpacity: 0.9, weight: 2 }).addTo(map).bindTooltip(props.name, { permanent: false })
    if (props.points.length) map.fitBounds(bounds, { padding: [32, 32], maxZoom: 12 })
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <figure class="grid gap-2">
    <div ref="el" class="h-72 rounded-md border border-line bg-surface md:h-96" role="img" :aria-label="`Map of ${name} and what is near it. The lists on this page have the same information.`" />
    <figcaption class="text-xs text-muted">
      <template v-if="failed">The map could not load. The lists on this page have the same information.</template>
      <template v-else>Crimson: {{ name }}. Blue: services. Dark: other destinations. Positions as recorded; check locally.</template>
    </figcaption>
  </figure>
</template>
