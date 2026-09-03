import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import {
  splitIntoParts,
  putWithProgress,
  uploadMultipartParts,
} from '../direct-upload'

// Minimal XHR fake: succeeds (or fails once per configured URL) on send(),
// returning an ETag derived from the presigned URL's partNumber.
class FakeXHR {
  static instances: FakeXHR[] = []
  static failOnce = new Set<string>()
  static etagFor: (url: string) => string | null = (url) => {
    const match = url.match(/partNumber=(\d+)/)
    return `"etag-${match ? match[1] : 'put'}"`
  }

  method = ''
  url = ''
  status = 0
  requestHeaders: Record<string, string> = {}
  upload: { onprogress: ((e: ProgressEvent) => void) | null } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null

  open(method: string, url: string) {
    this.method = method
    this.url = url
  }

  setRequestHeader(name: string, value: string) {
    this.requestHeaders[name] = value
  }

  getResponseHeader(name: string): string | null {
    return name === 'ETag' ? FakeXHR.etagFor(this.url) : null
  }

  send(_body: Blob) {
    FakeXHR.instances.push(this)
    queueMicrotask(() => {
      if (FakeXHR.failOnce.has(this.url)) {
        FakeXHR.failOnce.delete(this.url)
        this.status = 500
        this.onload?.()
        return
      }
      this.status = 200
      this.onload?.()
    })
  }
}

describe('splitIntoParts', () => {
  it('produces uniform parts with the remainder in the last part (R2 requirement)', () => {
    const parts = splitIntoParts(100, 30)

    expect(parts).toEqual([
      { partNumber: 1, start: 0, end: 30 },
      { partNumber: 2, start: 30, end: 60 },
      { partNumber: 3, start: 60, end: 90 },
      { partNumber: 4, start: 90, end: 100 },
    ])
  })

  it('handles an exact multiple without an empty trailing part', () => {
    const parts = splitIntoParts(64, 16)

    expect(parts).toHaveLength(4)
    expect(parts[3]).toEqual({ partNumber: 4, start: 48, end: 64 })
  })

  it('puts a small file in a single part', () => {
    expect(splitIntoParts(5, 16)).toEqual([{ partNumber: 1, start: 0, end: 5 }])
  })

  it('matches the backend part-count math for a 512MB video at 16MB parts', () => {
    const parts = splitIntoParts(512 * 1024 * 1024, 16 * 1024 * 1024)

    expect(parts).toHaveLength(32)
    // All non-final parts identical size — R2 rejects the upload otherwise.
    const sizes = new Set(parts.slice(0, -1).map((p) => p.end - p.start))
    expect(sizes.size).toBe(1)
  })

  it('returns no parts for empty input', () => {
    expect(splitIntoParts(0, 16)).toEqual([])
  })
})

describe('uploads via fake XHR', () => {
  beforeEach(() => {
    FakeXHR.instances = []
    FakeXHR.failOnce = new Set()
    vi.stubGlobal('XMLHttpRequest', FakeXHR)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('putWithProgress sends headers and resolves the ETag', async () => {
    const etag = await putWithProgress(
      'https://r2.example/presigned-put',
      new Blob(['0123456789']),
      { 'Content-Type': 'image/png' },
    )

    expect(etag).toBe('"etag-put"')
    expect(FakeXHR.instances[0].method).toBe('PUT')
    expect(FakeXHR.instances[0].requestHeaders['Content-Type']).toBe('image/png')
  })

  it('uploadMultipartParts uploads every slice and returns ordered etags', async () => {
    const file = new Blob(['0123456789']) // 10 bytes, part_size 4 -> 3 parts
    const session = {
      part_size: 4,
      parts: [1, 2, 3].map((n) => ({ part_number: n, url: `https://r2.example/x?partNumber=${n}` })),
    }
    const progress: number[] = []

    const parts = await uploadMultipartParts(file, session, (bytes) => progress.push(bytes))

    expect(parts).toEqual([
      { part_number: 1, etag: '"etag-1"' },
      { part_number: 2, etag: '"etag-2"' },
      { part_number: 3, etag: '"etag-3"' },
    ])
    expect(progress[progress.length - 1]).toBe(10) // aggregate reaches full file size
  })

  it('retries a failed part once and still succeeds', async () => {
    const file = new Blob(['0123456789'])
    const session = {
      part_size: 4,
      parts: [1, 2, 3].map((n) => ({ part_number: n, url: `https://r2.example/x?partNumber=${n}` })),
    }
    FakeXHR.failOnce.add('https://r2.example/x?partNumber=2')

    const parts = await uploadMultipartParts(file, session)

    expect(parts.map((p) => p.etag)).toEqual(['"etag-1"', '"etag-2"', '"etag-3"'])
    // part 2 was sent twice (fail + retry)
    expect(FakeXHR.instances.filter((x) => x.url.includes('partNumber=2'))).toHaveLength(2)
  })

  it('fails when the bucket does not expose an ETag (CORS misconfig)', async () => {
    FakeXHR.etagFor = () => null
    const file = new Blob(['012']) // 3 bytes -> single part, matching the plan
    const session = {
      part_size: 4,
      parts: [{ part_number: 1, url: 'https://r2.example/x?partNumber=1' }],
    }

    await expect(uploadMultipartParts(file, session)).rejects.toThrow(/ETag/)
    FakeXHR.etagFor = (url) => {
      const match = url.match(/partNumber=(\d+)/)
      return `"etag-${match ? match[1] : 'put'}"`
    }
  })

  it('rejects a part plan that does not match the server session', async () => {
    const file = new Blob(['0123456789'])
    const session = { part_size: 4, parts: [{ part_number: 1, url: 'https://r2.example/x?partNumber=1' }] }

    await expect(uploadMultipartParts(file, session)).rejects.toThrow(/mismatch/)
  })
})
