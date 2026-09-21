// Head tags for public pages that must answer with a real HTTP 200.
//
// The site is a single-page app on S3 + CloudFront: a path with no matching
// object (e.g. /privacy) is served index.html with status 404, so crawlers and
// automated URL checks — such as the Claude and ChatGPT plugin directory
// reviews, which link the privacy policy, terms, and /ai docs — see "not
// found". scripts/prerender-seo.ts writes dist/<path>/index.html for every
// entry, which S3 serves as a 302 to <path>/ followed by 200.
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
];
