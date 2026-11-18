import type { ContributionKind, ContributionStatus } from '@/types/api'

export const contributionKindLabels: Record<ContributionKind, string> = {
  trip: 'Full trip',
  road_report: 'Road condition report',
  accommodation: 'Accommodation',
  fuel: 'Fuel station',
  mechanic: 'Mechanic or repair',
  recommendation: 'Recommendation',
  unexpected: 'Unexpected situation',
  observation: 'Riding observation',
}

// What each status means to a reader. Publication is review, never proof.
export const contributionStatusLabels: Record<ContributionStatus, string> = {
  pending_review: 'Waiting for review',
  published: 'Published',
  community_corroborated: 'Corroborated by other riders',
  officially_sourced: 'Officially sourced',
  outdated: 'Outdated',
  rejected: 'Not published',
  flagged: 'Under re-review',
}

export const surfaceLabels: Record<string, string> = {
  paved: 'Paved',
  mixed: 'Mixed',
  gravel: 'Gravel',
  dirt: 'Dirt',
  blocked: 'Blocked',
  unknown: 'Not recorded',
}
