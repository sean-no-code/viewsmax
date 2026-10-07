import { describe, it, expect, vi, beforeEach } from 'vitest'

import { viewsMaxApi } from '../api-service'
import { startSearchOutliers } from '../outlier-service'

// Website actions priced like the AI tools: a refusal (402) must read as the
// server's message, and every charge must update the Credits badge.
const REFUSAL = 'Not enough credits to publish a post (10 needed, 3 available). Choose a plan to get more credits.'

function respond(status: number, body: unknown) {
  global.fetch = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(JSON.stringify(body)),
    json: () => Promise.resolve(body),
  }) as unknown as typeof fetch
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

  it('a refused outlier search throws the readable message', async () => {
    respond(402, { success: false, message: 'Not enough credits to search outliers (10 needed, 3 available). Choose a plan to get more credits.', user_credits: 3 })

    await expect(startSearchOutliers('home cooking')).rejects.toThrow('Not enough credits to search outliers')
    expect(badge).toHaveBeenCalledWith(3)
  })
})
