// Small alerts above the Analytics pages for connected accounts we can't get
// data from: reconnect needed, platform without stats support, a fetch error
// from the last daily run, or simply no snapshot yet.
import { Link } from "react-router-dom";
import { AlertTriangle, Info } from "lucide-react";
import { dataAlerts } from "@/lib/analytics-performance";
import type { Account } from "@/lib/analytics-performance";

export function DataAlerts({ accounts }: { accounts: Account[] }) {
  const alerts = dataAlerts(accounts);
  if (!alerts.length) return null;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
      {alerts.map((al) => {
        const warn = al.tone === "warn";
        const Icon = warn ? AlertTriangle : Info;
        return (
          <div
            key={al.key}
            role={warn ? "alert" : "status"}
            style={{
              display: "flex", alignItems: "flex-start", gap: 8, padding: "7px 12px", borderRadius: 8, fontSize: 13, lineHeight: 1.4,
              background: warn ? "rgba(255,176,32,.14)" : "var(--paper-2)",
              border: `1px solid ${warn ? "rgba(255,176,32,.5)" : "var(--line-1)"}`,
              color: "var(--ink-on-paper-1)",
            }}
          >
            <Icon size={15} style={{ flexShrink: 0, marginTop: 2, color: warn ? "#9A6700" : "var(--ink-on-paper-3)" }} />
            <span style={{ minWidth: 0 }}>
              {al.text}
              {al.action === "connections" && <> <Link to="/dashboard/connections" style={{ fontWeight: 600, color: "var(--vm-red-deep)" }}>Open Connections</Link></>}
            </span>
          </div>
        );
      })}
    </div>
  );
}
