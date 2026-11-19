import { api } from '@/lib/api/client'
import type { OwnVerification, VerificationKind } from '@/types/api'

export async function listOwnVerifications(): Promise<OwnVerification[]> {
  return (await api.get<{ data: OwnVerification[] }>('/users/me/verifications')).data.data
}

export async function applyForVerification(kind: VerificationKind, scope: string, statement: string) {
  await api.post('/users/me/verifications', { kind, scope: scope || undefined, statement: statement.trim() })
}
