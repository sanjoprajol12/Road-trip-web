import { ref } from 'vue'

/**
 * Staff preview: the staff portal opens the website with ?preview=<signed token> (30 minutes). The token is kept for
 * this tab only and sent as X-Preview-Token, so the API shows the site through maintenance mode. It grants nothing
 * else: no sign-in, no rider rights. The server checks the signature and the expiry on every request.
 */
const KEY = 'mrn.preview'
export const previewToken = ref<string | null>(null)

export function initPreview(): void {
  const url = new URL(window.location.href)
  const fromUrl = url.searchParams.get('preview')
  try {
    if (fromUrl) sessionStorage.setItem(KEY, fromUrl)
    previewToken.value = fromUrl ?? sessionStorage.getItem(KEY)
  } catch {
    previewToken.value = fromUrl // storage blocked: works for this page load only
  }
  if (fromUrl) {
    url.searchParams.delete('preview')
    window.history.replaceState(window.history.state, '', url.toString()) // keep the token out of shared links
  }
}

export function endPreview(): void {
  previewToken.value = null
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    /* nothing stored */
  }
}
