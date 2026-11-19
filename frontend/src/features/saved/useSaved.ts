import { ref, watch } from 'vue'
import { useAuth } from '@/features/authentication/useAuth'
import { listSaved, saveItem, unsaveItem, type SavedType } from './services/saved'

// Which items the signed-in rider saved, loaded once per sign-in and shared by every Save button.
const keys = ref(new Set<string>())
let loadedFor: string | null = null
const key = (type: SavedType, id: string) => `${type}:${id}`

export function useSaved() {
  const { user } = useAuth()
  watch(user, async (u) => {
    if (!u) return void ((keys.value = new Set()), (loadedFor = null))
    if (loadedFor === u.id) return
    loadedFor = u.id
    try {
      keys.value = new Set((await listSaved()).map((i) => key(i.type, i.id)))
    } catch {
      loadedFor = null // try again next time; buttons still work
    }
  }, { immediate: true })

  const isSaved = (type: SavedType, id: string) => keys.value.has(key(type, id))
  async function toggle(type: SavedType, id: string) {
    const k = key(type, id)
    if (keys.value.has(k)) await unsaveItem(type, id)
    else await saveItem(type, id)
    const next = new Set(keys.value)
    if (next.has(k)) next.delete(k)
    else next.add(k)
    keys.value = next
  }
  return { isSaved, toggle, user }
}
