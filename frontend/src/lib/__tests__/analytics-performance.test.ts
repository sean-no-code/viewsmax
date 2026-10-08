import { describe, expect, it } from "vitest";
import { FIXTURE } from "@/test/fixtures/performance";
import {
  clampRange, delta, fmt, fromApi, fromISODate, initialsOf, postsData, presetFor, presetRange, profileData, rangeLabel, rateLabel, signed, sourcesLabel, toISODate,
} from "../analytics-performance";

const END = new Date(2026, 9, 8); // 8 Oct 2026

describe("analytics-performance", () => {
  it("resolves presets relative to the end date and recognises them again", () => {
    const r28 = presetRange("28d", END);
    expect(toISODate(r28.from)).toBe("2026-09-11");
    expect(toISODate(r28.to)).toBe("2026-10-08");
    expect(presetFor(r28, END)).toBe("28d");
    expect(presetRange("lm", END)).toEqual({ from: new Date(2026, 8, 1), to: new Date(2026, 8, 30) });
    expect(toISODate(presetRange("ytd", END).from)).toBe("2026-01-01");
    expect(presetFor({ from: new Date(2026, 8, 3), to: new Date(2026, 8, 20) }, END)).toBeNull();
    expect(rangeLabel(r28)).toBe("Sep 11 – Oct 8, 2026");
    expect(rangeLabel({ from: new Date(2025, 11, 20), to: new Date(2026, 0, 5) })).toBe("Dec 20, 2025 – Jan 5, 2026");
  });

  it("round-trips ISO dates and clamps ranges", () => {
    expect(fromISODate("2026-02-03")?.getTime()).toBe(new Date(2026, 1, 3).getTime());
    expect(fromISODate("nope")).toBeNull();
    expect(fromISODate(null)).toBeNull();
    const c = clampRange({ from: new Date(2030, 0, 1), to: new Date(2000, 0, 1) }, new Date(2025, 0, 1), END);
    expect(toISODate(c.from)).toBe("2025-01-01");
    expect(toISODate(c.to)).toBe("2026-10-08");
  });

  it("formats numbers, rates and deltas", () => {
    expect(fmt(950)).toBe("950");
    expect(fmt(12345)).toBe("12.3K");
    expect(fmt(2_500_000)).toBe("2.5M");
    expect(signed(12)).toBe("+12");
    expect(signed(-3)).toBe("-3");
    expect(rateLabel(0.1234)).toBe("12.3%");
    expect(rateLabel(null)).toBe("–");
    expect(delta(110, 100)).toEqual({ label: "▲ 10.0%", dir: "up" });
    expect(delta(90, 100)).toEqual({ label: "▼ 10.0%", dir: "down" });
    expect(delta(5, 0)).toEqual({ label: "–", dir: "flat" });
    expect(delta(null, 5)).toEqual({ label: "–", dir: "flat" });
    expect(initialsOf("@sean.creates")).toBe("SC");
    expect(initialsOf("ViewsMax")).toBe("VI");
  });

  it("maps API accounts, series and posts into the view model", () => {
    const perf = fromApi(FIXTURE);
    expect(perf.hist.L).toBe(7);
    expect(perf.hist.dates).toHaveLength(14);
    expect(toISODate(perf.hist.dates[13])).toBe("2026-10-08");

    const yt = perf.accounts[0];
    expect(yt).toMatchObject({ id: "1", handle: "@seancreates", initials: "SE", supported: true, hasFollowerData: true, hasPostData: true });
    expect(yt.net.name).toBe("YouTube");
    expect(perf.hist.acc["1"].net).toEqual([0, 1, 1, 1, 1, 1, 1, 1, 1, 2, 2, 3, 3, 2]);
    expect(perf.hist.acc["1"].eng[7]).toBe(6); // 5 likes + 1 comment
    expect(perf.hist.acc["1"].views[7]).toBe(100);

    const li = perf.accounts.find((a) => a.id === "3")!;
    expect(li).toMatchObject({ handle: "Sean Facer", supported: false, hasFollowerData: false, hasPostData: false });
    expect(perf.accounts.find((a) => a.id === "2")).toMatchObject({ status: "needs_reauth", lastError: "Token revoked", hasPostData: true });
    expect(perf.accounts.find((a) => a.id === "4")).toMatchObject({ followerError: "HTTP 403 from TikTok" });

    expect(perf.posts).toHaveLength(3);
    const a = perf.posts.find((p) => p.id === "youtube|a")!;
    expect(a.eng).toBe(36);
    expect(a.rate).toBeCloseTo(0.06, 10);
    expect(a.account.id).toBe("1");
    const c = perf.posts.find((p) => p.id === "x|c")!;
    expect(c.rate).toBeNull(); // X gives no views
    expect(c.url).toBeNull();
  });

  it("derives profile KPIs, series and rows for the selection", () => {
    const perf = fromApi(FIXTURE);
    const all = perf.accounts.map((a) => a.id);
    const d = profileData(perf.hist, perf.accounts, all);

    expect(d.dates).toHaveLength(7);
    expect(toISODate(d.dates[0])).toBe("2026-10-02");
    expect(d.kpis.map((k) => k.label)).toEqual(["Total followers", "Net follower growth", "Engagements", "Engagement rate (per view)"]);
    expect(d.kpis[0].value).toBe("120");
    expect(d.kpis[0].delta).toEqual({ label: "▲ 13.2%", dir: "up" }); // 120 vs 106 at the end of the previous period
    expect(d.kpis[1].value).toBe("+14"); // 106 → 120
    expect(d.kpis[2].value).toBe("42"); // 7 days × (5 likes + 1 comment)
    expect(d.kpis[2].delta).toEqual({ label: "▲ 500.0%", dir: "up" }); // vs 7 × 1
    expect(d.kpis[3].value).toBe("6.0%"); // 42 / 700 views
    expect(d.followers.total).toEqual([107, 108, 110, 112, 115, 118, 120]);
    expect(d.followers.net).toEqual([1, 1, 2, 2, 3, 3, 2]);
    // Accounts without snapshots show "–" but still appear in the table.
    expect(d.followerRows).toHaveLength(5);
    expect(d.followerRows[0].followers).toBe("120");
    expect(d.followerRows[1].followers).toBe("–");
    expect(d.engagement.byNet.map((s) => s.net.id)).toEqual(["youtube", "tiktok", "instagram", "linkedin", "x"]);
    expect(d.engagement.totals.reduce((a, b) => a + b, 0)).toBe(42);
    expect(d.engagementRows[0].share).toBe(1);
    expect(d.engagementRows[0].rate).toBe("6.0%");
    expect(d.engagementRows[1].rate).toBe("–");
  });

  it("ignores accounts whose snapshots start mid-window when comparing periods", () => {
    const data = structuredClone(FIXTURE);
    // TikTok starts reporting 5,000 followers on the 3rd day of the current period.
    data.accounts[3].followers = data.accounts[3].followers.map((_, i) => (i >= 9 ? 5000 + (i - 9) * 10 : null));
    const perf = fromApi(data);
    const d = profileData(perf.hist, perf.accounts, perf.accounts.map((a) => a.id));
    expect(d.kpis[0].value).toBe("5,160"); // the headline total does include it…
    expect(d.kpis[0].delta).toEqual({ label: "▲ 13.2%", dir: "up" }); // …but the comparison is YouTube only (120 vs 106)
    expect(d.kpis[1].value).toBe("+54"); // 14 YouTube + 40 TikTok (its own day-over-day growth only)
    expect(d.kpis[1].delta).toEqual({ label: "▲ 133.3%", dir: "up" }); // YouTube 14 vs 6 (the first day of history has no prior value)
    expect(d.followers.total[1]).toBe(108); // before TikTok starts
    expect(d.followers.total[2]).toBe(5110);
  });

  it("returns null totals when no selected account has follower data", () => {
    const perf = fromApi(FIXTURE);
    const d = profileData(perf.hist, perf.accounts, ["3"]);
    expect(d.followers.total.every((v) => v === null)).toBe(true);
    expect(d.kpis[0].value).toBe("–");
    expect(d.kpis[0].delta.label).toBe("–");
  });

  it("filters and sorts posts, with rate-less posts last", () => {
    const perf = fromApi(FIXTURE);
    const all = perf.accounts.map((a) => a.id);
    expect(postsData(perf.posts, all, "rate").map((p) => p.id)).toEqual(["youtube|b", "youtube|a", "x|c"]);
    expect(postsData(perf.posts, all, "eng").map((p) => p.id)).toEqual(["youtube|a", "x|c", "youtube|b"]);
    expect(postsData(perf.posts, all, "views").map((p) => p.id)).toEqual(["youtube|a", "youtube|b", "x|c"]);
    expect(postsData(perf.posts, all, "shares")[0].id).toBe("x|c");
    expect(postsData(perf.posts, all, "date").map((p) => p.id)).toEqual(["youtube|b", "x|c", "youtube|a"]);
    expect(postsData(perf.posts, ["2"], "rate").map((p) => p.id)).toEqual(["x|c"]);
  });

  it("labels the sources trigger", () => {
    const { accounts } = fromApi(FIXTURE);
    const all = accounts.map((a) => a.id);
    expect(sourcesLabel(accounts, all)).toBe("All sources");
    expect(sourcesLabel(accounts, ["1"])).toBe("YouTube @seancreates");
    expect(sourcesLabel(accounts, ["1", "2", "3"])).toBe("3 of 5 accounts");
  });
});
