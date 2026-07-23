import '@testing-library/jest-dom'
import { vi } from 'vitest'

// Mock environment variables
Object.defineProperty(import.meta, 'env', {
  value: {
    VITE_API_BASE_URL: 'http://localhost:3000',
    VITE_GOOGLE_CLIENT_ID: 'test-google-client-id',
    VITE_YOUTUBE_API_KEY: 'test-youtube-api-key',
    VITE_PAYPAL_CLIENT_ID: 'test-paypal-client-id',
    VITE_PAYPAL_PLAN_ID: 'test-paypal-plan-id',
  },
  writable: true,
})

// Mock import.meta.env globally
vi.stubGlobal('import', {
  meta: {
    env: {
      VITE_API_BASE_URL: 'http://localhost:3000',
      VITE_GOOGLE_CLIENT_ID: 'test-google-client-id',
      VITE_YOUTUBE_API_KEY: 'test-youtube-api-key',
      VITE_PAYPAL_CLIENT_ID: 'test-paypal-client-id',
      VITE_PAYPAL_PLAN_ID: 'test-paypal-plan-id',
    }
  }
})

// Mock fetch globally
global.fetch = vi.fn()

// Mock localStorage
const localStorageMock = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
}
Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
})

// Mock window.location
Object.defineProperty(window, 'location', {
  value: {
    href: 'http://localhost:3000',
    origin: 'http://localhost:3000',
    pathname: '/',
    search: '',
    hash: '',
  },
  writable: true,
})

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(), // deprecated
    removeListener: vi.fn(), // deprecated
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// Mock ResizeObserver
global.ResizeObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}))
