import { describe, expect, it } from "vitest";
import {
  ACCOUNTS, ALL_ACCOUNT_IDS, HISTORY_DAYS, NETWORKS,
  buildHistory, clampRange, delta, fmt, fromISODate, postsData, presetFor, presetRange, profileData, rangeLabel, signed, sourcesLabel, toISODate,
} from "../analytics-performance-mock";

const END = new Date(2026, 9, 8); // 8 Oct 2026
const hist = () => buildHistory(END);

describe("analytics-performance-mock", () => {
  it("builds a deterministic, memoised history ending on the given day", () => {
    const h = hist();
    expect(h.dates).toHaveLength(HISTORY_DAYS);
    expect(h.dates[HISTORY_DAYS - 1].toDateString()).toBe(END.toDateString());
    expect(buildHistory(new Date(2026, 9, 8, 15, 30))).toBe(h); // same day → same object
    for (const a of ACCOUNTS) {
      const s = h.acc[a.id];
      expect(s.fol).toHaveLength(HISTORY_DAYS);
      // followers = running sum of the net series, starting from 55% of the account's base
      expect(s.fol[HISTORY_DAYS - 1]).toBeGreaterThan(a.base * 0.55);
      expect(s.imp.every((v, i) => v >= s.eng[i])).toBe(true);
    }
    expect(h.acc.yt1.net.slice(0, 5)).toEqual(buildHistory(new Date(2026, 9, 8)).acc.yt1.net.slice(0, 5));
  });

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

  it("round-trips ISO dates and clamps ranges to the history window", () => {
    expect(fromISODate("2026-02-03")?.getTime()).toBe(new Date(2026, 1, 3).getTime());
    expect(fromISODate("nope")).toBeNull();
    expect(fromISODate(null)).toBeNull();
    const h = hist();
    const c = clampRange(h, { from: new Date(2030, 0, 1), to: new Date(2000, 0, 1) });
    expect(c.from.getTime()).toBe(h.start.getTime());
    expect(c.to.getTime()).toBe(h.end.getTime());
  });

  it("formats numbers and deltas like the design", () => {
    expect(fmt(950)).toBe("950");
    expect(fmt(12345)).toBe("12.3K");
    expect(fmt(2_500_000)).toBe("2.5M");
    expect(signed(12)).toBe("+12");
    expect(signed(-3)).toBe("-3");
    expect(delta(110, 100)).toEqual({ label: "▲ 10.0%", dir: "up" });
    expect(delta(90, 100)).toEqual({ label: "▼ 10.0%", dir: "down" });
    expect(delta(5, 0)).toEqual({ label: "–", dir: "flat" });
  });

  it("derives profile KPIs, series and per-account rows for the selection", () => {
    const h = hist();
    const d = profileData(h, presetRange("7d", END), ALL_ACCOUNT_IDS);
    expect(d.dates).toHaveLength(7);
    expect(d.kpis.map((k) => k.label)).toEqual(["Total followers", "Net follower growth", "Engagements", "Engagement rate (per impression)"]);
    expect(d.followers.total).toHaveLength(7);
    expect(d.followers.net).toHaveLength(7);
    // total followers on the last day = sum of every account's running total
    const expectedTotal = ACCOUNTS.reduce((t, a) => t + h.acc[a.id].fol[HISTORY_DAYS - 1], 0);
    expect(d.followers.total[6]).toBe(expectedTotal);
    expect(d.kpis[0].value).toBe(fmt(expectedTotal));
    expect(d.followerRows).toHaveLength(ACCOUNTS.length);
    expect(d.engagement.byNet.map((s) => s.net.id)).toEqual(NETWORKS.map((n) => n.id));
    // stacked layers add up to the totals
    d.engagement.totals.forEach((t, i) => expect(d.engagement.byNet.reduce((s, l) => s + l.values[i], 0)).toBe(t));
    // engagement shares add up to 100%
    expect(d.engagementRows.reduce((s, r) => s + r.share, 0)).toBeCloseTo(1, 6);
  });

  it("narrows everything to the selected accounts", () => {
    const h = hist();
    const d = profileData(h, presetRange("28d", END), ["yt1", "yt2"]);
    expect(d.followerRows.map((r) => r.account.id)).toEqual(["yt1", "yt2"]);
    expect(d.engagement.byNet.map((s) => s.net.id)).toEqual(["youtube"]);
    expect(d.followers.total[27]).toBe(h.acc.yt1.fol[HISTORY_DAYS - 1] + h.acc.yt2.fol[HISTORY_DAYS - 1]);
  });

  it("returns posts inside the range, filtered by source and sorted", () => {
    const h = hist();
    const r = presetRange("28d", END);
    const byRate = postsData(h, r, ALL_ACCOUNT_IDS, "rate");
    expect(byRate.length).toBeGreaterThan(0);
    for (let i = 1; i < byRate.length; i++) expect(byRate[i - 1].rate).toBeGreaterThanOrEqual(byRate[i].rate);
    for (const p of byRate) {
      expect(p.date >= r.from && p.date <= r.to).toBe(true);
      expect(p.likes + p.comments + p.shares + p.saves).toBe(p.eng);
      expect(p.rate).toBeCloseTo(p.eng / p.imp, 10);
    }
    const byDate = postsData(h, r, ALL_ACCOUNT_IDS, "date");
    for (let i = 1; i < byDate.length; i++) expect(byDate[i - 1].date >= byDate[i].date).toBe(true);
    const tiktokOnly = postsData(h, r, ["tt1"], "eng");
    expect(tiktokOnly.length).toBeGreaterThan(0);
    expect(tiktokOnly.every((p) => p.account.id === "tt1")).toBe(true);
    // deterministic across calls
    expect(postsData(h, r, ALL_ACCOUNT_IDS, "imp").map((p) => p.id)).toEqual(postsData(h, r, ALL_ACCOUNT_IDS, "imp").map((p) => p.id));
  });

  it("labels the sources trigger", () => {
    expect(sourcesLabel(ALL_ACCOUNT_IDS)).toBe("All sources");
    expect(sourcesLabel(["tt1"])).toBe("TikTok @viewsmax");
    expect(sourcesLabel(["yt1", "yt2"])).toBe("All YouTube accounts");
    expect(sourcesLabel(["yt1", "tt1", "x1"])).toBe("3 of 8 accounts");
  });
});
