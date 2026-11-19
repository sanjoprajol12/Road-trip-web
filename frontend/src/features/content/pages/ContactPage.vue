<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Send } from 'lucide-vue-next'
import { errorMessage, fieldErrors } from '@/lib/api/client'
import { site, setPageMeta } from '@/lib/site'
import { sendContact } from '../services/content'

// Contact form. "website" is a honeypot people never see; the API refuses messages that fill it.
const form = reactive({ name: '', email: '', subject: '', body: '', website: '' })
const errors = ref<Record<string, string>>({})
const sent = ref<string | null>(null)
const failed = ref<string | null>(null)
const busy = ref(false)
async function submit() {
  busy.value = true
  errors.value = {}
  failed.value = null
  try {
    sent.value = await sendContact(form)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) failed.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
onMounted(() => setPageMeta('Contact'))
</script>

<template>
  <div class="wrap grid gap-12 pt-24 pb-24 lg:grid-cols-[1fr_20rem]">
    <div class="max-w-2xl">
      <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Contact</h1>
      <p class="mt-4 text-muted">Questions about the website, a correction to a destination or route, or a partnership. This is not an emergency service: in an emergency, call local emergency services.</p>
      <p v-if="sent" class="mt-8 rounded-md border border-line bg-surface p-4 font-semibold" role="status">{{ sent }}</p>
      <form v-else class="mt-8 grid gap-4" novalidate @submit.prevent="submit">
        <p v-if="failed" class="text-sm text-danger" role="alert">{{ failed }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="field"><label for="c-name">Your name</label><input id="c-name" v-model="form.name" class="input" autocomplete="name" maxlength="100" :aria-invalid="!!errors.name" aria-describedby="c-name-e" /><p v-if="errors.name" id="c-name-e" class="field-error">{{ errors.name }}</p></div>
          <div class="field"><label for="c-email">Email</label><input id="c-email" v-model="form.email" type="email" class="input" autocomplete="email" maxlength="255" :aria-invalid="!!errors.email" aria-describedby="c-email-e" /><p v-if="errors.email" id="c-email-e" class="field-error">{{ errors.email }}</p></div>
        </div>
        <div class="field"><label for="c-subject">Subject</label><input id="c-subject" v-model="form.subject" class="input" maxlength="150" :aria-invalid="!!errors.subject" aria-describedby="c-subject-e" /><p v-if="errors.subject" id="c-subject-e" class="field-error">{{ errors.subject }}</p></div>
        <div class="field"><label for="c-body">Message</label><textarea id="c-body" v-model="form.body" class="input" rows="6" maxlength="5000" :aria-invalid="!!errors.body" aria-describedby="c-body-e" /><p v-if="errors.body" id="c-body-e" class="field-error">{{ errors.body }}</p></div>
        <div class="hidden" aria-hidden="true"><label for="c-web">Leave empty</label><input id="c-web" v-model="form.website" tabindex="-1" autocomplete="off" /></div>
        <p class="text-xs text-muted">We use your name and email only to answer this message.</p>
        <button type="submit" class="btn btn-primary justify-self-start" :disabled="busy"><Send class="size-4" aria-hidden="true" /> Send message</button>
      </form>
    </div>
    <aside v-if="site?.general.contact_email || site?.general.contact_phone || site?.general.address" class="grid content-start gap-2 text-sm" aria-label="Other ways to reach us">
      <p v-if="site.general.contact_email"><a :href="`mailto:${site.general.contact_email}`" class="link">{{ site.general.contact_email }}</a></p>
      <p v-if="site.general.contact_phone">{{ site.general.contact_phone }}</p>
      <p v-if="site.general.address" class="whitespace-pre-line text-muted">{{ site.general.address }}</p>
    </aside>
  </div>
</template>
