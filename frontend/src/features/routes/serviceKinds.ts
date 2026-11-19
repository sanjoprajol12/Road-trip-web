import { Fuel, Hospital, Hotel, Landmark, Signal, UtensilsCrossed, Wrench } from 'lucide-vue-next'
import type { ServicePoint } from '@/types/api'

/** Icon and plain name for each kind of service point (route stretches and destinations). */
export const serviceKinds: Record<ServicePoint['kind'], { icon: typeof Fuel; label: string }> = {
  fuel: { icon: Fuel, label: 'Fuel' },
  mechanic: { icon: Wrench, label: 'Repairs' },
  medical: { icon: Hospital, label: 'Medical' },
  lodging: { icon: Hotel, label: 'Accommodation' },
  food: { icon: UtensilsCrossed, label: 'Food' },
  atm: { icon: Landmark, label: 'ATM' },
  connectivity: { icon: Signal, label: 'Mobile signal' },
}
