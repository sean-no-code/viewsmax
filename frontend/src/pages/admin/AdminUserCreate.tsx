// Admin — create a user by hand. The account skips email verification and can
// be a promotional customer: free access for a window (or unlimited) with no
// card on file. Server-gated by role:admin.
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Icon, SectionHead } from "@/components/analytics/primitives";
import { PostShell } from "@/components/post/PostList";
import PromoWindowSelect, { type PromoWindowChoice } from "@/components/admin/PromoWindowSelect";
import { useAuth } from "@/hooks/useAuth";
import { PROMO_ROLE } from "@/lib/access";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";

const mono = { fontFamily: "var(--font-mono)", fontSize: 11.5 } as const;
const inputStyle = { fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-1)", background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "10px 12px", outline: "none", width: "100%", boxSizing: "border-box" as const };

const ROLES = [
  { name: "customer", label: "Customer", hint: "Adds a card during onboarding, like a normal signup." },
  { name: PROMO_ROLE, label: "Promotional customer", hint: "Free access for a set window, no card." },
];

export default function AdminUserCreate() {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState<string>(PROMO_ROLE);
  const [promoDays, setPromoDays] = useState<PromoWindowChoice>(7);
  const [saving, setSaving] = useState(false);

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

  const canSave = name.trim().length > 0 && email.trim().length > 0 && password.length >= 8 && !saving;

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canSave) return;
    setSaving(true);
    const res = await viewsMaxApi.createAdminUser({
      name: name.trim(),
      email: email.trim(),
      password,
      role,
      promo_days: role === PROMO_ROLE && promoDays !== "keep" ? promoDays : null,
    });
    setSaving(false);
    if (res.success && res.data) {
      toast.success(`Created ${res.data.email}.`);
      navigate("/dashboard/admin/users");
    } else {
      toast.error(res.error || "Couldn't create user.");
    }
  };

  const label = (text: string) => (
    <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 6 }}>{text}</div>
  );

  return (
    <PostShell max={720}>
      <button onClick={() => navigate("/dashboard/admin/users")} style={{ alignSelf: "flex-start", display: "inline-flex", alignItems: "center", gap: 6, border: "none", background: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)" }}>
        <Icon name="chevron-left" size={15} stroke="var(--ink-on-paper-3)" /> Back to users
      </button>
      <SectionHead eyebrow="Admin" title="New user" />

      <form onSubmit={save} style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 24, boxShadow: "0 1px 2px rgba(10,10,12,.04)", display: "flex", flexDirection: "column", gap: 22 }}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
          <div>
            {label("Name")}
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Jane Creator" style={inputStyle} autoComplete="off" />
          </div>
          <div>
            {label("Email")}
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="jane@example.com" style={inputStyle} autoComplete="off" />
          </div>
        </div>
        <div>
          {label("Password")}
          <input type="text" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="At least 8 characters — share it with them" style={inputStyle} autoComplete="new-password" />
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 6 }}>
            No verification email is sent; they sign in straight away with this password.
          </div>
        </div>

        <div>
          {label("Role")}
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {ROLES.map((r) => {
              const on = role === r.name;
              return (
                <button key={r.name} type="button" onClick={() => setRole(r.name)} title={r.hint}
                  style={{ display: "inline-flex", alignItems: "center", gap: 8, padding: "9px 16px", borderRadius: 999, cursor: "pointer", border: "1px solid " + (on ? "var(--ink-900)" : "var(--line-2)"), background: on ? "var(--ink-900)" : "var(--paper-0)", color: on ? "#fff" : "var(--ink-on-paper-2)", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5 }}>
                  {on && <Icon name="check" size={14} stroke="var(--vm-volt)" />}
                  {r.label}
                </button>
              );
            })}
          </div>
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 8 }}>
            {ROLES.find((r) => r.name === role)?.hint}
          </div>
        </div>

        {role === PROMO_ROLE && <PromoWindowSelect value={promoDays} onChange={setPromoDays} />}

        <div style={{ display: "flex", gap: 10 }}>
          <button type="submit" disabled={!canSave}
            style={{ padding: "10px 22px", borderRadius: 999, border: "none", background: "var(--vm-red)", color: "#fff", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5, cursor: canSave ? "pointer" : "default", opacity: canSave ? 1 : 0.5 }}>
            {saving ? "Creating…" : "Create user"}
          </button>
        </div>
      </form>
    </PostShell>
  );
}
