<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { CircleCheck, LogOut } from 'lucide-vue-next'
import VerificationPanel from '@/features/verification/components/VerificationPanel.vue'
import AccountSecurity from '../components/AccountSecurity.vue'
import EmergencyContacts from '../components/EmergencyContacts.vue'
import MyActivity from '../components/MyActivity.vue'
import { signOut, useAuth } from '@/features/authentication/useAuth'
import { updateOwnProfile, type ProfileEdit } from '../services/profile'
import { errorMessage, fieldErrors } from '@/lib/api/client'

const route = useRoute()
const router = useRouter()
const { user } = useAuth()
const justVerified = computed(() => route.query.verified === '1')

const form = reactive<ProfileEdit>({ display_name: '', username: null, bio: null, home_city: null })
watch(user, (u) => u && Object.assign(form, { display_name: u.display_name, username: u.username, bio: u.bio, home_city: u.home_city }), { immediate: true })

const touched = ref(false)
const saving = ref(false)
const saveError = ref<string | null>(null)
const serverErrors = ref<Record<string, string>>({})
const saved = ref(false)

const errors = computed(() => ({
  display_name: form.display_name.trim().length < 2 ? 'Enter at least 2 characters.' : serverErrors.value.display_name ?? null,
  username: form.username && !/^[a-z0-9_]{3,30}$/.test(form.username) ? '3 to 30 lowercase letters, numbers or underscores.' : serverErrors.value.username ?? null,
}))

const blank = (v: string | null) => (v?.trim() ? v.trim() : null)

async function save() {
  touched.value = true
  saved.value = false
  serverErrors.value = {}
  if (errors.value.display_name || errors.value.username) return
  saving.value = true
  saveError.value = null
  try {
    user.value = await updateOwnProfile({ display_name: form.display_name.trim(), username: blank(form.username), bio: blank(form.bio), home_city: blank(form.home_city) })
    saved.value = true
  } catch (e) {
    serverErrors.value = fieldErrors(e)
    if (!Object.keys(serverErrors.value).length) saveError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

async function doSignOut() {
  await signOut()
  await router.replace('/')
}
</script>

<template>
  <div class="wrap pt-28 pb-24">
    <div class="flex flex-wrap items-end justify-between gap-4 border-b border-line pb-8">
      <div>
        <h1 class="display text-[clamp(2.75rem,2rem+3vw,4.5rem)]">Your account</h1>
        <p class="mt-2 text-muted">{{ user?.email }}</p>
      </div>
      <button type="button" class="btn btn-quiet" @click="doSignOut"><LogOut class="size-4" aria-hidden="true" /> Sign out</button>
    </div>
    <p v-if="justVerified" class="mt-6 flex items-center gap-2 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> Email confirmed. Welcome to My Ride Nepal.</p>
    <p v-if="user?.suspended_until" class="mt-6 rounded-md border border-warning/40 bg-surface px-4 py-3 text-sm" role="alert">
      Your account is suspended. You can read and plan, but cannot contribute, apply or join events until the suspension ends.
    </p>

    <nav aria-label="Account sections" class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm">
      <a href="#profile" class="link">Profile</a>
      <a href="#verify-title" class="link">Verification</a>
      <a href="#activity" class="link">Notifications and contributions</a>
      <a href="#emergency" class="link">Emergency contacts</a>
      <a href="#security" class="link">Password and sessions</a>
      <RouterLink v-if="user?.username" :to="`/riders/${user.username}`" class="link">Your public profile</RouterLink>
    </nav>

    <form id="profile" class="mt-10 grid max-w-2xl gap-6" novalidate @submit.prevent="save">
      <h2 class="display text-3xl">Public profile</h2>
      <p class="-mt-3 text-sm text-muted">Shown next to anything you contribute. Your email is never shown.</p>

      <p v-if="saveError" role="alert" class="rounded-md border border-danger/40 bg-crimson-wash px-4 py-3 text-sm text-danger">{{ saveError }}</p>

      <div class="grid gap-6 sm:grid-cols-2">
        <div class="field">
          <label for="display_name">Display name</label>
          <input id="display_name" v-model="form.display_name" class="input" maxlength="60" :aria-invalid="touched && !!errors.display_name" aria-describedby="dn-err" />
          <p v-if="touched && errors.display_name" id="dn-err" class="field-error">{{ errors.display_name }}</p>
        </div>
        <div class="field">
          <label for="username">Username <span class="font-normal text-muted">(optional)</span></label>
          <input id="username" v-model="form.username" class="input" maxlength="30" autocapitalize="none" :aria-invalid="touched && !!errors.username" aria-describedby="un-hint" />
          <p id="un-hint" :class="touched && errors.username ? 'field-error' : 'field-hint'">
            {{ (touched && errors.username) || 'Lowercase letters, numbers and underscores. Needed for invitations and a public profile.' }}
          </p>
        </div>
        <div class="field">
          <label for="home_city">Home city <span class="font-normal text-muted">(optional)</span></label>
          <input id="home_city" v-model="form.home_city" class="input" maxlength="80" autocomplete="address-level2" />
        </div>
      </div>
      <div class="field">
        <label for="bio">About you <span class="font-normal text-muted">(optional)</span></label>
        <textarea id="bio" v-model="form.bio" class="input" maxlength="500" rows="4" aria-describedby="bio-hint" />
        <p id="bio-hint" class="field-hint">What you ride and where you like riding. {{ 500 - (form.bio?.length ?? 0) }} characters left.</p>
      </div>
      <div class="flex flex-wrap items-center gap-4">
        <button type="submit" class="btn btn-primary" :disabled="saving" :aria-busy="saving">{{ saving ? 'Saving…' : 'Save profile' }}</button>
        <p v-if="saved" class="flex items-center gap-1.5 text-sm text-success" role="status"><CircleCheck class="size-4" aria-hidden="true" /> Saved</p>
      </div>
    </form>

    <VerificationPanel />
    <MyActivity />
    <EmergencyContacts />
    <AccountSecurity />
  </div>
</template>
