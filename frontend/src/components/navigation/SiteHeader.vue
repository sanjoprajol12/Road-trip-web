<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CircleUserRound, Menu, X } from 'lucide-vue-next'
import BrandMark from './BrandMark.vue'
import { useAuth } from '@/features/authentication/useAuth'
import SiteLink from '@/components/ui/SiteLink.vue'
import { menus } from '@/lib/site'


const route = useRoute()
const { isSignedIn } = useAuth()
// The header menu is edited in the staff portal; items for signed-in riders only are hidden for guests.
const nav = computed(() => menus.value.header.filter((i) => !i.signed_in_only || isSignedIn.value))
const scrolled = ref(false)
const onScroll = () => (scrolled.value = window.scrollY > 24)
onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
})
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))

// Small screens: a disclosure menu. Escape closes it and returns focus to the button.
const open = ref(false)
const toggle = ref<HTMLButtonElement | null>(null)
watch(() => route.fullPath, () => (open.value = false))
async function close() {
  open.value = false
  await nextTick()
  toggle.value?.focus()
}

const overlay = computed(() => route.meta.overlayHeader && !scrolled.value && !open.value)
</script>

<template>
  <header
    class="fixed inset-x-0 top-0 z-40 transition-colors duration-300"
    :class="overlay ? 'on-night bg-transparent text-night-text' : 'border-b border-line bg-paper/95 text-ink backdrop-blur-sm'"
    @keydown.esc="open && close()"
  >
    <a href="#main" class="sr-only rounded-md bg-ink px-3 py-2 text-surface focus:not-sr-only focus:absolute focus:left-4 focus:top-3">Skip to content</a>
    <div class="wrap flex h-16 items-center gap-4">
      <RouterLink to="/" class="flex items-center gap-2.5" aria-label="My Ride Nepal, home">
        <BrandMark class="size-7" />
        <span class="display text-[1.2rem] leading-none whitespace-nowrap sm:text-[1.375rem]">My Ride Nepal</span>
      </RouterLink>

      <nav aria-label="Main" class="ml-auto hidden items-center gap-1 lg:flex">
        <SiteLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          class="rounded-md px-2.5 py-2 text-sm font-semibold transition-opacity hover:opacity-70"
          active-class="underline decoration-2 underline-offset-[6px]"
        >
          {{ item.label }}
        </SiteLink>
      </nav>
      <div class="ml-auto flex items-center gap-1 lg:ml-2">
        <RouterLink v-if="isSignedIn" to="/account" class="flex items-center gap-2 rounded-md px-2.5 py-2 text-sm font-semibold transition-opacity hover:opacity-70">
          <CircleUserRound class="size-4.5" aria-hidden="true" /><span class="hidden sm:inline">Account</span><span class="sr-only sm:hidden">Account</span>
        </RouterLink>
        <RouterLink v-else to="/sign-in" class="btn btn-quiet min-h-9 px-3.5 text-sm">Sign in</RouterLink>
        <button
          ref="toggle"
          type="button"
          class="flex min-h-9 items-center gap-1.5 rounded-md px-2.5 text-sm font-semibold lg:hidden"
          :aria-expanded="open"
          aria-controls="mobile-nav"
          @click="open = !open"
        >
          <component :is="open ? X : Menu" class="size-5" aria-hidden="true" />Menu
        </button>
      </div>
    </div>
    <nav v-show="open" id="mobile-nav" aria-label="Main" class="border-t border-line bg-paper text-ink lg:hidden">
      <ul class="wrap grid py-2">
        <li v-for="item in nav" :key="item.to">
          <SiteLink :to="item.to" class="block border-b border-line py-3 font-semibold last:border-0" active-class="text-crimson">{{ item.label }}</SiteLink>
        </li>
      </ul>
    </nav>
  </header>
</template>
