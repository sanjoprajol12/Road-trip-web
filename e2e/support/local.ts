import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'

// E2E runs only against the local stack: Laravel (php artisan serve) on the local Supabase Postgres.
// These guards make sure a web/.env pointing anywhere else never receives test sign-ups or deletes.
export const LOCAL_DB_URL = 'postgresql://postgres:postgres@127.0.0.1:54322/myridenepal'
export const MAILPIT_URL = 'http://127.0.0.1:54324'
export const API_URL = 'http://127.0.0.1:8010'
export const TEST_EMAIL_DOMAIN = 'e2e.test'

export function assertLocalStack() {
  const env = readFileSync(new URL('../../.env', import.meta.url), 'utf8')
  const read = (k: string) => new RegExp(`^${k}=(.*)$`, 'm').exec(env)?.[1]?.trim() ?? ''
  if (read('DB_HOST') !== '127.0.0.1' || read('DB_PORT') !== '54322' || read('DB_DATABASE') !== 'myridenepal') {
    throw new Error('E2E refuses to run: web/.env does not point at the local myridenepal database.')
  }
}

export function sql(statement: string): string {
  return execFileSync('psql', [LOCAL_DB_URL, '-qtAc', statement], { encoding: 'utf8' }).trim()
}

/** Removes every account the E2E suites created (their trips, contributions and claims cascade). */
export function deleteTestUsers() {
  sql(`delete from users where email like '%@${TEST_EMAIL_DOMAIN}'`)
}

/** Registers through the public API, then confirms the address directly in the local database. */
export async function createConfirmedRider(label: string, password: string, username?: string): Promise<{ id: string; email: string }> {
  const email = `${label}-${Date.now()}-${Math.floor(Math.random() * 1e4)}@${TEST_EMAIL_DOMAIN}`
  const res = await fetch(`${API_URL}/api/v1/auth/register`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({ display_name: `E2E ${label}`, email, password }),
  })
  if (res.status !== 202) throw new Error(`register failed: ${res.status} ${await res.text()}`)
  const id = sql(`update users set email_verified_at = now()${username ? `, username = '${username}'` : ''} where email = '${email}' returning id`)
  return { id, email }
}

/** Polls the local mail catcher for the newest email to `to` and returns the confirmation or reset link in it. */
export async function authLinkFromEmail(to: string, type: 'signup' | 'recovery', timeoutMs = 15_000): Promise<string> {
  const pattern = type === 'signup' ? /https?:\/\/[^\s"'<>)]+\/api\/v1\/auth\/verify-email\/[^\s"'<>)]+/ : /https?:\/\/[^\s"'<>)]+\/reset-password\?[^\s"'<>)]+/
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    const search = await fetch(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`).then((r) => r.json())
    for (const m of search.messages ?? []) {
      const msg = await fetch(`${MAILPIT_URL}/api/v1/message/${m.ID}`).then((r) => r.json())
      const link = pattern.exec(`${msg.Text}\n${msg.HTML}`)?.[0]?.replaceAll('&amp;', '&')
      if (link) return link
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  throw new Error(`No ${type} email for ${to} within ${timeoutMs} ms`)
}
