// Boosts — like-threshold automations per connected X account. Auto Repost
// retweets a post once it hits N likes; Auto Promo replies to it with a promo
// comment. Checks run every 6h, up to 3 times per post, and stop on success.
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { SectionHead, Icon } from "@/components/analytics/primitives";
import { PAvatar } from "@/components/post/composer";
import { useAccountDirectory } from "@/components/post/useAccountDirectory";
import { viewsMaxApi, type BoostCheck, type BoostSetting } from "@/lib/api-service";
import { xWeightedLength, X_LIMIT } from "@/lib/text-metrics";

type Feature = "auto_repost" | "auto_promo";

interface DraftSetting {
  enabled: boolean;
  likesThreshold: number;
  promoText: string;
}

const FEATURE_COPY: Record<Feature, { title: string; blurb: string }> = {
  auto_repost: {
    title: "Auto Repost",
    blurb: "When a post reaches the likes below, repost it to give it a second wave of reach.",
  },
  auto_promo: {
    title: "Auto Promo",
    blurb: "When a post reaches the likes below, reply to it with your promo so everyone in the thread sees it.",
  },
};

const STATUS_TONE: Record<string, { color: string; label: string }> = {
  pending: { color: "var(--ink-on-paper-3)", label: "Watching" },
  triggered: { color: "var(--up)", label: "Boosted" },
  exhausted: { color: "var(--ink-on-paper-3)", label: "Never hit the threshold" },
  failed: { color: "var(--vm-red)", label: "Failed" },
};

function Toggle({ checked, onChange, disabled }: { checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <span
      onClick={() => !disabled && onChange(!checked)}
      role="switch"
      aria-checked={checked}
      style={{ width: 36, height: 20, borderRadius: 999, background: checked ? "var(--vm-volt)" : "var(--line-2)", position: "relative", transition: "background var(--dur)", cursor: disabled ? "not-allowed" : "pointer", flexShrink: 0, display: "inline-block", opacity: disabled ? 0.5 : 1 }}
    >
      <span style={{ position: "absolute", top: 2, left: checked ? 18 : 2, width: 16, height: 16, borderRadius: "50%", background: "#fff", transition: "left var(--dur)", boxShadow: "0 1px 2px rgba(0,0,0,.3)" }} />
    </span>
  );
}

