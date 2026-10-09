import { describe, it, expect, vi, beforeEach } from 'vitest'

import { viewsMaxApi } from '../api-service'
import { startSearchOutliers } from '../outlier-service'
import { ACCESS_CHECK_EVENT } from '../access'

// Website actions priced like the AI tools: a refusal (402) must read as the
// server's message, and every charge must update the Credits badge.
const REFUSAL = 'Not enough credits to create a post (10 needed, 3 available). Choose a plan to get more credits.'

function respond(status: number, body: unknown, credits?: number) {
  const res = {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(credits === undefined ? {} : { 'X-User-Credits': String(credits) }),
    text: () => Promise.resolve(JSON.stringify(body)),
    json: () => Promise.resolve(body),
    clone: () => res,
  }
  global.fetch = vi.fn().mockResolvedValue(res) as unknown as typeof fetch
}

describe('website actions that cost credits', () => {
  let badge: ReturnType<typeof vi.fn>

  beforeEach(() => {
    badge = vi.fn()
    viewsMaxApi.setCreditsUpdateCallback(badge)
    viewsMaxApi.setAuthSession({ token: 't', token_type: 'Bearer' } as never)
  })

  it('a refused publish shows the readable message and the balance', async () => {
    respond(402, { success: false, code: 'insufficient_credits', message: REFUSAL, user_credits: 3 })

    const res = await viewsMaxApi.createPost({ caption: 'Hi', status: 'scheduled' } as never)

    expect(res).toEqual({ success: false, error: REFUSAL })
    expect(badge).toHaveBeenCalledWith(3)
  })

  it('a successful publish updates the Credits badge', async () => {
    respond(201, { id: 1, status: 'scheduled', user_credits: 90 })

    await viewsMaxApi.createPost({ caption: 'Hi', status: 'scheduled' } as never)

    expect(badge).toHaveBeenCalledWith(90)
  })

  it('an outlier search updates the Credits badge', async () => {
    respond(200, { message: 'Search started', status: 'queued', user_credits: 80 })

    await startSearchOutliers('home cooking')

    expect(badge).toHaveBeenCalledWith(80)
  })

  it('a delete with no body still updates the Credits badge from the header', async () => {
    respond(204, null, 75)

    const res = await viewsMaxApi.deletePost(1)

    expect(res.success).toBe(true)
    expect(badge).toHaveBeenCalledWith(75)
  })

  it('a refused offer shows the readable message, not a status code', async () => {
    const message = 'Not enough credits to create an offer (5 needed, 2 available). Choose a plan to get more credits.'
    respond(402, { success: false, code: 'insufficient_credits', message, user_credits: 2 }, 2)

    const res = await viewsMaxApi.createTrackingEvent({ name: 'A' } as never)

    expect(res).toEqual({ success: false, error: message })
    expect(badge).toHaveBeenCalledWith(2)
  })

  it('a charge that spends the last credits shows its result and locks on the next click', async () => {
    const check = vi.fn()
    window.addEventListener(ACCESS_CHECK_EVENT, check)
    respond(200, { message: 'Search started', status: 'queued', user_credits: 0 })

    await startSearchOutliers('home cooking')
    expect(check).not.toHaveBeenCalled()

    window.dispatchEvent(new Event('pointerdown'))
    expect(check).toHaveBeenCalledTimes(1)
    window.removeEventListener(ACCESS_CHECK_EVENT, check)
  })

  it('a request refused because access ended locks straight away', async () => {
    const check = vi.fn()
    window.addEventListener(ACCESS_CHECK_EVENT, check)
    respond(403, { success: false, code: 'access_expired', message: "You've used all your free credits. Choose a plan to keep using ViewsMax." })

    await expect(startSearchOutliers('home cooking')).rejects.toThrow()

    expect(check).toHaveBeenCalledTimes(1)
    window.removeEventListener(ACCESS_CHECK_EVENT, check)
  })

  it('a refused outlier search throws the readable message', async () => {
    respond(402, { success: false, message: 'Not enough credits to search outliers (10 needed, 3 available). Choose a plan to get more credits.', user_credits: 3 })

    await expect(startSearchOutliers('home cooking')).rejects.toThrow('Not enough credits to search outliers')
    expect(badge).toHaveBeenCalledWith(3)
  })
})
