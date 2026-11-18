/** Only same-site paths may be used as a post-login redirect (blocks open redirects like //evil.test). */
export function safeNext(value: unknown, fallback = '/account'): string {
  return typeof value === 'string' && /^\/(?![/\\])/.test(value) ? value : fallback
}
