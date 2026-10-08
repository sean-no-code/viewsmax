// Platform badge (brand-coloured square with the white brand mark) and the
// account avatar that carries one in its corner.
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { BRAND_MARKS } from "@/components/analytics/primitives";
import type { Account, Network } from "@/lib/analytics-performance";

export function NetBadge({ net, size = 16, ring }: { net: Network; size?: number; ring?: string }) {
  const mark = BRAND_MARKS[net.id];
  return (
    <span
      title={net.name}
      style={{
        width: size, height: size, borderRadius: Math.round(size * 0.31), background: net.badge,
        display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0,
        boxShadow: ring ? `0 0 0 2px ${ring}` : undefined,
      }}
    >
      {mark ? (
        <svg width={size * 0.62} height={size * 0.62} viewBox="0 0 24 24" fill="none" style={{ display: "block" }} aria-hidden="true">{mark(net.badge)}</svg>
      ) : (
        <span style={{ font: `800 ${Math.round(size * 0.55)}px var(--font-body)`, color: "#fff", lineHeight: 1 }}>{net.name[0]}</span>
      )}
    </span>
  );
}

/** Avatar with the platform badge pinned to the bottom-right corner. */
export function AccountAvatar({ account, size = 32 }: { account: Account; size?: number }) {
  const badge = Math.round(size / 2);
  return (
    <span style={{ position: "relative", display: "flex", flexShrink: 0 }}>
      <Avatar style={{ width: size, height: size }}>
        {account.avatarUrl && <AvatarImage src={account.avatarUrl} alt="" />}
        <AvatarFallback className="text-xs font-semibold" style={{ background: account.tint, color: "var(--ink-on-paper-1)" }}>{account.initials}</AvatarFallback>
      </Avatar>
      <span style={{ position: "absolute", right: -4, bottom: -4, borderRadius: Math.round(badge * 0.31) + 2, borderWidth: 2, borderStyle: "solid", borderColor: "var(--paper-0)", display: "flex" }}>
        <NetBadge net={account.net} size={badge} />
      </span>
    </span>
  );
}

/** Avatar + handle + network name, as used in the per-account tables and post cards. */
export function AccountCell({ account, handleSize = 14 }: { account: Account; handleSize?: number }) {
  return (
    <span style={{ display: "flex", alignItems: "center", gap: 10, minWidth: 0 }}>
      <AccountAvatar account={account} />
      <span style={{ display: "flex", flexDirection: "column", minWidth: 0, lineHeight: 1.25 }}>
        <span style={{ fontSize: handleSize, fontWeight: 600, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{account.handle}</span>
        <span style={{ fontSize: 12, color: "var(--ink-on-paper-3)" }}>{account.net.name}</span>
      </span>
    </span>
  );
}
