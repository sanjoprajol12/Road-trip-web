<script setup lang="ts">
import CmsBanners from '@/features/content/components/CmsBanners.vue'
import { computed, type Component } from 'vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { useAsync } from '@/lib/useAsync'
import BannerSection from '../sections/BannerSection.vue'
import FeaturedDestinations from '../sections/FeaturedDestinations.vue'
import FeaturedExperiences from '../sections/FeaturedExperiences.vue'
import FeaturedRoutes from '../sections/FeaturedRoutes.vue'
import FreshnessSection from '../sections/FreshnessSection.vue'
import HeroSection from '../sections/HeroSection.vue'
import LayersSection from '../sections/LayersSection.vue'
import PlannerSection from '../sections/PlannerSection.vue'
import SlidesSection from '../sections/SlidesSection.vue'
import TextSection from '../sections/TextSection.vue'
import ToolsSection from '../sections/ToolsSection.vue'
import { getHome } from '../services/home'

// Content, order and visibility come from the staff portal (GET /api/v1/home); layout and behaviour stay in code.
// Unknown section types are skipped, so an older website never breaks on a newer API.
const views: Record<string, Component> = {
  hero: HeroSection, slides: SlidesSection, layers: LayersSection, featured_destinations: FeaturedDestinations,
  featured_routes: FeaturedRoutes, featured_experiences: FeaturedExperiences, freshness: FreshnessSection, banner: BannerSection, text: TextSection, planner: PlannerSection, tools: ToolsSection,
}
const home = useAsync(getHome)
// The hero carries the page's h1; without one (staff removed it) a hidden h1 names the page.
const hasHero = computed(() => (home.data.value ?? []).some((s) => s.type === 'hero'))
</script>

<template>
  <div>
    <div v-if="home.loading.value" class="wrap pt-36 pb-24">
      <h1 class="sr-only">My Ride Nepal: loading the home page</h1>
      <StatusPanel state="loading" title="Loading" />
    </div>
    <div v-else-if="home.error.value" class="wrap pt-36 pb-24">
      <h1 class="sr-only">My Ride Nepal: the home page could not be loaded</h1>
      <StatusPanel state="error" title="The home page could not be loaded" :message="home.error.value" @retry="home.run" />
    </div>
    <template v-else>
      <h1 v-if="!hasHero" class="sr-only">My Ride Nepal</h1>
      <template v-for="section in home.data.value ?? []" :key="section.id">
        <component :is="views[section.type]" v-if="views[section.type]" :id="section.id" :s="section" />
      </template>
      <CmsBanners placement="home" />
    </template>
  </div>
</template>
