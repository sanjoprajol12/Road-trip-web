<script setup lang="ts">
import { reactive, ref } from 'vue'
import { Phone, Trash2 } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { api, errorMessage, fieldErrors } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'

interface Contact {
  id: string
  name: string
  phone: string
  relation: string | null
}
const contacts = useAsync(async () => (await api.get<{ data: Contact[] }>('/users/me/emergency-contacts')).data.data)
const form = reactive({ name: '', phone: '', relation: '' })
const errors = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const busy = ref(false)

async function add() {
  busy.value = true
  errors.value = {}
  error.value = null
  try {
    await api.post('/users/me/emergency-contacts', { ...form, relation: form.relation || null })
    Object.assign(form, { name: '', phone: '', relation: '' })
    await contacts.run()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function remove(c: Contact) {
  if (!window.confirm(`Remove ${c.name} from your emergency contacts?`)) return
  try {
    await api.delete(`/users/me/emergency-contacts/${c.id}`)
    await contacts.run()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <section id="emergency" class="mt-16 max-w-2xl border-t border-line pt-10" aria-labelledby="ice-title">
    <h2 id="ice-title" class="display text-3xl">Emergency contacts</h2>
    <p class="mt-2 text-sm text-muted">
      Private to you: staff cannot see them. Keep a copy offline too. My Ride Nepal does not contact anyone or dispatch help.
    </p>
    <StatusPanel v-if="contacts.loading.value" state="loading" title="Loading contacts" />
    <StatusPanel v-else-if="contacts.error.value" state="error" :message="contacts.error.value" @retry="contacts.run" />
    <ul v-else-if="contacts.data.value?.length" class="mt-4 grid border-t border-line">
      <li v-for="c in contacts.data.value" :key="c.id" class="flex items-center gap-3 border-b border-line py-3 text-sm">
        <Phone class="size-4 text-muted" aria-hidden="true" />
        <span class="flex-1"><span class="font-semibold">{{ c.name }}</span><span v-if="c.relation" class="text-muted"> · {{ c.relation }}</span></span>
        <a :href="`tel:${c.phone.replace(/[^0-9+]/g, '')}`" class="link tabular">{{ c.phone }}</a>
        <button type="button" class="rounded-md p-2 text-muted hover:text-danger" :aria-label="`Remove ${c.name}`" @click="remove(c)"><Trash2 class="size-4" aria-hidden="true" /></button>
      </li>
    </ul>
    <p v-else class="mt-4 text-sm text-muted">No emergency contacts yet.</p>

    <form class="mt-6 grid gap-4 sm:grid-cols-[1fr_1fr_10rem_auto] sm:items-end" novalidate @submit.prevent="add">
      <p v-if="error" role="alert" class="text-sm text-danger sm:col-span-4">{{ error }}</p>
      <div class="field">
        <label for="ice-name">Name</label>
        <input id="ice-name" v-model="form.name" class="input" maxlength="120" :aria-invalid="!!errors.name" />
        <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
      </div>
      <div class="field">
        <label for="ice-phone">Phone</label>
        <input id="ice-phone" v-model="form.phone" type="tel" class="input" maxlength="40" autocomplete="tel" :aria-invalid="!!errors.phone" />
        <p v-if="errors.phone" class="field-error">{{ errors.phone }}</p>
      </div>
      <div class="field">
        <label for="ice-rel">Relation <span class="font-normal text-muted">(optional)</span></label>
        <input id="ice-rel" v-model="form.relation" class="input" maxlength="60" />
      </div>
      <button type="submit" class="btn btn-quiet" :disabled="busy || !form.name || !form.phone">Add</button>
    </form>
  </section>
</template>
