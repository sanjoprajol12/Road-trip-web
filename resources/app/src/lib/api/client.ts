import axios, { AxiosError } from 'axios'

/**
 * One client for the Laravel API (/api/v1). Sanctum cookie sessions: the XSRF-TOKEN cookie is read
 * by axios and sent back as X-XSRF-TOKEN. No tokens are ever stored in localStorage.
 */
export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  timeout: 20_000,
})

let csrfReady: Promise<unknown> | null = null
const hasXsrfCookie = () => document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))

/** Before the first state-changing request, fetch the CSRF cookie. */
api.interceptors.request.use(async (config) => {
  const method = (config.method ?? 'get').toLowerCase()
  if (method !== 'get' && !hasXsrfCookie()) {
    csrfReady ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true }).finally(() => (csrfReady = null))
    await csrfReady
  }
  return config
})

const unauthorizedListeners = new Set<() => void>()
/** Called when the server says the session is gone (expired, revoked, signed out elsewhere). */
export function onUnauthorized(fn: () => void) {
  unauthorizedListeners.add(fn)
}

api.interceptors.response.use(undefined, (error: AxiosError) => {
  // A stale CSRF token (419) gets one fresh cookie and a single retry.
  const config = error.config as (typeof error.config & { _retried?: boolean }) | undefined
  if (error.response?.status === 419 && config && !config._retried) {
    config._retried = true
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true }).then(() => api.request(config))
  }
  if (error.response?.status === 401 && !config?.url?.startsWith('/auth/')) unauthorizedListeners.forEach((fn) => fn())
  return Promise.reject(error)
})

type ErrorBody = { message?: string; errors?: Record<string, string[]> }

/** Turns any thrown value into a sentence a rider can read. */
export function errorMessage(e: unknown): string {
  if (e instanceof AxiosError) {
    if (!e.response) return 'Could not reach the server. Check your connection and try again.'
    const body = e.response.data as ErrorBody | undefined
    const status = e.response.status
    if (status === 422) return Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? 'Please check the form.'
    if (status === 401) return 'Please sign in again.'
    if (status === 403) return body?.message && body.message !== 'This action is unauthorized.' ? body.message : 'You are not allowed to do that.'
    if (status === 404) return 'This could not be found. It may have been removed.'
    if (status === 429) return 'Too many attempts. Wait a minute and try again.'
    if (status >= 500) return 'The server had a problem. Please try again in a moment.'
    return body?.message ?? 'Something went wrong. Please try again.'
  }
  return e instanceof Error ? e.message : 'Something went wrong. Please try again.'
}

/** Field-level messages from a 422 response, keyed by field name. */
export function fieldErrors(e: unknown): Record<string, string> {
  if (!(e instanceof AxiosError) || e.response?.status !== 422) return {}
  const errors = (e.response.data as ErrorBody).errors ?? {}
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0] ?? '']))
}

export const statusOf = (e: unknown) => (e instanceof AxiosError ? e.response?.status : undefined)
