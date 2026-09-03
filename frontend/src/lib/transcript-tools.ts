// Config for the free transcript tools (YouTube / TikTok / Instagram). One entry
// per platform drives the shared <TranscriptTool> page: SEO copy, hero, steps,
// FAQ, and a canned "dummy" transcript for the demo output (no real API yet).

export type PlatformKey = "youtube" | "tiktok" | "instagram";

export interface FaqItem {
  q: string;
  a: string;
}

export interface TranscriptLine {
  time: string; // "MM:SS"
  text: string;
}

export interface PlatformConfig {
  key: PlatformKey;
  slug: string; // full path, e.g. "/free-tools/youtube-transcript"
  name: string; // "YouTube"
  accent: string; // brand color
  seo: { title: string; description: string; keywords: string };
  h1: string;
  subhead: string;
  urlPlaceholder: string;
  ctaLabel: string;
  steps: { title: string; desc: string }[];
  features: { title: string; desc: string }[];
  useCases: { persona: string; desc: string }[];
  faq: FaqItem[];
  sample: TranscriptLine[];
}

const BASE = "/free-tools";

export const PLATFORMS: Record<PlatformKey, PlatformConfig> = {
  youtube: {
    key: "youtube",
    slug: `${BASE}/youtube-transcript`,
    name: "YouTube",
    accent: "#FF0000",
    seo: {
      title: "Free YouTube Transcript Downloader - No Login",
      description:
        "Generate a free YouTube transcript in seconds. Paste any video URL to get the full transcript, copy it, or download it as TXT or SRT. No login, no software — 100% free.",
      keywords:
        "youtube transcript, youtube transcript generator, download youtube transcript, youtube to text, youtube video transcript, free youtube transcript, copy youtube transcript, youtube subtitles download, youtube transcript online",
    },
    h1: "Free YouTube Transcript Generator",
    subhead:
      "Turn any YouTube video into text. Paste a link to generate, read, copy, and download the full transcript — free, no login required.",
    urlPlaceholder: "https://www.youtube.com/watch?v=...",
    ctaLabel: "Generate free transcript",
    steps: [
      { title: "Paste the YouTube URL", desc: "Copy any video or Shorts link and paste it into the box above." },
      { title: "Generate the transcript", desc: "We pull the full spoken text with timestamps in seconds." },
      { title: "Copy or download", desc: "Read it on-page, copy to your clipboard, or export as TXT or SRT." },
    ],
    features: [
      { title: "Full video transcripts", desc: "Every line of dialogue with clean, readable timestamps." },
      { title: "Copy & download", desc: "One-click copy, or export TXT for notes and SRT for subtitles." },
      { title: "No login, 100% free", desc: "No account, no install, no watermark — just paste and go." },
      { title: "Works with long videos", desc: "Podcasts, tutorials, lectures — no length limits." },
    ],
    useCases: [
      { persona: "Note takers & students", desc: "Turn lectures and tutorials into searchable notes you can skim." },
      { persona: "Content creators", desc: "Repurpose videos into blog posts, threads, and newsletters fast." },
      { persona: "Researchers", desc: "Quote and cite video content without rewatching hours of footage." },
      { persona: "Marketers", desc: "Feed transcripts to AI tools for summaries, clips, and SEO copy." },
    ],
    faq: [
      { q: "Is the YouTube transcript generator free to use?", a: "Yes — it's 100% free with no account, no software, and no watermark. Paste a URL and get the transcript." },
      { q: "How do I download a YouTube transcript?", a: "Generate the transcript, then use the Download button to export it as a plain-text TXT file or an SRT subtitle file." },
      { q: "Can I copy the YouTube transcript?", a: "Yes. Click Copy to put the full transcript on your clipboard, ready to paste into notes, docs, or an AI tool." },
      { q: "Is there a video length limit?", a: "No — the tool handles short clips and long-form videos like podcasts and lectures." },
      { q: "Do I need to log in?", a: "No login is required. Nothing to sign up for — just paste the link." },
      { q: "Can I use the transcript with AI tools?", a: "Absolutely. Copy the transcript into ChatGPT, Claude, or any AI to summarize, generate clips, or repurpose it." },
    ],
    sample: [
      { time: "00:00", text: "Hey everyone, welcome back to the channel. In this video we're breaking down exactly how to get your first thousand subscribers." },
      { time: "00:11", text: "The single biggest mistake new creators make is chasing trends instead of nailing one clear topic." },
      { time: "00:24", text: "So the first thing you want to do is pick a niche you can talk about for a hundred videos." },
      { time: "00:37", text: "Next, study your thumbnails. Your click-through rate is what actually decides whether a video takes off." },
      { time: "00:52", text: "And finally, be consistent. Posting on a schedule tells the algorithm your channel is worth recommending." },
      { time: "01:08", text: "If this helped, hit subscribe and I'll see you in the next one." },
    ],
  },
  tiktok: {
    key: "tiktok",
    slug: `${BASE}/tiktok-transcript`,
    name: "TikTok",
    accent: "#00F2EA",
    seo: {
      title: "Free TikTok Transcript Downloader - No Login",
      description:
        "Convert any TikTok video to text for free. Paste a TikTok link to generate the full transcript, copy it, or download it as TXT or SRT. No login, no app — instant and free.",
      keywords:
        "tiktok transcript, tiktok to text, tiktok video transcript, download tiktok transcript, tiktok transcript generator, free tiktok transcript, tiktok caption generator, transcribe tiktok, tiktok subtitles",
    },
    h1: "Free TikTok Transcript Generator",
    subhead:
      "Convert any TikTok into text in seconds. Paste a link to generate, read, copy, and download the full transcript — free, no login required.",
    urlPlaceholder: "https://www.tiktok.com/@user/video/...",
    ctaLabel: "Generate free transcript",
    steps: [
      { title: "Paste the TikTok URL", desc: "Copy the share link from any TikTok video and paste it above." },
      { title: "Generate the transcript", desc: "We turn the audio into clean, timestamped text in seconds." },
      { title: "Copy or download", desc: "Read on-page, copy to clipboard, or export as TXT or SRT." },
    ],
    features: [
      { title: "TikTok video to text", desc: "The full spoken script from any TikTok, timestamped and readable." },
      { title: "Copy & download", desc: "One-click copy, or export TXT for repurposing and SRT for captions." },
      { title: "No login, 100% free", desc: "No account, no app, no watermark — paste and go." },
      { title: "Great for hooks & scripts", desc: "Study what makes a video go viral, word for word." },
    ],
    useCases: [
      { persona: "Creators & editors", desc: "Reverse-engineer viral hooks and turn TikToks into scripts and captions." },
      { persona: "Social media managers", desc: "Repurpose TikToks into Reels, Shorts, carousels, and posts." },
      { persona: "Marketers", desc: "Pull talking points and keywords for ads, SEO, and content briefs." },
      { persona: "Researchers", desc: "Capture what's being said in trends without rewatching dozens of clips." },
    ],
    faq: [
      { q: "How do I get a transcript from a TikTok video?", a: "Copy the TikTok share link, paste it into the box, and click Generate. You'll get the full text transcript in seconds." },
      { q: "Is the TikTok to text tool free?", a: "Yes — it's completely free with no login, no app download, and no watermark." },
      { q: "Can I download the TikTok transcript?", a: "Yes. Export it as a plain-text TXT file or an SRT subtitle file for captions." },
      { q: "Do I need to log in to transcribe a TikTok?", a: "No. There's nothing to sign up for — just paste the link." },
      { q: "Can I use this as a TikTok caption generator?", a: "Yes — the transcript gives you the exact words, which you can trim into captions or subtitles." },
      { q: "Can I use the transcript with AI tools?", a: "Definitely. Copy it into any AI tool to summarize, rewrite, or turn it into new content." },
    ],
    sample: [
      { time: "00:00", text: "Okay, stop scrolling — this is the fastest way to edit your videos in half the time." },
      { time: "00:04", text: "First, batch everything. Film five videos in one sitting so you're never starting from zero." },
      { time: "00:09", text: "Second, use a template. Same intro, same captions, same format — your brain does less work." },
      { time: "00:15", text: "Third, cut the first three seconds. Your hook should start on the action, not the setup." },
      { time: "00:21", text: "Follow for part two where I show you the exact app I use." },
    ],
  },
  instagram: {
    key: "instagram",
    slug: `${BASE}/instagram-transcript`,
    name: "Instagram",
    accent: "#E1306C",
    seo: {
      title: "Free Instagram Transcript Downloader - No Login",
      description:
        "Convert Instagram Reels and videos to text for free. Paste a link to generate the full transcript, copy it, or download it as TXT or SRT. No login, no app — fast and free.",
      keywords:
        "instagram transcript, instagram reels transcript, instagram video to text, download instagram transcript, instagram transcript generator, free instagram transcript, reels to text, transcribe instagram reel, instagram caption generator",
    },
    h1: "Free Instagram Transcript Generator",
    subhead:
      "Convert Instagram Reels and videos into text in seconds. Paste a link to generate, read, copy, and download the full transcript — free, no login required.",
    urlPlaceholder: "https://www.instagram.com/reel/...",
    ctaLabel: "Generate free transcript",
    steps: [
      { title: "Paste the Instagram URL", desc: "Copy the share link from any Reel or video and paste it above." },
      { title: "Generate the transcript", desc: "We convert the audio into clean, timestamped text in seconds." },
      { title: "Copy or download", desc: "Read on-page, copy to clipboard, or export as TXT or SRT." },
    ],
    features: [
      { title: "Reels & video to text", desc: "The full spoken script from any Reel or Instagram video, timestamped." },
      { title: "Copy & download", desc: "One-click copy, or export TXT for repurposing and SRT for captions." },
      { title: "No login, 100% free", desc: "No account, no app, no watermark — just paste the link." },
      { title: "Repurpose everywhere", desc: "Turn one Reel into posts, carousels, TikToks, and Shorts." },
    ],
    useCases: [
      { persona: "Creators", desc: "Turn Reels into captions, carousels, and cross-platform scripts." },
      { persona: "Social media managers", desc: "Repurpose Reels into TikTok, YouTube Shorts, and blog content." },
      { persona: "Marketers", desc: "Extract keywords and talking points for SEO and content briefs." },
      { persona: "Accessibility", desc: "Add accurate captions and subtitles so every viewer can follow along." },
    ],
    faq: [
      { q: "How do I get a transcript from an Instagram Reel or video?", a: "Copy the Reel or video share link, paste it into the box, and click Generate. The full transcript appears in seconds." },
      { q: "Is there a free Instagram-to-text converter?", a: "Yes — this tool is 100% free with no login, no app, and no watermark." },
      { q: "Do I need to log in to transcribe an Instagram video?", a: "No login required. There's nothing to sign up for — just paste the link." },
      { q: "Can I export SRT captions or the Instagram caption text?", a: "Yes. Download the transcript as a TXT file, or as an SRT subtitle file for captions." },
      { q: "Can I use Instagram transcripts for content repurposing?", a: "Absolutely — copy the transcript into an AI tool to turn one Reel into posts, threads, and Shorts scripts." },
      { q: "Are there limits on Reel or video length?", a: "No — short Reels and longer Instagram videos both work." },
    ],
    sample: [
      { time: "00:00", text: "Save this if you struggle to stay consistent with content." },
      { time: "00:03", text: "The trick isn't motivation — it's lowering the bar so posting feels easy." },
      { time: "00:08", text: "Pick one format you can repeat. A talking-head Reel with captions works every time." },
      { time: "00:14", text: "Then batch a week of ideas in your notes app so you're never staring at a blank screen." },
      { time: "00:20", text: "Follow for more content systems that actually stick." },
    ],
  },
};

