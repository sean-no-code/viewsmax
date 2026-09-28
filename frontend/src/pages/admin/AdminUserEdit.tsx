// Admin — edit one user: change their role (single-role picker) and, for a
// promotional customer, the free-access window. Server-gated by role:admin;
// the backend also refuses changing your own role.
import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Icon, SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import PromoWindowSelect, { type PromoWindowChoice } from "@/components/admin/PromoWindowSelect";
import { useAuth } from "@/hooks/useAuth";
import { PROMO_ROLE, promoWindowLabel } from "@/lib/access";
import { viewsMaxApi, type AdminUserDetail } from "@/lib/api-service";
import { toast } from "sonner";

const mono = { fontFamily: "var(--font-mono)", fontSize: 11.5 } as const;

export default function AdminUserEdit() {
  const { user } = useAuth();
  const { id = "" } = useParams();
  const navigate = useNavigate();

  const [detail, setDetail] = useState<AdminUserDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [role, setRole] = useState<string>("");
  // "keep" = leave the current window alone; otherwise days from today / null.
  const [promoDays, setPromoDays] = useState<PromoWindowChoice>("keep");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!user?.is_admin) return;
    viewsMaxApi.getAdminUser(Number(id)).then((res) => {
      if (res.success && res.data) {
        setDetail(res.data);
        setRole(res.data.user.roles[0] ?? "customer");
      } else {
        setError(res.error || "Couldn't load user.");
      }
    });
  }, [id, user?.is_admin]);

  if (!user?.is_admin) {
    return (
      <PostShell>
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
          <Icon name="alert-circle" size={22} stroke="var(--vm-red)" />
          <div style={{ marginTop: 10, fontWeight: 600 }}>Admins only.</div>
        </div>
      </PostShell>
    );
  }

  const isSelf = detail?.user.id === user.id;
  const currentRole = detail?.user.roles[0] ?? "customer";
  const wasPromo = currentRole === PROMO_ROLE;
  const isPromo = role === PROMO_ROLE;
  const roleChanged = detail != null && role !== currentRole;
  const windowChanged = isPromo && promoDays !== "keep";
  const dirty = roleChanged || windowChanged;

  const pickRole = (name: string) => {
    setRole(name);
    // Switching someone onto promo needs a window; switching back doesn't.
    if (name === PROMO_ROLE && !wasPromo) setPromoDays(7);
    else setPromoDays("keep");
  };

  const save = async () => {
    if (!detail || !dirty) return;
    setSaving(true);
    const res = await viewsMaxApi.updateAdminUserRole(detail.user.id, role, isPromo && promoDays !== "keep" ? promoDays : undefined);
    setSaving(false);
    if (res.success && res.data) {
      setDetail({ ...detail, user: res.data });
      setPromoDays("keep");
      toast.success(isPromo
        ? `${detail.user.email} is now ${role} (${promoWindowLabel(res.data.promo_expires_at)}).`
        : `${detail.user.email} is now ${role}.`);
    } else {
      toast.error(res.error || "Couldn't update role.");
    }
  };

  return (
    <PostShell max={720}>
      <button onClick={() => navigate("/dashboard/admin/users")} style={{ alignSelf: "flex-start", display: "inline-flex", alignItems: "center", gap: 6, border: "none", background: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)" }}>
        <Icon name="chevron-left" size={15} stroke="var(--ink-on-paper-3)" /> Back to users
      </button>
      <SectionHead eyebrow="Admin" title="Edit user" />

      {error ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 40, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>{error}</div>
      ) : !detail ? (
        <AnalyticsLoading />
      ) : (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 24, boxShadow: "0 1px 2px rgba(10,10,12,.04)", display: "flex", flexDirection: "column", gap: 22 }}>
          {/* Who */}
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 20, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>{detail.user.name || "—"}</span>
            <span style={{ ...mono, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>{detail.user.email}</span>
            {detail.user.created_at && (
              <span style={{ ...mono, color: "var(--ink-on-paper-3)" }}>
                Joined {new Date(detail.user.created_at).toLocaleDateString([], { month: "short", day: "numeric", year: "numeric" })}
              </span>
            )}
          </div>

          {/* Role picker */}
          <div>
            <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 8 }}>Role</div>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              {detail.roles.map((r) => {
                const on = role === r.name;
                return (
                  <button key={r.id} onClick={() => !isSelf && pickRole(r.name)} disabled={isSelf}
                    style={{ display: "inline-flex", alignItems: "center", gap: 8, padding: "9px 16px", borderRadius: 999, cursor: isSelf ? "not-allowed" : "pointer", border: "1px solid " + (on ? "var(--ink-900)" : "var(--line-2)"), background: on ? "var(--ink-900)" : "var(--paper-0)", color: on ? "#fff" : "var(--ink-on-paper-2)", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5, opacity: isSelf ? 0.6 : 1 }}>
                    {on && <Icon name="check" size={14} stroke="var(--vm-volt)" />}
                    {r.display_name || r.name}
                  </button>
                );
              })}
            </div>
            {isSelf && (
              <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 8 }}>
                You can't change your own role.
              </div>
            )}
          </div>

          {isPromo && !isSelf && (
            <PromoWindowSelect
              value={promoDays}
              onChange={setPromoDays}
              currentExpiresAt={detail.user.promo_expires_at}
              showKeep={wasPromo}
            />
          )}

          <div style={{ display: "flex", gap: 10 }}>
            <button onClick={save} disabled={!dirty || saving || isSelf}
              style={{ padding: "10px 22px", borderRadius: 999, border: "none", background: "var(--vm-red)", color: "#fff", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5, cursor: dirty && !saving && !isSelf ? "pointer" : "default", opacity: dirty && !isSelf ? 1 : 0.5 }}>
              {saving ? "Saving…" : "Save changes"}
            </button>
          </div>
        </div>
      )}
    </PostShell>
  );
}