export default function Boosts() {
  const { accountsByPlatform, loading: dirLoading } = useAccountDirectory();
  const xAccounts = accountsByPlatform["x"] ?? [];
  const [settings, setSettings] = useState<BoostSetting[]>([]);
  const [activity, setActivity] = useState<BoostCheck[]>([]);
  const [drafts, setDrafts] = useState<Record<string, DraftSetting>>({});
  const [savingKey, setSavingKey] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const keyOf = (accountId: number, feature: Feature) => `${accountId}:${feature}`;

  useEffect(() => {
    let cancelled = false;
    Promise.all([viewsMaxApi.getBoostSettings(), viewsMaxApi.getBoostActivity()]).then(([s, a]) => {
      if (cancelled) return;
      if (s.success && s.data) {
        setSettings(s.data);
        const d: Record<string, DraftSetting> = {};
        for (const st of s.data) {
          d[keyOf(st.social_account_id, st.feature)] = {
            enabled: st.enabled,
            likesThreshold: st.likes_threshold,
            promoText: st.promo_text ?? "",
          };
        }
        setDrafts(d);
      }
      if (a.success && a.data) setActivity(a.data);
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  const draftFor = (accountId: number, feature: Feature): DraftSetting =>
    drafts[keyOf(accountId, feature)] ?? { enabled: false, likesThreshold: 20, promoText: "" };

  const patchDraft = (accountId: number, feature: Feature, p: Partial<DraftSetting>) =>
    setDrafts((d) => ({ ...d, [keyOf(accountId, feature)]: { ...draftFor(accountId, feature), ...p } }));

  const save = async (accountId: number, feature: Feature, next?: Partial<DraftSetting>) => {
    const draft = { ...draftFor(accountId, feature), ...next };
    if (next) patchDraft(accountId, feature, next);
    const key = keyOf(accountId, feature);
    setSavingKey(key);
    const res = await viewsMaxApi.updateBoostSetting(accountId, {
      feature,
      enabled: draft.enabled,
      likes_threshold: Math.max(1, draft.likesThreshold),
      promo_text: feature === "auto_promo" ? draft.promoText : undefined,
    });
    setSavingKey(null);
    if (!res.success) {
      toast.error(res.error || "Couldn't save the boost setting.");
      return;
    }
    toast.success(`${FEATURE_COPY[feature].title} ${draft.enabled ? "activated" : "turned off"}.`);
  };

  const recentFor = useMemo(() => {
    const byAccount: Record<number, BoostCheck[]> = {};
    for (const check of activity) {
      const accId = check.setting?.social_account_id;
      if (accId != null) (byAccount[accId] ??= []).push(check);
    }
    return byAccount;
  }, [activity]);

  const inputStyle = { width: 90, border: "1px solid var(--line-1)", borderRadius: 8, padding: "6px 9px", fontFamily: "var(--font-mono)", fontSize: 12.5, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" } as const;

  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ maxWidth: 760, margin: "0 auto", display: "flex", flexDirection: "column", gap: 18 }}>
        <SectionHead eyebrow="AUTOMATIONS" title="Boosts." />
        <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-2)", margin: 0, lineHeight: 1.5 }}>
          When one of your X posts takes off, ViewsMax gives it a push automatically — a repost for extra reach, a promo reply for extra conversions. Checks run for 18 hours after each post publishes.
        </p>

        {loading || dirLoading ? (
          <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 32, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 13.5 }}>Loading…</div>
        ) : xAccounts.length === 0 ? (
          <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 32, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 13.5 }}>
            Boosts work on X (Twitter) accounts. Connect one on the Connections page to get started.
          </div>
        ) : (
          xAccounts.map((account) => {
            const accId = account.id!;
            const recent = (recentFor[accId] ?? []).slice(0, 6);
            return (
              <div key={accId} style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 20, boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
                <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 16 }}>
                  <PAvatar id="x" size={36} avatarUrl={account.avatarUrl} />
                  <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 15, color: "var(--ink-on-paper-1)" }}>{account.name ?? "X account"}</span>
                  {account.needsReconnect && <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--vm-red)" }}>Reconnect needed</span>}
                </div>

                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
                  {(Object.keys(FEATURE_COPY) as Feature[]).map((feature) => {
                    const draft = draftFor(accId, feature);
                    const key = keyOf(accId, feature);
                    const promoLen = xWeightedLength(draft.promoText);
                    return (
                      <div key={feature} style={{ border: "1px solid var(--line-1)", borderRadius: 12, padding: 14, background: "var(--paper-1)", display: "flex", flexDirection: "column", gap: 10 }}>
                        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
                          <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>{FEATURE_COPY[feature].title}</span>
                          <Toggle
                            checked={draft.enabled}
                            disabled={savingKey === key}
                            onChange={(v) => {
                              if (v && feature === "auto_promo" && !draft.promoText.trim()) {
                                patchDraft(accId, feature, { enabled: true });
                                toast.info("Write the promo comment, then Save to activate.");
                                return;
                              }
                              void save(accId, feature, { enabled: v });
                            }}
                          />
                        </div>
                        <p style={{ margin: 0, fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>{FEATURE_COPY[feature].blurb}</p>
                        <label style={{ display: "flex", alignItems: "center", gap: 8, fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-2)" }}>
                          Likes to trigger
                          <input
                            type="number"
                            min={1}
                            value={draft.likesThreshold}
                            onChange={(e) => patchDraft(accId, feature, { likesThreshold: Math.max(1, Number(e.target.value) || 1) })}
                            style={inputStyle}
                          />
                        </label>
                        {feature === "auto_promo" && (
                          <>
                            <textarea
                              value={draft.promoText}
                              onChange={(e) => patchDraft(accId, feature, { promoText: e.target.value })}
                              rows={3}
                              placeholder="e.g. Register to my newsletter → https://…"
                              style={{ border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontFamily: "var(--font-body)", fontSize: 12.5, lineHeight: 1.5, resize: "vertical", background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
                            />
                            <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, color: promoLen > X_LIMIT ? "var(--vm-red)" : "var(--ink-on-paper-3)", alignSelf: "flex-end" }}>{X_LIMIT - promoLen} left</span>
                          </>
                        )}
                        <button
                          onClick={() => void save(accId, feature)}
                          disabled={savingKey === key || (feature === "auto_promo" && draft.enabled && (!draft.promoText.trim() || promoLen > X_LIMIT))}
                          style={{ alignSelf: "flex-start", background: "var(--ink-on-paper-1)", color: "var(--paper-0)", border: "none", borderRadius: 999, padding: "7px 14px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, cursor: "pointer", opacity: savingKey === key ? 0.6 : 1 }}
                        >
                          {savingKey === key ? "Saving…" : "Save"}
                        </button>
                      </div>
                    );
                  })}
                </div>

                {recent.length > 0 && (
                  <div style={{ marginTop: 14 }}>
                    <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, letterSpacing: "0.08em", color: "var(--ink-on-paper-3)", marginBottom: 6 }}>RECENT ACTIVITY</div>
                    {recent.map((check) => {
                      const tone = STATUS_TONE[check.status] ?? STATUS_TONE.pending;
                      return (
                        <div key={check.id} style={{ display: "flex", alignItems: "center", gap: 8, padding: "6px 0", borderTop: "1px solid var(--paper-2)", fontFamily: "var(--font-body)", fontSize: 12 }}>
                          <Icon name={check.feature === "auto_repost" ? "repeat" : "message-circle"} size={13} stroke="var(--ink-on-paper-3)" />
                          <span style={{ color: "var(--ink-on-paper-2)", flex: 1, minWidth: 0, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                            {FEATURE_COPY[check.feature].title} · {check.target?.post?.caption?.slice(0, 60) || `post ${check.target?.platform_post_id ?? ""}`}
                          </span>
                          <span style={{ color: tone.color, fontWeight: 700, flexShrink: 0 }} title={check.error ?? undefined}>{tone.label}</span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })
        )}

        <p style={{ fontFamily: "var(--font-body)", fontSize: 11.5, color: "var(--ink-on-paper-3)", margin: 0, lineHeight: 1.5 }}>
          Each post is boosted at most once per automation. Like counts come from the X API — the free tier's read quota is very small, so a paid X API plan is recommended for Boosts.
        </p>
      </div>
    </div>
  );
}
