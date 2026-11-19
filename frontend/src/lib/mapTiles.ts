import type { Map as LeafletMap } from 'leaflet'
import { site } from '@/lib/site'

type L = typeof import('leaflet')

/** Adds the base map from the Maps settings (tile URL, attribution). Leaflet renders attribution as HTML, so staff text is escaped. */
export function addTiles(L: L, map: LeafletMap) {
  const m = site.value?.maps
  const esc = (t: string) => t.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
  const attribution = m ? (m.attribution_url ? `<a href="${esc(m.attribution_url)}">${esc(m.attribution)}</a>` : esc(m.attribution)) : '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  L.tileLayer(m?.tile_url ?? 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution }).addTo(map)
}
