// "New Automation" → pick the trigger + Instagram account, then open the editor.
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { Btn, Icon, SearchSelect } from "@/components/analytics/primitives";
import { Modal } from "@/components/analytics/Modal";
import { viewsMaxApi, type AutomationAccount, type AutomationTrigger } from "@/lib/api-service";
import { TRIGGERS, TRIGGER_META } from "@/lib/automations";
import { ReconnectInstagramNotice } from "./ReconnectInstagramNotice";
import { HINT, PANEL } from "./ui";

export function TriggerPickerModal({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<AutomationAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [trigger, setTrigger] = useState<AutomationTrigger>("comment");
  const [accountId, setAccountId] = useState<string>("");

  const load = async () => {
    setLoading(true);
    const res = await viewsMaxApi.getAutomationAccounts();
    setLoading(false);
    if (res.success && res.data) {
      setAccounts(res.data.accounts);
      const first = res.data.accounts.find((a) => a.can_automate) ?? res.data.accounts[0];
      if (first && !accountId) setAccountId(String(first.id));
    } else toast.error(res.error || "Couldn't load your Instagram accounts.");
  };
  useEffect(() => { void load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const account = accounts.find((a) => String(a.id) === accountId) ?? null;

  return (
    <Modal title="New automation" sub="Start with a trigger — what should kick it off?" onClose={onClose} width={620}>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 10, marginBottom: 18 }}>
        {TRIGGERS.map((t) => {
          const meta = TRIGGER_META[t];
          const active = trigger === t;
          return (
            <div key={t} onClick={() => setTrigger(t)} style={{ ...PANEL, cursor: "pointer", borderColor: active ? "var(--vm-red)" : "var(--line-1)", background: active ? "var(--paper-0)" : "var(--paper-1)" }}>
              <Icon name={meta.icon} size={20} stroke={active ? "var(--vm-red)" : "var(--ink-on-paper-2)"} />
              <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 14, marginTop: 8, color: "var(--ink-on-paper-1)" }}>{meta.label}</div>
              <div style={{ ...HINT, marginTop: 4 }}>{meta.blurb}</div>
            </div>
          );
        })}
      </div>

      <div style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-1)", marginBottom: 7 }}>Instagram account</div>
      {!loading && accounts.length === 0 ? (
        <div style={{ ...HINT, marginBottom: 12 }}>No Instagram account connected yet. Connect one on the Connections page first.</div>
      ) : (
        <SearchSelect
          options={accounts.map((a) => ({ id: String(a.id), label: `@${a.username ?? a.name ?? a.id}${a.can_automate ? "" : " · needs reconnect"}` }))}
          value={accountId}
          onChange={setAccountId}
          loading={loading}
          placeholder="Choose an account"
        />
      )}
      {account && !account.can_automate && <div style={{ marginTop: 12 }}><ReconnectInstagramNotice compact onReconnected={load} /></div>}

      <div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 18 }}>
        <Btn kind="ghost" onClick={onClose}>Cancel</Btn>
        <Btn icon="arrow-right" onClick={() => {
          if (!account) { toast.error("Choose an Instagram account."); return; }
          navigate(`/dashboard/automations/new?trigger=${trigger}&account=${account.id}`);
        }}>Continue</Btn>
      </div>
    </Modal>
  );
}
