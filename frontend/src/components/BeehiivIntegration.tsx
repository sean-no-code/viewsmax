// Beehiiv API-key connection UI: status + connect/update/disconnect, with a
// secure key prompt. The key is validated + stored encrypted server-side and
// never returned; we only ever show a masked hint. Usable in Settings and,
// later, when a user picks a Beehiiv placement for a tracking link.
import { useEffect, useState, type CSSProperties } from "react";
import { toast } from "sonner";
import { Modal, Field, TextInput } from "@/components/analytics/Modal";
import { viewsMaxApi, type BeehiivConnectionStatus } from "@/lib/api-service";

const primaryBtn: CSSProperties = { padding: "8px 16px", borderRadius: 10, border: "none", background: "var(--vm-red)", color: "#fff", fontWeight: 700, fontSize: 13, cursor: "pointer", fontFamily: "var(--font-body)" };
const ghostBtn: CSSProperties = { padding: "8px 16px", borderRadius: 10, border: "1px solid var(--line-1)", background: "var(--paper-0)", color: "var(--ink-on-paper-1)", fontWeight: 600, fontSize: 13, cursor: "pointer", fontFamily: "var(--font-body)" };

export function BeehiivIntegration({ onChange }: { onChange?: (s: BeehiivConnectionStatus) => void }) {
  const [status, setStatus] = useState<BeehiivConnectionStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [apiKey, setApiKey] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    viewsMaxApi.getBeehiivConnection().then((res) => {
      if (cancelled) return;
      setStatus(res.success && res.data ? res.data : { connected: false });
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  const connect = async () => {
    const key = apiKey.trim();
    if (!key) { toast.error("Paste your Beehiiv API key."); return; }
    setSaving(true);
    const res = await viewsMaxApi.connectBeehiiv(key);
    setSaving(false);
    if (res.success && res.data) {
      setStatus(res.data);
      onChange?.(res.data);
      setOpen(false);
      setApiKey("");
      toast.success(`Beehiiv connected${res.data.publication_name ? ` — ${res.data.publication_name}` : ""}.`);
    } else {
      toast.error(res.error || "Couldn't connect Beehiiv.");
    }
  };

  const disconnect = async () => {
    if (!window.confirm("Disconnect Beehiiv? Your stored API key will be removed.")) return;
    const res = await viewsMaxApi.disconnectBeehiiv();
    if (res.success) {
      const next = { connected: false };
      setStatus(next);
      onChange?.(next);
      toast.success("Beehiiv disconnected.");
    } else {
      toast.error(res.error || "Couldn't disconnect.");
    }
  };

  const connected = !!status?.connected;

  return (
    <>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontWeight: 600, fontSize: 14, color: "var(--ink-on-paper-1)" }}>Beehiiv</div>
          <div style={{ fontSize: 13, color: connected ? "var(--up)" : "var(--ink-on-paper-3)", marginTop: 2 }}>
            {loading ? "Checking…"
              : connected
                ? `Connected${status?.publication_name ? ` — ${status.publication_name}` : ""}${status?.key_hint ? ` · ${status.key_hint}` : ""}`
                : "Pull newsletter post views into your reach conversion rate."}
          </div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          {connected ? (
            <>
              <button onClick={() => setOpen(true)} style={ghostBtn}>Update key</button>
              <button onClick={disconnect} style={ghostBtn}>Disconnect</button>
            </>
          ) : (
            <button onClick={() => setOpen(true)} disabled={loading} style={primaryBtn}>Connect Beehiiv</button>
          )}
        </div>
      </div>

      {open && (
        <Modal title="Connect Beehiiv" sub="We store your key encrypted and use it only to read your post view counts." onClose={() => setOpen(false)}>
          <Field label="Beehiiv API key" hint="beehiiv → Settings → API">
            <TextInput type="password" autoFocus placeholder="Paste your API key" value={apiKey} onChange={(e) => setApiKey(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") connect(); }} />
          </Field>
          <div style={{ fontSize: 12, color: "var(--ink-on-paper-3)", marginTop: -4, marginBottom: 4, lineHeight: 1.5 }}>
            Create a key in beehiiv under Settings → Integrations → API. We validate it with beehiiv before saving; it's never shown again.
          </div>
          <div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 18 }}>
            <button onClick={() => setOpen(false)} style={ghostBtn}>Cancel</button>
            <button onClick={connect} disabled={saving} style={{ ...primaryBtn, opacity: saving ? 0.6 : 1 }}>{saving ? "Validating…" : "Connect"}</button>
          </div>
        </Modal>
      )}
    </>
  );
}
