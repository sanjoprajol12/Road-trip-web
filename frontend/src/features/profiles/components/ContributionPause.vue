<script setup lang="ts">
import { ref } from 'vue'
import { PauseCircle } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import { appealContributionPause } from '../services/account'
import { formatDay } from '@/lib/format'
import { useAuth } from '@/features/authentication/useAuth'

// Shown while a moderator has paused this rider's contribution rights: the reason, the end date and one appeal.
const { user, refresh } = useAuth()
const appeal = ref('')
const msg = ref<string | null>(null)
async function send() {
  try {
    msg.value = await appealContributionPause(appeal.value)
    await refresh()
  } catch (e) {
    msg.value = errorMessage(e)
  }
}
</script>

<template>
  <section v-if="user?.contribution_suspension" class="mt-10 max-w-2xl rounded-md border border-warning/40 bg-surface p-5" aria-labelledby="pause-title">
    <h2 id="pause-title" class="flex items-center gap-2 font-bold"><PauseCircle class="size-5 text-warning" aria-hidden="true" /> Contributions paused until {{ formatDay(user.contribution_suspension.until) }}</h2>
    <p class="mt-2 text-sm">{{ user.contribution_suspension.reason }}</p>
    <p class="mt-2 text-sm text-muted">You can still read, plan trips, save places and join events. You cannot submit, correct, confirm or report contributions until then.</p>
    <p v-if="user.contribution_suspension.appeal_decision === 'upheld'" class="mt-3 text-sm font-semibold">Your appeal was reviewed and the pause stays.</p>
    <p v-else-if="user.contribution_suspension.appealed_at" class="mt-3 text-sm font-semibold">Appeal sent {{ formatDay(user.contribution_suspension.appealed_at) }}. A moderator will review it.</p>
    <form v-else class="mt-4 grid gap-2" @submit.prevent="send">
      <label for="appeal" class="text-sm font-semibold">Appeal (once)</label>
      <textarea id="appeal" v-model="appeal" class="input" rows="3" maxlength="2000" placeholder="Explain why the pause should be lifted." />
      <button type="submit" class="btn btn-quiet justify-self-start" :disabled="appeal.trim().length < 20">Send appeal</button>
    </form>
    <p v-if="msg" class="mt-2 text-sm" role="status">{{ msg }}</p>
  </section>
</template>
