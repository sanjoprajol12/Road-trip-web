<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import BrandMark from './BrandMark.vue'
import SiteLink from '@/components/ui/SiteLink.vue'
import { menus, site } from '@/lib/site'

// Footer text, social links and the footer menu come from the staff portal (Settings > Footer, Social; CMS > Menus).
// Text labels, not brand icons: Lucide has no icon for every network, and the UI uses Lucide only.
const networks = { facebook: 'Facebook', instagram: 'Instagram', youtube: 'YouTube', tiktok: 'TikTok', x: 'X' } as const
const social = computed(() => Object.entries(networks).flatMap(([k, label]) => {
  const url = site.value?.social[k as keyof typeof networks]
  return url ? [{ url, label }] : []
}))
const footer = computed(() => site.value?.footer)
</script>

<template>
  <footer class="on-night border-t border-night-line bg-night text-night-text">
    <div class="wrap grid gap-10 py-14 md:grid-cols-[1.4fr_1fr]">
      <div class="grid content-start gap-4">
        <RouterLink to="/" class="flex items-center gap-2.5 text-night-text">
          <BrandMark class="size-7" />
          <span class="display text-2xl leading-none">{{ site?.general.site_name ?? 'My Ride Nepal' }}</span>
        </RouterLink>
        <p v-if="footer?.tagline" class="lead max-w-md text-night-muted">{{ footer.tagline }}</p>
        <nav v-if="menus.footer.length" aria-label="Footer" class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
          <SiteLink v-for="item in menus.footer" :key="item.to" :to="item.to" class="font-semibold text-night-text hover:underline">{{ item.label }}</SiteLink>
        </nav>
        <ul v-if="social.length" class="flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="My Ride Nepal elsewhere">
          <li v-for="s in social" :key="s.url"><a :href="s.url" rel="noopener noreferrer" class="text-night-muted hover:text-night-text">{{ s.label }}</a></li>
        </ul>
      </div>
      <div class="grid content-start gap-3 text-sm text-night-muted">
        <!-- The emergency disclaimer is required content: it has a default and cannot be emptied (server validation). -->
        <p>{{ footer?.disclaimer ?? 'Not an emergency service. My Ride Nepal does not dispatch help or track riders. In an emergency, contact local emergency services directly.' }}</p>
        <p v-if="footer?.note">{{ footer.note }}</p>
      </div>
    </div>
    <div v-if="footer?.credits" class="border-t border-night-line">
      <p class="wrap py-5 text-xs text-night-muted">{{ footer.credits }}</p>
    </div>
  </footer>
</template>
