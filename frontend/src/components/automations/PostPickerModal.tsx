// Grid of the account's posts/reels (via /api/automations/media), multi-select.
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Btn, Icon } from "@/components/analytics/primitives";
import { Modal } from "@/components/analytics/Modal";
import { viewsMaxApi, type AutomationPost } from "@/lib/api-service";
import { MAX_POSTS } from "@/lib/automations";
import { HINT } from "./ui";

export function PostThumb({ post, size = 64, selected, onClick }: { post: AutomationPost; size?: number; selected?: boolean; onClick?: () => void }) {
  return (
    <div onClick={onClick} title={post.caption ?? post.id} style={{ width: size, height: size, borderRadius: 8, overflow: "hidden", background: "var(--paper-2)", border: selected ? "2px solid var(--vm-red)" : "2px solid transparent", cursor: onClick ? "pointer" : "default", position: "relative", flexShrink: 0 }}>
      {post.thumbnail_url ? <img src={post.thumbnail_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} /> : <div style={{ display: "grid", placeItems: "center", height: "100%" }}><Icon name="image" size={18} stroke="var(--ink-on-paper-3)" /></div>}
      {post.media_product_type === "REELS" && <span style={{ position: "absolute", top: 4, right: 4, background: "rgba(0,0,0,.6)", borderRadius: 4, padding: "1px 4px", color: "#fff", fontSize: 9, fontFamily: "var(--font-mono)" }}>REEL</span>}
      {selected && <span style={{ position: "absolute", bottom: 4, right: 4, width: 18, height: 18, borderRadius: "50%", background: "var(--vm-red)", display: "grid", placeItems: "center" }}><Icon name="check" size={12} stroke="#fff" /></span>}
    </div>
  );
}

export function PostPickerModal({ accountId, selected, onClose, onSave, onReconnect }: {
  accountId: number; selected: AutomationPost[]; onClose: () => void; onSave: (posts: AutomationPost[]) => void; onReconnect?: () => void;
}) {
  const [items, setItems] = useState<AutomationPost[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [picked, setPicked] = useState<AutomationPost[]>(selected);

  const load = async (after: string | null, refresh = false) => {
    setLoading(true);
    const res = await viewsMaxApi.getAutomationMedia(accountId, after, refresh);
    setLoading(false);
    if (!res.success || !res.data) {
      if (res.code === "reconnect_required") { toast.error("Reconnect Instagram to load your posts."); onReconnect?.(); }
      else toast.error(res.error || "Couldn't load your posts.");
      return;
    }
    setItems((prev) => (after ? [...prev, ...res.data!.items] : res.data!.items));
    setCursor(res.data.next_cursor);
  };

  useEffect(() => { void load(null); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [accountId]);

  const toggle = (post: AutomationPost) => {
    setPicked((p) => {
      if (p.some((x) => x.id === post.id)) return p.filter((x) => x.id !== post.id);
      if (p.length >= MAX_POSTS) { toast.info(`You can pick up to ${MAX_POSTS} posts.`); return p; }
      return [...p, post];
    });
  };

  return (
    <Modal title="Choose posts or reels" sub="The automation fires on comments under the selected posts." onClose={onClose} width={720}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
        <span style={HINT}>{picked.length} selected</span>
        <Btn kind="ghost" size="sm" icon="rotate-cw" onClick={() => load(null, true)}>Refresh from Instagram</Btn>
      </div>
      {items.length === 0 && !loading ? (
        <div style={{ ...HINT, textAlign: "center", padding: 32 }}>No posts found on this account yet.</div>
      ) : (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(96px,1fr))", gap: 8, maxHeight: 420, overflowY: "auto" }}>
          {items.map((post) => <PostThumb key={post.id} post={post} size={96} selected={picked.some((x) => x.id === post.id)} onClick={() => toggle(post)} />)}
        </div>
      )}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 16 }}>
        <span>{cursor && <Btn kind="ghost" size="sm" onClick={loading ? undefined : () => load(cursor)}>{loading ? "Loading…" : "Load more"}</Btn>}</span>
        <div style={{ display: "flex", gap: 10 }}>
          <Btn kind="ghost" onClick={onClose}>Cancel</Btn>
          <Btn icon="check" onClick={() => { onSave(picked); onClose(); }}>Use {picked.length} post{picked.length === 1 ? "" : "s"}</Btn>
        </div>
      </div>
    </Modal>
  );
}
