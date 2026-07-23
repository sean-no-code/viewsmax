// Provider-agnostic OAuth "connect account" flow.
// Generalizes the YouTube popup + postMessage pattern in youtube-auth.ts to
// support YouTube, TikTok and Instagram. The popup posts a generic
// { type: 'OAUTH_SUCCESS', provider, code } message (see OAuthCallback.tsx),
// which we exchange for a stored connection via the backend.

import { viewsMaxApi, type Connection, type OAuthProvider } from "@/lib/api-service";
import { isMockApi } from "@/lib/mock-api";

const REDIRECT_URI = `${window.location.origin}/oauth/callback`;

interface ProviderConfig {
  label: string;
  // `codeChallenge` is supplied for providers that require PKCE (TikTok).
  buildAuthUrl: (state: string, codeChallenge?: string) => string;
}

// ---- PKCE helpers (required by TikTok's OAuth v2) ----
const base64UrlEncode = (bytes: Uint8Array): string => {
  let str = "";
  for (const b of bytes) str += String.fromCharCode(b);
  return btoa(str).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
};
const generateCodeVerifier = (): string => {
  const arr = new Uint8Array(32);
  crypto.getRandomValues(arr);
  return base64UrlEncode(arr); // 43-char URL-safe string
};
const computeCodeChallenge = async (verifier: string): Promise<string> => {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier));
  return base64UrlEncode(new Uint8Array(digest));
};

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;
const TIKTOK_CLIENT_KEY = import.meta.env.VITE_TIKTOK_CLIENT_KEY;
const INSTAGRAM_CLIENT_ID = import.meta.env.VITE_INSTAGRAM_CLIENT_ID;

export const PROVIDER_CONFIG: Record<OAuthProvider, ProviderConfig> = {
  youtube: {
    label: "YouTube",
    buildAuthUrl: (state) => {
      const params = new URLSearchParams({
        client_id: GOOGLE_CLIENT_ID || "",
        redirect_uri: REDIRECT_URI,
        scope: [
          // Publishing (resumable video upload) — required to post to YouTube.
          "https://www.googleapis.com/auth/youtube.upload",
          // Read + analytics — keep so the Analytics feature keeps working.
          "https://www.googleapis.com/auth/youtube.readonly",
          "https://www.googleapis.com/auth/yt-analytics.readonly",
          "https://www.googleapis.com/auth/userinfo.profile",
        ].join(" "),
        response_type: "code",
        access_type: "offline",
        prompt: "select_account consent",
        state,
        include_granted_scopes: "true",
      });
      return `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`;
    },
  },
  tiktok: {
    label: "TikTok",
    buildAuthUrl: (state, codeChallenge) => {
      const params = new URLSearchParams({
        client_key: TIKTOK_CLIENT_KEY || "",
        redirect_uri: REDIRECT_URI,
        // video.publish = Direct Post; video.upload = Upload-to-Inbox (the
        // fallback used while the app is unaudited, so public accounts can post).
        scope: "user.info.basic,video.list,video.publish,video.upload",
        response_type: "code",
        state,
        // TikTok requires PKCE.
        code_challenge: codeChallenge || "",
        code_challenge_method: "S256",
      });
      return `https://www.tiktok.com/v2/auth/authorize/?${params.toString()}`;
    },
  },
  instagram: {
    label: "Instagram",
    buildAuthUrl: (state) => {
      const params = new URLSearchParams({
        client_id: INSTAGRAM_CLIENT_ID || "",
        redirect_uri: REDIRECT_URI,
        scope: "instagram_basic,pages_show_list",
        response_type: "code",
        state,
      });
      return `https://www.facebook.com/v19.0/dialog/oauth?${params.toString()}`;
    },
  },
};

export const PROVIDERS = Object.keys(PROVIDER_CONFIG) as OAuthProvider[];

// Whether the publishable client id/key for a provider is configured.
export const isProviderConfigured = (provider: OAuthProvider): boolean => {
  switch (provider) {
    case "youtube":
      return !!GOOGLE_CLIENT_ID;
    case "tiktok":
      return !!TIKTOK_CLIENT_KEY;
    case "instagram":
      return !!INSTAGRAM_CLIENT_ID;
    default:
      return false;
  }
};

// Wait for the popup to post back its authorization code for THIS provider.
const waitForCallback = (popup: Window, provider: OAuthProvider): Promise<{ code: string }> => {
  return new Promise((resolve, reject) => {
    const checkClosed = setInterval(() => {
      if (popup.closed) {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        reject(new Error("Connection window was closed before finishing."));
      }
    }, 1000);

    const messageHandler = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      if (data.type === "OAUTH_SUCCESS" && data.provider === provider) {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        popup.close();
        resolve({ code: data.code });
      } else if (data.type === "OAUTH_ERROR" && data.provider === provider) {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        popup.close();
        reject(new Error(data.error || "Authorization failed."));
      }
    };

    window.addEventListener("message", messageHandler);
  });
};

