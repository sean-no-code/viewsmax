// Mock API "demo mode" for local testing without the ViewsMax backend.
//
// Enable by either:
//   - setting VITE_USE_MOCK_API=true in .env (compile-time), or
//   - running `localStorage.setItem('mock_api', '1')` in the browser console
//     (runtime — no rebuild needed; refresh the page after toggling).
//
// State is persisted in localStorage under `mock_api_db` so the multi-step
// onboarding flow stays coherent across navigations and reloads.

import type { ApiResponse, Connection, FeatureRequest, OAuthProvider } from "@/lib/api-service";

export const isMockApi = (): boolean => {
  try {
    if (import.meta.env.VITE_USE_MOCK_API === "true") return true;
    return localStorage.getItem("mock_api") === "1";
  } catch {
    return false;
  }
};

interface MockUser {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  onboarding_completed_at: string | null;
}

interface MockSubscription {
  stripe_subscription_id: string;
  status: string;
  current_period_end: string;
  plan_id: string;
}

interface MockDb {
  users: Record<string, MockUser>;
  tokens: Record<string, string>; // verify-token -> email
  lastPendingEmail: string | null;
  connections: Connection[];
  subscription: MockSubscription | null;
  featureRequests: FeatureRequest[];
}

const DB_KEY = "mock_api_db";

const seedFeatureRequests = (): FeatureRequest[] => [
  {
    id: 1,
    title: "Bulk export analytics to CSV",
    description: "Let me download channel analytics as a spreadsheet for reporting.",
    category: "Feature",
    upvotes_count: 12,
    has_upvoted: false,
    status: "Planned",
    created_at: new Date(Date.now() - 86400000 * 3).toISOString(),
  },
  {
    id: 2,
    title: "Dark mode for the dashboard",
    description: "A dark theme would be easier on the eyes for late-night editing.",
    category: "Improvement",
    upvotes_count: 5,
    has_upvoted: false,
    status: null,
    created_at: new Date(Date.now() - 86400000).toISOString(),
  },
];

const loadDb = (): MockDb => {
  try {
    const raw = localStorage.getItem(DB_KEY);
    if (raw) return JSON.parse(raw) as MockDb;
  } catch {
    /* fall through to fresh db */
  }
  const fresh: MockDb = {
    users: {},
    tokens: {},
    lastPendingEmail: null,
    connections: [],
    subscription: null,
    featureRequests: seedFeatureRequests(),
  };
  saveDb(fresh);
  return fresh;
};

const saveDb = (db: MockDb) => {
  try {
    localStorage.setItem(DB_KEY, JSON.stringify(db));
  } catch {
    /* ignore quota errors in mock */
  }
};

const delay = (ms = 350) => new Promise((resolve) => setTimeout(resolve, ms));

const randomId = () => Math.random().toString(36).slice(2, 11);

const currentSessionEmail = (): string | null => {
  try {
    const raw = localStorage.getItem("auth_session");
    if (!raw) return null;
    return JSON.parse(raw)?.user?.email ?? null;
  } catch {
    return null;
  }
};

// Build the user payload the frontend expects, with onboarding fields computed
// from the current mock state.
const buildUser = (db: MockDb, email: string) => {
  const u = db.users[email];
  return {
    id: u.id,
    name: u.name,
    email: u.email,
    email_verified_at: u.email_verified_at,
    onboarding_completed_at: u.onboarding_completed_at,
    connections_count: db.connections.length,
    has_active_subscription:
      !!db.subscription && ["active", "trialing"].includes(db.subscription.status),
  };
};

const PROVIDER_LABEL: Record<OAuthProvider, string> = {
  youtube: "YouTube",
  tiktok: "TikTok",
  instagram: "Instagram",
};

