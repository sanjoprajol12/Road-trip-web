<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { MailCheck } from 'lucide-vue-next'
import AuthShell from '../components/AuthShell.vue'
import ResendConfirmation from '../components/ResendConfirmation.vue'
import { site } from '@/lib/site'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { register } from '../services/auth'

const name = ref('')
const email = ref('')
const password = ref('')
const touched = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)
const sentTo = ref<string | null>(null)

const nameError = computed(() => (name.value.trim().length < 2 ? 'Enter at least 2 characters.' : null))
const emailError = computed(() => (/^\S+@\S+\.\S+$/.test(email.value.trim()) ? null : 'Enter a valid email address.'))
const passwordError = computed(() => (password.value.length < 8 ? 'Use at least 8 characters.' : null))

async function submit() {
  touched.value = true
  if (nameError.value || emailError.value || passwordError.value) return
  busy.value = true
  error.value = null
  try {
    // Same answer whether or not the address already has an account; no session until it is confirmed.
    await register({ display_name: name.value.trim(), email: email.value.trim(), password: password.value })
    sentTo.value = email.value.trim()
  } catch (e) {
    const fields = fieldErrors(e)
    error.value = fields.email ?? fields.password ?? fields.display_name ?? errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Create an account" intro="Free. Needed only to save things, plan trips and share what you found on the road.">
    <div v-if="sentTo" class="flex gap-3 border-t border-line pt-6" role="status">
      <MailCheck class="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
      <p>
        <span class="font-semibold">Check your email.</span>
        We sent a confirmation link to {{ sentTo }}. Open it to finish creating your account.
      </p>
    </div>
    <div v-if="sentTo" class="mt-6 grid gap-2 text-sm text-muted">
      <p>Nothing after a few minutes? Check your spam folder, or ask for a new link.</p>
      <ResendConfirmation :email="sentTo" />
    </div>
    <p v-else-if="site && !site.registration.open" class="rounded-md border border-line bg-surface px-4 py-3 text-sm" role="status">
      {{ site.registration.closed_message }} Already have an account? <RouterLink to="/sign-in" class="link font-semibold">Sign in</RouterLink>.
    </p>
    <form v-else class="grid gap-5" novalidate @submit.prevent="submit">
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
      <div class="field">
        <label for="name">Name shown to other riders</label>
        <input
          id="name"
          v-model="name"
          class="input"
          autocomplete="nickname"
          maxlength="60"
          :aria-invalid="touched && !!nameError"
          aria-describedby="name-err"
        />
        <p v-if="touched && nameError" id="name-err" class="field-error">{{ nameError }}</p>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          class="input"
          autocomplete="email"
          :aria-invalid="touched && !!emailError"
          aria-describedby="email-err"
        />
        <p v-if="touched && emailError" id="email-err" class="field-error">{{ emailError }}</p>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input
          id="password"
          v-model="password"
          type="password"
          class="input"
          autocomplete="new-password"
          :aria-invalid="touched && !!passwordError"
          aria-describedby="password-hint"
        />
        <p id="password-hint" :class="touched && passwordError ? 'field-error' : 'field-hint'">At least 8 characters.</p>
      </div>
      <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">
        {{ busy ? 'Creating account…' : 'Create account' }}
      </button>
      <p class="text-sm text-muted">
        Already riding with us? <RouterLink to="/sign-in" class="link font-semibold text-ink">Sign in</RouterLink>
      </p>
    </form>
  </AuthShell>
</template>
