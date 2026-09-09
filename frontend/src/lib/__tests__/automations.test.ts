import { describe, it, expect } from "vitest";
import { dmTextLimit, emptyDraft, formatCtr, parseKeywords, timeAgo, triggerSummary, validateDraft, draftToPayload } from "@/lib/automations";

describe("automations helpers", () => {
  it("parses comma/newline separated keywords, lowercased and de-duped", () => {
    expect(parseKeywords(" Price, LINK\nshop,,price ")).toEqual(["price", "link", "shop"]);
  });

  it("switches the DM limit between text and card", () => {
    expect(dmTextLimit(false)).toBe(1000);
    expect(dmTextLimit(true)).toBe(80);
  });

  it("mirrors the backend trigger summary", () => {
    expect(triggerSummary({ trigger_type: "comment", post_match: "specific", keyword_mode: "contains", keywords: ["price", "link"] }))
      .toBe("User comments on a specific Post or Reel and comment contains price, link");
    expect(triggerSummary({ trigger_type: "comment", post_match: "any", keyword_mode: "any", keywords: [] })).toBe("User comments on any Post or Reel");
    expect(triggerSummary({ trigger_type: "story_reply", post_match: "any", keyword_mode: "exact", keywords: ["info"] })).toBe("User replies to any Story and message is exactly info");
    expect(triggerSummary({ trigger_type: "dm", post_match: "any", keyword_mode: "any", keywords: [] })).toBe("User sends a DM");
  });

  it("formats CTR", () => {
    expect(formatCtr({ runs: 0, dms_sent: 0, clicked: 0, ctr: null })).toBe("n/a");
    expect(formatCtr({ runs: 10, dms_sent: 8, clicked: 3, ctr: 0.375 })).toBe("37.5%");
  });

  it("validates a draft like the backend does", () => {
    const d = emptyDraft("comment", 1);
    expect(validateDraft(d)).toContain("Pick at least one post or reel, or switch to any post.");
    d.post_match = "any";
    d.keyword_mode = "any";
    d.dm_text = "x".repeat(90);
    expect(validateDraft(d)).toEqual([]);
    d.dm_button_label = "Open";
    d.dm_button_url = "https://example.com";
    expect(validateDraft(d)).toEqual(["With a button the message is sent as a card, limited to 80 characters."]);
    d.dm_text = "Short";
    d.dm_button_url = "example.com";
    expect(validateDraft(d)).toEqual(["The button needs a full URL (https://…)."]);
  });

  it("builds a payload that drops fields the trigger doesn't use", () => {
    const d = emptyDraft("dm", 7);
    d.keyword_mode = "any";
    d.dm_text = "hi";
    d.reply_enabled = true;
    const p = draftToPayload(d);
    expect(p.post_match).toBeNull();
    expect(p.posts).toBeNull();
    expect(p.reply_enabled).toBe(false);
    expect(p.keywords).toBeNull();
    expect(p.dm_button_url).toBeNull();
  });

  it("renders relative time", () => {
    const now = new Date("2026-09-09T12:00:00Z");
    expect(timeAgo("2026-09-09T11:59:50Z", now)).toBe("just now");
    expect(timeAgo("2026-09-09T11:30:00Z", now)).toBe("30 minutes ago");
    expect(timeAgo("2026-08-09T12:00:00Z", now)).toBe("1 month ago");
    expect(timeAgo(null, now)).toBe("—");
  });
});
