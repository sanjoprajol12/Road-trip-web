import type { Directive } from 'vue'

// v-reveal: fade/rise once when scrolled into view. v-reveal="2" staggers by index.
// v-reveal:draw only marks the element .is-visible (for CSS that draws lines), without hiding it.
// Content is visible by default if JS or IntersectionObserver is missing.
const observer =
  typeof IntersectionObserver === 'undefined'
    ? null
    : new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (!entry.isIntersecting) continue
            entry.target.classList.add('is-visible')
            observer?.unobserve(entry.target)
          }
        },
        { rootMargin: '0px 0px -8% 0px' },
      )

export const vReveal: Directive<HTMLElement, number | undefined> = {
  mounted(el, { value, arg }) {
    if (!observer) return
    if (arg !== 'draw') el.classList.add('reveal')
    if (value) el.style.setProperty('--reveal-i', String(value))
    observer.observe(el)
  },
  unmounted(el) {
    observer?.unobserve(el)
  },
}
