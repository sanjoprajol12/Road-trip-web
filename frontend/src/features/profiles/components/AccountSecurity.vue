<script setup lang="ts">
import { ref } from 'vue'
import { CircleCheck, Monitor } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { changePassword as savePassword, mySessions, signOutOtherSessions } from '../services/account'

interface SessionRow {
  current: boolean
  ip_address: string | null
  user_agent: string
  last_active_at: string
}
const sessions = useAsync(() => mySessions<SessionRow>())

const current = ref('')
const next = ref('')
const pwErrors = ref<Record<string, string>>({})
const pwError = ref<string | null>(null)
const pwSaved = ref(false)
const pwBusy = ref(false)

async function changePassword() {
  pwBusy.value = true
  pwErrors.value = {}
  pwError.value = null
  pwSaved.value = false
  try {
    await savePassword(current.value, next.value)
    current.value = ''
    next.value = ''
    pwSaved.value = true
    await sessions.run()
  } catch (e) {
    pwErrors.value = fieldErrors(e)
    if (!Object.keys(pwErrors.value).length) pwError.value = errorMessage(e)
  } finally {
    pwBusy.value = false
  }
}

const signOutMsg = ref<string | null>(null)
async function signOutOthers() {
  try {
    signOutMsg.value = await signOutOtherSessions()
    await sessions.run()
  } catch (e) {
    signOutMsg.value = errorMessage(e)
  }
}
const device = (ua: string) => (/mobile|android|iphone/i.test(ua) ? 'Phone' : 'Computer') + (/firefox/i.test(ua) ? ' · Firefox' : /chrome|chromium/i.test(ua) ? ' · Chrome' : /safari/i.test(ua) ? ' · Safari' : '')
</script>

<template>
  <section id="security" class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="security-title">
    <h2 id="security-title" class="display text-3xl">Password and sessions</h2>

    <form class="mt-6 grid gap-5 sm:grid-cols-2" novalidate @submit.prevent="changePassword">
      <p v-if="pwError" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger sm:col-span-2">{{ pwError }}</p>
      <div class="field">
        <label for="current_password">Current password</label>
        <input id="current_password" v-model="current" type="password" class="input" autocomplete="current-password" :aria-invalid="!!pwErrors.current_password" aria-describedby="cp-err" />
        <p v-if="pwErrors.current_password" id="cp-err" class="field-error">{{ pwErrors.current_password }}</p>
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input id="new_password" v-model="next" type="password" class="input" autocomplete="new-password" :aria-invalid="!!pwErrors.password" aria-describedby="np-hint" />
        <p id="np-hint" :class="pwErrors.password ? 'field-error' : 'field-hint'">{{ pwErrors.password ?? 'At least 8 characters. Other devices are signed out.' }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
        <button type="submit" class="btn btn-quiet" :disabled="pwBusy || !current || !next" :aria-busy="pwBusy">{{ pwBusy ? 'Changing…' : 'Change password' }}</button>
        <p v-if="pwSaved" class="flex items-center gap-1.5 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> Password changed</p>
      </div>
    </form>

    <h3 class="mt-10 font-semibold">Where you are signed in</h3>
    <StatusPanel v-if="sessions.loading.value" state="loading" title="Loading sessions" />
    <StatusPanel v-else-if="sessions.error.value" state="error" :message="sessions.error.value" @retry="sessions.run" />
    <template v-else>
      <ul class="mt-3 grid border-t border-line text-sm">
        <li v-for="(s, i) in sessions.data.value ?? []" :key="i" class="flex items-center gap-3 border-b border-line py-3">
          <Monitor class="size-4 text-muted" aria-hidden="true" />
          <span class="flex-1">{{ device(s.user_agent) }}<span v-if="s.current" class="font-semibold"> (this device)</span></span>
          <span class="text-muted">Active {{ formatDay(s.last_active_at) }}</span>
        </li>
      </ul>
      <button v-if="(sessions.data.value ?? []).length > 1" type="button" class="btn btn-quiet mt-4" @click="signOutOthers">Sign out everywhere else</button>
      <p v-if="signOutMsg" class="mt-2 text-sm" role="status">{{ signOutMsg }}</p>
    </template>
  </section>
</template>
