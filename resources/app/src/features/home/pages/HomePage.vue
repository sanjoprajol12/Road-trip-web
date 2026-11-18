<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowRight, ArrowUpRight } from 'lucide-vue-next'
import PlacePhoto from '@/components/ui/PlacePhoto.vue'
import StatusPanel from '@/components/ui/StatusPanel.vue'
import { listDestinations } from '@/features/explore/services/destinations'
import { useAsync } from '@/lib/useAsync'

const destinations = useAsync(listDestinations)

const layers = [
  {
    name: 'Destination',
    text: 'Where you are going: permits, seasons, fuel, repairs, connectivity and places to sleep.',
  },
  {
    name: 'Route',
    text: 'How riders get there, with distance, riding time and the stops they actually used.',
  },
  {
    name: 'Segment',
    text: 'Each stretch between checkpoints, reported on separately, so trouble on one stretch is not mistaken for the whole road.',
  },
  {
    name: 'Observation',
    text: 'One rider’s report from one stretch, with the day they rode it. Reports age in plain sight. None of it is live.',
  },
]

const heroLines = ['Plan better.', 'Ride prepared.', 'Learn from real journeys.']
</script>

<template>
  <div>
    <!-- Hero ------------------------------------------------------------ -->
    <section class="on-night relative isolate flex min-h-[max(36rem,92svh)] items-end overflow-hidden bg-night text-night-text">
      <img
        src="/images/photos/kathmandu-motorcycle-1920.webp"
        srcset="
          /images/photos/kathmandu-motorcycle-640.webp   640w,
          /images/photos/kathmandu-motorcycle-1280.webp 1280w,
          /images/photos/kathmandu-motorcycle-1920.webp 1920w,
          /images/photos/kathmandu-motorcycle-2560.webp 2560w
        "
        sizes="100vw"
        alt=""
        fetchpriority="high"
        class="hero-photo absolute inset-0 -z-10 size-full object-cover object-[68%_center]"
      />
      <!-- Legibility scrim behind the words only. -->
      <div class="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgb(17_18_21/0.82)_0%,rgb(17_18_21/0.45)_45%,transparent_75%),linear-gradient(0deg,rgb(17_18_21/0.7)_0%,transparent_45%)]" />
      <!-- On narrow screens the words cover the bright part of the photo too. -->
      <div class="absolute inset-0 -z-10 bg-night/45 md:hidden" />

      <div class="wrap pt-28 pb-14 sm:pb-20">
        <p class="hero-in label text-marigold" style="--d: 0ms">Motorcycle travel in Nepal</p>
        <h1 class="display mt-5 max-w-[11ch] text-[clamp(3rem,1.6rem+5vw,6.25rem)]">
          <span v-for="(line, i) in heroLines" :key="line" class="block overflow-hidden pb-[0.06em]">
            <span class="hero-line block" :style="{ '--d': `${120 + i * 110}ms` }">{{ line }}</span>
          </span>
        </h1>
        <p class="hero-in lead mt-7 max-w-xl text-night-text/85" style="--d: 520ms">
          Destinations, routes and road reports from riders who were there, each one dated, sourced and kept apart from
          what is merely old news.
        </p>
        <div class="hero-in mt-9 flex flex-wrap gap-3" style="--d: 640ms">
          <RouterLink to="/explore" class="btn btn-primary">
            Explore destinations
            <ArrowRight class="size-4" aria-hidden="true" />
          </RouterLink>
          <RouterLink to="/sign-up" class="btn btn-quiet">Create an account</RouterLink>
        </div>
      </div>
      <p class="absolute right-4 bottom-4 hidden text-xs text-night-muted sm:block">
        Kathmandu. Photo: Igor Ovsyannykov, CC0
      </p>
    </section>

    <!-- How knowledge is organised -------------------------------------- -->
    <section class="wrap py-20 sm:py-28" aria-labelledby="layers-title">
      <div class="grid gap-6 md:grid-cols-[1fr_1.3fr] md:items-end">
        <h2 id="layers-title" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">
          The road, broken into things you can check
        </h2>
        <p v-reveal="1" class="lead max-w-xl text-muted">
          Not a feed. Every piece of rider knowledge is filed under the place and the stretch of road it describes, so
          you can see exactly what was reported, where, and when.
        </p>
      </div>

      <ol v-reveal:draw class="layers relative mt-16 grid gap-10 md:grid-cols-4 md:gap-6">
        <li v-for="(layer, i) in layers" :key="layer.name" v-reveal="i" class="relative grid content-start gap-3 pl-10 md:pt-12 md:pl-0">
          <span
            class="absolute top-1 left-0 grid size-6 place-items-center rounded-full border-2 border-crimson bg-paper md:top-0"
            aria-hidden="true"
          >
            <span class="size-2 rounded-full bg-crimson" />
          </span>
          <span class="label tabular text-muted">0{{ i + 1 }}</span>
          <h3 class="display text-3xl">{{ layer.name }}</h3>
          <p class="max-w-xs text-[0.9375rem] leading-relaxed text-muted">{{ layer.text }}</p>
        </li>
      </ol>
    </section>

    <!-- Destinations ---------------------------------------------------- -->
    <section class="border-t border-line bg-surface" aria-labelledby="dest-title">
      <div class="wrap py-20 sm:py-24">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <h2 id="dest-title" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">Destinations</h2>
          <RouterLink to="/explore" class="link inline-flex items-center gap-1.5 font-semibold">
            All destinations <ArrowRight class="size-4" aria-hidden="true" />
          </RouterLink>
        </div>

        <StatusPanel v-if="destinations.loading.value" class="mt-8" state="loading" title="Loading destinations" />
        <StatusPanel
          v-else-if="destinations.error.value"
          class="mt-8"
          state="error"
          title="Destinations could not be loaded"
          :message="destinations.error.value"
          @retry="destinations.run"
        />
        <StatusPanel
          v-else-if="!destinations.data.value?.length"
          class="mt-8"
          state="empty"
          title="No destinations are published yet"
          message="Destinations appear here once the editorial team has checked and published them."
        />
        <div v-else class="mt-10 grid gap-8 md:grid-cols-[1.35fr_1fr]">
          <RouterLink
            v-for="d in destinations.data.value.slice(0, 1)"
            :key="d.id"
            v-reveal
            :to="`/explore/${d.slug}`"
            class="group relative block aspect-4/3 overflow-hidden rounded-md bg-night"
          >
            <PlacePhoto
              v-if="d.photo_path"
              :base="d.photo_path"
              :alt="d.photo_alt ?? ''"
              sizes="(min-width: 768px) 55vw, 100vw"
              class="transition-transform duration-700 ease-out-quint group-hover:scale-[1.03]"
            />
            <div class="absolute inset-0 bg-[linear-gradient(0deg,rgb(17_18_21/0.85),rgb(17_18_21/0.35)_45%,transparent_70%)]" />
            <div class="on-night absolute inset-x-0 bottom-0 p-6 text-night-text">
              <p class="label text-marigold">{{ [d.district, d.province].filter(Boolean).join(', ') }}</p>
              <p class="display mt-2 text-5xl">{{ d.name }}</p>
            </div>
          </RouterLink>
          <ul class="grid content-start border-t border-line">
            <li v-for="(d, i) in destinations.data.value.slice(1, 7)" :key="d.id" v-reveal="i" class="border-b border-line">
              <RouterLink :to="`/explore/${d.slug}`" class="group flex items-center gap-4 py-5">
                <span class="label tabular w-6 text-muted">{{ String(i + 2).padStart(2, '0') }}</span>
                <span class="grid flex-1 gap-0.5">
                  <span class="display text-2xl">{{ d.name }}</span>
                  <span class="text-sm text-muted">{{ [d.district, d.province].filter(Boolean).join(', ') }}</span>
                </span>
                <ArrowUpRight class="size-5 text-muted transition-colors group-hover:text-ink" aria-hidden="true" />
              </RouterLink>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- Freshness ------------------------------------------------------- -->
    <section class="on-night bg-night text-night-text" aria-labelledby="fresh-title">
      <div class="wrap grid gap-12 py-20 sm:py-28 md:grid-cols-2">
        <div>
          <h2 id="fresh-title" v-reveal class="display text-[clamp(2.25rem,1.6rem+2.6vw,4rem)]">
            Every observation carries its date
          </h2>
          <p v-reveal="1" class="lead mt-6 max-w-lg text-night-muted">
            A road report from last monsoon is history, not a forecast. My Ride Nepal shows when something was seen, by
            whom, and how it was checked, then lets it age visibly.
          </p>
        </div>

        <!-- Anatomy of a report stamp: a legend, not a real report. -->
        <figure v-reveal="2" class="self-center">
          <div class="rounded-md border border-night-line p-6">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
              <div class="grid gap-1">
                <dt class="label text-marigold">Observed</dt>
                <dd class="text-night-muted">The day the rider rode that stretch</dd>
              </div>
              <div class="grid gap-1">
                <dt class="label text-marigold">Submitted</dt>
                <dd class="text-night-muted">When the report reached us</dd>
              </div>
              <div class="grid gap-1">
                <dt class="label text-marigold">Status</dt>
                <dd class="text-night-muted">Pending, published, corroborated, officially sourced or outdated</dd>
              </div>
              <div class="grid gap-1">
                <dt class="label text-marigold">Contributor</dt>
                <dd class="text-night-muted">Who reported it, and which claims about them are verified</dd>
              </div>
            </dl>
          </div>
          <figcaption class="mt-3 text-xs text-night-muted">
            What every rider report will show. Publication means a report passed review, not that the road is open
            today.
          </figcaption>
        </figure>
      </div>
    </section>
  </div>
