<script setup lang="ts">
import type { Component } from 'vue'
import { ArrowRight, BookOpenCheck, CalendarDays, MapPin, MessageSquareText, Navigation, Route, Users } from 'lucide-vue-next'
import SiteLink from '@/components/ui/SiteLink.vue'
import type { ToolsData } from '../types'

defineProps<{ s: ToolsData; id: string }>()
// The icon follows where the item links to, so staff edit words and links only.
const icons: [string, Component][] = [['/routes', Route], ['/rides', CalendarDays], ['/places', MapPin], ['/experiences', MessageSquareText], ['/events', Users], ['/preparation', BookOpenCheck], ['/journey', Navigation]]
const iconFor = (to?: string) => icons.find(([p]) => to?.startsWith(p))?.[1] ?? ArrowRight
</script>

<template>
  <section class="wrap py-20 sm:py-28" :aria-labelledby="`tools-${id}`">
    <div class="grid gap-6 md:grid-cols-[1fr_1.3fr] md:items-end">
      <h2 :id="`tools-${id}`" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)] text-balance">{{ s.heading }}</h2>
      <p v-if="s.intro" v-reveal="1" class="lead max-w-xl text-muted">{{ s.intro }}</p>
    </div>
    <ul class="mt-14 grid gap-x-12 md:grid-cols-2">
      <li v-for="(t, i) in s.items" :key="t.name" v-reveal="i % 2" class="grid grid-cols-[2.5rem_1fr] gap-x-4 gap-y-2 border-t border-line py-7">
        <span class="grid size-10 place-items-center rounded-md border border-line-strong text-crimson" aria-hidden="true"><component :is="iconFor(t.cta?.to)" class="size-5" /></span>
        <h3 class="self-center text-xl font-bold">{{ t.name }}</h3>
        <p class="col-start-2 max-w-md leading-relaxed text-muted">{{ t.text }}</p>
        <SiteLink v-if="t.cta?.to" :to="t.cta.to" class="link col-start-2 inline-flex items-center gap-1 justify-self-start text-sm font-semibold">{{ t.cta.label || 'Open' }} <ArrowRight class="size-4" aria-hidden="true" /></SiteLink>
      </li>
    </ul>
  </section>
</template>
