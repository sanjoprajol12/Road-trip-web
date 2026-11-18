import { describe, expect, it } from 'vitest'
import { safeNext } from '../safeNext'

describe('safeNext', () => {
  it('keeps same-site paths', () => {
    expect(safeNext('/explore/pokhara?x=1')).toBe('/explore/pokhara?x=1')
  })
  it('refuses anything that could leave the site', () => {
    for (const bad of ['//evil.test', '/\\evil.test', 'https://evil.test', 'javascript:alert(1)', '', undefined, ['/a']]) {
      expect(safeNext(bad)).toBe('/account')
    }
  })
})
