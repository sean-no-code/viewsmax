import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import './index.css'
import './i18n' // must load before any component calls useTranslation

createRoot(document.getElementById("root")!).render(<App />);
