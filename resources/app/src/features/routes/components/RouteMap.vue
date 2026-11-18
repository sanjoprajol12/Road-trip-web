<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { Map as LeafletMap } from 'leaflet'
import type { Segment } from '@/types/api'

const props = defineProps<{ segments: Segment[] }>()
const el = ref<HTMLDivElement | null>(null)
const failed = ref(false)
let map: LeafletMap | null = null

const surfaceColor: Record<string, string> = { paved: '#17181b', mixed: '#5c5850', gravel: '#8a5a00', dirt: '#a51d2d', unknown: '#b9b3a8' }

onMounted(async () => {
  const lines = props.segments.filter((s) => s.start && s.end)
  if (!el.value || !lines.length) return
  try {
    // Leaflet loads only on pages with a map.
    const L = (await import('leaflet')).default
    await import('leaflet/dist/leaflet.css')
    map = L.map(el.value, { scrollWheelZoom: false, zoomAnimation: !matchMedia('(prefers-reduced-motion: reduce)').matches })
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map)
    const bounds = L.latLngBounds([])
    for (const s of lines) {
      const pts: [number, number][] = [s.start!, s.end!]
      L.polyline(pts, { color: surfaceColor[s.surface] ?? '#17181b', weight: 5 }).addTo(map).bindTooltip(`${s.start_name} to ${s.end_name}`)
      for (const p of pts) {
        L.circleMarker(p, { radius: 5, color: '#a51d2d', fillColor: '#faf9f7', fillOpacity: 1, weight: 2 }).addTo(map)
        bounds.extend(p)
      }
      for (const svc of s.services) if (svc.position) L.circleMarker(svc.position, { radius: 4, color: '#285a8c', weight: 2 }).addTo(map).bindTooltip(svc.name)
    }
    map.fitBounds(bounds, { padding: [24, 24] })
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <figure v-if="segments.some((s) => s.start && s.end)" class="grid gap-2">
    <div ref="el" class="h-80 rounded-md border border-line bg-surface md:h-[28rem]" role="img" aria-label="Map of the route's segments. The segment list below has the same information." />
    <figcaption class="text-xs text-muted">
      <template v-if="failed">The map could not load. The segment list below has the same information.</template>
      <template v-else>Line colour shows recorded surface: dark paved, grey mixed, brown gravel, crimson dirt. Positions are approximate.</template>
    </figcaption>
  </figure>
  <p v-else class="rounded-md border border-line bg-surface p-4 text-sm text-muted">No map positions have been recorded for this route yet.</p>
</template>
