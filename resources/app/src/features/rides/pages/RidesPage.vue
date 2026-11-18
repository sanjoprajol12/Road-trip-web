<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { Plus, Users } from 'lucide-vue-next'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { api, errorMessage } from '@/lib/api/client'
import { useAsync } from '@/lib/useAsync'
import { formatDay } from '@/lib/format'
import type { Trip } from '@/types/api'

const router = useRouter()
const trips = useAsync(async () => (await api.get<{ data: Trip[] }>('/trips')).data.data)
const title = ref('')
const error = ref<string | null>(null)
async function create() {
  error.value = null
  try {
    const { data } = await api.post<{ data: Trip }>('/trips', { title: title.value.trim() })
    await router.push(`/rides/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <h1 class="display text-[clamp(3rem,2rem+4vw,6rem)]">My rides</h1>
    <p class="lead mt-5 max-w-xl text-muted">Private trip plans. Invite riders to plan together; nobody else, staff included, can see them.</p>
    <form class="mt-8 flex max-w-xl flex-wrap items-end gap-3" @submit.prevent="create">
      <div class="field flex-1"><label for="new-trip">New trip</label><input id="new-trip" v-model="title" class="input" maxlength="120" placeholder="For example: Upper Mustang in April" /></div>
      <button type="submit" class="btn btn-primary" :disabled="title.trim().length < 2"><Plus class="size-4" aria-hidden="true" /> Create</button>
    </form>
    <p v-if="error" class="mt-2 text-sm text-danger" role="alert">{{ error }}</p>
    <section class="mt-10" aria-label="Your trips">
      <StatusPanel v-if="trips.loading.value" state="loading" title="Loading trips" />
      <StatusPanel v-else-if="trips.error.value" state="error" :message="trips.error.value" @retry="trips.run" />
      <StatusPanel v-else-if="!trips.data.value?.length" state="empty" title="No trips yet" message="Create one above to start planning stops, budget and checklists." />
      <ul v-else class="grid border-t border-line">
        <li v-for="t in trips.data.value" :key="t.id" class="border-b border-line">
          <RouterLink :to="`/rides/${t.id}`" class="flex flex-wrap items-baseline justify-between gap-3 py-5">
            <span class="display text-3xl">{{ t.title }}</span>
            <span class="flex items-center gap-3 text-sm text-muted">
              <span v-if="t.starts_on">{{ formatDay(t.starts_on) }}<template v-if="t.ends_on"> to {{ formatDay(t.ends_on) }}</template></span>
              <span class="flex items-center gap-1"><Users class="size-4" aria-hidden="true" />{{ t.member_count }}</span>
              <span class="capitalize">{{ t.my_role }}</span>
            </span>
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