</template>

<style scoped>
.hero-photo {
  animation: hero-settle 1800ms var(--ease-out-quint) both;
}
.hero-line {
  animation: hero-rise 900ms var(--ease-out-quint) both;
  animation-delay: var(--d);
}
.hero-in {
  animation: hero-fade 800ms var(--ease-out-quint) both;
  animation-delay: var(--d);
}
@keyframes hero-settle {
  from {
    transform: scale(1.07);
    opacity: 0.4;
  }
}
@keyframes hero-rise {
  from {
    transform: translateY(105%);
  }
}
@keyframes hero-fade {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
}

/* The route line joining the four layers draws itself in once the list is revealed. */
.layers::before {
  content: '';
  position: absolute;
  left: 11px;
  top: 0.5rem;
  bottom: 0.5rem;
  width: 2px;
  background: var(--color-line-strong);
  transform-origin: top;
  transform: scaleY(0);
  transition: transform 1400ms var(--ease-in-out) 150ms;
}
.layers.is-visible::before {
  transform: none;
}
@media (min-width: 768px) {
  .layers::before {
    left: 0;
    right: 0;
    top: 11px;
    bottom: auto;
    width: auto;
    height: 2px;
    transform-origin: left;
    transform: scaleX(0);
  }
  .layers.is-visible::before {
    transform: none;
  }
}
</style>
