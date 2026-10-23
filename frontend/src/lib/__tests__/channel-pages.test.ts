import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { CHANNEL_LIST, CHANNELS_PATH, buildChannelJsonLd, buildChannelsHubJsonLd } from "@/lib/channel-pages";
import { hasBrandIcon } from "@/components/post/brand-icons";
import { STATIC_PAGE_SEO } from "@/lib/static-page-seo";
import { AGENT_LIST } from "@/lib/agent-pages";
import en from "@/pages/landing/copy/en.json";

const sitemap = readFileSync(join(process.cwd(), "public", "sitemap-pages.xml"), "utf8");

describe("CHANNEL_LIST", () => {
  it("has a page for every posting channel a user can connect", () => {
    expect(CHANNEL_LIST.map((c) => c.slug).sort()).toEqual(
      ["bluesky", "instagram", "linkedin", "pinterest", "threads", "tiktok", "x", "youtube"].map((k) => `${CHANNELS_PATH}/${k}`).sort(),
    );
  });

  it("gives every page real head tags and enough content to be useful", () => {
    for (const c of CHANNEL_LIST) {
      expect(c.slug).toBe(`${CHANNELS_PATH}/${c.key}`);
      expect(c.seo.title).toContain(c.name);
      expect(c.seo.description.trim()).not.toBe("");
      expect(c.seo.keywords.trim()).not.toBe("");
      expect(c.formats.length).toBeGreaterThanOrEqual(1);
      expect(c.options.length).toBeGreaterThanOrEqual(3);
      expect(c.steps).toHaveLength(3);
      expect(c.features).toHaveLength(4);
      expect(c.faq.length).toBeGreaterThanOrEqual(4);
    }
  });

  it("uses a brand glyph and a nav description that exist for every channel", () => {
    for (const c of CHANNEL_LIST) {
      expect(hasBrandIcon(c.key), c.key).toBe(true);
      expect((en.channelCards as Record<string, string>)[c.key], c.key).toBeTruthy();
    }
  });

  it("quotes the caption limit the backend enforces", () => {
    // CaptionRules::LIMITS is the single source of truth for every channel.
    const php = readFileSync(join(process.cwd(), "..", "backend", "app", "Services", "Social", "CaptionRules.php"), "utf8");
    for (const c of CHANNEL_LIST) {
      const m = php.match(new RegExp(`'${c.key}' => (\\d+),`));
      expect(m, c.key).not.toBeNull();
      expect(c.captionLimit, c.key).toBe(Number(m![1]));
    }
  });

  it("does not collide with the other prerendered public pages", () => {
    const others = [...STATIC_PAGE_SEO.map((p) => p.path), ...AGENT_LIST.map((a) => a.slug)];
    for (const c of CHANNEL_LIST) expect(others).not.toContain(c.slug);
  });

  it("is listed in the sitemap, hub included", () => {
    expect(sitemap).toContain(`<loc>https://viewsmax.com${CHANNELS_PATH}</loc>`);
    for (const c of CHANNEL_LIST) expect(sitemap, c.slug).toContain(`<loc>https://viewsmax.com${c.slug}</loc>`);
  });
});

describe("JSON-LD", () => {
  it("gives each channel page a FAQ and a breadcrumb trail to the hub", () => {
    for (const c of CHANNEL_LIST) {
      const ld = buildChannelJsonLd(c) as { "@type": string; mainEntity?: unknown[]; itemListElement?: { item: string }[] }[];
      const faq = ld.find((d) => d["@type"] === "FAQPage");
      expect(faq?.mainEntity).toHaveLength(c.faq.length);
      const crumbs = ld.find((d) => d["@type"] === "BreadcrumbList");
      expect(crumbs?.itemListElement?.map((i) => i.item)).toEqual([`https://viewsmax.com${CHANNELS_PATH}`, `https://viewsmax.com${c.slug}`]);
    }
  });

  it("lists every channel page on the hub", () => {
    const [list] = buildChannelsHubJsonLd() as { itemListElement: { url: string }[] }[];
    expect(list.itemListElement.map((i) => i.url)).toEqual(CHANNEL_LIST.map((c) => `https://viewsmax.com${c.slug}`));
  });
});