export const mockApi = {
  async register(name: string, email: string): Promise<ApiResponse<{ email: string }>> {
    await delay();
    const db = loadDb();
    const token = randomId();
    db.users[email] = {
      id: randomId(),
      name,
      email,
      email_verified_at: null,
      onboarding_completed_at: null,
    };
    db.tokens[token] = email;
    db.lastPendingEmail = email;
    saveDb(db);
    // Surface the magic link in the console since there is no real email.
    // eslint-disable-next-line no-console
    console.info(`[mock-api] Verify link: ${window.location.origin}/verify-email?token=${token}`);
    return { success: true, data: { email }, message: "verification_sent" };
  },

  async verifyEmail(token: string): Promise<ApiResponse<{ token: string; token_type: string; user: ReturnType<typeof buildUser> }>> {
    await delay();
    const db = loadDb();
    // Accept the matching token, or fall back to the last pending email so a
    // hand-typed token (e.g. /verify-email?token=test) still works in demo mode.
    const email = db.tokens[token] || db.lastPendingEmail;
    if (!email || !db.users[email]) {
      return { success: false, error: "This verification link is invalid or has expired." };
    }
    db.users[email].email_verified_at = new Date().toISOString();
    delete db.tokens[token];
    saveDb(db);
    return {
      success: true,
      data: { token: `mock-token-${db.users[email].id}`, token_type: "Bearer", user: buildUser(db, email) },
    };
  },

  async resendVerification(): Promise<ApiResponse<void>> {
    await delay();
    const db = loadDb();
    if (db.lastPendingEmail) {
      const token = randomId();
      db.tokens[token] = db.lastPendingEmail;
      saveDb(db);
      // eslint-disable-next-line no-console
      console.info(`[mock-api] Verify link: ${window.location.origin}/verify-email?token=${token}`);
    }
    return { success: true };
  },

  async login(email: string): Promise<ApiResponse<{ token: string; token_type: string; user: ReturnType<typeof buildUser> }>> {
    await delay();
    const db = loadDb();
    const user = db.users[email];
    if (!user) {
      return { success: false, error: "Invalid email or password. Please check your credentials." };
    }
    if (!user.email_verified_at) {
      return { success: false, error: "email_not_verified" };
    }
    return {
      success: true,
      data: { token: `mock-token-${user.id}`, token_type: "Bearer", user: buildUser(db, email) },
    };
  },

  async getUserProfile(): Promise<ApiResponse<{ user: ReturnType<typeof buildUser>; user_credits: number }>> {
    await delay(150);
    const db = loadDb();
    const email = currentSessionEmail();
    if (!email || !db.users[email]) {
      return { success: false, error: "Not authenticated" };
    }
    return { success: true, data: { user: buildUser(db, email), user_credits: 1000 } };
  },

  async getCurrentPlan(): Promise<ApiResponse<{ plan: unknown; pivot: unknown }>> {
    await delay(150);
    const db = loadDb();
    if (!db.subscription) {
      return { success: true, data: { plan: null, pivot: null } };
    }
    return {
      success: true,
      data: {
        plan: { name: "Creator Pro (mock)" },
        pivot: {
          stripe_subscription_id: db.subscription.stripe_subscription_id,
          status: db.subscription.status,
          plan_id: db.subscription.plan_id,
          current_period_end: db.subscription.current_period_end,
          cancelled_at: null,
        },
      },
    };
  },

  async completeOnboarding(): Promise<ApiResponse<{ user: ReturnType<typeof buildUser> }>> {
    await delay();
    const db = loadDb();
    const email = currentSessionEmail();
    if (!email || !db.users[email]) {
      return { success: false, error: "Not authenticated" };
    }
    if (!db.subscription) {
      return { success: false, error: "Add payment to finish setting up your account." };
    }
    db.users[email].onboarding_completed_at = new Date().toISOString();
    saveDb(db);
    return { success: true, data: { user: buildUser(db, email) } };
  },

  async getConnections(): Promise<ApiResponse<Connection[]>> {
    await delay(150);
    return { success: true, data: loadDb().connections };
  },

  async exchangeOAuthCode(provider: OAuthProvider): Promise<ApiResponse<{ connection: Connection }>> {
    await delay();
    const db = loadDb();
    const connection: Connection = {
      id: Date.now(),
      provider,
      account_name: `My ${PROVIDER_LABEL[provider]} (mock)`,
      account_id: `mock_${randomId()}`,
      avatar_url: null,
      connected_at: new Date().toISOString(),
    };
    db.connections = [...db.connections.filter((c) => c.provider !== provider), connection];
    saveDb(db);
    return { success: true, data: { connection } };
  },

  async disconnectConnection(id: number): Promise<ApiResponse<void>> {
    await delay();
    const db = loadDb();
    db.connections = db.connections.filter((c) => c.id !== id);
    saveDb(db);
    return { success: true };
  },

  async createStripeSetupIntent(): Promise<ApiResponse<{ client_secret: string; customer_id: string }>> {
    await delay();
    return { success: true, data: { client_secret: `seti_mock_${randomId()}`, customer_id: `cus_mock_${randomId()}` } };
  },

  async createStripeSubscription(): Promise<ApiResponse<{ stripe_subscription_id: string; status: string; current_period_end: string; plan_id: string }>> {
    await delay();
    const db = loadDb();
    const sub: MockSubscription = {
      stripe_subscription_id: `sub_mock_${randomId()}`,
      status: "trialing",
      current_period_end: new Date(Date.now() + 86400000 * 3).toISOString(),
      plan_id: "price_mock_creator_pro",
    };
    db.subscription = sub;
    saveDb(db);
    return { success: true, data: sub };
  },

  async getFeatureRequests(sort: "top" | "new"): Promise<ApiResponse<FeatureRequest[]>> {
    await delay(150);
    const list = [...loadDb().featureRequests];
    list.sort((a, b) =>
      sort === "new"
        ? new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
        : b.upvotes_count - a.upvotes_count
    );
    return { success: true, data: list };
  },

  async createFeatureRequest(data: { title: string; description: string; category?: string }): Promise<ApiResponse<FeatureRequest>> {
    await delay();
    const db = loadDb();
    const fr: FeatureRequest = {
      id: Date.now(),
      title: data.title,
      description: data.description,
      category: data.category ?? null,
      upvotes_count: 1,
      has_upvoted: true,
      status: null,
      created_at: new Date().toISOString(),
    };
    db.featureRequests = [fr, ...db.featureRequests];
    saveDb(db);
    return { success: true, data: fr };
  },

  async upvoteFeatureRequest(id: number): Promise<ApiResponse<{ upvotes_count: number; has_upvoted: boolean }>> {
    await delay(150);
    const db = loadDb();
    const fr = db.featureRequests.find((r) => r.id === id);
    if (!fr) return { success: false, error: "Not found" };
    fr.has_upvoted = !fr.has_upvoted;
    fr.upvotes_count += fr.has_upvoted ? 1 : -1;
    saveDb(db);
    return { success: true, data: { upvotes_count: fr.upvotes_count, has_upvoted: fr.has_upvoted } };
  },
};
