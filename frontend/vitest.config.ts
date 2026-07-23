/// <reference types="vitest" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react-swc'
import path from 'path'

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'happy-dom',
    setupFiles: ['./src/test/setup.ts'],
    testMatch: ['**/__tests__/**/*.{test,spec}.{js,ts,jsx,tsx}'],
    // Exclude stale git worktree copies — a full second copy of the repo lives
    // under .claude/worktrees and would otherwise pollute the suite with failures.
    exclude: ['**/node_modules/**', '**/dist/**', '**/.claude/**'],
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
})
