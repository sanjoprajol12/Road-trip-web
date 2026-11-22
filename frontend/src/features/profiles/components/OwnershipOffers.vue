<script setup lang="ts">
import { ref } from 'vue'
import { CircleCheck } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import { answerOffer, incomingOffers } from '../services/account'

// Another rider is closing their account and offers you their event or group trip. Nothing changes unless you accept.
const offers = useAsync(() => incomingOffers())
const busy = ref<string | null>(null)
const busyAction = ref<'accept' | 'decline' | null>(null)
const message = ref<string | null>(null)
const error = ref<string | null>(null)

async function answer(id: string, a: 'accept' | 'decline') {
  busy.value = id
  busyAction.value = a
  error.value = null
  try {
    message.value = await answerOffer(id, a)
    await offers.run()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section v-if="(offers.data.value ?? []).length || message" id="offers" class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="offers-title">
    <h2 id="offers-title" class="display text-3xl">Offers to take over</h2>
    <p class="mt-2 text-sm text-muted">These riders are closing their accounts. If you accept, you become the organizer or owner; if you decline, they can ask someone else.</p>
    <p v-if="message" class="feedback-in mt-4 flex items-center gap-1.5 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> {{ message }}</p>
    <p v-if="error" role="alert" class="feedback-in mt-4 rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ error }}</p>
    <ul class="mt-4 grid gap-3">
      <li v-for="o in offers.data.value ?? []" :key="o.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-4">
        <span>
          <span class="block font-semibold text-balance wrap-anywhere">{{ o.title }}</span>
          <span class="block text-sm text-muted">{{ o.subject_type === 'event' ? 'Event' : 'Group trip' }} from {{ o.from.display_name }} · offered {{ formatDay(o.offered_at) }}</span>
        </span>
        <span class="flex shrink-0 gap-2">
          <button type="button" class="btn btn-primary" :disabled="busy === o.id" :aria-label="`Accept ${o.title}`" :aria-busy="busy === o.id && busyAction === 'accept'" @click="answer(o.id, 'accept')">{{ busy === o.id && busyAction === 'accept' ? 'Accepting…' : 'Accept' }}</button>
          <button type="button" class="btn btn-quiet" :disabled="busy === o.id" :aria-label="`Decline ${o.title}`" :aria-busy="busy === o.id && busyAction === 'decline'" @click="answer(o.id, 'decline')">{{ busy === o.id && busyAction === 'decline' ? 'Declining…' : 'Decline' }}</button>
        </span>
      </li>
    </ul>
  </section>
</template>
