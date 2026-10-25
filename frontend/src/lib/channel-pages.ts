// Config for the per-channel pages (/channels/pinterest, /channels/youtube, ...)
// and the /channels hub. One entry per posting platform drives the shared
// <ChannelPage>, the Channels dropdown in the marketing nav, the build-time
// prerender (head tags + JSON-LD) and the sitemap entries.
//
// Facts come from the code that enforces them: formats from PLATFORM_CAPS in
// components/post/composer.tsx, caption limits from backend CaptionRules::LIMITS
// (mirrored in lib/text-metrics.ts), per-platform options from the publish
// option types in lib/api-service.ts. Keep them in step when a provider changes.
//
// No import.meta.env access at module scope: scripts/prerender-seo.ts imports
// this file under plain Node.

export const CHANNELS_PATH = "/channels";

export type ChannelKey = "youtube" | "tiktok" | "instagram" | "x" | "linkedin" | "threads" | "bluesky" | "pinterest";

export interface FaqItem {
  q: string;
  a: string;
}

export interface ChannelConfig {
  key: ChannelKey;
  /** Full path, e.g. "/channels/pinterest". */
  slug: string;
  name: string;
  /** Brand accent behind the white glyph (BrandIcon in components/post/brand-icons.tsx). */
  color: string;
  /** One line for the hub cards and the "more channels" grid. */
  card: string;
  seo: { title: string; description: string; keywords: string };
  h1: string;
  subhead: string;
  /** What a post can be on this channel, as the composer offers it. */
  formats: string[];
  /** Caption / description limit the composer and API enforce. */
  captionLimit: number;
  /** How the limit is counted when it isn't plain characters. */
  captionNote?: string;
  /** How an account is connected. */
  connect: string;
  /** Channel-specific publish options the composer exposes. */
  options: string[];
  steps: { title: string; body: string }[];
  features: { title: string; body: string }[];
  faq: FaqItem[];
}

const slug = (key: ChannelKey) => `${CHANNELS_PATH}/${key}`;

// Shared answers that are true on every channel; each page phrases its own
// channel-specific questions around them.
const PLAN_FAQ: FaqItem = {
  q: "Is there a free trial?",
  a: "Yes. Every new account gets a 7-day free trial of the plan you pick, for a $1 activation fee. The plan price isn't charged until the trial ends, and you can cancel anytime from Settings.",
};
const AGENT_FAQ = (name: string): FaqItem => ({
  q: `Can an AI assistant post to ${name} for me?`,
  a: `Yes. Claude, ChatGPT, Cursor and other agents connect to ViewsMax over MCP or the REST API and can draft, schedule and publish ${name} posts to your connected account, then read back how they performed.`,
});
const SALES_FAQ = (name: string): FaqItem => ({
  q: `How does ViewsMax know which ${name} post made a sale?`,
  a: "Every link you share goes through a tracked link tied to the post and the channel. Clicks, email sign-ups, booked calls and purchases are attributed back to that post, so the analytics show revenue per post, not just views.",
});

