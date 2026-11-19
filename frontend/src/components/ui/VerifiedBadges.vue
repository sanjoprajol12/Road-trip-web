<script setup lang="ts">
import { BadgeCheck } from 'lucide-vue-next'
import type { VerifiedClaim } from '@/types/api'

// Each verified claim is shown on its own: a verified trip contributor is not an expert.
defineProps<{ claims: VerifiedClaim[] }>()
const label = (c: VerifiedClaim) =>
  ({ trip_contributor: 'Verified trip contributor', event_organizer: 'Verified organizer', identity: 'Identity verified', expert_reviewer: `Verified expert: ${c.scope.replace('_', ' ')}` })[c.kind]
</script>

<template>
  <span v-if="claims.length" class="inline-flex flex-wrap gap-x-3 gap-y-1">
    <span v-for="c in claims" :key="c.kind + c.scope" class="inline-flex items-center gap-1 text-xs font-semibold text-success">
      <BadgeCheck class="size-3.5" aria-hidden="true" />{{ label(c) }}
    </span>
  </span>
</template>
