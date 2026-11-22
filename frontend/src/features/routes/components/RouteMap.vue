<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { Map as LeafletMap } from 'leaflet'
import type { Segment, ServicePoint } from '@/types/api'
import { formatDay } from '@/lib/format'
import { serviceKinds } from '../serviceKinds'
import { addTiles } from '@/lib/mapTiles'

// `nearby`: places off the route line (Explore Along My Route), drawn as hollow markers.
const props = defineProps<{ segments: Segment[]; nearby?: { name: string; position: [number, number] | null }[] }>()
const el = ref<HTMLDivElement | null>(null)
const failed = ref(false)
let map: LeafletMap | null = null

const surfaceColor: Record<string, string> = { paved: '#17181b', mixed: '#5c5850', gravel: '#8a5a00', dirt: '#a51d2d', unknown: '#b9b3a8' }

function servicePopup(svc: ServicePoint, stretch: string): HTMLElement {
  const box = document.createElement('div')
  box.setAttribute('data-testid', 'service-popup')
  for (const [text, bold] of [[svc.name, true], [`${serviceKinds[svc.kind].label} · ${stretch}`, false], [svc.notes ?? '', false],
    [svc.last_confirmed_on ? `Last confirmed ${formatDay(svc.last_confirmed_on)}` : 'Never confirmed. Check locally.', false]] as const) {
    if (!text) continue
    const p = document.createElement('p')
    p.textContent = text
    if (bold) p.style.fontWeight = '600'
    box.appendChild(p)
  }
  return box
}

onMounted(async () => {
  const lines = props.segments.filter((s) => s.start && s.end)
  if (!el.value || !lines.length) return
  try {
    // Leaflet loads only on pages with a map.
    const L = (await import('leaflet')).default
    await import('leaflet/dist/leaflet.css')
    map = L.map(el.value, { scrollWheelZoom: false, zoomAnimation: !matchMedia('(prefers-reduced-motion: reduce)').matches })
    addTiles(L, map)
    const bounds = L.latLngBounds([])
    for (const s of lines) {
      const pts: [number, number][] = [s.start!, s.end!]
      // Only an imported track is drawn as a road; without one, the checkpoints alone are shown (never a guessed line).
      if (s.path && s.path.length > 1) {
        L.polyline(s.path, { color: surfaceColor[s.surface] ?? '#17181b', weight: 5 }).addTo(map).bindTooltip(`${s.start_name} to ${s.end_name}`)
        for (const p of s.path) bounds.extend(p)
      }
      for (const p of pts) {
        L.circleMarker(p, { radius: 5, color: '#a51d2d', fillColor: '#faf9f7', fillOpacity: 1, weight: 2 }).addTo(map)
        bounds.extend(p)
      }
      // Recorded service positions only. Selecting a marker opens its details (built as text, never HTML).
      for (const svc of s.services) {
        if (!svc.position) continue
        L.circleMarker(svc.position, { radius: 6, color: '#285a8c', fillColor: '#faf9f7', fillOpacity: 1, weight: 2 }).addTo(map)
          .bindTooltip(`${serviceKinds[svc.kind].label}: ${svc.name}`).bindPopup(() => servicePopup(svc, `${s.start_name} to ${s.end_name}`))
        bounds.extend(svc.position)
      }
    }
    for (const p of props.nearby ?? []) if (p.position) L.circleMarker(p.position, { radius: 5, color: '#285a8c', weight: 2, fillOpacity: 0 }).addTo(map).bindTooltip(p.name)
    map.fitBounds(bounds, { padding: [24, 24] })
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => map?.remove())
</script>

<template>
  <figure v-if="segments.some((s) => s.start && s.end)" class="grid gap-2">
    <div ref="el" class="isolate h-80 rounded-md border border-line bg-surface md:h-[28rem]" role="img" aria-label="Map of the route's segments. The segment list below has the same information." />
    <figcaption class="text-xs text-muted">
      <template v-if="failed">The map could not load. The segment list below has the same information.</template>
      <template v-else-if="segments.some((s) => s.path?.length)">Lines are recorded tracks; colour shows surface: dark paved, grey mixed, brown gravel, crimson dirt. Dots are checkpoints.<template v-if="segments.some((s) => s.start && s.end && !s.path?.length)"> Stretches without a recorded track show only their checkpoints.</template></template>
      <template v-else>No recorded track for this route yet: the dots are its checkpoints, not the road between them.</template>
    </figcaption>
  </figure>
  <p v-else class="rounded-md border border-line bg-surface p-4 text-sm text-muted">No map positions have been recorded for this route yet.</p>
</template>
