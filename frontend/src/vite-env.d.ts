/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_GOOGLE_CLIENT_ID: string
  readonly VITE_YOUTUBE_API_KEY: string
  readonly VITE_PAYPAL_CLIENT_ID: string
  readonly VITE_PAYPAL_PLAN_ID: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

// Extend Window interface for Google API
declare global {
  interface Window {
    gapi: {
      load: (api: string, callback: () => void) => void;
      [key: string]: unknown;
    };
  }
}