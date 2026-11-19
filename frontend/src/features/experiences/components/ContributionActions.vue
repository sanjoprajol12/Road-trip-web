<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { CheckCheck, Flag, PenLine, Pencil } from 'lucide-vue-next'
import { errorMessage } from '@/lib/api/client'
import { useAuth } from '@/features/authentication/useAuth'
import type { Contribution, ReportCategory } from '@/types/api'
import { addCorrection, confirmStillAccurate, reportContribution } from '../services/contributions'

// What a signed-in rider can do with a report: the author edits (while pending) or adds a correction;
// other riders confirm it is still accurate or report a problem. The server decides; these are shortcuts.
const props = defineProps<{ c: Contribution }>()
const emit = defineEmits<{ changed: [] }>()
const { user } = useAuth()
const visible = ['published', 'community_corroborated', 'officially_sourced', 'outdated']
const categories: [ReportCategory, string][] = [
  ['dangerous', 'Dangerous advice or a safety risk'], ['wrong', 'Wrong information'], ['outdated', 'Out of date'],
  ['spam', 'Spam or advertising'], ['offensive', 'Offensive'], ['other', 'Something else'],
]
const msg = ref<string | null>(null)
const busy = ref(false)
async function run(action: () => Promise<string>) {
  busy.value = true
  try {
    msg.value = await action()
    emit('changed')
  } catch (e) {
    msg.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const reporting = ref(false)
const reason = ref('')
const category = ref<ReportCategory>('wrong')
const report = () => run(async () => {
  const m = await reportContribution(props.c.id, reason.value, category.value)
  reporting.value = false
  return m
})
const confirm = () => run(async () => (await confirmStillAccurate(props.c.id)).message)

const correcting = ref(false)
const correction = ref('')
const correct = () => run(async () => {
  await addCorrection(props.c.id, correction.value)
  correcting.value = false
  return 'Correction sent. It appears under your report once a moderator has seen it.'
})
</script>

<template>
  <template v-if="user && user.id === c.author?.id">
    <RouterLink v-if="c.status === 'pending_review'" :to="`/experiences/${c.id}/edit`" class="btn btn-quiet justify-self-start"><Pencil class="size-4" aria-hidden="true" /> Edit while it waits for review</RouterLink>
    <template v-else-if="visible.includes(c.status) || c.status === 'flagged'">
      <p v-if="c.correction_request" class="rounded-md border border-warning/40 bg-surface p-3"><span class="font-semibold">A moderator asked:</span> {{ c.correction_request }}</p>
      <button v-if="!correcting" type="button" class="link inline-flex items-center gap-1.5 justify-self-start" @click="correcting = true"><PenLine class="size-4" aria-hidden="true" /> Add a correction</button>
      <form v-else class="grid gap-2" @submit.prevent="correct">
        <label for="correction" class="font-semibold">Your correction</label>
        <p class="text-muted">Your original report stays as written. The correction is shown underneath it, with today's date.</p>
        <textarea id="correction" v-model="correction" class="input" rows="3" maxlength="2000" />
        <button type="submit" class="btn btn-quiet justify-self-start" :disabled="correction.trim().length < 10 || busy">Send correction</button>
      </form>
    </template>
  </template>
  <template v-else-if="user && visible.includes(c.status)">
    <button type="button" class="link inline-flex items-center gap-1.5 justify-self-start" :disabled="busy" @click="confirm"><CheckCheck class="size-4" aria-hidden="true" /> I rode this recently: still accurate</button>
    <button v-if="!reporting" type="button" class="link inline-flex items-center gap-1.5 justify-self-start" @click="reporting = true"><Flag class="size-4" aria-hidden="true" /> Report a problem</button>
    <form v-else class="grid gap-2" @submit.prevent="report">
      <label for="category" class="font-semibold">What kind of problem?</label>
      <select id="category" v-model="category" class="input"><option v-for="[v, l] in categories" :key="v" :value="v">{{ l }}</option></select>
      <label for="reason" class="font-semibold">What is wrong?</label>
      <textarea id="reason" v-model="reason" class="input" rows="3" maxlength="1000" placeholder="For example: the bridge was rebuilt in March." />
      <button type="submit" class="btn btn-quiet justify-self-start" :disabled="reason.trim().length < 10 || busy">Send to moderators</button>
    </form>
  </template>
  <p v-if="msg" role="status">{{ msg }}</p>
</template>
