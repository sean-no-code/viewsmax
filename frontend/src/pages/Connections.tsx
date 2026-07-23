// Connections — connect the social accounts you publish to (YouTube, TikTok,
// Instagram). Wraps the existing ConnectAccounts flow in the ViewsMax shell.
import ConnectAccounts from "@/components/ConnectAccounts";
import BrandManager from "@/components/BrandManager";
import { SectionHead } from "@/components/analytics/primitives";

export default function Connections() {
  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ maxWidth: 960, margin: "0 auto", display: "flex", flexDirection: "column", gap: 18 }}>
        <SectionHead eyebrow="ACCOUNTS" title="Connections." />
        <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-2)", margin: 0, lineHeight: 1.5 }}>
          Connect the platforms you publish to. Once linked, they appear as targets in Create Post and as sources in Analytics.
        </p>
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 20, boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          <ConnectAccounts mode="settings" />
        </div>
        <SectionHead eyebrow="GROUPS" title="Brands." />
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 20, boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          <BrandManager />
        </div>
      </div>
    </div>
  );
}
