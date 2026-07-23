import { useEffect, useState } from "react";
import { viewsMaxApi, accountNeedsReconnect } from "@/lib/api-service";

/**
 * One merged view of "who am I on each platform", built from both account
 * stores (legacy Connections: YouTube/TikTok/Instagram; SocialAccounts: X,
 * LinkedIn, Threads, Instagram, …). Gives the composer, calendar and lists a
 * real account avatar + display name per platform instead of a brand glyph.
 *
 * The fetch is cached module-wide so every consumer shares one request per
 * page load; call refreshAccountDirectory() after connecting/disconnecting.
 */
export interface AccountEntry {
  /** SocialAccount id — null for legacy Connection-store platforms (yt/tiktok) */
  id: number | null;
  platform: string;
  avatarUrl: string | null;
  name: string | null;
  needsReconnect: boolean;
}

export type AccountDirectory = Record<string, AccountEntry>;

export interface AccountDirectoryState {
  loading: boolean;
  /** platform id → best display identity for that platform */
  directory: AccountDirectory;
  /** platform id → every connected account (social store only, may be several) */
  accountsByPlatform: Record<string, AccountEntry[]>;
  /** platforms with at least one account */
  connectedIds: string[];
  /** platforms where an account exists but must be re-authorized */
  reconnectIds: string[];
}

let cached: AccountDirectoryState | null = null;
let inflight: Promise<AccountDirectoryState> | null = null;
const listeners = new Set<(s: AccountDirectoryState) => void>();

async function fetchDirectory(): Promise<AccountDirectoryState> {
  const [legacy, social] = await Promise.all([
    viewsMaxApi.getConnections(),
    viewsMaxApi.getSocialAccounts(),
  ]);

  const directory: AccountDirectory = {};
  const accountsByPlatform: Record<string, AccountEntry[]> = {};
  const connected = new Set<string>();
  const broken = new Set<string>();

  if (legacy.success && legacy.data) {
    legacy.data.forEach((conn) => {
      connected.add(conn.provider);
      directory[conn.provider] = {
        id: null,
        platform: conn.provider,
        avatarUrl: conn.avatar_url || null,
        name: conn.account_name || null,
        needsReconnect: false,
      };
    });
  }

  if (social.success && social.data) {
    social.data.forEach((acct) => {
      connected.add(acct.platform);
      const needsReauth = accountNeedsReconnect(acct);
      if (needsReauth) broken.add(acct.platform);
      const entry: AccountEntry = {
        id: acct.id,
        platform: acct.platform,
        avatarUrl: acct.avatar_url || null,
        name: acct.name || (acct.username ? `@${acct.username}` : null),
        needsReconnect: needsReauth,
      };
      (accountsByPlatform[acct.platform] ??= []).push(entry);
      const existing = directory[acct.platform];
      // Prefer a healthy account's identity when a platform has several.
      if (!existing || (existing.needsReconnect && !needsReauth)) {
        directory[acct.platform] = entry;
      }
    });
  }

  return {
    loading: false,
    directory,
    accountsByPlatform,
    connectedIds: [...connected],
    reconnectIds: [...broken],
  };
}

function load(): Promise<AccountDirectoryState> {
  if (cached) return Promise.resolve(cached);
  if (!inflight) {
    inflight = fetchDirectory().then((state) => {
      cached = state;
      inflight = null;
      listeners.forEach((fn) => fn(state));
      return state;
    });
  }
  return inflight;
}

/** Drop the cache (after connect/disconnect) and refetch for all consumers. */
export function refreshAccountDirectory(): void {
  cached = null;
  inflight = null;
  void load();
}

const EMPTY: AccountDirectoryState = { loading: true, directory: {}, accountsByPlatform: {}, connectedIds: [], reconnectIds: [] };

export function useAccountDirectory(): AccountDirectoryState {
  const [state, setState] = useState<AccountDirectoryState>(cached ?? EMPTY);

  useEffect(() => {
    let mounted = true;
    const onUpdate = (s: AccountDirectoryState) => { if (mounted) setState(s); };
    listeners.add(onUpdate);
    void load().then(onUpdate);
    return () => {
      mounted = false;
      listeners.delete(onUpdate);
    };
  }, []);

  return state;
}