// Open the provider's OAuth consent popup, then exchange the returned code for a
// stored Connection on the backend. Returns the created Connection.
export const connectProvider = async (provider: OAuthProvider): Promise<Connection> => {
  // Demo mode: skip the real OAuth popup and create a mock connection directly.
  if (isMockApi()) {
    const result = await viewsMaxApi.exchangeOAuthCode(provider, "mock-code", REDIRECT_URI);
    if (!result.success || !result.data) {
      throw new Error(result.error || "Failed to connect account.");
    }
    return result.data.connection;
  }

  if (!isProviderConfigured(provider)) {
    throw new Error(`${PROVIDER_CONFIG[provider].label} isn't configured yet.`);
  }

  const state = `${provider}_${Date.now()}`;

  // TikTok mandates PKCE: generate a verifier now, send its challenge on the
  // authorize URL, and pass the verifier to the backend for the token exchange.
  let codeVerifier: string | undefined;
  let codeChallenge: string | undefined;
  if (provider === "tiktok") {
    codeVerifier = generateCodeVerifier();
    codeChallenge = await computeCodeChallenge(codeVerifier);
  }

  const authUrl = PROVIDER_CONFIG[provider].buildAuthUrl(state, codeChallenge);

  const popup = window.open(
    authUrl,
    "oauth-connect",
    "width=500,height=700,scrollbars=yes,resizable=yes,location=yes"
  );

  if (!popup) {
    throw new Error("Popup blocked. Please allow popups for this site and try again.");
  }

  const { code } = await waitForCallback(popup, provider);

  const result = await viewsMaxApi.exchangeOAuthCode(provider, code, REDIRECT_URI, codeVerifier);
  if (!result.success || !result.data) {
    throw new Error(result.error || "Failed to connect account.");
  }

  return result.data.connection;
};

// ---------------------------------------------------------------------------
// Newer multi-platform OAuth (the `/api/social` system — e.g. X / Twitter).
// Here the backend builds the authorize URL and owns the PKCE verifier + CSRF
// state, so the frontend just opens the popup and matches the callback by the
// opaque `state` the backend handed us. This avoids duplicating PKCE/secret
// handling client-side and reuses the fully-built provider on the server.
// ---------------------------------------------------------------------------

const waitForCallbackByState = (popup: Window, expectedState: string): Promise<{ code: string; state: string }> => {
  return new Promise((resolve, reject) => {
    const checkClosed = setInterval(() => {
      if (popup.closed) {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        reject(new Error("Connection window was closed before finishing."));
      }
    }, 1000);

    const messageHandler = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      // Match on `state` (unique per attempt) so this can't be resolved by a
      // legacy popup's OAUTH_SUCCESS, and vice-versa.
      if (data.state !== expectedState) return;
      if (data.type === "OAUTH_SUCCESS") {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        popup.close();
        resolve({ code: data.code, state: data.state });
      } else if (data.type === "OAUTH_ERROR") {
        clearInterval(checkClosed);
        window.removeEventListener("message", messageHandler);
        popup.close();
        reject(new Error(data.error || "Authorization failed."));
      }
    };

    window.addEventListener("message", messageHandler);
  });
};

// Connect a platform that lives in the `/api/social` system. Returns the
// connected SocialAccount(s).
export const connectSocialPlatform = async (platform: string) => {
  // Open the popup synchronously (inside the click gesture) so the browser
  // doesn't block it while we fetch the authorize URL, then navigate it.
  const popup = window.open(
    "",
    "oauth-connect",
    "width=500,height=700,scrollbars=yes,resizable=yes,location=yes"
  );
  if (!popup) {
    throw new Error("Popup blocked. Please allow popups for this site and try again.");
  }

  const urlRes = await viewsMaxApi.getSocialAuthUrl(platform, REDIRECT_URI);
  if (!urlRes.success || !urlRes.data) {
    popup.close();
    throw new Error(urlRes.error || `${platform} isn't configured yet.`);
  }

  const { authorization_url, state, redirect_uri } = urlRes.data;
  popup.location.href = authorization_url;

  const { code } = await waitForCallbackByState(popup, state);

  const result = await viewsMaxApi.exchangeSocialCode(platform, { code, state, redirectUri: redirect_uri });
  // An empty array is a truthy value but means nothing was actually connected
  // (e.g. an Instagram login with no Business account linked to a Page).
  if (!result.success || !result.data || result.data.length === 0) {
    throw new Error(result.error || "No account was connected. Check the platform's requirements and try again.");
  }

  return result.data;
};
