<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { MailCheck } from 'lucide-vue-next'
import AuthShell from '../components/AuthShell.vue'
import { api, errorMessage } from '@/lib/api/client'

const email = ref('')
const sent = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = null
  try {
    await api.post('/auth/forgot-password', { email: email.value.trim() })
    sent.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell title="Reset your password" intro="We will email you a link to choose a new one.">
    <!-- Same answer whether or not the address has an account, so this cannot be used to find accounts. -->
    <div v-if="sent" class="flex gap-3 border-t border-line pt-6" role="status">
      <MailCheck class="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
      <p>If an account uses that address, a reset link is on its way. It expires after a while, so use it soon.</p>
    </div>
    <form v-else class="grid gap-5" @submit.prevent="submit">
      <p v-if="error" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" class="input" autocomplete="email" required />
      </div>
      <button type="submit" class="btn btn-primary" :disabled="busy || !email" :aria-busy="busy">
        {{ busy ? 'Sending…' : 'Send reset link' }}
      </button>
      <RouterLink to="/sign-in" class="link text-sm">Back to sign in</RouterLink>
    </form>
  </AuthShell>
</template>
