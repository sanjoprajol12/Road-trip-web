import { api } from '@/lib/api/client'
import type { Me } from '@/types/api'

export type ProfileEdit = Pick<Me, 'display_name' | 'username' | 'bio' | 'home_city'>

/** The server lets a rider change only these four fields, and only on their own account. */
export async function updateOwnProfile(edit: ProfileEdit): Promise<Me> {
  return (await api.patch<{ data: Me }>('/users/me', edit)).data.data
}
