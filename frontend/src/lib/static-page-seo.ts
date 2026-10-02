// Head tags for public pages that must answer with a real HTTP 200.
//
// The site is a single-page app on S3 + CloudFront: a path with no matching
// object (e.g. /privacy) is served index.html with status 404, so crawlers and
// automated URL checks — such as the Claude and ChatGPT plugin directory
// reviews, which link the privacy policy, terms, and /ai docs — see "not
// found". scripts/prerender-seo.ts writes dist/<path>/index.html for every
// entry, which S3 serves as a 302 to <path>/ followed by 200. Those reviews
// don't run JavaScript either, so scripts/prerender-bodies.ts bakes each page's
// text into that file too — add the page to src/lib/static-page-body.tsx when
// adding one here.
export interface StaticPageSeo {
  path: string;
  title: string;
  description: string;
  keywords: string;
}

export const STATIC_PAGE_SEO: StaticPageSeo[] = [
  {
    path: "/privacy",
    title: "Privacy Policy | ViewsMax",
    description:
      "How ViewsMax collects, uses, shares, retains, and protects your data, and how to request deletion.",
    keywords: "viewsmax privacy policy, data protection, data deletion, privacy",
  },
  {
    path: "/terms",
    title: "Terms of Service | ViewsMax",
    description: "The terms that govern your use of ViewsMax.",
    keywords: "viewsmax terms of service, terms and conditions",
  },
  {
    path: "/ai",
    title: "Connect Your AI Assistant to ViewsMax | ViewsMax",
    description:
      "Connect Claude, ChatGPT, and other AI assistants to ViewsMax through the MCP server or REST API to post, track offers, and read analytics.",
    keywords: "viewsmax mcp, claude connector, chatgpt plugin, ai assistant, rest api",
  },
  // Same tags the two tool pages set for themselves once React runs.
  {
    path: "/youtube-monetization-calculator",
    title: "YouTube Monetization Calculator - Calculate Your Channel Revenue | ViewsMax",
    description:
      "Calculate your potential YouTube revenue with our free monetization calculator. Get 12-month growth projections for conservative, moderate, and aggressive scenarios based on your channel's views, subscribers, and content category.",
    keywords:
      "youtube monetization calculator, youtube revenue calculator, youtube earnings calculator, youtube cpm calculator, youtube rpm calculator, youtube ad revenue, youtube channel revenue",
  },
  {
    path: "/thumbnail-preview",
    title: "YouTube Thumbnail Preview Tool - See How Your Thumbnail Looks | ViewsMax",
    description:
      "Upload your YouTube thumbnail and see exactly how it will appear on YouTube's home page. Test your thumbnail design before publishing to maximize click-through rates.",
    keywords:
      "youtube thumbnail preview, thumbnail tester, youtube thumbnail checker, thumbnail preview tool, youtube thumbnail design, thumbnail mockup",
  },
];
