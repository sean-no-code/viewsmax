import { describe, it, expect, vi, beforeEach } from 'vitest'

import { viewsMaxApi } from '../api-service'

// A connect refused because another ViewsMax account already used the channel
// comes back as a 422 with a readable message; the user must see that message,
// not the raw response body.
const REFUSAL = 'You already created an account with this channel on email se****@gmail.com. Log in with that email to use it.'

function respond(status: number, body: unknown) {
  global.fetch = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(JSON.stringify(body)),
    json: () => Promise.resolve(body),
  }) as unknown as typeof fetch
}

describe('connect refusals show the readable message', () => {
  beforeEach(() => {
    viewsMaxApi.setAuthSession({ token: 't', token_type: 'Bearer' } as never)
  })

  it('YouTube connect from the Connections page (exchangeOAuthCode)', async () => {
    respond(422, { success: false, message: REFUSAL })
    const res = await viewsMaxApi.exchangeOAuthCode('youtube', 'code', 'https://app.test/cb')
    expect(res).toEqual({ success: false, error: REFUSAL })
  })

  it('YouTube connect from the Analytics and Review pages (exchangeYouTubeCode)', async () => {
    respond(422, { success: false, message: REFUSAL })
    const res = await viewsMaxApi.exchangeYouTubeCode('code', 'https://app.test/cb', 't')
    expect(res).toEqual({ success: false, error: REFUSAL })
  })

  it('other errors still include the status and body', async () => {
    respond(500, { message: 'boom' })
    const res = await viewsMaxApi.exchangeOAuthCode('youtube', 'code', 'https://app.test/cb')
    expect(res.success).toBe(false)
    expect(res.error).toContain('500')
  })
})
