// Pure helpers for the Automations feature (index + editor). Mirrors the
// backend rules (Automation model / AutomationController) so the editor can
// validate and preview before saving.
import type {
  Automation, AutomationKeywordMode, AutomationPayload, AutomationPost, AutomationPostMatch, AutomationStats, AutomationTrigger,
} from "@/lib/api-service";

/** Instagram limits (must match App\Models\Automation). */
export const DM_TEXT_MAX = 1000;
export const CARD_TITLE_MAX = 80;
export const CARD_SUBTITLE_MAX = 80;
export const BUTTON_LABEL_MAX = 20;
export const MAX_POSTS = 50;
export const MAX_KEYWORDS = 30;
export const MAX_REPLY_TEXTS = 5;

export const TRIGGER_META: Record<AutomationTrigger, { label: string; short: string; blurb: string; icon: string; subject: string }> = {
  comment: { label: "Post or Reel comment", short: "Comment", blurb: "Someone comments on one of your posts or reels.", icon: "message-circle", subject: "comment" },
  story_reply: { label: "Story reply", short: "Story reply", blurb: "Someone replies to one of your stories.", icon: "radio", subject: "message" },
  dm: { label: "Direct message", short: "DM", blurb: "Someone sends your account a direct message.", icon: "send", subject: "message" },
};

export const TRIGGERS: AutomationTrigger[] = ["comment", "story_reply", "dm"];

export interface AutomationDraft {
  social_account_id: number | null;
  name: string;
  trigger_type: AutomationTrigger;
  post_match: AutomationPostMatch;
  posts: AutomationPost[];
  include_replies: boolean;
  keyword_mode: AutomationKeywordMode;
  keywords: string[];
  cooldown_hours: number;
  reply_enabled: boolean;
  reply_texts: string[];
  dm_text: string;
  dm_subtitle: string;
  dm_image_url: string;
  dm_button_label: string;
  dm_button_url: string;
}

export function emptyDraft(trigger: AutomationTrigger = "comment", accountId: number | null = null): AutomationDraft {
  return {
    social_account_id: accountId,
    name: "Untitled",
    trigger_type: trigger,
    post_match: "specific",
    posts: [],
    include_replies: false,
    keyword_mode: "contains",
    keywords: [],
    cooldown_hours: 24,
    reply_enabled: false,
    reply_texts: [],
    dm_text: "",
    dm_subtitle: "",
    dm_image_url: "",
    dm_button_label: "",
    dm_button_url: "",
  };
}

export function draftFromAutomation(a: Automation): AutomationDraft {
  return {
    social_account_id: a.social_account_id,
    name: a.name ?? "Untitled",
    trigger_type: a.trigger_type,
    post_match: a.post_match ?? "any",
    posts: a.posts ?? [],
    include_replies: !!a.include_replies,
    keyword_mode: a.keyword_mode ?? "any",
    keywords: a.keywords ?? [],
    cooldown_hours: a.cooldown_hours ?? 24,
    reply_enabled: !!a.reply_enabled,
    reply_texts: a.reply_texts ?? [],
    dm_text: a.dm_text ?? "",
    dm_subtitle: a.dm_subtitle ?? "",
    dm_image_url: a.dm_image_url ?? "",
    dm_button_label: a.dm_button_label ?? "",
    dm_button_url: a.dm_button_url ?? "",
  };
}

/** Split "Price, Link,shop" → ["price", "link", "shop"] (trimmed, lowercased, de-duped). */
export function parseKeywords(input: string): string[] {
  const out: string[] = [];
  for (const raw of input.split(/[,\n]/)) {
    const k = raw.trim().toLowerCase().replace(/\s+/g, " ");
    if (k && !out.includes(k)) out.push(k);
  }
  return out.slice(0, MAX_KEYWORDS);
}

export function hasButton(d: Pick<AutomationDraft, "dm_button_label" | "dm_button_url">): boolean {
  return d.dm_button_label.trim() !== "" || d.dm_button_url.trim() !== "";
}

/** With a button the DM is a card (80-char title); otherwise plain text (1000). */
export function dmTextLimit(withButton: boolean): number {
  return withButton ? CARD_TITLE_MAX : DM_TEXT_MAX;
}

