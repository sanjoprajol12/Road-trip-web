import { describe, expect, it } from 'vitest'
import { statementError } from '../rules'

describe('statementError', () => {
  it('counts trimmed characters', () => {
    expect(statementError('   short   ')).toBe('Write at least 20 characters (5 so far).')
    expect(statementError('x'.repeat(20))).toBeNull()
    expect(statementError('x'.repeat(2001))).toBe('Keep it under 2000 characters.')
  })
})
