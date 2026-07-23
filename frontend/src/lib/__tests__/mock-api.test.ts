import { describe, it, expect, beforeEach } from "vitest";
import { viewsMaxApi } from "@/lib/api-service";
import { connectProvider } from "@/lib/oauth-connect";

// Drives the real viewsMaxApi singleton with mock mode enabled, proving the
// full signup -> verify -> connect -> trial -> onboarding flow works offline.
describe("mock API demo mode", () => {
  beforeEach(() => {
    // The shared test setup stubs localStorage with no-op vi.fn()s; install a
    // real in-memory implementation so mock-api can persist its state.
    const store = new Map<string, string>();
    const ls = {
      getItem: (k: string) => (store.has(k) ? store.get(k)! : null),
      setItem: (k: string, v: string) => {
        store.set(k, String(v));
      },
      removeItem: (k: string) => {
        store.delete(k);
      },
      clear: () => store.clear(),
      key: (i: number) => [...store.keys()][i] ?? null,
      get length() {
        return store.size;
      },
    };
    Object.defineProperty(window, "localStorage", { value: ls, configurable: true, writable: true });
    Object.defineProperty(globalThis, "localStorage", { value: ls, configurable: true, writable: true });
    localStorage.setItem("mock_api", "1");
  });

  const setSession = (user: { email: string }) =>
    localStorage.setItem("auth_session", JSON.stringify({ token: "t", token_type: "Bearer", user }));

  it("walks the full onboarding flow", async () => {
    // 1. Register -> verification sent, no token.
    const reg = await viewsMaxApi.register("Jane", "jane@example.com", "pw", "pw", true);
    expect(reg.success).toBe(true);
    expect(reg.data?.email).toBe("jane@example.com");

    // 2. Login before verifying is blocked.
    const earlyLogin = await viewsMaxApi.login("jane@example.com", "pw");
    expect(earlyLogin.success).toBe(false);
    expect(earlyLogin.error).toMatch(/not_verified/);

    // 3. Verify email -> returns a session; onboarding not yet complete.
    const verify = await viewsMaxApi.verifyEmail("any-token");
    expect(verify.success).toBe(true);
    expect(verify.data?.user.email_verified_at).toBeTruthy();
    expect(verify.data?.user.onboarding_completed_at).toBeNull();
    setSession(verify.data!.user);

    // 4. No connections yet; completing onboarding is rejected.
    expect((await viewsMaxApi.getConnections()).data).toHaveLength(0);
    expect((await viewsMaxApi.completeOnboarding()).success).toBe(false);

    // 5. Connect an account (popup bypassed in mock mode).
    const conn = await connectProvider("youtube");
    expect(conn.provider).toBe("youtube");
    expect((await viewsMaxApi.getConnections()).data).toHaveLength(1);

    // 6. Start the trial subscription.
    const sub = await viewsMaxApi.createStripeSubscription("pm_mock");
    expect(sub.success).toBe(true);
    expect(sub.data?.status).toBe("trialing");

    // 7. Profile now reflects connection + active subscription.
    const profile = await viewsMaxApi.getUserProfile();
    expect(profile.data?.user.connections_count).toBe(1);
    expect(profile.data?.user.has_active_subscription).toBe(true);

    // 8. Complete onboarding succeeds and stamps the timestamp.
    const done = await viewsMaxApi.completeOnboarding();
    expect(done.success).toBe(true);
    expect(done.data?.user.onboarding_completed_at).toBeTruthy();
  });

  it("completes onboarding with payment but no connection (connecting is optional)", async () => {
    // Register -> verify -> session, then subscribe WITHOUT connecting any account.
    await viewsMaxApi.register("Skip", "skip@example.com", "pw", "pw", true);
    const verify = await viewsMaxApi.verifyEmail("any-token");
    setSession(verify.data!.user);

    // No subscription yet -> completion is rejected even though payment is the
    // only requirement.
    expect((await viewsMaxApi.getConnections()).data).toHaveLength(0);
    expect((await viewsMaxApi.completeOnboarding()).success).toBe(false);

    // Subscribe (payment is required) but skip connecting any account.
    const sub = await viewsMaxApi.createStripeSubscription("pm_mock");
    expect(sub.success).toBe(true);

    // Completion now succeeds despite having zero connections.
    expect((await viewsMaxApi.getConnections()).data).toHaveLength(0);
    const done = await viewsMaxApi.completeOnboarding();
    expect(done.success).toBe(true);
    expect(done.data?.user.onboarding_completed_at).toBeTruthy();
  });

  it("supports feature request create + upvote", async () => {
    const created = await viewsMaxApi.createFeatureRequest({
      title: "Test idea",
      description: "Please add this",
      category: "Feature",
    });
    expect(created.success).toBe(true);

    const list = await viewsMaxApi.getFeatureRequests("top");
    expect(list.data?.some((r) => r.title === "Test idea")).toBe(true);

    const id = created.data!.id;
    const before = created.data!.upvotes_count;
    const toggled = await viewsMaxApi.upvoteFeatureRequest(id);
    expect(toggled.data?.upvotes_count).toBe(before - 1); // was auto-upvoted on create
    expect(toggled.data?.has_upvoted).toBe(false);
  });
});
