import { describe, it, expect } from 'vitest'

import { checkoutCopy } from '../StripeTrialStep'

describe('checkout wording', () => {
  it('a user on free credits is charged today, with no mention of a trial', () => {
    const copy = checkoutCopy({ kind: 'free-credits' })
    expect(copy.cta).toBe('Subscribe now')
    expect(copy.lead).toBe('Charged today.')
    expect(copy.note).not.toMatch(/trial/i)
    expect(copy.note).toContain("Your plan's monthly credits replace any free credits left")
    expect(copy.banner).toBeNull()
  })

  it('the card-backed trial wording is unchanged', () => {
    expect(checkoutCopy({ kind: 'card-trial' }).cta).toBe('Start 7-day free trial')
  })
})
