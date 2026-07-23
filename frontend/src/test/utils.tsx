import React, { ReactElement } from 'react'
import { render, RenderOptions } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'sonner'
import { vi } from 'vitest'

// Mock the useAuth hook
const mockUseAuth = {
  user: null,
  setAuthData: vi.fn(),
  session: null,
  loading: false,
}

// Mock the useUserRole hook
const mockUseUserRole = {
  userRole: 'free',
  isLoading: false,
}

// Mock the useMobile hook
const mockUseMobile = false

// Mock all the hooks
vi.mock('@/hooks/useAuth', () => ({
  useAuth: () => mockUseAuth,
}))

vi.mock('@/hooks/useUserRole', () => ({
  useUserRole: () => mockUseUserRole,
}))

vi.mock('@/hooks/use-mobile', () => ({
  useMobile: () => mockUseMobile,
}))

// Mock the API service
vi.mock('@/lib/api-service', () => ({
  viewsMaxApi: {
    login: vi.fn(),
    register: vi.fn(),
    forgotPassword: vi.fn(),
    setAuthSession: vi.fn(),
  },
}))

// Mock API Service
vi.mock('@/lib/api-service', () => ({
  viewsMaxApi: {
    getProjects: vi.fn(),
    saveProject: vi.fn(),
    updateProject: vi.fn(),
    deleteProject: vi.fn(),
    getProject: vi.fn(),
    generateScript: vi.fn(),
    searchTitles: vi.fn(),
    reviewVideo: vi.fn(),
    deleteAllUserData: vi.fn(),
    setAuthSession: vi.fn(),
  },
}))

// Custom render function that includes providers
const AllTheProviders = ({ children }: { children: React.ReactNode }) => {
  return (
    <BrowserRouter>
      {children}
      <Toaster />
    </BrowserRouter>
  )
}

const customRender = (
  ui: ReactElement,
  options?: Omit<RenderOptions, 'wrapper'>
) => render(ui, { wrapper: AllTheProviders, ...options })

// Re-export everything
export * from '@testing-library/react'
export { customRender as render }

// Export mock functions for use in tests
export { mockUseAuth, mockUseUserRole, mockUseMobile }