export const CHANNELS: Record<ChannelKey, ChannelConfig> = {
  youtube: {
    key: "youtube",
    slug: slug("youtube"),
    name: "YouTube",
    color: "#FF0000",
    card: "Schedule Shorts and videos, pick the visibility",
    seo: {
      title: "YouTube Scheduler — Schedule Shorts & Videos | ViewsMax",
      description:
        "Schedule YouTube Shorts and videos from one calendar. Upload once, set public, unlisted or private, add a custom thumbnail, cross-post to every other channel and see which video made the sale.",
      keywords:
        "youtube scheduler, schedule youtube videos, schedule youtube shorts, youtube post scheduler, youtube upload scheduler, youtube auto post, youtube social media scheduler",
    },
    h1: "Schedule YouTube Shorts and videos from one calendar",
    subhead:
      "Upload the video once, choose public, unlisted or private, set the thumbnail and let ViewsMax publish on time. Then see which video actually drove sales, not just views.",
    formats: ["Video", "Shorts"],
    captionLimit: 5000,
    connect: "Google sign-in to the channel you manage",
    options: ["Public, unlisted or private", "Custom thumbnail", "Title and description"],
    steps: [
      { title: "Connect your channel", body: "Sign in with Google and pick the YouTube channel. Brand channels work too; connect as many as your plan allows." },
      { title: "Upload and schedule", body: "Drop in the video, write the title and description, choose the visibility and thumbnail, and pick a time on the calendar." },
      { title: "Track what it earned", body: "Put a tracked link in the description or a pinned comment and ViewsMax ties every click, sign-up and sale back to that video." },
    ],
    features: [
      { title: "Visibility per upload", body: "Publish public, drop an unlisted link to a client first, or upload private and flip it later. The choice is saved with the scheduled post." },
      { title: "Thumbnails included", body: "Attach a custom cover image with the upload instead of fixing it in YouTube Studio afterwards." },
      { title: "Cross-post the same video", body: "Schedule the Short to TikTok, Instagram Reels and Threads in the same step, with a caption trimmed to each channel's limit." },
      { title: "Analytics that follow the money", body: "Views, watch time and subscribers next to the clicks and revenue each video produced." },
    ],
    faq: [
      { q: "Can ViewsMax schedule YouTube Shorts?", a: "Yes. A vertical video uploads as a Short. You schedule it exactly like a long-form video and choose the visibility it goes out with." },
      { q: "Which visibility settings are supported?", a: "Public, unlisted and private. You pick one per upload; private uploads stay on the channel until you change them in YouTube." },
      { q: "Can I schedule to more than one YouTube channel?", a: "Yes. Connect each channel separately and choose the target when you schedule." },
      SALES_FAQ("YouTube"),
      AGENT_FAQ("YouTube"),
      PLAN_FAQ,
    ],
  },
  tiktok: {
    key: "tiktok",
    slug: slug("tiktok"),
    name: "TikTok",
    color: "#0A0A0C",
    card: "Videos and photo sets with privacy levels",
    seo: {
      title: "TikTok Scheduler — Schedule Videos & Photo Posts | ViewsMax",
      description:
        "Schedule TikTok videos and photo carousels, set the privacy level and cover frame, cross-post to Reels and Shorts, and track the clicks and sales each TikTok drives.",
      keywords:
        "tiktok scheduler, schedule tiktok posts, tiktok post scheduler, schedule tiktok videos, tiktok auto post, tiktok photo carousel scheduler, tiktok social media scheduler",
    },
    h1: "Schedule TikTok videos and photo posts ahead of time",
    subhead:
      "Queue a week of TikToks in one sitting. Pick the privacy level, choose the cover frame and publish straight to your account, with the same clip going to Reels and Shorts if you want.",
    formats: ["Video", "Photo set"],
    captionLimit: 2200,
    connect: "TikTok login, direct publishing to your account",
    options: ["Privacy level per post", "Cover frame time", "Creator settings respected"],
    steps: [
      { title: "Connect TikTok", body: "Log in with TikTok and allow direct posting. ViewsMax reads your creator settings so each post respects what your account allows." },
      { title: "Compose the post", body: "Add a video or up to a set of photos, write the caption within the 2,200-character limit, choose who can see it and the frame used as the cover." },
      { title: "Publish and measure", body: "The post goes out at the scheduled time. A tracked link in your bio or caption shows which TikTok sent the traffic and the sales." },
    ],
    features: [
      { title: "Photo carousels too", body: "Schedule photo sets, not just video. The same composer handles both." },
      { title: "Privacy you choose", body: "Public, friends or only you. You pick it per post and ViewsMax asks instead of guessing." },
      { title: "One clip, three channels", body: "Send the same vertical video to TikTok, Instagram Reels and YouTube Shorts with per-channel captions." },
      { title: "Sales, not just views", body: "Tracked links tie every click, sign-up and purchase back to the TikTok that caused it." },
    ],
    faq: [
      { q: "Does ViewsMax post directly to TikTok?", a: "Yes. Posts publish straight to your TikTok account at the scheduled time; there is no notification to finish manually in the app." },
      { q: "Can I schedule TikTok photo posts?", a: "Yes. A photo set schedules like a video, with the same caption and privacy options." },
      { q: "How is the privacy level chosen?", a: "You choose it for every post from the levels your account offers. ViewsMax never picks one for you." },
      SALES_FAQ("TikTok"),
      AGENT_FAQ("TikTok"),
      PLAN_FAQ,
    ],
  },
  instagram: {
    key: "instagram",
    slug: slug("instagram"),
    name: "Instagram",
    color: "#DD2A7B",
    card: "Reels and photos on Business and Creator accounts",
    seo: {
      title: "Instagram Scheduler — Schedule Reels & Photos | ViewsMax",
      description:
        "Schedule Instagram Reels and photo posts to a Business or Creator account, set a custom Reel cover, cross-post to TikTok and Shorts, and track which post made the sale.",
      keywords:
        "instagram scheduler, schedule instagram posts, schedule instagram reels, instagram post scheduler, instagram auto post, instagram reels scheduler, instagram business scheduler",
    },
    h1: "Schedule Instagram Reels and photos that publish themselves",
    subhead:
      "Plan Reels and photo posts for your Business or Creator account, add a cover, and let ViewsMax publish on schedule while the analytics track what each post earned.",
    formats: ["Reel", "Photo"],
    captionLimit: 2200,
    connect: "Instagram Business or Creator account",
    options: ["Custom Reel cover", "Caption with hashtags", "Reach and engagement insights"],
    steps: [
      { title: "Connect your account", body: "Connect an Instagram Business or Creator account. Personal accounts need to be switched first, which takes a minute in the Instagram app." },
      { title: "Schedule a Reel or photo", body: "Add the video or image, write the caption, attach a cover for Reels and choose the time." },
      { title: "See the results", body: "Reach and engagement sit next to the clicks and sales your tracked links recorded for each post." },
    ],
    features: [
      { title: "Reels with a real cover", body: "Upload the cover image with the Reel so your grid looks the way you planned." },
      { title: "Captions that fit", body: "The composer counts against Instagram's 2,200-character limit as you type." },
      { title: "Same post, more places", body: "Send the Reel to TikTok, YouTube Shorts and Threads in the same step." },
      { title: "Revenue per post", body: "Tracked links in your bio or caption show which Reel brought the buyers." },
    ],
    faq: [
      { q: "Which Instagram accounts can connect?", a: "Business and Creator accounts. If yours is personal, switch it in Instagram's settings first; it is free and takes a minute." },
      { q: "Can I schedule Reels?", a: "Yes. Reels are the main format, with an optional cover image uploaded alongside the video." },
      { q: "Can I schedule a photo post?", a: "Yes. A single photo schedules with the same caption and timing options as a Reel." },
      SALES_FAQ("Instagram"),
      AGENT_FAQ("Instagram"),
      PLAN_FAQ,
    ],
  },
  x: {
    key: "x",
    slug: slug("x"),
    name: "X",
    color: "#0A0A0C",
    card: "Posts and threads with up to 4 images",
    seo: {
      title: "X (Twitter) Scheduler — Schedule Posts & Threads | ViewsMax",
      description:
        "Schedule X posts and threads with up to 4 images. Split a thread with ---, count characters the way X does, cross-post to Threads and Bluesky, and track the sales each post drives.",
      keywords:
        "x scheduler, twitter scheduler, schedule tweets, schedule x posts, twitter thread scheduler, x thread scheduler, twitter auto post, schedule twitter threads",
    },
    h1: "Schedule X posts and threads, counted the way X counts",
    subhead:
      "Write a post or a whole thread, attach up to four images, and schedule it. ViewsMax weighs links and emoji the way X does, so nothing gets cut at publish time.",
    formats: ["Text", "Up to 4 images", "Thread"],
    captionLimit: 280,
    captionNote: "per post; links count as 23",
    connect: "X login, direct publishing",
    options: ["Threads split on ---", "Up to 25 posts per thread", "Link-aware character count"],
    steps: [
      { title: "Connect X", body: "Log in with X. Connect several accounts if you run more than one." },
      { title: "Write the post or thread", body: "Type the post, or separate thread segments with a line that is just ---. Each segment is checked against the 280 limit with links counted as 23 characters." },
      { title: "Schedule and track", body: "Pick the time. Any link in the post can be a tracked link, so you see which post sent the clicks and the revenue." },
    ],
    features: [
      { title: "Threads, not just posts", body: "Up to 25 connected posts from one draft. Write it as one piece and split it with ---." },
      { title: "The real character count", body: "URLs count as 23 and wide characters as 2, matching X's rules, so a draft that fits here fits there." },
      { title: "Images per post", body: "Attach up to four images to a post or to each segment of a thread." },
      { title: "Cross-post short text", body: "The same words go to Threads and Bluesky, each trimmed to its own limit." },
    ],
    faq: [
      { q: "Can ViewsMax schedule a thread on X?", a: "Yes. Put --- on its own line between segments and ViewsMax posts them as a connected thread, up to 25 segments." },
      { q: "How are characters counted?", a: "Like X: every link counts as 23 characters and characters outside the Latin ranges, such as emoji and CJK, count as 2. The composer shows the weighted count as you type." },
      { q: "Can I attach video to an X post?", a: "Not yet. Posts can carry text and up to four images." },
      SALES_FAQ("X"),
      AGENT_FAQ("X"),
      PLAN_FAQ,
    ],
  },
  linkedin: {
    key: "linkedin",
    slug: slug("linkedin"),
    name: "LinkedIn",
    color: "#0A66C2",
    card: "Posts with an image and an automatic first comment",
    seo: {
      title: "LinkedIn Scheduler — Schedule Posts with a First Comment | ViewsMax",
      description:
        "Schedule LinkedIn posts with an image and an automatic first comment for your link. 3,000-character posts, cross-posting to X and Threads, and sales tracking per post.",
      keywords:
        "linkedin scheduler, schedule linkedin posts, linkedin post scheduler, linkedin auto post, linkedin first comment, linkedin social media scheduler, schedule linkedin content",
    },
    h1: "Schedule LinkedIn posts with the link in the first comment",
    subhead:
      "Write up to 3,000 characters, attach an image and let ViewsMax post the link as the first comment right after publishing, where LinkedIn readers expect it.",
    formats: ["Text", "One image"],
    captionLimit: 3000,
    connect: "LinkedIn login",
    options: ["Automatic first comment", "Image attachment", "Per-post tracked links"],
    steps: [
      { title: "Connect LinkedIn", body: "Log in with LinkedIn and connect the profile you post from." },
      { title: "Write and schedule", body: "Draft the post, add an image if you want one, and put your link in the first-comment field. Pick a time on the calendar." },
      { title: "Measure the outcome", body: "The first comment goes out seconds after the post. Its tracked link reports the clicks, leads and sales back to that post." },
    ],
    features: [
      { title: "First comment, automatically", body: "Keep the post clean and let ViewsMax add the link as a comment immediately after publishing." },
      { title: "Long-form friendly", body: "The 3,000-character limit is counted as you write, so long posts never get cut." },
      { title: "One image per post", body: "Attach a chart, a carousel cover or a photo; it uploads with the post." },
      { title: "Know what converted", body: "Tracked links in the comment tell you which LinkedIn post booked the call or made the sale." },
    ],
    faq: [
      { q: "Can ViewsMax post the link as the first comment?", a: "Yes. Write the comment while scheduling and it is posted right after the post publishes." },
      { q: "Does LinkedIn scheduling support video?", a: "Not yet. Posts can carry text and one image; a video target is refused with a clear note rather than silently dropped." },
      { q: "Can I schedule to a company page?", a: "Posts go to the LinkedIn profile you connect. Connect the account that owns the page to post as it." },
      SALES_FAQ("LinkedIn"),
      AGENT_FAQ("LinkedIn"),
      PLAN_FAQ,
    ],
  },
  threads: {
    key: "threads",
    slug: slug("threads"),
    name: "Threads",
    color: "#0A0A0C",
    card: "Text, photo or video posts, 500 characters",
    seo: {
      title: "Threads Scheduler — Schedule Threads Posts | ViewsMax",
      description:
        "Schedule posts to Threads with text, one image or one video. 500-character counting that matches Meta, cross-posting from X and Instagram, and sales tracking per post.",
      keywords:
        "threads scheduler, schedule threads posts, threads post scheduler, threads auto post, meta threads scheduler, schedule posts to threads, threads social media scheduler",
    },
    h1: "Schedule Threads posts alongside the rest of your channels",
    subhead:
      "Text, a photo or a video, up to 500 characters counted the way Meta counts them. Schedule it with the matching X and Bluesky posts and track what it earned.",
    formats: ["Text", "One image", "One video"],
    captionLimit: 500,
    captionNote: "counted in UTF-8 bytes, like Meta",
    connect: "Threads login through your Instagram account",
    options: ["Text, image or video", "Meta-accurate character count", "Per-post tracked links"],
    steps: [
      { title: "Connect Threads", body: "Log in with the Instagram account behind your Threads profile and allow posting." },
      { title: "Compose", body: "Write the post and add one image or one video. The counter uses Meta's byte-based rule, so emoji and accents are weighed correctly." },
      { title: "Schedule and attribute", body: "Pick the time. Tracked links report clicks and sales back to the post." },
    ],
    features: [
      { title: "Counted like Meta", body: "Threads measures the 500 limit in bytes, so an emoji costs more than one. ViewsMax counts the same way." },
      { title: "Image or video", body: "Attach one image or one video to a post from the same composer you use everywhere else." },
      { title: "Short-text cross-posting", body: "Write once and send to X, Bluesky and Threads with each version trimmed to its limit." },
      { title: "Sales per post", body: "See which Threads post sent the buyers, not just the likes." },
    ],
    faq: [
      { q: "Why does my Threads count differ from other apps?", a: "Meta counts the 500 limit in UTF-8 bytes, so emoji, curly quotes and accented letters cost more than one. ViewsMax uses the same rule so your post is never rejected at publish time." },
      { q: "Can I schedule video to Threads?", a: "Yes. A post can carry one video or one image." },
      { q: "Do I need an Instagram account?", a: "Yes. Threads profiles belong to an Instagram account, and that account is what you connect." },
      SALES_FAQ("Threads"),
      AGENT_FAQ("Threads"),
      PLAN_FAQ,
    ],
  },
  bluesky: {
    key: "bluesky",
    slug: slug("bluesky"),
    name: "Bluesky",
    color: "#0085FF",
    card: "Posts with up to 4 images, 300 graphemes",
    seo: {
      title: "Bluesky Scheduler — Schedule Bluesky Posts | ViewsMax",
      description:
        "Schedule Bluesky posts with up to 4 images. Connects with an app password, counts the 300-grapheme limit correctly, cross-posts from X and Threads, and tracks sales per post.",
      keywords:
        "bluesky scheduler, schedule bluesky posts, bluesky post scheduler, bluesky auto post, bluesky app password, schedule posts to bluesky, bluesky social media scheduler",
    },
    h1: "Schedule Bluesky posts with your other short-form channels",
    subhead:
      "Connect with an app password, write up to 300 graphemes, attach up to four images and schedule. Pair it with X and Threads and see which network actually converts.",
    formats: ["Text", "Up to 4 images"],
    captionLimit: 300,
    captionNote: "graphemes: an emoji sequence counts once",
    connect: "Handle plus an app password (no OAuth)",
    options: ["Up to 4 images", "Grapheme-accurate count", "Per-post tracked links"],
    steps: [
      { title: "Create an app password", body: "In Bluesky, open Settings, then App Passwords, and create one for ViewsMax. Your main password is never used." },
      { title: "Connect and compose", body: "Enter your handle and the app password. Write the post, add up to four images, and pick a time." },
      { title: "Publish and track", body: "The post goes out on schedule. Tracked links show the clicks and sales it produced." },
    ],
    features: [
      { title: "App password, not your login", body: "Bluesky connects with a revocable app password you can delete at any time." },
      { title: "Counted in graphemes", body: "A flag or a family emoji counts once, the way Bluesky counts it, so the 300 limit is accurate." },
      { title: "Four images per post", body: "Attach up to four images from the same composer." },
      { title: "Compare networks", body: "Post the same text to X, Threads and Bluesky and see which one sends paying traffic." },
    ],
    faq: [
      { q: "How does ViewsMax connect to Bluesky?", a: "With your handle and an app password created in Bluesky's settings. Revoke it there at any time to disconnect." },
      { q: "Can I post video to Bluesky?", a: "Not yet. Posts can carry text and up to four images." },
      { q: "How is the 300 limit counted?", a: "In graphemes, matching Bluesky: a multi-codepoint emoji or a letter with a combining mark counts as one." },
      SALES_FAQ("Bluesky"),
      AGENT_FAQ("Bluesky"),
      PLAN_FAQ,
    ],
  },
  pinterest: {
    key: "pinterest",
    slug: slug("pinterest"),
    name: "Pinterest",
    color: "#E60023",
    card: "Image and video Pins to any board",
    seo: {
      title: "Pinterest Scheduler — Schedule Pins to Any Board | ViewsMax",
      description:
        "Schedule Pinterest image and video Pins to the board you choose, with a title, destination link and alt text. Carousel Pins up to 5 images, cross-posting from Instagram, and sales tracked per Pin.",
      keywords:
        "pinterest scheduler, schedule pins, pinterest pin scheduler, schedule pinterest posts, pinterest auto post, pinterest video pin scheduler, pinterest board scheduler, pinterest marketing tool",
    },
    h1: "Schedule Pinterest Pins to the right board, with the link attached",
    subhead:
      "Image Pins, video Pins and carousels, each with a title, destination link and alt text. Pick the board, pick the time, and ViewsMax publishes while the tracked link reports every click and sale.",
    formats: ["Image Pin", "Video Pin", "Carousel of 2 to 5 images"],
    captionLimit: 800,
    connect: "Pinterest login, direct publishing",
    options: ["Board picker per account", "Title up to 100 characters", "Destination link", "Alt text up to 500 characters"],
    steps: [
      { title: "Connect Pinterest", body: "Log in with Pinterest. ViewsMax loads your boards; connect several accounts if you manage more than one." },
      { title: "Build the Pin", body: "Add an image, a video or two to five images for a carousel. Write the title, description, destination link and alt text, then choose the board." },
      { title: "Schedule and track", body: "Pick the time on the calendar. The destination link can be a tracked link, so every click and sale is attributed to the Pin." },
    ],
    features: [
      { title: "Boards, not guesses", body: "Choose the board for every Pin. An account with a single board is filled in for you; nothing else is assumed." },
      { title: "Video Pins", body: "Upload a video and ViewsMax handles Pinterest's upload, processing wait and cover image before the Pin is created." },
      { title: "Carousel Pins", body: "Two to five images in one Pin, straight from the composer." },
      { title: "Links that report back", body: "The Pin's destination link can be tracked, so you know which Pin brought the purchases, not just the saves." },
    ],
    faq: [
      { q: "Can ViewsMax schedule video Pins?", a: "Yes. Upload the video and ViewsMax registers it with Pinterest, waits for processing and publishes the Pin with a cover image." },
      { q: "Can I choose the board?", a: "Yes. Every Pin needs a board and you pick it when scheduling. If the account has only one board it is selected for you." },
      { q: "Can I schedule carousel Pins?", a: "Yes. Add two to five images to one post and it publishes as a carousel Pin." },
      { q: "Can a Pin be text only?", a: "No. Pinterest requires an image or a video on every Pin, so the composer asks for media before you can schedule." },
      SALES_FAQ("Pinterest"),
      AGENT_FAQ("Pinterest"),
      PLAN_FAQ,
    ],
  },
};

