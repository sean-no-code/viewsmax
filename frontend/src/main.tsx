import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import './index.css'
import './i18n' // must load before any component calls useTranslation
import { initTracking } from './lib/tracking'

// Analytics/pixels: IDs come from VITE_* env vars and only load on the configured
// production hostname (see src/lib/tracking.ts and .env.example).
initTracking()

createRoot(document.getElementById("root")!).render(<App />);
