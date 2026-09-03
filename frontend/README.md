# Welcome to your Lovable project

## ✅ Running tests

Unit tests use [Vitest](https://vitest.dev/).

```bash
npm test        # watch mode (re-runs on file changes)
npx vitest run  # run once and exit (use in CI)
```

## Project info

**URL**: https://lovable.dev/projects/3fb59891-0805-4cff-8505-7bb16297af10

## How can I edit this code?

There are several ways of editing your application.

**Use Lovable**

Simply visit the [Lovable Project](https://lovable.dev/projects/3fb59891-0805-4cff-8505-7bb16297af10) and start prompting.

Changes made via Lovable will be committed automatically to this repo.

**Use your preferred IDE**

If you want to work locally using your own IDE, you can clone this repo and push changes. Pushed changes will also be reflected in Lovable.

The only requirement is having Node.js & npm installed - [install with nvm](https://github.com/nvm-sh/nvm#installing-and-updating)

Follow these steps:

```sh
# Step 1: Clone the repository using the project's Git URL.
git clone <YOUR_GIT_URL>

# Step 2: Navigate to the project directory.
cd <YOUR_PROJECT_NAME>

# Step 3: Install the necessary dependencies.
npm i

# Step 4: Start the development server with auto-reloading and an instant preview.
npm run dev
```

## How to run tests

This project uses Vitest as the testing framework with Testing Library for React component testing. The test suite includes unit tests, integration tests, and API service tests.

### Available test commands:

```sh
# Run tests in watch mode (recommended for development)
npm run test

# Run tests with UI interface
npm run test:ui

# Run tests once and exit
npm run test:run

# Run tests with coverage report
npm run test:coverage
```

### Test structure:

- **Unit tests**: Located in `src/lib/__tests__/` - Test individual functions and utilities
- **Component tests**: Located in `src/pages/__tests__/` - Test React components and their behavior
- **Test setup**: Configuration in `src/test/setup.ts` - Global mocks and test environment setup

### Writing tests:

Tests are written using Vitest and Testing Library. Test files should be placed in `__tests__` directories and follow the naming convention `*.test.{js,ts,jsx,tsx}` or `*.spec.{js,ts,jsx,tsx}`.

Example test structure:
```typescript
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MyComponent } from '../MyComponent'

describe('MyComponent', () => {
  it('renders correctly', () => {
    render(<MyComponent />)
    expect(screen.getByText('Expected text')).toBeInTheDocument()
  })
})
```

**Edit a file directly in GitHub**

- Navigate to the desired file(s).
- Click the "Edit" button (pencil icon) at the top right of the file view.
- Make your changes and commit the changes.

**Use GitHub Codespaces**

- Navigate to the main page of your repository.
- Click on the "Code" button (green button) near the top right.
- Select the "Codespaces" tab.
- Click on "New codespace" to launch a new Codespace environment.
- Edit files directly within the Codespace and commit and push your changes once you're done.

## What technologies are used for this project?

This project is built with:

- Vite
- TypeScript
- React
- shadcn-ui
- Tailwind CSS
- Vitest (testing framework)
- Testing Library (React testing utilities)

## How can I deploy this project?

Simply open [Lovable](https://lovable.dev/projects/3fb59891-0805-4cff-8505-7bb16297af10) and click on Share -> Publish.

## Can I connect a custom domain to my Lovable project?

Yes, you can!

To connect a domain, navigate to Project > Settings > Domains and click Connect Domain.

Read more here: [Setting up a custom domain](https://docs.lovable.dev/tips-tricks/custom-domain#step-by-step-guide)