/** Client-side validation matching the backend 422s. Empty array = valid. */
export function validateDraft(d: AutomationDraft): string[] {
  const errors: string[] = [];
  if (!d.social_account_id) errors.push("Choose the Instagram account.");
  if (d.trigger_type === "comment" && d.post_match === "specific" && d.posts.length === 0) errors.push("Pick at least one post or reel, or switch to any post.");
  if (d.keyword_mode !== "any" && d.keywords.length === 0) errors.push("Add at least one keyword, or switch to any word.");
  if (d.trigger_type === "comment" && d.reply_enabled && d.reply_texts.filter((t) => t.trim()).length === 0) errors.push("Write the reply to post under the comment, or turn the reply off.");
  if (!d.dm_text.trim()) errors.push("Write the message they'll get.");
  const button = hasButton(d);
  if (button) {
    if (!d.dm_button_label.trim()) errors.push("Give the button a label.");
    if (d.dm_button_label.length > BUTTON_LABEL_MAX) errors.push(`Button labels are limited to ${BUTTON_LABEL_MAX} characters.`);
    if (!/^https?:\/\//i.test(d.dm_button_url.trim())) errors.push("The button needs a full URL (https://…).");
    if (d.dm_text.length > CARD_TITLE_MAX) errors.push(`With a button the message is sent as a card, limited to ${CARD_TITLE_MAX} characters.`);
    if (d.dm_subtitle.length > CARD_SUBTITLE_MAX) errors.push(`The subtitle is limited to ${CARD_SUBTITLE_MAX} characters.`);
  } else if (d.dm_text.length > DM_TEXT_MAX) {
    errors.push(`Messages are limited to ${DM_TEXT_MAX} characters.`);
  }
  return errors;
}

export function draftToPayload(d: AutomationDraft): AutomationPayload {
  const comment = d.trigger_type === "comment";
  const button = hasButton(d);
  return {
    social_account_id: d.social_account_id ?? undefined,
    name: d.name.trim() || "Untitled",
    trigger_type: d.trigger_type,
    post_match: comment ? d.post_match : null,
    posts: comment && d.post_match === "specific" ? d.posts.map(({ id, media_type, thumbnail_url, permalink, caption }) => ({ id, media_type, thumbnail_url, permalink, caption: caption ? caption.slice(0, 300) : null })) : null,
    include_replies: comment ? d.include_replies : false,
    keyword_mode: d.keyword_mode,
    keywords: d.keyword_mode === "any" ? null : d.keywords,
    cooldown_hours: d.cooldown_hours,
    reply_enabled: comment ? d.reply_enabled : false,
    reply_texts: comment && d.reply_enabled ? d.reply_texts.filter((t) => t.trim()) : null,
    dm_text: d.dm_text,
    dm_subtitle: button ? d.dm_subtitle.trim() || null : null,
    dm_image_url: button ? d.dm_image_url.trim() || null : null,
    dm_button_label: button ? d.dm_button_label.trim() : null,
    dm_button_url: button ? d.dm_button_url.trim() : null,
  };
}

/** Same wording as Automation::triggerSummary() on the backend. */
export function triggerSummary(d: Pick<AutomationDraft, "trigger_type" | "post_match" | "keyword_mode" | "keywords">): string {
  const subject = TRIGGER_META[d.trigger_type].subject;
  const kw = d.keywords.join(", ");
  const condition = d.keyword_mode === "contains" && kw ? ` and ${subject} contains ${kw}`
    : d.keyword_mode === "exact" && kw ? ` and ${subject} is exactly ${kw}` : "";
  if (d.trigger_type === "comment") return `User comments on ${d.post_match === "specific" ? "a specific Post or Reel" : "any Post or Reel"}${condition}`;
  if (d.trigger_type === "story_reply") return `User replies to any Story${condition}`;
  return `User sends a DM${condition}`;
}

export function formatCtr(stats: AutomationStats | null | undefined): string {
  if (!stats || stats.ctr == null) return "n/a";
  return `${(stats.ctr * 100).toFixed(1)}%`;
}

export function timeAgo(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return "—";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "—";
  const s = Math.max(0, Math.round((now.getTime() - then) / 1000));
  const units: [number, string][] = [[60, "second"], [60, "minute"], [24, "hour"], [30, "day"], [12, "month"]];
  let value = s;
  let unit = "second";
  for (const [div, name] of units) {
    unit = name;
    if (value < div) break;
    value = Math.floor(value / div);
    unit = name === "second" ? "minute" : name === "minute" ? "hour" : name === "hour" ? "day" : name === "day" ? "month" : "year";
  }
  if (unit === "second") return "just now";
  return `${value} ${unit}${value === 1 ? "" : "s"} ago`;
}