export const PLATFORM_LIST = Object.values(PLATFORMS);

/** JSON-LD (SoftwareApplication + FAQPage) for a platform's page. */
export function buildJsonLd(cfg: PlatformConfig): Record<string, unknown>[] {
  const url = `https://viewsmax.com${cfg.slug}`;
  return [
    {
      "@context": "https://schema.org",
      "@type": "SoftwareApplication",
      name: cfg.seo.title.split(" — ")[0].split(" | ")[0].trim(),
      applicationCategory: "UtilitiesApplication",
      operatingSystem: "Web",
      url,
      description: cfg.seo.description,
      offers: { "@type": "Offer", price: "0", priceCurrency: "USD" },
    },
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      mainEntity: cfg.faq.map((f) => ({
        "@type": "Question",
        name: f.q,
        acceptedAnswer: { "@type": "Answer", text: f.a },
      })),
    },
  ];
}

/** SEO for the /free-tools hub (shared by the page + the prerender script). */
export const HUB_SEO = {
  title: "Free Tools for Creators — Transcript Generators & More | ViewsMax",
  description:
    "Free tools for creators and marketers: YouTube, TikTok, and Instagram transcript generators, a YouTube revenue calculator, and a thumbnail preview tool. No login required.",
  keywords:
    "free creator tools, youtube transcript, tiktok transcript, instagram transcript, youtube revenue calculator, thumbnail preview, free social media tools",
};
