<script setup lang="ts">
import { computed, ref } from 'vue'
import { Ban, BadgeCheck, CircleX, Clock, PauseCircle } from 'lucide-vue-next'
import { applicableKinds, expertScopes, STATEMENT_MAX, statementError, verificationKindLabels, verificationStatusLabels, type ExpertScope, type VerificationKind, type VerificationStatus } from '@/features/verification/rules'
import { formatDay } from '@/lib/format'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { applyForVerification, listOwnVerifications } from '../services/verifications'
import { errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'

const claims = useAsync(listOwnVerifications)

const kindHelp: Partial<Record<VerificationKind, string>> = {
  event_organizer: 'Host rides and gatherings. Tell us which rides you have organised, roughly how many riders came, and where.',
  trip_contributor: 'Your trip reports get a verified label. Tell us which routes you have ridden, and when.',
  expert_reviewer: 'Review safety-critical guidance in one field only. Tell us your qualification and how long you have practised.',
}
const statusIcon: Record<VerificationStatus, { icon: typeof Clock; tone: string }> = {
  pending: { icon: Clock, tone: 'text-muted' },
  approved: { icon: BadgeCheck, tone: 'text-success' },
  rejected: { icon: CircleX, tone: 'text-danger' },
  suspended: { icon: PauseCircle, tone: 'text-warning' },
  revoked: { icon: Ban, tone: 'text-danger' },
}

const open = ['pending', 'approved', 'suspended'] as const
const isOpen = (kind: VerificationKind, scope: string) =>
  (claims.data.value ?? []).some((c) => c.kind === kind && c.scope === scope && (open as readonly string[]).includes(c.status))
const freeScopes = computed(() => (Object.keys(expertScopes) as ExpertScope[]).filter((s) => !isOpen('expert_reviewer', s)))
const availableKinds = computed(() =>
  applicableKinds.filter((k) => (k === 'expert_reviewer' ? freeScopes.value.length > 0 : !isOpen(k, ''))),
)

const formOpen = ref(false)
const kind = ref<VerificationKind | ''>('')
const scope = ref<ExpertScope | ''>('')
const statement = ref('')
const touched = ref(false)
const busy = ref(false)
const submitError = ref<string | null>(null)
const submitted = ref(false)
const stmtError = computed(() => statementError(statement.value))
const scopeLabel = (s: string) => (s ? expertScopes[s as ExpertScope] ?? s : '')

async function submit() {
  touched.value = true
  if (!kind.value || stmtError.value || (kind.value === 'expert_reviewer' && !scope.value)) return
  busy.value = true
  submitError.value = null
  try {
    await applyForVerification(kind.value, kind.value === 'expert_reviewer' ? scope.value : '', statement.value)
    formOpen.value = false
    kind.value = ''
    scope.value = ''
    statement.value = ''
    touched.value = false
    submitted.value = true
    await claims.run()
  } catch (e) {
    submitError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="verify-title">
    <h2 id="verify-title" class="display text-3xl">Verification</h2>
    <p class="mt-2 text-sm text-muted">
      Each claim is checked on its own. A verified trip contributor is not automatically an expert, and only verified
      organizers can host events. Staff can suspend or revoke a verification, and will tell you why.
    </p>

    <StatusPanel v-if="claims.loading.value" class="mt-6" state="loading" title="Loading your verifications" />
    <StatusPanel v-else-if="claims.error.value" class="mt-6" state="error" :message="claims.error.value" @retry="claims.run" />
    <template v-else>
      <p v-if="submitted" class="mt-6 flex items-center gap-2 text-sm text-success" role="status">
        <Clock class="size-4" aria-hidden="true" /> Application sent. Staff will review it.
      </p>

      <ul v-if="claims.data.value?.length" class="mt-6 grid border-t border-line" aria-label="Your verification claims">
        <li v-for="c in claims.data.value" :key="c.id" class="grid gap-2 border-b border-line py-4">
          <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <p class="font-semibold">
              {{ verificationKindLabels[c.kind] }}<span v-if="c.scope" class="font-normal text-muted">: {{ scopeLabel(c.scope) }}</span>
            </p>
            <p class="flex items-center gap-1.5 text-sm font-semibold" :class="statusIcon[c.status].tone">
              <component :is="statusIcon[c.status].icon" class="size-4" aria-hidden="true" />
              {{ verificationStatusLabels[c.status] }}
            </p>
          </div>
          <p class="text-xs text-muted">
            Applied {{ formatDay(c.created_at) }}<template v-if="c.reviewed_at"> · Decided {{ formatDay(c.reviewed_at) }}</template>
          </p>
          <p v-if="c.review_note" class="rounded-md bg-surface px-3 py-2 text-sm">
            <span class="font-semibold">Note from the reviewer:</span> {{ c.review_note }}
          </p>
        </li>
      </ul>
      <p v-else class="mt-6 text-sm text-muted">You have not applied for any verification.</p>

      <button v-if="!formOpen && availableKinds.length" type="button" class="btn btn-quiet mt-6" @click="formOpen = true">
        Apply for verification
      </button>

      <form v-if="formOpen" class="mt-6 grid gap-5 rounded-md border border-line bg-surface p-5" novalidate @submit.prevent="submit">
        <p v-if="submitError" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ submitError }}</p>
        <fieldset class="grid gap-2">
          <legend class="text-sm font-semibold">What should we verify?</legend>
          <label v-for="k in availableKinds" :key="k" class="flex cursor-pointer gap-3 rounded-md border border-line p-3 has-[:checked]:border-ink">
            <input v-model="kind" type="radio" name="kind" :value="k" class="mt-1 accent-crimson" />
            <span>
              <span class="block font-semibold">{{ verificationKindLabels[k] }}</span>
              <span class="block text-sm text-muted">{{ kindHelp[k] }}</span>
            </span>
          </label>
          <p v-if="touched && !kind" class="field-error">Choose what to verify.</p>
        </fieldset>
        <div v-if="kind === 'expert_reviewer'" class="field">
          <label for="scope">Field of expertise</label>
          <select id="scope" v-model="scope" class="input sm:w-72" :aria-invalid="touched && !scope">
            <option value="" disabled>Choose one</option>
            <option v-for="s in freeScopes" :key="s" :value="s">{{ expertScopes[s] }}</option>
          </select>
        </div>
        <div class="field">
          <label for="statement">Your statement</label>
          <textarea
            id="statement"
            v-model="statement"
            class="input"
            rows="5"
            :maxlength="STATEMENT_MAX"
            :aria-invalid="touched && !!stmtError"
            aria-describedby="statement-hint"
          />
          <p id="statement-hint" :class="touched && stmtError ? 'field-error' : 'field-hint'">
            {{ (touched && stmtError) || `Facts staff can check. ${STATEMENT_MAX - statement.length} characters left.` }}
          </p>
        </div>
        <div class="flex flex-wrap gap-3">
          <button type="submit" class="btn btn-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Sending…' : 'Send application' }}</button>
          <button type="button" class="btn btn-quiet" @click="formOpen = false">Cancel</button>
        </div>
      </form>
    </template>
  </section>
</template>
