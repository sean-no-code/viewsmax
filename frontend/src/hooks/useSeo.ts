import { useEffect } from "react";

const DEFAULT_TITLE = "ViewsMax - Content Analytics & Trend Insights";

export interface SeoConfig {
  title: string;
  description: string;
  keywords?: string;
  /** Path only, e.g. "/free-tools/youtube-transcript"; origin is added at runtime. */
  canonicalPath: string;
  ogImage?: string;
  /** JSON-LD objects injected as <script type="application/ld+json"> (e.g. FAQPage, SoftwareApplication). */
  jsonLd?: Record<string, unknown>[];
}

/**
 * Per-page SEO: sets <title>, description/keywords, Open Graph + Twitter tags,
 * canonical, and JSON-LD structured data — cleaning up the tags it created on
 * unmount. Consolidates the meta-effect that was duplicated across tool pages.
 * The effect runs during the build-time prerender too, so the real head ships
 * in the served HTML for these routes.
 */
export function useSeo({ title, description, keywords, canonicalPath, ogImage, jsonLd }: SeoConfig) {
  // Stringify JSON-LD outside the dep array so a new array literal each render
  // doesn't re-run the effect.
  const jsonLdKey = JSON.stringify(jsonLd ?? []);

  useEffect(() => {
    const origin = typeof window !== "undefined" ? window.location.origin : "https://viewsmax.com";
    const url = `${origin}${canonicalPath}`;
    const createdMeta: Element[] = [];

    document.title = title;

    const upsertMeta = (name: string, content: string, isProperty = false) => {
      const attr = isProperty ? "property" : "name";
      let el = document.querySelector(`meta[${attr}="${name}"]`) as HTMLMetaElement | null;
      if (!el) {
        el = document.createElement("meta");
        el.setAttribute(attr, name);
        document.head.appendChild(el);
        createdMeta.push(el);
      }
      el.setAttribute("content", content);
    };

    upsertMeta("description", description);
    if (keywords) upsertMeta("keywords", keywords);
    upsertMeta("og:title", title, true);
    upsertMeta("og:description", description, true);
    upsertMeta("og:type", "website", true);
    upsertMeta("og:url", url, true);
    if (ogImage) upsertMeta("og:image", ogImage, true);
    upsertMeta("twitter:card", "summary_large_image");
    upsertMeta("twitter:title", title);
    upsertMeta("twitter:description", description);
    if (ogImage) upsertMeta("twitter:image", ogImage);

    let canonical = document.querySelector("link[rel='canonical']") as HTMLLinkElement | null;
    let createdCanonical = false;
    const prevCanonical = canonical?.href;
    if (!canonical) {
      canonical = document.createElement("link");
      canonical.rel = "canonical";
      document.head.appendChild(canonical);
      createdCanonical = true;
    }
    canonical.href = url;

    const ldScripts = (jsonLd ?? []).map((data) => {
      const s = document.createElement("script");
      s.type = "application/ld+json";
      s.setAttribute("data-seo", "page");
      s.text = JSON.stringify(data);
      document.head.appendChild(s);
      return s;
    });

    return () => {
      document.title = DEFAULT_TITLE;
      createdMeta.forEach((el) => el.remove());
      ldScripts.forEach((s) => s.remove());
      if (createdCanonical) canonical?.remove();
      else if (canonical && prevCanonical) canonical.href = prevCanonical;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [title, description, keywords, canonicalPath, ogImage, jsonLdKey]);
}
