import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import './index.css'
import './i18n' // must load before any component calls useTranslation
import { initTracking } from './lib/tracking'
import { initSentry } from './lib/sentry'

// Analytics/pixels: IDs come from VITE_* env vars and only load on the configured
// production hostname (see src/lib/tracking.ts and .env.example).
initTracking()

// Error reporting: VITE_SENTRY_DSN, production hostname only (see src/lib/sentry.ts).
initSentry()

createRoot(document.getElementById("root")!).render(<App />);
