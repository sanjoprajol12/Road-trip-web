<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AuthShell from '../components/AuthShell.vue'
import { errorMessage } from '@/lib/api/client'
import { resetPassword } from '../services/auth'

const route = useRoute()
const router = useRouter()
// The emailed link carries the one-time token and the address it was sent to.
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
const password = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

async function submit() {
  if (password.value.length < 8) {
    error.value = 'Use at least 8 characters.'
    return
  }
  busy.value = true
  error.value = null
  try {
    await resetPassword({ token: token.value, email: email.value, password: password.value })
    await router.replace({ path: '/sign-in', query: { reset: 'done' } })
  } catch (e) {
    error.value = errorMessage(e).replace('This password reset token is invalid.', 'This link has expired or was already used. Request a new one.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Choose a new password">
    <div v-if="!token || !email" class="grid gap-4 border-t border-line pt-6" role="status">
      <p>This link has expired or was already used.</p>
      <RouterLink to="/forgot-password" class="btn btn-quiet justify-self-start">Request a new link</RouterLink>
    </div>
    <form v-else class="grid gap-5" @submit.prevent="submit">
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">
        {{ error }} <RouterLink v-if="error.includes('expired')" to="/forgot-password" class="link font-semibold">Request a new link</RouterLink>
      </p>
      <p class="text-sm text-muted">For {{ email }}</p>
      <div class="field">
        <label for="password">New password</label>
        <input id="password" v-model="password" type="password" class="input" autocomplete="new-password" aria-describedby="pw-hint" />
        <p id="pw-hint" class="field-hint">At least 8 characters. You will be signed out everywhere else.</p>
      </div>
      <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save password' }}</button>
    </form>
  </AuthShell>
</template>
