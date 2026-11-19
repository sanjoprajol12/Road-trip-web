<script setup lang="ts">
import { ref } from 'vue'
import { MailCheck } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import { resendConfirmation } from '../services/auth'

// The server answers the same whether or not the address exists, and limits how often links are sent.
const props = defineProps<{ email: string }>()
const busy = ref(false)
const message = ref<string | null>(null)
const failed = ref(false)

async function resend() {
  busy.value = true
  failed.value = false
  try {
    message.value = await resendConfirmation(props.email)
  } catch (e) {
    failed.value = true
    message.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="grid gap-2">
    <button type="button" class="btn btn-quiet justify-self-start" :disabled="busy || !email" :aria-busy="busy" @click="resend">
      <MailCheck class="size-4" aria-hidden="true" /> {{ busy ? 'Sending…' : 'Send a new confirmation link' }}
    </button>
    <p v-if="message" :role="failed ? 'alert' : 'status'" class="text-sm" :class="failed ? 'text-danger' : 'text-muted'">{{ message }}</p>
  </div>
</template>
