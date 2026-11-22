import { Castle, Coffee, Compass, Droplets, Landmark, MapPin, Mountain, Palette, Star, Waves } from 'lucide-vue-next'
import type { PlaceCategory } from './services/places'

/** Plain names and Lucide icons for place categories (same list as the API). */
export const placeCategories: Record<PlaceCategory, { label: string; icon: typeof Mountain }> = {
  viewpoint: { label: 'Viewpoint', icon: Mountain },
  lake: { label: 'Lake', icon: Waves },
  waterfall: { label: 'Waterfall', icon: Droplets },
  temple: { label: 'Temple', icon: Landmark },
  monastery: { label: 'Monastery', icon: Landmark },
  heritage: { label: 'Historical place', icon: Castle },
  cultural: { label: 'Cultural place', icon: Palette },
  attraction: { label: 'Attraction', icon: Star },
  rest_stop: { label: 'Rest stop', icon: Coffee },
  hidden_place: { label: 'Hidden place', icon: Compass },
  other: { label: 'Other', icon: MapPin },
}
export const isPlaceCategory = (k: string): k is PlaceCategory => k in placeCategories
export const statusLabels: Record<string, string> = {
  draft: 'Draft', pending_review: 'Waiting for review', changes_requested: 'Changes requested', published: 'Published', rejected: 'Not accepted', archived: 'Archived',
}
