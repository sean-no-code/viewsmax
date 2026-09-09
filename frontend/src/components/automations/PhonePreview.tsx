// Right-hand phone mock: Post / Comments / DM views of what the automation
// will do, driven straight from the draft.
import { useState } from "react";
import { Segmented } from "@/components/analytics/primitives";
import { hasButton, type AutomationDraft } from "@/lib/automations";
import type { AutomationAccount } from "@/lib/api-service";

const SCREEN: React.CSSProperties = { background: "#000", color: "#fff", borderRadius: 34, padding: "14px 12px 18px", width: 300, minHeight: 560, boxShadow: "0 30px 60px -30px rgba(0,0,0,.6)", fontFamily: "var(--font-body)", position: "relative", overflow: "hidden" };
const BUBBLE_IN: React.CSSProperties = { background: "#262626", borderRadius: 18, padding: "10px 12px", fontSize: 13, maxWidth: 210, lineHeight: 1.4 };
const BUBBLE_OUT: React.CSSProperties = { ...BUBBLE_IN, background: "#3797f0", marginLeft: "auto" };

export function PhonePreview({ draft, account }: { draft: AutomationDraft; account?: AutomationAccount | null }) {
  const isComment = draft.trigger_type === "comment";
  const views = isComment ? [{ id: "post", label: "Post" }, { id: "comments", label: "Comments" }, { id: "dm", label: "DM" }] : [{ id: "dm", label: "DM" }];
  const [view, setView] = useState<string>(isComment ? "post" : "dm");
  const active = views.some((v) => v.id === view) ? view : "dm";
  const handle = account?.username ? account.username : "your.account";
  const post = draft.posts[0];
  const sampleKeyword = draft.keyword_mode === "any" ? "This is amazing 🔥" : (draft.keywords[0] ?? "link");
  const reply = draft.reply_texts.find((t) => t.trim()) ?? "Sent you a DM!";
  const button = hasButton(draft);

  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 14 }}>
      <div style={SCREEN}>
        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 11, padding: "0 10px 12px", opacity: 0.85 }}><span>9:41</span><span>●●● ▲</span></div>
        <div style={{ textAlign: "center", fontSize: 11, opacity: 0.6, letterSpacing: ".04em" }}>{handle.toUpperCase()}</div>
        <div style={{ textAlign: "center", fontWeight: 700, fontSize: 14, marginBottom: 12 }}>{active === "dm" ? "Messages" : "Posts"}</div>

        {active === "post" && (
          <div>
            <div style={{ display: "flex", alignItems: "center", gap: 8, padding: "6px 4px", fontSize: 12 }}>
              <span style={{ width: 26, height: 26, borderRadius: "50%", background: "linear-gradient(135deg,#f9ce34,#ee2a7b,#6228d7)", display: "inline-block" }} />
              <b>{handle}</b>
            </div>
            <div style={{ background: "#1a1a1a", borderRadius: 8, aspectRatio: "4/5", overflow: "hidden", display: "grid", placeItems: "center", color: "#777", fontSize: 12 }}>
              {post?.thumbnail_url ? <img src={post.thumbnail_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} /> : draft.post_match === "any" ? "Any post or reel" : "Pick a post"}
            </div>
            <div style={{ fontSize: 12, marginTop: 8, opacity: 0.85, display: "-webkit-box", WebkitLineClamp: 3, WebkitBoxOrient: "vertical", overflow: "hidden" }}><b>{handle}</b> {post?.caption ?? "Your caption shows here."}</div>
          </div>
        )}

        {active === "comments" && (
          <div style={{ display: "flex", flexDirection: "column", gap: 12, padding: "4px 2px" }}>
            <div style={{ display: "flex", gap: 8, fontSize: 12.5 }}>
              <span style={{ width: 26, height: 26, borderRadius: "50%", background: "#444", flexShrink: 0 }} />
              <div><b>a_fan</b> {sampleKeyword}<div style={{ fontSize: 10.5, opacity: 0.5, marginTop: 3 }}>2m · Reply</div></div>
            </div>
            {draft.reply_enabled && (
              <div style={{ display: "flex", gap: 8, fontSize: 12.5, paddingLeft: 30 }}>
                <span style={{ width: 22, height: 22, borderRadius: "50%", background: "linear-gradient(135deg,#f9ce34,#ee2a7b,#6228d7)", flexShrink: 0 }} />
                <div><b>{handle}</b> {reply}<div style={{ fontSize: 10.5, opacity: 0.5, marginTop: 3 }}>now</div></div>
              </div>
            )}
            {!draft.reply_enabled && <div style={{ fontSize: 11, opacity: 0.5, paddingLeft: 34 }}>No public reply — turn it on to reply under the comment.</div>}
          </div>
        )}

        {active === "dm" && (
          <div style={{ display: "flex", flexDirection: "column", gap: 8, padding: "4px 2px" }}>
            {!isComment && <div style={BUBBLE_IN}>{draft.trigger_type === "story_reply" ? "↩ Replied to your story: " : ""}{sampleKeyword}</div>}
            {button ? (
              <div style={{ ...BUBBLE_OUT, padding: 0, overflow: "hidden", width: 220, background: "#262626" }}>
                {draft.dm_image_url ? <img src={draft.dm_image_url} alt="" style={{ width: "100%", height: 110, objectFit: "cover" }} /> : <div style={{ height: 6 }} />}
                <div style={{ padding: "10px 12px" }}>
                  <div style={{ fontWeight: 700, fontSize: 13 }}>{draft.dm_text || "Card title"}</div>
                  {draft.dm_subtitle && <div style={{ fontSize: 12, opacity: 0.7, marginTop: 2 }}>{draft.dm_subtitle}</div>}
                </div>
                <div style={{ borderTop: "1px solid #3a3a3a", padding: "10px 12px", textAlign: "center", color: "#3797f0", fontWeight: 600, fontSize: 13 }}>{draft.dm_button_label || "Open link"}</div>
              </div>
            ) : (
              <div style={{ ...BUBBLE_OUT, whiteSpace: "pre-wrap" }}>{draft.dm_text || "Your message shows here."}</div>
            )}
          </div>
        )}
      </div>
      {views.length > 1 && <Segmented options={views} value={active} onChange={setView} mono={false} />}
    </div>
  );
}
