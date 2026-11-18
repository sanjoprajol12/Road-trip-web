import type { VerificationKind as Kind, VerificationStatus as Status } from '@/types/api'

// What riders can apply for, and the statement rules. The database enforces the same limits
// (user_verifications check constraints); change both together.

export type VerificationKind = Kind
export type VerificationStatus = Status

/** Kinds riders can apply for online. Identity checks need documents, which are not supported yet. */
export const applicableKinds: readonly VerificationKind[] = ['event_organizer', 'trip_contributor', 'expert_reviewer']

export const expertScopes = {
  mechanical: 'Motorcycle mechanics',
  medical: 'First aid and medical',
  road_safety: 'Road safety',
} as const
export type ExpertScope = keyof typeof expertScopes

export const STATEMENT_MIN = 20
export const STATEMENT_MAX = 2000

export function statementError(text: string): string | null {
  const n = text.trim().length
  if (n < STATEMENT_MIN) return `Write at least ${STATEMENT_MIN} characters (${n} so far).`
  if (n > STATEMENT_MAX) return `Keep it under ${STATEMENT_MAX} characters.`
  return null
}

export const verificationKindLabels: Record<VerificationKind, string> = {
  identity: 'Identity',
  trip_contributor: 'Trip contributor',
  expert_reviewer: 'Expert reviewer',
  event_organizer: 'Event organizer',
}

export const verificationStatusLabels: Record<VerificationStatus, string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  suspended: 'Suspended',
  revoked: 'Revoked',
}
