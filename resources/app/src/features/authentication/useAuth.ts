import { computed, ref } from 'vue'
import { api, onUnauthorized } from '@/lib/api/client'
import type { Me } from '@/types/api'

// The signed-in rider, from the Laravel session cookie. A module-level ref: the only global state.
const me = ref<Me | null>(null)
let ready: Promise<void> | null = null

async function loadMe() {
  try {
    me.value = (await api.get<{ data: Me | null }>('/auth/session')).data.data
  } catch {
    me.value = null // server unreachable: treat as signed out; pages show their own errors
  }
}

onUnauthorized(() => (me.value = null))

export function initAuth(): Promise<void> {
  ready ??= loadMe()
  return ready
}

export async function signIn(email: string, password: string) {
  await api.post('/auth/login', { email, password })
  await loadMe()
}

export async function signOut() {
  try {
    await api.post('/auth/logout')
  } finally {
    me.value = null
  }
}

export function useAuth() {
  return {
    user: me,
    isSignedIn: computed(() => me.value !== null),
    refresh: loadMe,
  }
}
