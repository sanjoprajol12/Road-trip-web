<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { CircleCheck } from 'lucide-vue-next'
import AuthShell from '../components/AuthShell.vue'
import ResendConfirmation from '../components/ResendConfirmation.vue'
import { signIn } from '../useAuth'
import { errorMessage } from '@/lib/api/client'
import { safeNext } from '@/lib/safeNext'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

const notice = computed(() => {
  if (route.query.reset === 'done') return 'Password changed. Sign in with your new password.'
  if (route.query.verification === 'done') return 'Email confirmed. Sign in to continue.'
  return null
})
const linkProblem = computed(() => route.query.verification === 'invalid')
// Shown only after the server said the address is not confirmed (it reveals that only with the right password).
const unconfirmed = computed(() => !!error.value?.startsWith('Confirm your email'))

async function submit() {
  busy.value = true
  error.value = null
  try {
    await signIn(email.value.trim(), password.value)
    await router.replace(safeNext(route.query.next))
  } catch (e) {
    // Server messages: wrong details, unconfirmed email, suspended account, too many attempts.
    error.value = errorMessage(e).replace('These credentials do not match our records.', 'Email or password is incorrect.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Sign in" intro="Pick up your saved destinations and trip plans.">
    <form class="grid gap-5" novalidate @submit.prevent="submit">
      <p v-if="notice" role="status" class="flex gap-2 text-sm text-success"><CircleCheck class="size-4 shrink-0" aria-hidden="true" /> {{ notice }}</p>
      <p v-if="linkProblem" role="alert" class="rounded-md border border-warning/40 bg-surface px-4 py-3 text-sm">
        That confirmation link has expired or was already used. Sign in to get a new one.
      </p>
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
      <ResendConfirmation v-if="unconfirmed" :email="email.trim()" />
      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" class="input" autocomplete="email" required />
      </div>
      <div class="field">
        <div class="flex items-baseline justify-between">
          <label for="password" class="text-sm font-semibold">Password</label>
          <RouterLink to="/forgot-password" class="link text-sm">Forgot password?</RouterLink>
        </div>
        <input id="password" v-model="password" type="password" class="input" autocomplete="current-password" required />
      </div>
      <button type="submit" class="btn btn-primary" :disabled="busy || !email || !password" :aria-busy="busy">
        {{ busy ? 'Signing in…' : 'Sign in' }}
      </button>
      <p class="text-sm text-muted">
        New here? <RouterLink to="/sign-up" class="link font-semibold text-ink">Create an account</RouterLink>
      </p>
    </form>
  </AuthShell>
</template>