export const CHANNEL_LIST: ChannelConfig[] = Object.values(CHANNELS);

export const CHANNELS_HUB_SEO = {
  title: "Social Media Channels You Can Schedule With ViewsMax",
  description:
    "Schedule and auto-post to YouTube, TikTok, Instagram, X, LinkedIn, Threads, Bluesky and Pinterest from one calendar, and see which post on which channel made the sale.",
  keywords:
    "social media scheduler channels, schedule posts youtube tiktok instagram, multi channel social media scheduler, cross post scheduler, pinterest youtube tiktok scheduler",
};

const ORIGIN = "https://viewsmax.com";

const breadcrumbs = (items: { name: string; path: string }[]) => ({
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  itemListElement: items.map((it, i) => ({ "@type": "ListItem", position: i + 1, name: it.name, item: `${ORIGIN}${it.path}` })),
});

/** JSON-LD for one channel page: the page, its FAQ and its breadcrumb trail. */
export function buildChannelJsonLd(cfg: ChannelConfig): Record<string, unknown>[] {
  return [
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      name: cfg.seo.title,
      description: cfg.seo.description,
      url: `${ORIGIN}${cfg.slug}`,
      isPartOf: { "@type": "WebSite", name: "ViewsMax", url: `${ORIGIN}/` },
      about: { "@type": "SoftwareApplication", name: "ViewsMax", applicationCategory: "BusinessApplication", operatingSystem: "Web", url: `${ORIGIN}/` },
    },
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      mainEntity: cfg.faq.map((f) => ({ "@type": "Question", name: f.q, acceptedAnswer: { "@type": "Answer", text: f.a } })),
    },
    breadcrumbs([{ name: "Channels", path: CHANNELS_PATH }, { name: cfg.name, path: cfg.slug }]),
  ];
}

/** JSON-LD for the /channels hub: the list of channel pages. */
export function buildChannelsHubJsonLd(): Record<string, unknown>[] {
  return [
    {
      "@context": "https://schema.org",
      "@type": "ItemList",
      name: CHANNELS_HUB_SEO.title,
      url: `${ORIGIN}${CHANNELS_PATH}`,
      itemListElement: CHANNEL_LIST.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, url: `${ORIGIN}${c.slug}` })),
    },
    breadcrumbs([{ name: "Channels", path: CHANNELS_PATH }]),
  ];
}
