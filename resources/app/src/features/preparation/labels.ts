import type { Guide } from '@/types/api'

export const categoryLabels: Record<Guide['category'], string> = {
  preparation: 'Preparing the trip',
  mechanical: 'Motorcycle and repairs',
  medical: 'First aid',
  road_safety: 'Road safety',
  weather: 'Weather',
  emergency: 'If something goes wrong',
}
