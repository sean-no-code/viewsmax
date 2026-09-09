// ViewsMax API Service
// Handles all communication with api.viewsmax.ai backend

import { isMockApi, mockApi } from "@/lib/mock-api";
import {
  putWithProgress,
  uploadMultipartParts,
  type DirectUploadSession,
  type UploadedPart,
} from "@/lib/direct-upload";

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL;

// Rewardful affiliate tracking (loaded in index.html). When the visitor came
// through an affiliate link, Rewardful exposes their referral UUID here — we
// forward it with checkout/subscribe calls so the backend can attribute the
// conversion in Stripe.
declare global {
  interface Window {
    Rewardful?: { referral?: string };
  }
}

export function rewardfulReferral(): string | null {
  return (typeof window !== "undefined" && window.Rewardful?.referral) || null;
}

// App-served media (Laravel's `/storage/{path}` route) is stored as an absolute
// URL built from the backend host. In dev that host drifts (e.g. ngrok tunnels
// rotate on restart), so a URL saved by an old tunnel points at a dead host and
// the <video>/<img> fails to load. Rebase any `/storage/...` URL onto the API
// origin the frontend is actually talking to. S3/CDN URLs (prod) don't use the
// `/storage` prefix, so they pass through untouched.
export function resolveMediaUrl(url?: string | null): string | undefined {
  if (!url) return url ?? undefined;
  if (!API_BASE_URL) return url;
  const at = url.indexOf("/storage/");
  if (at === -1) return url;
  try {
    return new URL(url.slice(at), API_BASE_URL).href;
  } catch {
    return url;
  }
}

interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  /** Machine-readable failure tag, when the endpoint sends one. */
  code?: string;
  message?: string;
  user_credits?: number;
  count?: number;
  status?: number;
}

/**
 * A pricing tier returned by GET /api/plans. NULL limits mean "unlimited".
 */
export interface PlanTier {
  id: number;
  name: string;
  display_name: string;
  description: string | null;
  price: string;
  currency: string;
  billing_cycle: string | null;
  features: string[] | null;
  max_channels: number | null;
  max_offers: number | null;
  max_posts_per_month: number | null;
  stripe_price_id: string | null;
  is_active: boolean;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  next_page_url?: string | null;
  prev_page_url?: string | null;
}

interface AuthSession {
  token: string;
  token_type: string;
}

interface User {
  id: string;
  name: string;
  email: string;
  created_at?: string;
  updated_at?: string;
  email_verified_at?: string | null;
  onboarding_completed_at?: string | null;
  connections_count?: number;
  has_active_subscription?: boolean;
}

export type OAuthProvider = 'youtube' | 'tiktok' | 'instagram';

export interface Connection {
  id: number;
  provider: OAuthProvider;
  account_name: string;
  account_id: string;
  avatar_url?: string | null;
  connected_at?: string;
}

// Account connected via the newer multi-platform `/api/social` system
// (SocialAccount on the backend). Used for platforms not in the legacy
// Connection store — currently X (Twitter).
export interface SocialAccount {
  id: number;
  platform: string;           // 'x', 'tiktok', 'youtube', ...
  platform_label: string;
  platform_account_id?: string | null;
  name?: string | null;
  username?: string | null;
  avatar_url?: string | null;
  profile_url?: string | null;
  status: string;             // 'connected' | 'needs_reauth' | ...
  token_valid: boolean;
  last_error?: string | null; // why the account needs reconnecting, when it does
  connected_at?: string;
}

/** An account that can't publish until the user re-runs the OAuth connect. */
export function accountNeedsReconnect(acct: SocialAccount): boolean {
  return acct.status !== "connected" || acct.token_valid === false;
}

// A named group of connected accounts (both stores). Selecting a brand in the
// composer auto-selects every account in it.
export interface Brand {
  id: number;
  name: string;
  social_accounts: SocialAccount[];
  connections: Connection[];
  created_at?: string;
}

export interface BrandPayload {
  name: string;
  social_account_ids?: number[];
  connection_ids?: number[];
}

// One row from the X @mention typeahead (`/api/social/x/users/search`).
export interface XUserSuggestion {
  id: string;
  username: string;
  name: string;
  avatar_url?: string | null;
  verified?: boolean;
}

export interface XUserSearchResult {
  users: XUserSuggestion[];
  // 'search' = live prefix search; 'lookup' = exact-handle only (the app's X
  // API tier doesn't allow search — degraded mode).
  mode: "search" | "lookup";
  degraded: boolean;
}

// One row of the MCP audit log (`/api/user/mcp-activity`) — a tool call an
// AI assistant made on the user's account.
export interface McpActivityItem {
  id: number;
  tool: string;
  arguments: Record<string, unknown> | null;
  is_error: boolean;
  auth_mode: 'key' | 'oauth' | null;
  created_at: string;
}

// One entry from `/api/social/platforms` — the catalog the connect screen uses
// to know which platforms are enabled / have credentials configured.
export interface SocialPlatformInfo {
  platform: string;
  label: string;
  enabled: boolean;
  configured: boolean;
  uses_oauth: boolean;
}

// Per-user preference toggles from `/api/user/settings`.
export interface UserSettings {
  notify_post_failures: boolean;
  // UI language (en/es/de/fr/pt); null = browser default.
  locale?: string | null;
}

// Boosts (X-only v1): per-account like-threshold automations.
export interface BoostSetting {
  id: number;
  user_id: number;
  social_account_id: number;
  feature: "auto_repost" | "auto_promo";
  enabled: boolean;
  likes_threshold: number;
  promo_text?: string | null;
  social_account?: SocialAccount;
}

export interface BoostCheck {
  id: number;
  post_target_id: number;
  boost_setting_id: number;
  feature: "auto_repost" | "auto_promo";
  runs_completed: number;
  next_run_at: string;
  status: "pending" | "triggered" | "exhausted" | "failed";
  result_remote_id?: string | null;
  error?: string | null;
  updated_at?: string;
  target?: { id: number; platform: string; platform_post_id?: string | null; post?: { id: number; caption: string | null } | null } | null;
  setting?: BoostSetting | null;
}

// Automations — Instagram comment / story-reply / DM auto-responders.
export type AutomationTrigger = "comment" | "story_reply" | "dm";
export type AutomationStatus = "live" | "stopped";
export type AutomationKeywordMode = "any" | "contains" | "exact";
export type AutomationPostMatch = "specific" | "any";

export interface AutomationPost {
  id: string;
  media_type?: string | null;
  media_product_type?: string | null;
  thumbnail_url?: string | null;
  permalink?: string | null;
  caption?: string | null;
  timestamp?: string | null;
}

export interface AutomationAccount {
  id: number;
  platform: string;
  username?: string | null;
  name?: string | null;
  avatar_url?: string | null;
  status: string;
  token_valid: boolean;
  has_messaging_scopes: boolean;
  missing_scopes: string[];
  can_automate: boolean;
  webhook_subscribed_at?: string | null;
}

export interface AutomationStats {
  runs: number;
  dms_sent: number;
  clicked: number;
  ctr: number | null;
}

export interface AutomationRun {
  id: number;
  automation_id: number;
  trigger_type: AutomationTrigger;
  event_id: string;
  sender_id: string;
  sender_username?: string | null;
  media_id?: string | null;
  inbound_text?: string | null;
  matched_keyword?: string | null;
  status: "pending" | "completed" | "partial" | "failed" | "skipped";
  reply_status?: "sent" | "failed" | "skipped" | null;
  dm_status?: "sent" | "failed" | "skipped" | null;
  clicked_at?: string | null;
  error?: string | null;
  executed_at?: string | null;
  created_at: string;
}

export interface Automation {
  id: number;
  user_id: number;
  social_account_id: number;
  platform: string;
  name: string;
  trigger_type: AutomationTrigger;
  status: AutomationStatus;
  post_match: AutomationPostMatch | null;
  posts: AutomationPost[] | null;
  include_replies: boolean;
  keyword_mode: AutomationKeywordMode;
  keywords: string[] | null;
  cooldown_hours: number;
  reply_enabled: boolean;
  reply_texts: string[] | null;
  dm_text: string;
  dm_subtitle?: string | null;
  dm_image_url?: string | null;
  dm_button_label?: string | null;
  dm_button_url?: string | null;
  last_run_at?: string | null;
  last_error?: string | null;
  created_at: string;
  updated_at: string;
  trigger_summary: string;
  stats: AutomationStats;
  needs_reconnect: boolean;
  social_account: AutomationAccount | null;
  recent_runs?: AutomationRun[];
}

/** Editable fields (create / update). */
export interface AutomationPayload {
  social_account_id?: number;
  name?: string | null;
  trigger_type?: AutomationTrigger;
  post_match?: AutomationPostMatch | null;
  posts?: AutomationPost[] | null;
  include_replies?: boolean;
  keyword_mode?: AutomationKeywordMode;
  keywords?: string[] | null;
  cooldown_hours?: number;
  reply_enabled?: boolean;
  reply_texts?: string[] | null;
  dm_text?: string;
  dm_subtitle?: string | null;
  dm_image_url?: string | null;
  dm_button_label?: string | null;
  dm_button_url?: string | null;
}

export interface AutomationListResponse {
  automations: Automation[];
  enabled: boolean;
  limit: number | null;
  used: number;
}

export interface AutomationAccountsResponse {
  accounts: AutomationAccount[];
  enabled: boolean;
  limit: number | null;
  used: number;
}

export interface InstagramMediaPage {
  items: AutomationPost[];
  next_cursor: string | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface FeatureRequest {
  id: number;
  title: string;
  description: string;
  category?: string | null;
  upvotes_count: number;
  has_upvoted: boolean;
  status?: string | null;
  created_at: string;
}

interface Project {
  id: string;
  description: string;
  script?: Script;
  created_at: string;
  updated_at?: string;
}

interface Thumbnail {
  id: string;
  type?: 'generated' | 'copied';
  description?: string;
  image_url?: string;
  file_location?: string;
  combined_prompts?: string;
  visualizable_scene?: string;
  status?: 'pending' | 'processing' | 'processed' | 'completed' | 'failed';
  created_at: string;
  updated_at: string;
  parents?: Thumbnail[];
  // Fields used by copied thumbnails
  method?: 'generate' | 'head_swap';
  quality?: string;
  prompt?: string;
  base_image_path?: string;
  base_image_url?: string;
  reference_image_path?: string;
  result_image_path?: string;
  source_image_url?: string;
  result_image_paths?: string[];
  result_image_url?: string;
  image_path?: string;
  result_image_count?: number;
  error_message?: string;
}

// CopyThumbnail is now unified into the Thumbnail interface with type='copied'
type CopyThumbnail = Thumbnail;

interface Title {
  id: string;
  title?: string;
  description?: string;
  enhanced_title?: string;
  original_title?: string;
  virality_score?: number;
  generated?: boolean;
  status?: 'pending' | 'processed' | 'completed' | 'failed';
  created_at: string;
  updated_at: string;
}

interface Script {
  id: number;
  user_id?: number;
  project_id?: number;
  text?: string | null;
  length?: number | null;
  title?: string;
  prompt?: string;
  content?: string;
  description?: string;
  status?: 'pending' | 'processing' | 'completed' | 'failed';
  error_message?: string;
  created_at: string;
  updated_at?: string;
  project?: Project;
  word_count?: number;
  reading_time?: number;
  research?: ScriptResearch;
  components?: LibraryComponent[];
  videos?: { id?: number; youtube_video_id: string; title?: string; }[];
  stage?: number;
}

interface ScriptResearch {
  id: number;
  script_id: number;
  body: string | null;
  references: { title: string; url: string }[] | null;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  created_at: string;
  updated_at: string;
}

interface LibraryComponentTag {
  id: number;
  name: string;
}

interface LibraryComponent {
  id: number;
  user_id: number;
  type: 'hook' | 'cta' | 'outro' | 'transition' | 'story';
  title: string;
  body: string;
  tags?: LibraryComponentTag[];
  created_at: string;
  updated_at: string;
}

export interface ScriptVersion {
  id: number;
  script_id: number;
  user_id: number;
  content: string;
  content_preview: string;
  created_at: string;
}

interface Model {
  id: number;
  name: string;
  thumbnail_image?: string;
  thumbnail?: {
    id: number;
    name: string;
    original_name: string;
    url: string;
    mime_type: string;
    file_size: number;
    human_file_size: string;
    category: {
      id: number;
      name: string;
    };
    created_at: string;
  };
  file_upload_count?: number;
  status?: 'pending' | 'processing' | 'completed' | 'failed';
  error_message?: string | null;
  replicates_prediction_id?: string;
  huggingface_model_id?: string;
  huggingface_model_url?: string;
  model_name?: string;
  trigger_word?: string;
  user_id?: number;
  user?: {
    id: number;
    name: string | null;
    email: string;
  };
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface TrackingLink {
  id: number;
  tracking_event_id: number;
  video_id: number | null;
  youtube_video_id?: string | null; // Added field
  beehiiv_post_id?: string | null; // reach source: Beehiiv post
  x_post_id?: string | null; // pinned X post (published via the app)
  video?: any; // Added video relationship
  placement: string;
  // Multi-platform links: every platform this link is placed on (placement =
  // the first entry, kept for back-compat).
  placements?: string[] | null;
  // Distinct people behind the clicks.
  visitors_count?: number;
  // Where clicks actually came from (top referrer hosts + per-platform mix).
  top_referrers?: ReferrerStat[];
  platform_breakdown?: Record<string, number>;
  // The linked content's TOTAL view count (the `views` field is the delta
  // gained since the link was created, which powers the reach conv. rate).
  total_views?: number;
  name: string | null;
  content_title?: string | null; // linked content's title snapshot (video/newsletter/tweet)
  parameter_id: string;
  initial_view_count?: number;
  current_view_count?: number | null; // freshly-synced reach (views now)
  reach_synced_at?: string | null;
  description?: string; // Added description field
  created_at: string;
  updated_at: string;
  clicks_count?: number;
  views?: number; // views_since = current - initial (reach since link created)
  view_conversion_rate?: number | null; // conversions / views_since (%), null if no reach data
  // Per-link aggregates injected by the index() endpoint:
  sales_amount?: number;
  conversions_count?: number;
  calls_booked_count?: number;
  email_signups_count?: number;
  last_click_at?: string | null;
  clicks_spark?: number[];
}

export interface TrackingGoal {
  id: number;
  tracking_event_id: number;
  event_type: string;
  conversion_url: string;
  conversion_value?: number;
  created_at: string;
  updated_at: string;
}

// A predefined conversion goal/event type (seeded reference list, served by
// GET /api/goal-types). `value` is the event_type stored on a goal; `label` is
// its display name. The list is DB-owned, not hardcoded in the SPA.
export interface GoalType {
  value: string;
  label: string;
  sort_order?: number;
}

export interface TrackingEvent {
  id: number;
  user_id: number;
  name: string | null;
  offer_url: string;
  conversion_value: string; // Decimal from DB comes as string usually
  goals?: TrackingGoal[]; // New Relationship
  created_at: string;
  updated_at: string;
  links?: TrackingLink[];
  links_count?: number;
  conversions_count?: number;
  total_video_views?: number; // Added field (views_since across the event's videos)
  view_conversion_rate?: number | null; // conversions / total_video_views (%), null if no reach
  // Aggregates injected by the index() endpoint:
  total_clicks?: number;
  sales_amount?: number;
  calls_booked_count?: number;
  email_signups_count?: number;
}

export interface TrackingTimeseriesPoint {
  date: string;
  clicks: number;
  // Distinct people (visitor ids) behind the day's clicks.
  visitors?: number;
  views: number;
  revenue: number;
  conversions: number;
}

// One referrer source rolled up from a link's clicks.
export interface ReferrerStat {
  host: string;
  url: string;
  count: number;
}

// GA-style acquisition rows from `/api/tracking-events/sources`.
export interface TrafficSource {
  source: string;
  visitors: number;
  views: number;
}

export interface TrafficReferrer {
  referrer: string;
  visitors: number;
  views: number;
}

export interface TrafficSourcesData {
  // "pageviews" once the site beacons page loads; "clicks" is the fallback
  // derived from tracking-link clicks.
  basis: "pageviews" | "clicks";
  sources: TrafficSource[];
  referrers: TrafficReferrer[];
}

// Audience Growth — per-platform follower series from /api/analytics/audience.
export interface AudiencePoint {
  date: string;
  followers: number;
}

export interface AudiencePlatformSeries {
  platform: string;
  account_id: number;
  account_name: string | null;
  // Whether the platform can supply a follower count under current config;
  // false → the UI shows a "not supported" state.
  supported: boolean;
  current: number | null;
  delta: number;
  points: AudiencePoint[];
}

export interface AudienceGrowthData {
  platforms: AudiencePlatformSeries[];
}

// One engagement-ranked post from /api/analytics/posts.
export interface TopPost {
  platform: string;
  remote_post_id: string;
  url: string | null;
  caption: string | null;
  published_at: string | null;
  likes: number;
  comments: number;
  shares: number;
  views: number;
  engagement_total: number;
  engagement_delta: number;
}

export interface PostMediaItem {
  type: "image" | "video";
  g?: string; // gradient placeholder (until real upload)
  url?: string;
  path?: string;
  disk?: string;
  mime?: string;
  bytes?: number;
}

export interface TikTokOptions {
  privacy_level: string; // SELF_ONLY | PUBLIC_TO_EVERYONE | MUTUAL_FOLLOW_FRIENDS | FOLLOWER_OF_CREATOR
  disable_comment?: boolean;
  disable_duet?: boolean;
  disable_stitch?: boolean;
  // Commercial / branded-content disclosure (required by TikTok UX guidelines)
  disclose_commercial?: boolean;
  your_brand?: boolean;
  branded_content?: boolean;
  // Photo/slideshow only: ask TikTok to auto-add its recommended background
  // music. Off by default; has no effect on video posts.
  auto_add_music?: boolean;
}

export interface YouTubeOptions {
  // Visibility the uploaded video is published with.
  privacy_status: "public" | "unlisted" | "private";
}

export interface LinkedInOptions {
  // Posted as a comment right after publishing (the usual spot for links).
  first_comment?: string;
}

// An X post published through the app — pickable as a tracking-link source.
export interface XPublishedPost {
  id: string;         // tweet id
  text: string;       // first-tweet excerpt
  url: string;
  posted_at: string | null;
}

export interface TikTokCreatorInfo {
  creator_nickname?: string;
  creator_username?: string;
  creator_avatar_url?: string | null;
  privacy_level_options: string[];
  comment_disabled: boolean;
  duet_disabled: boolean;
  stitch_disabled: boolean;
  max_video_post_duration_sec: number;
}

export interface PostTarget {
  id: number;
  post_id: number;
  platform: string;
  // The exact connected account this target publishes through (multi-account
  // platforms). Null on legacy targets — those publish via the newest
  // connected account of the platform.
  social_account_id?: number | null;
  social_account?: {
    id: number;
    platform: string;
    name?: string | null;
    username?: string | null;
    avatar_url?: string | null;
  } | null;
  caption_override: string | null;
  status?: "pending" | "publishing" | "published" | "failed";
  platform_post_id?: string | null;
  error?: string | null;
  published_at?: string | null;
  options?: Record<string, unknown> | null;
  // How the platform accepted the post (admin list payload; extracted from
  // meta.mode). "inbox" = TikTok inbox upload — the video sits in the creator's
  // TikTok app awaiting manual finish, and no caption could be attached.
  delivery_mode?: "direct" | "inbox" | null;
  // Audit trail of the platform exchange (publish_id, mode, raw request/response
  // log, last_status). Only present on the admin detail (show) endpoint.
  meta?: {
    publish_id?: string;
    mode?: "direct" | "inbox";
    last_status?: Record<string, unknown>;
    log?: TikTokLogEntry[];
    // Live URL of the published post, when the platform returns one.
    url?: string; // Instagram / X / LinkedIn / Threads
    video_url?: string; // YouTube
    remote_post_url?: string; // generic social path
    [k: string]: unknown;
  } | null;
}

export interface TikTokLogEntry {
  step: string;
  status: number;
  response?: unknown;
  at?: string;
  range?: string;
}

export interface Post {
  id: number;
  user_id: number;
  // The brand selected in the composer when saved (informational).
  brand_id?: number | null;
  caption: string | null;
  media: PostMediaItem[] | null;
  status: "draft" | "scheduled" | "posted";
  scheduled_at: string | null;
  // URLs in captions/comments are swapped for tracked /l/{slug} links at save.
  shorten_links?: boolean;
  targets?: PostTarget[];
  comments?: PostComment[];
  created_at: string;
  updated_at: string;
}

export interface AdminPost extends Post {
  user?: { id: number; name: string; email: string } | null;
}

export interface AdminPostStats {
  published: number;
  failed: number;
  publishing: number;
  pending: number;
  posts: number;
}

export interface AdminOfferRow {
  id: number;
  name: string | null;
  url: string;
  user: { id: number; name: string | null; email: string } | null;
  links_count: number;
  views: number;
  clicks: number;
  conversions: number;
  revenue: number;
  view_conversion_rate: number | null;
  click_conversion_rate: number | null;
  created_at: string | null;
}

export interface AdminLinkRow {
  id: number;
  name: string | null;
  placement: string;
  parameter_id: string;
  offer: { id: number; name: string | null; url: string } | null;
  user: { id: number; name: string | null; email: string } | null;
  views: number;
  clicks: number;
  conversions: number;
  revenue: number;
  view_conversion_rate: number | null;
  click_conversion_rate: number | null;
  created_at: string | null;
}

export interface BeehiivConnectionStatus {
  connected: boolean;
  status?: string;
  publication_id?: string | null;
  publication_name?: string | null;
  key_hint?: string | null;
  last_validated_at?: string | null;
  last_error?: string | null;
  publications?: { id: string; name: string | null }[];
}

export interface AdminReconcileReport {
  stuck_targets: number;
  requeueable: number;
  already_on_platform: number;
  overdue_scheduled: number;
  affected_post_ids: number[];
}

export interface AdminRequeueResult {
  post_id: number;
  status: string;
  requeued: number;
  healed: number;
  skipped: number;
  targets: PostTarget[];
}

export interface AdminUser {
  id: number;
  name: string | null;
  email: string;
  created_at: string | null;
  last_login_at: string | null;
  last_login_country: string | null;
  last_login_country_code: string | null;
  has_card: boolean;
  subscribed: boolean;
  plan: string | null;
  onboarded: boolean;
  posts_count: number;
  posts_posted_count: number;
  // Sum of both connection stores (legacy connections + social accounts).
  accounts_count: number;
  offers_count: number;
  // When they first created a post or an offer — null if never.
  first_action_at: string | null;
  // cancelled_at of the LATEST subscription row — null if not cancelled
  // (a re-subscribe clears it).
  subscription_cancelled_at: string | null;
}

export interface AdminUsersPage {
  users: AdminUser[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
}

// One connected account, normalized across both stores ('social-*' = social_accounts,
// 'legacy-*' = the old connections table).
export interface AdminUserAccount {
  id: string;
  platform: string;
  name: string | null;
  username: string | null;
  avatar_url: string | null;
  // Best public URL for the account (stored profile_url or built from
  // platform + username / account id); null when nothing linkable exists.
  url: string | null;
  status: string | null;
  connected_at: string | null;
}

export interface AdminRole {
  id: number;
  name: string;
  display_name: string | null;
}

export interface AdminUserDetail {
  user: { id: number; name: string | null; email: string; created_at: string | null; roles: string[] };
  roles: AdminRole[];
}

export interface AdminUserStatsPoint {
  date: string;
  signups: number;
  added_card: number;
  subscribed: number;
}

export interface AdminUserStats {
  from: string;
  to: string;
  totals: { signups: number; added_card: number; subscribed: number };
  series: AdminUserStatsPoint[];
}

export type AdminUserSort =
  | "name" | "email" | "created_at" | "has_card" | "plan" | "last_login_at"
  | "posts_count" | "accounts_count" | "offers_count" | "first_action_at" | "subscription_cancelled_at";

export interface AdminUsersFilters {
  from?: string;
  to?: string;
  name?: string;
  email?: string;
  card?: string[]; // subset of ["yes","no"]
  plan?: string[];
  cancelled?: string[]; // subset of ["yes","no"]
  sort?: AdminUserSort;
  dir?: "asc" | "desc";
  page?: number;
  per_page?: number;
}

// One composed follow-up comment: posted to each supporting target after it
// publishes (X reply threads, LinkedIn/IG comments, Threads replies).
export interface PostComment {
  id: number;
  post_id: number;
  position: number;
  body: string;
  delay_seconds: number;
  target_comments?: PostTargetCommentStatus[];
}

export interface PostTargetCommentStatus {
  id: number;
  post_comment_id: number;
  post_target_id: number;
  status: "pending" | "posting" | "posted" | "skipped" | "failed";
  platform_comment_id?: string | null;
  error?: string | null;
  posted_at?: string | null;
}

export interface PostPayload {
  caption?: string | null;
  media?: PostMediaItem[];
  status?: "draft" | "scheduled" | "posted";
  scheduled_at?: string | null;
  // The brand whose accounts the current selection matches (informational).
  brand_id?: number | null;
  platforms?: string[];
  // Account-explicit targets — one entry per (platform, account). Supersedes
  // `platforms` when present; lets a post hit several accounts on one platform.
  targets?: Array<{ platform: string; social_account_id?: number | null }>;
  // Follow-up comments with per-comment delay (seconds from the previous
  // message in the chain).
  comments?: Array<{ body: string; delay_seconds?: number }>;
  // Swap URLs in caption/overrides/comments for tracked short links at save.
  shorten_links?: boolean;
  overrides?: Record<string, string>;
  // Per-platform publish settings keyed by platform id (e.g. { tiktok: {...} }).
  options?: Record<string, unknown>;
}



class ViewsMaxApiService {
  // Tracking Management
  async getTrackingEvent(id: string): Promise<ApiResponse<TrackingEvent>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-events/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch tracking event: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createTrackingEvent(data: Partial<TrackingEvent>): Promise<ApiResponse<TrackingEvent>> {
    try {
      // Ensure goals are formatted correctly if present
      // Frontend TrackingGoal might need mapping if API expects something different, 
      // but usually it's 1:1. 
      // NOTE: backend expects 'goals' array.

      const response = await fetch(`${this.baseUrl}/api/tracking-events`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        // Surface the backend's friendly message (e.g. the plan offer-limit
        // notice) rather than dumping the raw JSON body into a toast.
        const body = await response.json().catch(() => null);
        const fieldErrors = body?.errors ? Object.values(body.errors).flat().join(' ') : '';
        return {
          success: false,
          error: body?.message || fieldErrors || `Failed to create tracking event (${response.status})`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async updateTrackingEvent(id: string | number, data: Partial<TrackingEvent>): Promise<ApiResponse<TrackingEvent>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-events/${id}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const body = await response.json().catch(() => null);
        const fieldErrors = body?.errors ? Object.values(body.errors).flat().join(' ') : '';
        return {
          success: false,
          error: body?.message || fieldErrors || `Failed to update tracking event (${response.status})`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getOffers(): Promise<ApiResponse<string[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-events/offers`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch offers: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || []
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getTrackingStats(filters?: { from?: Date; to?: Date }): Promise<ApiResponse<{
    views: number;
    clicks: number;
    callsBooked: number;
    emailSignups: number;
    sales: number;
    eventCount: number;
  }>> {
    try {
      const params = new URLSearchParams();

      const formatDate = (d: Date) => {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      };

      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));

      const response = await fetch(`${this.baseUrl}/api/tracking-events/stats?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch tracking stats');
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data
      };
    } catch (error) {
      console.error('Error fetching tracking stats:', error);
      return {
        success: false,
        error: error instanceof Error ? error.message : 'Unknown error occurred'
      };
    }
  }

  async getTrackingEvents(filters?: { from?: Date; to?: Date }): Promise<ApiResponse<TrackingEvent[]>> {
    try {
      const params = new URLSearchParams();

      const formatDate = (d: Date) => {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      };

      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));

      const response = await fetch(`${this.baseUrl}/api/tracking-events?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch events: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      // Ensure we return an array
      const events = Array.isArray(result) ? result : (result.data || []);
      return {
        success: true,
        data: events
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // GA-style acquisition: visitors grouped by source platform + full referrer
  // URLs, from pageview beacons (click-derived until pageview data exists).
  async getTrackingSources(filters?: { from?: Date; to?: Date; eventId?: number | string }): Promise<ApiResponse<TrafficSourcesData>> {
    if (isMockApi()) return { success: true, data: { basis: "pageviews", sources: [], referrers: [] } };
    try {
      const params = new URLSearchParams();
      const formatDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));
      if (filters?.eventId != null) params.append('event_id', String(filters.eventId));

      const response = await fetch(`${this.baseUrl}/api/tracking-events/sources?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to fetch traffic sources: ${response.status}` };
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async getTrackingTimeseries(filters?: { from?: Date; to?: Date; eventId?: number | string }): Promise<ApiResponse<TrackingTimeseriesPoint[]>> {
    try {
      const params = new URLSearchParams();

      const formatDate = (d: Date) => {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      };

      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));
      if (filters?.eventId != null) params.append('event_id', String(filters.eventId));

      const response = await fetch(`${this.baseUrl}/api/tracking-events/timeseries?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch tracking timeseries');
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || []
      };
    } catch (error) {
      console.error('Error fetching tracking timeseries:', error);
      return {
        success: false,
        error: error instanceof Error ? error.message : 'Unknown error occurred'
      };
    }
  }

  // Audience Growth: per-platform follower series over a date range.
  async getAudienceGrowth(filters?: { from?: Date; to?: Date }): Promise<ApiResponse<AudienceGrowthData>> {
    if (isMockApi()) return { success: true, data: { platforms: [] } };
    try {
      const params = new URLSearchParams();
      const formatDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));

      const response = await fetch(`${this.baseUrl}/api/analytics/audience?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to fetch audience growth: ${response.status}` };
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // Audience Growth: posts ranked by engagement (optional platform filter).
  async getTopPosts(filters?: { from?: Date; to?: Date; platform?: string }): Promise<ApiResponse<TopPost[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const params = new URLSearchParams();
      const formatDate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      if (filters?.from) params.append('from', formatDate(filters.from));
      if (filters?.to) params.append('to', formatDate(filters.to));
      if (filters?.platform) params.append('platform', filters.platform);

      const response = await fetch(`${this.baseUrl}/api/analytics/posts?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to fetch top posts: ${response.status}` };
      const result = await response.json();
      return { success: true, data: result.data?.posts || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // Posts (multi-platform composer)
  async getPosts(filters?: { status?: string; from?: Date; to?: Date }): Promise<ApiResponse<Post[]>> {
    try {
      const params = new URLSearchParams();
      const fmt = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      if (filters?.status) params.append('status', filters.status);
      if (filters?.from) params.append('from', fmt(filters.from));
      if (filters?.to) params.append('to', fmt(filters.to));
      const response = await fetch(`${this.baseUrl}/api/posts?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch posts: ${response.status}`);
      const result = await response.json();
      return { success: true, data: Array.isArray(result) ? result : (result.data || []) };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Admin-only: all clients' posts with owner + per-platform targets.
  async getAdminPosts(filters?: { status?: string; post_status?: string; platform?: string; overdue?: boolean; q?: string }): Promise<ApiResponse<AdminPost[]>> {
    try {
      const params = new URLSearchParams();
      if (filters?.status) params.append('status', filters.status);
      if (filters?.post_status) params.append('post_status', filters.post_status);
      if (filters?.platform) params.append('platform', filters.platform);
      if (filters?.overdue) params.append('overdue', '1');
      if (filters?.q) params.append('q', filters.q);
      const response = await fetch(`${this.baseUrl}/api/admin/posts?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch admin posts: ${response.status}`);
      const result = await response.json();
      return { success: true, data: Array.isArray(result) ? result : (result.data || []) };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminPost(id: number): Promise<ApiResponse<AdminPost>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/posts/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch admin post: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminPostStats(): Promise<ApiResponse<AdminPostStats>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/posts/stats`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch admin stats: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminOffers(q?: string): Promise<ApiResponse<AdminOfferRow[]>> {
    try {
      const params = new URLSearchParams();
      if (q) params.append('q', q);
      const response = await fetch(`${this.baseUrl}/api/admin/offers?${params.toString()}`, { method: 'GET', headers: this.getAuthHeaders() });
      if (!response.ok) throw new Error(`Failed to fetch admin offers: ${response.status}`);
      const result = await response.json();
      return { success: true, data: Array.isArray(result) ? result : (result.data || []) };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminLinks(q?: string): Promise<ApiResponse<AdminLinkRow[]>> {
    try {
      const params = new URLSearchParams();
      if (q) params.append('q', q);
      const response = await fetch(`${this.baseUrl}/api/admin/links?${params.toString()}`, { method: 'GET', headers: this.getAuthHeaders() });
      if (!response.ok) throw new Error(`Failed to fetch admin links: ${response.status}`);
      const result = await response.json();
      return { success: true, data: Array.isArray(result) ? result : (result.data || []) };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getBeehiivConnection(): Promise<ApiResponse<BeehiivConnectionStatus>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/beehiiv/connection`, { method: 'GET', headers: this.getAuthHeaders() });
      if (!response.ok) throw new Error(`Failed to fetch Beehiiv status: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async connectBeehiiv(apiKey: string, publicationId?: string): Promise<ApiResponse<BeehiivConnectionStatus>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/beehiiv/connection`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ api_key: apiKey, publication_id: publicationId }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) return { success: false, error: body.message || `Couldn't connect Beehiiv (${response.status}).` };
      return { success: true, data: body.data || body };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async disconnectBeehiiv(): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/beehiiv/connection`, { method: 'DELETE', headers: this.getAuthHeaders() });
      if (!response.ok) throw new Error(`Failed to disconnect Beehiiv: ${response.status}`);
      return { success: true };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Read-only scan for publishing stuck by a worker/scheduler outage.
  async reconcileAdminPosts(): Promise<ApiResponse<AdminReconcileReport>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/posts/reconcile`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to reconcile: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Re-drive one post's unfinished platform targets (double-post safe server-side).
  async requeueAdminPost(id: number): Promise<ApiResponse<AdminRequeueResult>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/posts/${id}/requeue`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to requeue: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Admin-only: paginated index of users, filterable by search + created_at range.
  async getAdminUsers(filters?: AdminUsersFilters): Promise<ApiResponse<AdminUsersPage>> {
    try {
      const params = new URLSearchParams();
      if (filters?.from) params.append('from', filters.from);
      if (filters?.to) params.append('to', filters.to);
      if (filters?.name) params.append('name', filters.name);
      if (filters?.email) params.append('email', filters.email);
      if (filters?.card?.length) params.append('card', filters.card.join(','));
      if (filters?.plan?.length) params.append('plan', filters.plan.join(','));
      if (filters?.cancelled?.length) params.append('cancelled', filters.cancelled.join(','));
      if (filters?.sort) params.append('sort', filters.sort);
      if (filters?.dir) params.append('dir', filters.dir);
      if (filters?.page) params.append('page', String(filters.page));
      if (filters?.per_page) params.append('per_page', String(filters.per_page));
      const response = await fetch(`${this.baseUrl}/api/admin/users?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch admin users: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Admin-only: typeahead values for the users-table filters (name/email/plan).
  async getTranscript(
    platform: "youtube" | "tiktok" | "instagram",
    url: string,
  ): Promise<ApiResponse<{ platform: string; url: string; text: string; segments: { text: string; startMs: number; endMs: number }[]; language: string | null; cached: boolean }>> {
    try {
      // Public endpoint — no auth headers; the CaptAPI key stays server-side.
      const response = await fetch(`${this.baseUrl}/api/free-tools/transcript`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ platform, url }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) return { success: false, error: body.message || `Failed to fetch transcript (${response.status})` };
      return { success: true, data: body.data };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : "Unknown error" };
    }
  }

  async getAdminUserAccounts(id: number): Promise<ApiResponse<AdminUserAccount[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/users/${id}/accounts`, { headers: this.getAuthHeaders() });
      if (!response.ok) return { success: false, error: `Failed to load accounts: ${response.status}` };
      const body = await response.json();
      return { success: true, data: body.data || [] };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminUser(id: number): Promise<ApiResponse<AdminUserDetail>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/users/${id}`, { headers: this.getAuthHeaders() });
      if (!response.ok) return { success: false, error: `Failed to load user: ${response.status}` };
      const body = await response.json();
      return { success: true, data: body.data };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async updateAdminUserRole(id: number, role: string): Promise<ApiResponse<AdminUserDetail['user']>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/users/${id}/role`, {
        method: 'PUT',
        headers: { ...this.getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ role }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) return { success: false, error: body.message || `Failed to update role: ${response.status}` };
      return { success: true, data: body.data?.user };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async deleteUser(id: number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/admin/users/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        return { success: false, error: body.message || `Failed to delete user: ${response.status}` };
      }
      return { success: true };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getAdminUserSuggest(field: 'name' | 'email' | 'plan', q?: string): Promise<ApiResponse<string[]>> {
    try {
      const params = new URLSearchParams({ field });
      if (q) params.append('q', q);
      const response = await fetch(`${this.baseUrl}/api/admin/users/suggest?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch suggestions: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result || [] };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Admin-only: signup / added-card / subscribed widgets over a date range.
  async getAdminUserStats(filters?: { from?: string; to?: string }): Promise<ApiResponse<AdminUserStats>> {
    try {
      const params = new URLSearchParams();
      if (filters?.from) params.append('from', filters.from);
      if (filters?.to) params.append('to', filters.to);
      const response = await fetch(`${this.baseUrl}/api/admin/users/stats?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) throw new Error(`Failed to fetch admin user stats: ${response.status}`);
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async getPost(id: number): Promise<ApiResponse<Post>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/posts/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: response.status === 404 ? 'Post not found.' : `Failed to fetch post: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async createPost(data: PostPayload): Promise<ApiResponse<Post>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/posts`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });
      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to create post: ${response.status} - ${errorData}` };
      }
      return { success: true, data: await response.json() };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async updatePost(id: number, data: PostPayload): Promise<ApiResponse<Post>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/posts/${id}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });
      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to update post: ${response.status} - ${errorData}` };
      }
      return { success: true, data: await response.json() };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async deletePost(id: number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/posts/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to delete post: ${response.status}` };
      return { success: true };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  /**
   * Re-queue publishing for a single failed platform target. The server only
   * touches this target (siblings that already published are left alone), and
   * returns the post with refreshed targets.
   */
  async retryPostTarget(postId: number, targetId: number): Promise<ApiResponse<Post>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/posts/${postId}/targets/${targetId}/retry`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to retry: ${response.status} - ${errorData}` };
      }
      return { success: true, data: await response.json() };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // Upload a single post media file (image/video). Returns a public URL the
  // backend publishing APIs can fetch (e.g. TikTok PULL_FROM_URL).
  //
  // Preferred path: presigned direct-to-R2 upload (single PUT, or parallel
  // multipart for large videos) so the file doesn't relay through the API
  // server. Falls back to the legacy relay endpoint when the backend has no
  // S3-compatible media disk or the direct path fails (e.g. missing bucket
  // CORS). Progress covers the full journey: bytes map to 0–99, and 100 only
  // fires once the backend has verified the object.
  async uploadPostMedia(
    file: File,
    type: "image" | "video",
    onProgress?: (percent: number) => void,
  ): Promise<ApiResponse<PostMediaItem>> {
    let session: DirectUploadSession;
    try {
      const response = await fetch(`${this.baseUrl}/api/posts/media/direct`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ type, filename: file.name, mime: file.type, size: file.size }),
      });
      if (response.status === 422) {
        // The file itself is invalid (size/mime) — the relay would reject it
        // identically, so surface the validation message instead of retrying.
        const body = await response.json().catch(() => null);
        const errors = body?.errors ? Object.values(body.errors).flat().join(' ') : '';
        return { success: false, error: errors || body?.message || 'File is not a supported image/video.' };
      }
      if (!response.ok) throw new Error(`Session request failed: ${response.status}`);
      session = (await response.json()).data;
    } catch {
      // Older backend or transient failure — the relay path still works.
      return this.uploadPostMediaRelay(file, type, onProgress);
    }

    if (session?.strategy !== 'put' && session?.strategy !== 'multipart') {
      return this.uploadPostMediaRelay(file, type, onProgress);
    }

    const onBytes = (bytes: number) => {
      if (onProgress) onProgress(Math.min(99, Math.round((bytes / file.size) * 99)));
    };

    try {
      let parts: UploadedPart[] | undefined;
      if (session.strategy === 'put') {
        await putWithProgress(session.url!, file, session.headers ?? {}, onBytes);
      } else {
        parts = await uploadMultipartParts(
          file,
          { part_size: session.part_size!, parts: session.parts! },
          onBytes,
        );
      }

      const result = await this.completeDirectUpload(type, session.path!, session.upload_id, parts);
      if (result.success && onProgress) onProgress(100);
      return result;
    } catch (error) {
      if (session.strategy === 'multipart' && session.upload_id) {
        // Best-effort cleanup — abandoned parts are billable on R2.
        this.abortDirectUpload(session.path!, session.upload_id);
      }
      console.warn('Direct upload failed, falling back to relay:', error);
      return this.uploadPostMediaRelay(file, type, onProgress);
    }
  }

  private async completeDirectUpload(
    type: "image" | "video",
    path: string,
    uploadId?: string,
    parts?: UploadedPart[],
  ): Promise<ApiResponse<PostMediaItem>> {
    const response = await fetch(`${this.baseUrl}/api/posts/media/direct/complete`, {
      method: 'POST',
      headers: this.getAuthHeaders(),
      body: JSON.stringify({
        type,
        path,
        ...(uploadId ? { upload_id: uploadId, parts } : {}),
      }),
    });
    const body = await response.json().catch(() => null);
    if (!response.ok || !body?.success) {
      // Verification failures (422: too big / wrong mime) are terminal — the
      // relay applies the same rules, so don't retry there.
      if (response.status === 422) {
        return { success: false, error: body?.message || 'Uploaded file failed verification.' };
      }
      throw new Error(body?.message || `Completing the upload failed: ${response.status}`);
    }
    return { success: true, data: body.data };
  }

  private abortDirectUpload(path: string, uploadId: string): void {
    fetch(`${this.baseUrl}/api/posts/media/direct/abort`, {
      method: 'POST',
      headers: this.getAuthHeaders(),
      body: JSON.stringify({ path, upload_id: uploadId }),
    }).catch(() => undefined);
  }

  // Legacy path: multipart POST through the API server, which re-uploads to
  // storage before responding (slower for big files; kept as the fallback).
  private uploadPostMediaRelay(
    file: File,
    type: "image" | "video",
    onProgress?: (percent: number) => void,
  ): Promise<ApiResponse<PostMediaItem>> {
    return new Promise((resolve) => {
      try {
        const formData = new FormData();
        formData.append('type', type);
        formData.append('file', file);

        const headers = this.getAuthHeaders();
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${this.baseUrl}/api/posts/media`);
        // Only forward Authorization — let the browser set the multipart
        // Content-Type (with boundary) itself.
        if (headers['Authorization']) xhr.setRequestHeader('Authorization', headers['Authorization']);

        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable && onProgress) onProgress(Math.round((e.loaded / e.total) * 100));
        };
        xhr.onload = () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const result = JSON.parse(xhr.responseText);
              resolve({ success: true, data: result.data || result });
            } catch {
              resolve({ success: false, error: 'Upload succeeded but the server response was invalid.' });
            }
          } else {
            resolve({ success: false, error: `Failed to upload media: ${xhr.status} - ${xhr.responseText}` });
          }
        };
        xhr.onerror = () => resolve({ success: false, error: 'Network error during upload. Check your connection and try again.' });
        xhr.ontimeout = () => resolve({ success: false, error: 'Upload timed out. Try again.' });
        xhr.send(formData);
      } catch (error) {
        resolve({ success: false, error: error instanceof Error ? error.message : 'Unknown error' });
      }
    });
  }

  // Fetch TikTok creator info — drives the required privacy / interaction
  // options the composer must show before a post can be published.
  async getTikTokCreatorInfo(accountId?: number): Promise<ApiResponse<TikTokCreatorInfo>> {
    try {
      const qs = accountId ? `?account_id=${accountId}` : '';
      const response = await fetch(`${this.baseUrl}/api/connections/tiktok/creator-info${qs}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        // TikTok answers "this creator can't post right now" with a code the
        // backend tags as creator_cannot_post. Content Sharing Guidelines,
        // Required UX 1(b) needs that told apart from a generic outage, so pass
        // the message through verbatim.
        const body = await response.json().catch(() => null);
        if (body?.code === 'creator_cannot_post') {
          return { success: false, error: body.message, code: body.code };
        }
        return { success: false, error: body?.message || `Failed to fetch TikTok creator info: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  async deleteTrackingEvent(id: number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-events/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to delete event: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getBeehiivPosts(): Promise<ApiResponse<{ id: string; title: string | null; web_url: string | null }[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/beehiiv/posts`, { method: 'GET', headers: this.getAuthHeaders() });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) return { success: false, error: body.message || `Failed to fetch Beehiiv posts (${response.status}).` };
      return { success: true, data: body.data || [] };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  /**
   * X posts published through the app (both posting stores) — the pin-to-post
   * picker for tracking links. No X API reads happen server-side.
   */
  async getXPublishedPosts(): Promise<ApiResponse<XPublishedPost[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/social/x/posts`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to load X posts: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data ?? [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async generateTrackingLink(data: {
    video_id?: number;
    youtube_video_id?: string;
    beehiiv_post_id?: string;
    x_post_id?: string;
    placement: string;
    // Multi-platform links: every platform this link is placed on.
    placements?: string[];
    tracking_event_id: number;
    name?: string;
    description?: string;
    // parameter_id is generated by backend
  }): Promise<ApiResponse<{ link: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-links`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to generate link: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };

    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async updateTrackingLink(id: number, data: {
    name?: string;
    placement?: string;
    placements?: string[];
    youtube_video_id?: string | null;
    beehiiv_post_id?: string | null;
    x_post_id?: string | null;
    description?: string;
  }): Promise<ApiResponse<TrackingLink>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/tracking-links/${id}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to update link: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };

    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getGoalTypes(): Promise<ApiResponse<GoalType[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/goal-types`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to load goal types: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data ?? result
      };

    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  private readonly baseUrl = API_BASE_URL;
  private authSession: AuthSession | null = null;
  private creditsUpdateCallback: ((credits: number) => void) | null = null;

  constructor() {
    // Try to get auth session from localStorage on initialization
    this.loadAuthSession();
  }

  private loadAuthSession() {
    try {
      const storedSession = localStorage.getItem('auth_session');
      if (storedSession) {
        const parsedSession = JSON.parse(storedSession);
        this.authSession = {
          token: parsedSession.token,
          token_type: parsedSession.token_type || 'Bearer'
        };
      }
    } catch (error) {
      console.error('Error loading auth session:', error);
    }
  }

  public setAuthSession(session: AuthSession | null) {
    this.authSession = session;
  }

  private ensureAuthSession() {
    if (!this.authSession) {
      this.loadAuthSession();
    }
  }

  private getAuthHeaders(): Record<string, string> {
    this.ensureAuthSession();

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };

    // A free-tier ngrok tunnel answers browser requests with an HTML
    // interstitial (no CORS headers) unless this header is present, which the
    // browser then reports as a CORS failure. Only relevant when the API itself
    // is tunnelled, so keep it off for every other host.
    if (/^https?:\/\/[^/?#]*\.ngrok(-free)?\.(app|dev)(?=[/:?#]|$)/i.test(String(this.baseUrl ?? ''))) {
      headers['ngrok-skip-browser-warning'] = 'true';
    }

    if (this.authSession?.token) {
      headers['Authorization'] = `${this.authSession.token_type || 'Bearer'} ${this.authSession.token}`;
    }

    return headers;
  }

  // Set callback to be called when user_credits is found in responses
  public setCreditsUpdateCallback(callback: ((credits: number) => void) | null) {
    this.creditsUpdateCallback = callback;
  }

  // Helper method to extract user_credits from response and notify callback
  private extractCreditsFromResponse(responseData: any): void {
    if (responseData && typeof responseData.user_credits === 'number') {
      // Always store in localStorage for persistence
      localStorage.setItem('user_credits', responseData.user_credits.toString());
      // Notify callback if registered
      if (this.creditsUpdateCallback) {
        this.creditsUpdateCallback(responseData.user_credits);
      }
    }
  }


  // Privacy Consent Management (already implemented in consent.ts)
  async checkPrivacyConsent(userId: string, policyVersion: string): Promise<ApiResponse<{ has_consented: boolean }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/consent/check`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          user_id: userId,
          policy_version: policyVersion
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to check privacy consent: ${response.status} - ${errorData}`
        };
      }

      const data = await response.json();
      return {
        success: true,
        data: data
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async recordPrivacyConsent(userId: string, policyVersion: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/consent/record`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          user_id: userId,
          policy_version: policyVersion,
          consented_at: new Date().toISOString()
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to record privacy consent: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Script Saving & History  
  async saveScript(id: number | string, text: string): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}/save`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ text }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to save script: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data,
        message: result.message
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getScriptHistory(id: number | string): Promise<ApiResponse<ScriptVersion[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}/history`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch history: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // YouTube OAuth Token Management
  async exchangeYouTubeCode(code: string, redirectUri: string, token: string): Promise<ApiResponse<{
    access_token: string;
    refresh_token?: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/auth/youtube/exchange`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          code: code,
          redirect_uri: redirectUri
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Token exchange failed: ${response.status} - ${errorData}`
        };
      }

      const responseData = await response.json();
      // Backend returns { success: true, data: { access_token, ... } }
      return {
        success: responseData.success ?? true,
        data: responseData.data || responseData
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async refreshYouTubeToken(refreshToken: string): Promise<ApiResponse<{
    access_token: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/auth/youtube/refresh`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          refresh_token: refreshToken
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Token refresh failed: ${response.status} - ${errorData}`
        };
      }

      const tokens = await response.json();
      return {
        success: true,
        data: tokens
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Title Suggestions
  async searchTitles(query: string): Promise<ApiResponse<string[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/titles/enhance`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          query: query
        }),
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use generic error
        }
        return {
          success: false,
          error: 'Failed to generate titles'
        };
      }

      const data = await response.json();

      // Extract credits from response
      this.extractCreditsFromResponse(data);

      // Check if API returned success: false (e.g., insufficient credits)
      if (data.success === false) {
        return {
          success: false,
          error: data.message || 'Failed to generate titles'
        };
      }

      // Extract titles from the response - handle both string array and object array formats
      let titles: string[] = [];
      if (data.data && Array.isArray(data.data)) {
        titles = data.data.map((item: string | { enhanced_title?: string; original_title?: string; title?: string }) => {
          if (typeof item === 'string') {
            // Remove encompassing quotes if they exist (handle double-encoded JSON)
            let cleanTitle = item;
            if (cleanTitle.startsWith('"') && cleanTitle.endsWith('"')) {
              cleanTitle = cleanTitle.slice(1, -1);
            }
            return cleanTitle;
          } else if (typeof item === 'object' && item !== null) {
            // Use enhanced_title if available, otherwise original_title
            let title = item.enhanced_title || item.original_title || item.title || '';
            // Remove encompassing quotes if they exist
            if (title.startsWith('"') && title.endsWith('"')) {
              title = title.slice(1, -1);
            }
            return title;
          }
          return '';
        }).filter(title => title.trim() !== '');
      }

      return {
        success: true,
        data: titles,
        user_credits: data.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Channel Management
  async getChannels(token: string): Promise<ApiResponse<Array<{
    id: number;
    youtube_channel_id: string;
    channel_name: string;
    channel_description?: string;
    subscriber_count: number;
    video_count: number;
    view_count: number;
    profile_image_url?: string;
    custom_url?: string;
    country?: string;
    published_at?: string;
  }>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch channels: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data || [],
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // MCP API key: one non-expiring key per user for connecting an AI
  // assistant (Claude, Cursor, …) to the /api/mcp endpoint. show() returns
  // only a masked hint; the full key is revealed once by rotate().
  async getApiKey(): Promise<ApiResponse<{ hint: string; access: 'read' | 'full'; created_at: string } | null>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/api-key`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to fetch API key: ${response.status} - ${errorData}` };
      }

      const result = await response.json();
      return { success: true, data: result.data ?? null };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // access: 'full' (read + write, default) or 'read' (read-only).
  async rotateApiKey(access: 'read' | 'full' = 'full'): Promise<ApiResponse<{ key: string; hint: string; access: 'read' | 'full'; created_at: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/api-key/rotate`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ access }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to rotate API key: ${response.status} - ${errorData}` };
      }

      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }

  // MCP audit log: what the user's connected AI assistants actually did
  // (tool calls with arguments, outcome, and auth mode), newest first.
  async getMcpActivity(page = 1, perPage = 20): Promise<ApiResponse<{
    data: McpActivityItem[];
    current_page: number;
    last_page: number;
    total: number;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/mcp-activity?per_page=${perPage}&page=${page}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return { success: false, error: `Failed to fetch MCP activity: ${response.status} - ${errorData}` };
      }

      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error instanceof Error ? error.message : 'Unknown error' };
    }
  }



  // Authentication Methods
  async login(email: string, password: string): Promise<ApiResponse<{
    token: string;
    token_type: string;
    user: User;
  }>> {
    if (isMockApi()) return mockApi.login(email);
    try {
      const response = await fetch(`${this.baseUrl}/api/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          email,
          password,
        }),
      });

      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const textResponse = await response.text();
        return {
          success: false,
          error: `Server returned non-JSON response: ${response.status} - ${textResponse}`
        };
      }

      const data = await response.json();

      if (!response.ok) {
        // A 422 carries per-field messages; surface those so the user sees the
        // actual problem instead of a bare "Validation failed".
        const fieldErrors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
        return {
          success: false,
          error: fieldErrors || data.message || "Invalid email or password. Please check your credentials."
        };
      }

      // Extract credits from response
      this.extractCreditsFromResponse(data);

      return {
        success: true,
        data: data.data,
        user_credits: data.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Registration now triggers a magic-link email verification instead of
  // logging the user in. The backend creates the user (email_verified_at=null,
  // onboarding_completed_at=null) and emails a link to /verify-email?token=...
  async register(name: string, email: string, password: string, passwordConfirmation: string, marketingConsent: boolean): Promise<ApiResponse<{
    email: string;
  }>> {
    if (isMockApi()) return mockApi.register(name, email);
    try {
      const response = await fetch(`${this.baseUrl}/api/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name,
          email,
          password,
          password_confirmation: passwordConfirmation,
          marketing_consent: marketingConsent,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        // Surface Laravel's per-field validation detail (data.errors) instead of
        // the generic "Validation failed" so users know which field to fix.
        const fieldErrors = data.errors
          ? Object.values(data.errors).flat().join(" ")
          : "";
        return {
          success: false,
          error: fieldErrors || data.message || "Failed to create account. Please try again."
        };
      }

      return {
        success: true,
        data: { email: data.email ?? email },
        message: data.message
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Verify a signup email via the magic-link token. On success the backend
  // returns the standard login payload { token, token_type, user }.
  async verifyEmail(token: string): Promise<ApiResponse<{
    token: string;
    token_type: string;
    user: User;
  }>> {
    if (isMockApi()) return mockApi.verifyEmail(token);
    try {
      const response = await fetch(`${this.baseUrl}/api/auth/verify-email`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token }),
      });

      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const textResponse = await response.text();
        return {
          success: false,
          error: `Server returned non-JSON response: ${response.status} - ${textResponse}`
        };
      }

      const data = await response.json();

      if (!response.ok) {
        return {
          success: false,
          error: data.message || "This verification link is invalid or has expired."
        };
      }

      this.extractCreditsFromResponse(data);

      return {
        success: true,
        data: data.data,
        user_credits: data.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Resend the verification email. Always succeeds from the caller's view to
  // avoid leaking whether an email is registered.
  async resendVerification(email: string): Promise<ApiResponse<void>> {
    if (isMockApi()) return mockApi.resendVerification();
    try {
      const response = await fetch(`${this.baseUrl}/api/auth/resend-verification`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email }),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || "Failed to resend verification email."
        };
      }

      return { success: true };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async forgotPassword(email: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/forgot-password`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          email: email,
        }),
      });

      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const textResponse = await response.text();
        return {
          success: false,
          error: `Server returned non-JSON response: ${response.status} - ${textResponse}`
        };
      }

      if (!response.ok) {
        const data = await response.json();
        return {
          success: false,
          error: data.message || "Failed to send reset email. Please try again."
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  /**
   * Complete a password reset using the token + email from the emailed link.
   */
  async resetPassword(
    email: string,
    token: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/reset-password`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          email,
          token,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });

      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const textResponse = await response.text();
        return {
          success: false,
          error: `Server returned non-JSON response: ${response.status} - ${textResponse}`,
        };
      }

      if (!response.ok) {
        const data = await response.json();
        return {
          success: false,
          error: data.message || "Couldn't reset your password. The link may have expired.",
        };
      }

      return { success: true };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`,
      };
    }
  }

  /**
   * Get user profile details including credits
   */
  async getUserProfile(): Promise<ApiResponse<{ user: User; user_credits: number }>> {
    if (isMockApi()) return mockApi.getUserProfile();
    try {
      const response = await fetch(`${this.baseUrl}/api/profile`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return {
          success: false,
          error: 'Failed to fetch user profile'
        };
      }

      const data = await response.json();

      // Extract credits if available
      if (data.user_credits !== undefined) {
        this.extractCreditsFromResponse(data);
      }

      return {
        success: true,
        data: {
          user: data.data?.user || data.data, // Handle nested or direct structure
          user_credits: data.user_credits
        }
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Script Generation
  async generateScript(data: {
    title: string;
    options: {
      includeMotionGraphics: boolean;
      includeReferences: boolean;
      includeYouTubeVideos: boolean;
    };
  }): Promise<ApiResponse<string>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/generate-script`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use generic error
        }
        return {
          success: false,
          error: 'Failed to generate script'
        };
      }

      const result = await response.json();

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to generate script'
        };
      }

      return {
        success: true,
        data: result.data || result.content || ''
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get videos for a channel (from backend database)
  async getChannelVideos(token: string, channelId: number, perPage: number = 50): Promise<ApiResponse<{
    videos: Array<{
      id: number;
      youtube_video_id: string;
      title: string;
      description: string;
      thumbnail_url?: string;
      thumbnail_medium_url?: string;
      thumbnail_high_url?: string;
      published_at: string;
      view_count: number;
      like_count: number;
      comment_count: number;
      duration: string;
      formatted_duration?: string;
      definition?: string;
      has_captions: boolean;
    }>;
    pagination?: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
      from: number | null;
      to: number | null;
    };
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}/videos?per_page=${perPage}`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch videos: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: {
          videos: result.data || [],
          pagination: result.pagination
        },
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Project Management
  async saveProject(data: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  }): Promise<ApiResponse<{ id: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Project save failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || { id: result.id }
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Fetch videos from YouTube API and import them into database
  async fetchChannelVideosFromYouTube(token: string, channelId: number): Promise<ApiResponse<{
    data: Array<unknown>;
    imported: number;
    updated: number;
    total: number;
    fetched_from_youtube: number;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}/fetch-videos`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Project save failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();

      return {
        success: true,
        data: result.data || { id: result.id }
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }


  async updateProject(id: string, data: {
    title: string;
    script: string;
    include_motion_graphics: boolean;
    include_references: boolean;
    include_youtube_videos: boolean;
  }): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects/${id}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Project update failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async deleteProject(id: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Project delete failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }


  // Video Review
  async reviewVideo(data: {
    videoId: string;
    videoTitle: string;
    channelId?: string;
    accessToken?: string;
    userId?: string;
  }): Promise<ApiResponse<{ success: boolean; message?: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/review-video`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Review video by database ID
  async reviewVideoById(token: string, videoId: number): Promise<ApiResponse<{
    video: any;
    title_analyzer?: {
      id: number;
      status: 'pending' | 'processing' | 'completed' | 'failed';
    };
    thumbnail_analyzer?: {
      id: number;
      status: 'pending' | 'processing' | 'completed' | 'failed';
    };
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/videos/${videoId}/review`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get review status for polling
  // Get single video by ID
  async getVideo(token: string, videoId: number): Promise<ApiResponse<{
    id: number;
    youtube_video_id: string;
    title: string;
    description: string;
    thumbnail_url: string;
    thumbnail_high_url: string;
    published_at: string;
    view_count: number;
    like_count: number;
    comment_count: number;
    channel_id: number;
    formatted_duration?: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/videos/${videoId}`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get title analyzer status by ID
  async getTitleAnalyzerStatus(token: string, analyzerId: number): Promise<ApiResponse<{
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    scores?: any;
    error_message?: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/title-scores/${analyzerId}/status`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get thumbnail analyzer status by ID
  async getThumbnailAnalyzerStatus(token: string, analyzerId: number): Promise<ApiResponse<{
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    scores?: any;
    error_message?: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnail-scores/${analyzerId}/status`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Refresh channel data from YouTube API
  async refreshChannelData(token: string, channelId: number): Promise<ApiResponse<{
    success: boolean;
    data: {
      id: number;
      youtube_channel_id: string;
      channel_name: string;
      channel_description?: string;
      subscriber_count: number;
      video_count: number;
      view_count: number;
      profile_image_url?: string;
      custom_url?: string;
      country?: string;
      published_at?: string;
    };
    message: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}/refresh`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Video review failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get comprehensive analytics data for a channel (from backend)
  async getChannelAnalytics(token: string, channelId: number): Promise<ApiResponse<{
    playlists: Array<{
      id: string;
      title: string;
      description: string;
      thumbnail: string;
      itemCount: number;
      publishedAt: string;
      privacy: string;
    }>;
    viewsOverTime: Array<{
      date: string;
      views: number;
      estimatedMinutesWatched: number;
      averageViewDuration: number;
    }>;
    audienceDemographics: {
      ageGroups: {
        age13_17?: number;
        age18_24?: number;
        age25_34?: number;
        age35_44?: number;
        age45_54?: number;
        age55_64?: number;
        age65_?: number;
      };
      gender: {
        male?: number;
        female?: number;
        other?: number;
      };
      topCountries: Array<{
        country: string;
        views: number;
        percentage: number;
      }>;
    } | null;
    watchTimeAnalytics: {
      averageViewDuration: string;
      averageViewDurationSeconds: number;
      totalWatchTimeHours: number;
    } | null;
    analyticsEligible: boolean;
    analyticsReason: string | null;
    analyticsLastUpdated: string | null;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}/analytics/comprehensive`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch analytics: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();

      return {
        success: true,
        data: result.data
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Thumbnail Management - Unified endpoint returning both generated and copied thumbnails
  async getThumbnails(params?: {
    page?: number;
    per_page?: number;
  }): Promise<ApiResponse<PaginatedResponse<Thumbnail>>> {
    try {
      const queryParams = new URLSearchParams();
      if (params?.page) queryParams.append('page', params.page.toString());
      if (params?.per_page) queryParams.append('per_page', params.per_page.toString());

      const url = `${this.baseUrl}/api/thumbnails${queryParams.toString() ? '?' + queryParams.toString() : ''}`;

      const response = await fetch(url, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get thumbnails failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Handle both paginated and flat array responses
      const responseData = result.data || result;

      // If the response is a paginated object with a nested data array
      if (responseData.data && Array.isArray(responseData.data)) {
        return {
          success: true,
          data: responseData,
          user_credits: result.user_credits
        };
      }

      // If the response is a flat array, wrap it in pagination structure
      if (Array.isArray(responseData)) {
        return {
          success: true,
          data: {
            data: responseData,
            current_page: 1,
            last_page: 1,
            per_page: responseData.length,
            total: responseData.length,
            from: responseData.length > 0 ? 1 : null,
            to: responseData.length > 0 ? responseData.length : null,
          },
          user_credits: result.user_credits
        };
      }

      return {
        success: true,
        data: responseData,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async generateThumbnail(data: {
    description: string;
    number_of_thumbnails?: number;
    ai_model_id?: string | number;
  }): Promise<ApiResponse<Thumbnail[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use text
        }
        return {
          success: false,
          error: 'Failed to generate thumbnail'
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to generate thumbnail'
        };
      }

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async updateThumbnail(id: string, data: {
    prompt: string;
  }, type?: string): Promise<ApiResponse<Thumbnail>> {
    try {
      const typeParam = type ? `?type=${type}` : '';
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}${typeParam}`, {
        method: 'PUT',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Request failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get playlists for a channel (from backend)
  async getChannelPlaylists(token: string, channelId: number): Promise<ApiResponse<Array<{
    id: string;
    title: string;
    description: string;
    thumbnail: string;
    itemCount: number;
    publishedAt: string;
    privacy: string;
  }>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}/playlists`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Update thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async deleteThumbnail(id: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getThumbnailStatus(id: string): Promise<ApiResponse<Thumbnail>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get thumbnail status failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async downloadThumbnail(id: string): Promise<ApiResponse<Blob>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}/download`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Download thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      const blob = await response.blob();

      return {
        success: true,
        data: blob
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async enhanceThumbnailDescription(description: string): Promise<ApiResponse<{ original_description: string, description: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/enhance-description`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          description: description
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Enhance description failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get user's default reference image
  async getUserDefaultReferenceImage(): Promise<ApiResponse<{ has_default_image: boolean; image_url: string | null }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/default-image`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get default image failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || { has_default_image: false, image_url: null }
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Upload user's default reference image
  async uploadUserDefaultReferenceImage(image: File): Promise<ApiResponse<{ has_default_image: boolean; image_url: string }>> {
    try {
      const formData = new FormData();
      formData.append('image', image);

      const headers = this.getAuthHeaders();
      delete headers['Content-Type'];

      const response = await fetch(`${this.baseUrl}/api/user/default-image`, {
        method: 'POST',
        headers: headers,
        body: formData,
      });

      if (!response.ok) {
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return { success: false, error: errorJson.message };
          }
        } catch {
          // If JSON parsing fails, use default error
        }
        return { success: false, error: 'Failed to upload default image' };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Delete user's default reference image
  async deleteUserDefaultReferenceImage(): Promise<ApiResponse<{ message: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user/default-image`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return { success: false, error: errorJson.message };
          }
        } catch {
          // If JSON parsing fails, use default error
        }
        return { success: false, error: 'Failed to delete default image' };
      }

      const result = await response.json();
      return {
        success: true,
        data: result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // getCopyThumbnails is no longer needed - GET /api/thumbnails now returns both types

  // Copy Thumbnail - POST /api/thumbnails/copy
  async copyThumbnail(data: {
    method?: 'generate' | 'head_swap';
    quality?: 'fast' | 'normal' | 'high' | 'very_high';
    prompt?: string;
    baseImage?: File;           // Inspiration image (file upload)
    baseImageUrl?: string;      // Inspiration image (URL)
    referenceImage?: File;      // Reference image (optional if user has default)
    numberOfImages?: number;
  }): Promise<ApiResponse<Thumbnail>> {
    try {
      const formData = new FormData();

      // Method defaults to head_swap
      formData.append('method', data.method || 'head_swap');

      // Quality defaults to fast
      formData.append('quality', data.quality || 'fast');

      // Prompt is optional
      if (data.prompt) {
        formData.append('prompt', data.prompt);
      }

      // Base image - either file or URL
      if (data.baseImage) {
        formData.append('base_image', data.baseImage);
      } else if (data.baseImageUrl) {
        formData.append('source_image_url', data.baseImageUrl);
      }

      // Reference image (optional)
      if (data.referenceImage) {
        formData.append('reference_image', data.referenceImage);
      }

      // Number of images defaults to 1
      formData.append('number_of_images', (data.numberOfImages || 1).toString());

      // Get auth headers but remove Content-Type so browser sets it with boundary
      const headers = this.getAuthHeaders();
      delete headers['Content-Type'];

      const response = await fetch(`${this.baseUrl}/api/thumbnails/copy`, {
        method: 'POST',
        headers: headers,
        body: formData,
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use text
        }
        return {
          success: false,
          error: 'Failed to generate image'
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to generate image'
        };
      }

      const responseData = result.data || result;

      return {
        success: true,
        data: { ...responseData, type: 'copied' },
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Update Copy Thumbnail - PUT /api/thumbnails/{id}?type=copy
  async updateCopyThumbnail(id: string | number, data: {
    prompt: string;
  }): Promise<ApiResponse<Thumbnail>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}?type=copy`, {
        method: 'PUT',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Update copy thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: { ...(result.data || result), type: 'copied' },
        user_credits: result.user_credits,
        count: result.count
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Get Copy Thumbnail Status - GET /api/thumbnails/{id}/status?type=copy
  async getCopyThumbnailStatus(id: string | number): Promise<ApiResponse<Thumbnail>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}/status?type=copy`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get status failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      const responseData = result.data || result;

      return {
        success: true,
        data: { ...responseData, type: 'copied' },
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Delete Copy Thumbnail - DELETE /api/thumbnails/{id}?type=copy
  async deleteCopyThumbnail(id: string | number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}?type=copy`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete copy thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Download Copy Thumbnail - GET /api/thumbnails/{id}/download?type=copy
  async downloadCopyThumbnail(id: string | number): Promise<ApiResponse<Blob>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnails/${id}/download?type=copy`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Download copy thumbnail failed: ${response.status} - ${errorData}`
        };
      }

      const blob = await response.blob();

      return {
        success: true,
        data: blob
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async saveThumbnailBoard(data: {
    items: Array<{
      thumbnailId: string;
      title: string;
      x: number;
      y: number;
    }>;
    zoom?: number;
  }): Promise<ApiResponse<{ id: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/thumbnailBoards`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Save thumbnail board failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Model Management
  async getModels(): Promise<ApiResponse<Model[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/ai-models`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get models failed: ${response.status} - ${errorData}`
        };
      }

      const data = await response.json();

      return {
        success: true,
        data: data
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getAIModelTypes(): Promise<ApiResponse<Array<{ id: string | number; name?: string; value?: string } | string>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/ai-models/types`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get AI model types failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getAIModelEthnicities(): Promise<ApiResponse<Array<{ id: string | number; name?: string; value?: string } | string>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/ai-models/ethnicities`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get AI model ethnicities failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createModel(data: {
    name: string;
    images: File[];
    age?: number;
    type?: string;
    ethnicity?: string;
    bald_shaved_head?: boolean;
  }): Promise<ApiResponse<Model>> {
    try {
      const formData = new FormData();
      formData.append('name', data.name);

      // Append all image files as an array
      data.images.forEach((image, index) => {
        formData.append(`images[]`, image);
      });

      // Append optional fields
      if (data.age !== undefined) {
        formData.append('age', data.age.toString());
      }
      if (data.type) {
        formData.append('ai_model_type_id', data.type);
      }
      if (data.ethnicity) {
        formData.append('ethnicity_id', data.ethnicity);
      }
      if (data.bald_shaved_head !== undefined) {
        formData.append('bald', data.bald_shaved_head ? '1' : '0');
      }

      // Debug: Log what we're sending
      console.log('Creating model with:', {
        name: data.name,
        imageCount: data.images.length,
        age: data.age,
        ai_model_type_id: data.type,
        ethnicity_id: data.ethnicity,
        bald: data.bald_shaved_head
      });

      const response = await fetch(`${this.baseUrl}/api/ai-models`, {
        method: 'POST',
        headers: {
          'Authorization': this.getAuthHeaders()['Authorization'],
          // Don't set Content-Type, let browser set it with boundary for FormData
        },
        body: formData,
      });

      if (!response.ok) {
        const errorData = await response.text();
        console.error('Create model error response:', errorData);
        return {
          success: false,
          error: `Create model failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      console.error('Create model network error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Disconnect/Delete a channel
  async disconnectChannel(token: string, channelId: number): Promise<ApiResponse<{
    message: string;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/channels/${channelId}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
      });

      if (!response.ok) {
        const errorData = await response.text();
        console.error('Create model error response:', errorData);
        return {
          success: false,
          error: `Create model failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();

      return {
        success: true,
        data: result
      };
    } catch (error) {
      console.error('Create model network error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getModelStatus(id: string | number): Promise<ApiResponse<Model>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/ai-models/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get model status failed: ${response.status} - ${errorData}`
        };
      }

      const data = await response.json();

      return {
        success: true,
        data: data
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async deleteModel(id: string | number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/ai-models/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete model failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Projects Management
  async getProjects(): Promise<ApiResponse<Project[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get projects failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getProject(id: string): Promise<ApiResponse<Project & { titles?: Title[] }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get project failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async generateTitlesFromProject(description: string): Promise<ApiResponse<Array<{
    title: string;
    virality_score: number;
    generated: boolean;
  }>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/titles/generate-from-project`, {
        method: 'POST',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          project: {
            description: description
          }
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Generate titles from project failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      console.error('Generate titles from project error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createTitlesForProject(id: string): Promise<ApiResponse<Title[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects/create_titles`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ id }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Create titles failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Title Management
  async getTitles(): Promise<ApiResponse<Title[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/titles`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get titles failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async generateTitle(data: {
    description: string;
    generate_titles: boolean;
  }): Promise<ApiResponse<Title[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use generic error
        }
        return {
          success: false,
          error: 'Failed to generate title'
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to generate title'
        };
      }

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async deleteTitle(id: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/titles/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete title failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getTitleStatus(id: string): Promise<ApiResponse<Title>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/titles/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get title status failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Script Management
  async getScripts(page: number = 1, perPage: number = 10): Promise<ApiResponse<PaginatedResponse<Script>>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts?page=${page}&per_page=${perPage}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get scripts failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Map backend structure to PaginatedResponse
      const paginatedData: PaginatedResponse<Script> = {
        data: result.data,
        current_page: result.pagination?.current_page || 1,
        last_page: result.pagination?.last_page || 1,
        per_page: result.pagination?.per_page || perPage,
        total: result.pagination?.total || 0,
        from: result.pagination?.from || null,
        to: result.pagination?.to || null,
      };

      return {
        success: true,
        data: paginatedData,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async generateScripts(data: {
    description: string;
    number_of_scripts?: number;
  }): Promise<ApiResponse<Script[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Generate scripts failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createScript(data: {
    title: string;
    prompt: string;
    script_length?: number;
    inspirations?: {
      youtubeUrls?: string[];
    };
  }): Promise<ApiResponse<Script>> {
    try {
      // Validate prompt before processing
      if (!data.prompt || typeof data.prompt !== 'string' || data.prompt.trim() === '') {
        console.error('Prompt validation failed:', {
          prompt: data.prompt,
          type: typeof data.prompt
        });
        return {
          success: false,
          error: 'Prompt field is required and must be a non-empty string'
        };
      }

      // Always use JSON format
      const headers: HeadersInit = {
        ...this.getAuthHeaders(),
        'Content-Type': 'application/json',
      };

      const jsonData: {
        title?: string;
        prompt: string;
        script_length?: number;
        youtube_urls?: string[];
      } = {
        prompt: data.prompt,
      };

      // Add title if provided
      if (data.title && data.title.trim()) {
        jsonData.title = data.title.trim();
      }

      // Add script_length if provided
      if (data.script_length) {
        jsonData.script_length = data.script_length;
      }

      // Only add YouTube URLs if they exist (files are no longer supported)
      if (data.inspirations?.youtubeUrls && data.inspirations.youtubeUrls.length > 0) {
        jsonData.youtube_urls = data.inspirations.youtubeUrls;
      }

      const body = JSON.stringify(jsonData);

      const response = await fetch(`${this.baseUrl}/api/scripts`, {
        method: 'POST',
        headers,
        body,
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use text
          const errorData = await response.text();
          return {
            success: false,
            error: `Create script failed: ${response.status} - ${errorData}`
          };
        }
        return {
          success: false,
          error: 'Failed to create script'
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to create script'
        };
      }

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      console.error('Create script error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async updateScript(id: string | number, data: {
    text?: string;
    title?: string;
    prompt?: string;
    script_length?: number;
    regenerate?: boolean;
    refine?: boolean;
  }): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}`, {
        method: 'PUT',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Update script failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      console.error('Update script error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async regenerateScript(id: string, data: {
    prompt: string;
    length: number;
  }): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}`, {
        method: 'PUT',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Regenerate script failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      console.error('Regenerate script error:', error);
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async deleteScript(id: string): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete script failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async getScriptStatus(id: string): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Get script status failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createScriptForProject(id: string): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/projects/${id}/create_script`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        // Try to parse JSON error response to extract message
        try {
          const errorJson = await response.json();
          if (errorJson.message) {
            return {
              success: false,
              error: errorJson.message
            };
          }
        } catch {
          // If JSON parsing fails, use generic error
        }
        return {
          success: false,
          error: 'Failed to create script'
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);

      // Check if API returned success: false (e.g., insufficient credits)
      if (result.success === false) {
        return {
          success: false,
          error: result.message || 'Failed to create script'
        };
      }

      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // User Data Management
  async deleteAllUserData(): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user-data`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Delete user data failed: ${response.status} - ${errorData}`
        };
      }

      return {
        success: true
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Subscription Management
  /**
   * Cancel a PayPal subscription
   * 
   * This sends a request to the backend to cancel the subscription via PayPal's API.
   * The backend should call PayPal's POST /v1/billing/subscriptions/{subscription_id}/cancel endpoint.
   * 
   * @param subscriptionId - The PayPal subscription ID to cancel
   * @param reason - Optional reason for cancellation
   * @returns ApiResponse indicating success or failure
   */
  async cancelSubscription(subscriptionId: string, reason?: string): Promise<ApiResponse<{ message: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/subscriptions/${subscriptionId}/cancel`, {
        method: 'POST',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          subscription_id: subscriptionId,
          reason: reason || 'User requested cancellation',
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: errorData.message || `Cancel subscription failed: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || { message: 'Subscription cancelled successfully' },
        message: result.message
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Plan Subscription Management
  /**
   * Subscribe user to a plan
   */
  async subscribeToPlan(planId: string | null, subscriptionId: string, paypalPlanId?: string): Promise<ApiResponse<{ plan: any; plan_id?: string; user: any; credits_allocated: number }> & { status?: number; processing?: boolean }> {
    try {
      const requestBody: any = {
        paypal_subscription_id: subscriptionId,
        paypal_plan_id: paypalPlanId,
      };

      // Only include plan_id if it's provided (commented out for now)
      if (planId) {
        requestBody.plan_id = planId;
      }

      const response = await fetch(`${this.baseUrl}/api/user-plans/subscribe`, {
        method: 'POST',
        headers: {
          ...this.getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestBody),
      });

      const result = await response.json();

      // Extract credits from response to update UI immediately
      this.extractCreditsFromResponse(result);

      // Handle 202 Accepted (Processing) as a special success case
      if (response.status === 202) {
        return {
          success: true,
          status: 202,
          processing: true,
          data: result.data,
          message: result.message,
          user_credits: result.user_credits
        };
      }

      if (!response.ok) {
        return {
          success: false,
          status: response.status,
          error: result.message || `Subscribe failed: ${response.status}`
        };
      }

      return {
        success: true,
        status: response.status,
        data: result.data,
        message: result.message,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Plan Management
  /**
   * Get current user's plan
   */
  async getCurrentPlan(): Promise<ApiResponse<{ plan: any; pivot: any }>> {
    if (isMockApi()) return mockApi.getCurrentPlan();
    try {
      const response = await fetch(`${this.baseUrl}/api/user-plans/current`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: errorData.message || `Get current plan failed: ${response.status}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data,
        message: result.message,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  /**
   * Fetch the active, public pricing plans (the 4 tiers) with their numeric
   * limits and Stripe price ids. Powers the pricing page.
   */
  async getPlans(): Promise<ApiResponse<PlanTier[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/plans`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
        return { success: false, error: errorData.message || `Get plans failed: ${response.status}` };
      }

      const result = await response.json();
      return { success: true, data: result.data, message: result.message };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  /**
   * Upgrade or downgrade the current subscription to another tier's price.
   * Stripe prorates. The backend blocks a downgrade that would leave the user
   * over the target plan's offer cap (HTTP 422) — that message is surfaced as
   * `error` so the UI can prompt the user to delete offers first.
   */
  async changePlan(priceId: string): Promise<ApiResponse<{ subscription_id: string; new_price_id: string; status: string | null }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/user-plans/change-plan`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ price_id: priceId }),
      });

      const result = await response.json().catch(() => ({}));

      if (!response.ok) {
        return {
          success: false,
          error: result.message || `Change plan failed: ${response.status}`,
          status: response.status,
        };
      }

      return { success: true, data: result.data, message: result.message };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }
  // Script Architecture & Library Methods

  async getLibraryComponents(): Promise<ApiResponse<LibraryComponent[]>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/library-components`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to fetch library: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async createLibraryComponent(data: Omit<Partial<LibraryComponent>, 'tags'> & { tags?: string[] }): Promise<ApiResponse<LibraryComponent>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/library-components`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to create component: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async updateLibraryComponent(id: number, data: Omit<Partial<LibraryComponent>, 'tags'> & { tags?: string[] }): Promise<ApiResponse<LibraryComponent>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/library-components/${id}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to update component: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async deleteLibraryComponent(id: number): Promise<ApiResponse<void>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/library-components/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to delete component: ${await response.text()}` };
      }
      return { success: true };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async updateScriptResearch(scriptId: number, body: string): Promise<ApiResponse<ScriptResearch>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${scriptId}/research`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ body }),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to update research: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async createScriptResearch(scriptId: number): Promise<ApiResponse<ScriptResearch>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${scriptId}/research/generate`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to generate research: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async saveScriptComponents(scriptId: number, componentIds: number[]): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${scriptId}/components`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ component_ids: componentIds }),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to save components: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  async generateFinalScript(scriptId: number): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${scriptId}/generate`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to start generation: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }
  async getScript(id: number | string): Promise<ApiResponse<Script>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/scripts/${id}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        return { success: false, error: `Failed to fetch script: ${await response.text()}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: error.message };
    }
  }

  // ---------------------------------------------------------------------------
  // Onboarding
  // ---------------------------------------------------------------------------

  // Marks onboarding complete (backend validates an active/trialing subscription;
  // connecting an account is optional) and returns the updated user object.
  async completeOnboarding(): Promise<ApiResponse<{ user: User }>> {
    if (isMockApi()) return mockApi.completeOnboarding();
    try {
      const response = await fetch(`${this.baseUrl}/api/onboarding/complete`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || `Failed to complete onboarding: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: { user: result.data?.user || result.data },
        message: result.message
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // ---------------------------------------------------------------------------
  // Multi-provider account connections (YouTube / TikTok / Instagram)
  // ---------------------------------------------------------------------------

  async getConnections(): Promise<ApiResponse<Connection[]>> {
    if (isMockApi()) return mockApi.getConnections();
    try {
      const response = await fetch(`${this.baseUrl}/api/connections`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch connections: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || []
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Generic OAuth code exchange that stores a connection for the given provider.
  // Generalizes the YouTube-specific exchangeYouTubeCode flow.
  async exchangeOAuthCode(provider: OAuthProvider, code: string, redirectUri: string, codeVerifier?: string): Promise<ApiResponse<{ connection: Connection }>> {
    if (isMockApi()) return mockApi.exchangeOAuthCode(provider);
    try {
      const response = await fetch(`${this.baseUrl}/api/auth/${provider}/exchange`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          code,
          redirect_uri: redirectUri,
          ...(codeVerifier ? { code_verifier: codeVerifier } : {}),
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Connection failed: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: result.success ?? true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async disconnectConnection(id: number): Promise<ApiResponse<void>> {
    if (isMockApi()) return mockApi.disconnectConnection(id);
    try {
      const response = await fetch(`${this.baseUrl}/api/connections/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to disconnect: ${response.status} - ${errorData}`
        };
      }

      return { success: true };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // ---------------------------------------------------------------------------
  // Multi-platform social accounts (newer `/api/social` system — e.g. X).
  // Separate from the legacy Connection store above; used for platforms whose
  // OAuth lives in the SocialAccount system.
  // ---------------------------------------------------------------------------

  async getSocialAccounts(): Promise<ApiResponse<SocialAccount[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const response = await fetch(`${this.baseUrl}/api/social/accounts`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to fetch social accounts: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async getSocialPlatforms(): Promise<ApiResponse<SocialPlatformInfo[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const response = await fetch(`${this.baseUrl}/api/social/platforms`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to fetch platforms: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // ---------------------------------------------------------------------------
  // Brands: named groups of connected accounts (both stores) for one-click
  // composer selection.
  // ---------------------------------------------------------------------------

  async getBrands(): Promise<ApiResponse<Brand[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const response = await fetch(`${this.baseUrl}/api/brands`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to fetch brands: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async createBrand(data: BrandPayload): Promise<ApiResponse<Brand>> {
    return this.saveBrand(`${this.baseUrl}/api/brands`, 'POST', data);
  }

  async updateBrand(id: number, data: BrandPayload): Promise<ApiResponse<Brand>> {
    return this.saveBrand(`${this.baseUrl}/api/brands/${id}`, 'PUT', data);
  }

  private async saveBrand(url: string, method: string, data: BrandPayload): Promise<ApiResponse<Brand>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(url, {
        method,
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.success === false) {
        // Surface field errors (e.g. duplicate name) rather than a raw dump.
        const fieldErrors = result?.errors ? Object.values(result.errors).flat().join(' ') : '';
        return { success: false, error: result.message || fieldErrors || `Failed to save brand: ${response.status}` };
      }
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async deleteBrand(id: number): Promise<ApiResponse<void>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}/api/brands/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to delete brand: ${response.status}` };
      return { success: true };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // X user search for the composer's @mention typeahead. Any failure resolves
  // as an error result — the composer degrades to recent mentions.
  async searchXUsers(q: string, socialAccountId?: number | null): Promise<ApiResponse<XUserSearchResult>> {
    if (isMockApi()) return { success: true, data: { users: [], mode: 'search', degraded: false } };
    try {
      const params = new URLSearchParams({ q });
      if (socialAccountId != null) params.append('social_account_id', String(socialAccountId));
      const response = await fetch(`${this.baseUrl}/api/social/x/users/search?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.success === false) {
        return { success: false, error: result.message || `X user search failed: ${response.status}` };
      }
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // Connect a Bluesky account with handle + app password (Bluesky has no
  // OAuth — see Settings → App Passwords on bsky.app).
  async connectBlueskyAccount(identifier: string, password: string): Promise<ApiResponse<SocialAccount[]>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}/api/social/bluesky/connect`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({ identifier, password }),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.success === false) {
        return { success: false, error: result.message || `Failed to connect Bluesky: ${response.status}` };
      }
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // ---------------------------------------------------------------------------
  // Boosts — like-threshold automations per connected X account.
  // ---------------------------------------------------------------------------

  async getBoostSettings(): Promise<ApiResponse<BoostSetting[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const response = await fetch(`${this.baseUrl}/api/boosts/settings`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to fetch boost settings: ${response.status}` };
      const result = await response.json();
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async updateBoostSetting(socialAccountId: number, setting: {
    feature: "auto_repost" | "auto_promo";
    enabled: boolean;
    likes_threshold: number;
    promo_text?: string | null;
  }): Promise<ApiResponse<BoostSetting>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}/api/boosts/settings/${socialAccountId}`, {
        method: 'PUT',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(setting),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) return { success: false, error: result.message || `Failed to save boost setting: ${response.status}` };
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async getBoostActivity(): Promise<ApiResponse<BoostCheck[]>> {
    if (isMockApi()) return { success: true, data: [] };
    try {
      const response = await fetch(`${this.baseUrl}/api/boosts/activity`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) return { success: false, error: `Failed to fetch boost activity: ${response.status}` };
      const result = await response.json();
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // ---------------------------------------------------------------------------
  // Automations — Instagram comment / story-reply / DM auto-responders.
  // Every call shares one wrapper so 422s carry the backend `message` and
  // `code` (e.g. "reconnect_required") through to the UI.
  // ---------------------------------------------------------------------------

  private async automationCall<T>(path: string, init: RequestInit = {}, fallback = 'Request failed'): Promise<ApiResponse<T>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}${path}`, { headers: this.getAuthHeaders(), ...init });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        return { success: false, error: result.message || `${fallback}: ${response.status}`, code: result.code };
      }
      return { success: true, data: result.data as T, message: result.message };
    } catch (error) {
      return { success: false, error: `Network error: ${(error as Error).message}` };
    }
  }

  async getAutomations(params: { search?: string; trigger_type?: string; status?: string } = {}): Promise<ApiResponse<AutomationListResponse>> {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) if (v) qs.set(k, v);
    const suffix = qs.toString() ? `?${qs}` : '';
    return this.automationCall<AutomationListResponse>(`/api/automations${suffix}`, { method: 'GET' }, 'Failed to load automations');
  }

  async getAutomation(id: number): Promise<ApiResponse<Automation>> {
    return this.automationCall<Automation>(`/api/automations/${id}`, { method: 'GET' }, 'Failed to load the automation');
  }

  async createAutomation(payload: AutomationPayload): Promise<ApiResponse<Automation>> {
    return this.automationCall<Automation>('/api/automations', { method: 'POST', body: JSON.stringify(payload) }, 'Failed to create the automation');
  }

  async updateAutomation(id: number, payload: AutomationPayload): Promise<ApiResponse<Automation>> {
    return this.automationCall<Automation>(`/api/automations/${id}`, { method: 'PUT', body: JSON.stringify(payload) }, 'Failed to save the automation');
  }

  async deleteAutomation(id: number): Promise<ApiResponse<null>> {
    return this.automationCall<null>(`/api/automations/${id}`, { method: 'DELETE' }, 'Failed to delete the automation');
  }

  async startAutomation(id: number): Promise<ApiResponse<Automation>> {
    return this.automationCall<Automation>(`/api/automations/${id}/start`, { method: 'POST' }, 'Failed to start the automation');
  }

  async stopAutomation(id: number): Promise<ApiResponse<Automation>> {
    return this.automationCall<Automation>(`/api/automations/${id}/stop`, { method: 'POST' }, 'Failed to stop the automation');
  }

  async getAutomationRuns(id: number, page = 1): Promise<ApiResponse<Paginated<AutomationRun>>> {
    return this.automationCall<Paginated<AutomationRun>>(`/api/automations/${id}/runs?page=${page}`, { method: 'GET' }, 'Failed to load runs');
  }

  async getAutomationAccounts(): Promise<ApiResponse<AutomationAccountsResponse>> {
    return this.automationCall<AutomationAccountsResponse>('/api/automations/accounts', { method: 'GET' }, 'Failed to load Instagram accounts');
  }

  async getAutomationMedia(socialAccountId: number, after?: string | null, refresh = false): Promise<ApiResponse<InstagramMediaPage>> {
    const qs = new URLSearchParams({ social_account_id: String(socialAccountId) });
    if (after) qs.set('after', after);
    if (refresh) qs.set('refresh', '1');
    return this.automationCall<InstagramMediaPage>(`/api/automations/media?${qs}`, { method: 'GET' }, 'Failed to load your posts');
  }

  // ---------------------------------------------------------------------------
  // Per-user preference toggles (Settings → Notifications).
  // ---------------------------------------------------------------------------

  async getUserSettings(): Promise<ApiResponse<UserSettings>> {
    if (isMockApi()) return { success: true, data: { notify_post_failures: true } };
    try {
      const response = await fetch(`${this.baseUrl}/api/user/settings`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to fetch settings: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async updateUserSettings(settings: Partial<UserSettings>): Promise<ApiResponse<UserSettings>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}/api/user/settings`, {
        method: 'PATCH',
        headers: { ...this.getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(settings),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to update settings: ${response.status}` };
      }
      const result = await response.json();
      return { success: true, data: result.data };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // Ask the backend to build the OAuth authorization URL for a platform. The
  // backend keeps the PKCE verifier + CSRF state server-side and returns the
  // opaque `state` we echo back at exchange time.
  async getSocialAuthUrl(platform: string, redirectUri: string): Promise<ApiResponse<{ authorization_url: string; state: string; redirect_uri: string }>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const params = new URLSearchParams({ redirect_uri: redirectUri });
      const response = await fetch(`${this.baseUrl}/api/social/${platform}/auth-url?${params.toString()}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        return { success: false, error: result.message || `Failed to start ${platform} connect: ${response.status}` };
      }
      return { success: true, data: result.data || result };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // Exchange the authorization code (returned to the popup) for stored tokens.
  async exchangeSocialCode(platform: string, payload: { code: string; state?: string; redirectUri?: string }): Promise<ApiResponse<SocialAccount[]>> {
    if (isMockApi()) return { success: false, error: 'Not available in demo mode.' };
    try {
      const response = await fetch(`${this.baseUrl}/api/social/${platform}/exchange`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify({
          code: payload.code,
          ...(payload.state ? { state: payload.state } : {}),
          ...(payload.redirectUri ? { redirect_uri: payload.redirectUri } : {}),
        }),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        return { success: false, error: result.message || `Connection failed: ${response.status}` };
      }
      return { success: true, data: result.data || [] };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  async disconnectSocialAccount(id: number): Promise<ApiResponse<void>> {
    if (isMockApi()) return { success: true };
    try {
      const response = await fetch(`${this.baseUrl}/api/social/accounts/${id}`, {
        method: 'DELETE',
        headers: this.getAuthHeaders(),
      });
      if (!response.ok) {
        return { success: false, error: `Failed to disconnect: ${response.status}` };
      }
      return { success: true };
    } catch (error) {
      return { success: false, error: `Network error: ${error.message}` };
    }
  }

  // ---------------------------------------------------------------------------
  // Stripe billing (onboarding trial: $0 today, $29/mo after 3-day trial)
  // ---------------------------------------------------------------------------

  async createStripeSetupIntent(): Promise<ApiResponse<{ client_secret: string; customer_id: string }>> {
    if (isMockApi()) return mockApi.createStripeSetupIntent();
    try {
      const response = await fetch(`${this.baseUrl}/api/billing/stripe/setup-intent`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || `Failed to start card setup: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Billing status incl. past_due (getCurrentPlan hides past_due to gate features).
  // Used by the billing page to show a "payment failed — update card" banner.
  async getBillingStatus(): Promise<ApiResponse<{
    has_subscription: boolean;
    status?: string;
    plan_display_name?: string;
    plan_id?: string;
    subscription_id?: string;
    expires_at?: string | null;
  }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/billing/status`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || `Failed to load billing status: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // Mint a Stripe Billing Portal URL so the user can update their card / pay an
  // open invoice — the recovery path out of past_due. The past_due -> active flip
  // is handled by the invoice_payment.paid webhook once they pay.
  async createBillingPortalSession(): Promise<ApiResponse<{ url: string }>> {
    try {
      const response = await fetch(`${this.baseUrl}/api/billing/stripe/portal`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || `Failed to open billing portal: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createStripeSubscription(paymentMethodId: string, priceId?: string): Promise<ApiResponse<{
    stripe_subscription_id: string;
    status: string;
    current_period_end: string;
    plan_id?: string;
  }>> {
    if (isMockApi()) return mockApi.createStripeSubscription();
    try {
      const response = await fetch(`${this.baseUrl}/api/billing/stripe/subscribe`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        // price_id tells the backend which tier the user chose; omitted → trial default.
        // referral: Rewardful's visitor UUID when the signup came through an
        // affiliate link — the backend pins it to the Stripe customer so the
        // conversion is credited to the affiliate.
        body: JSON.stringify({
          payment_method_id: paymentMethodId,
          ...(priceId ? { price_id: priceId } : {}),
          ...(rewardfulReferral() ? { referral: rewardfulReferral() } : {}),
        }),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: data.message || `Failed to start subscription: ${response.status}`
        };
      }

      const result = await response.json();
      this.extractCreditsFromResponse(result);
      return {
        success: true,
        data: result.data || result,
        user_credits: result.user_credits
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  // ---------------------------------------------------------------------------
  // Feature requests
  // ---------------------------------------------------------------------------

  async getFeatureRequests(sort: 'top' | 'new' = 'top'): Promise<ApiResponse<FeatureRequest[]>> {
    if (isMockApi()) return mockApi.getFeatureRequests(sort);
    try {
      const response = await fetch(`${this.baseUrl}/api/feature-requests?sort=${sort}`, {
        method: 'GET',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to fetch feature requests: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || []
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async createFeatureRequest(data: { title: string; description: string; category?: string }): Promise<ApiResponse<FeatureRequest>> {
    if (isMockApi()) return mockApi.createFeatureRequest(data);
    try {
      const response = await fetch(`${this.baseUrl}/api/feature-requests`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
        return {
          success: false,
          error: errorData.message || `Failed to submit feature request: ${response.status}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

  async upvoteFeatureRequest(id: number): Promise<ApiResponse<{ upvotes_count: number; has_upvoted: boolean }>> {
    if (isMockApi()) return mockApi.upvoteFeatureRequest(id);
    try {
      const response = await fetch(`${this.baseUrl}/api/feature-requests/${id}/upvote`, {
        method: 'POST',
        headers: this.getAuthHeaders(),
      });

      if (!response.ok) {
        const errorData = await response.text();
        return {
          success: false,
          error: `Failed to upvote: ${response.status} - ${errorData}`
        };
      }

      const result = await response.json();
      return {
        success: true,
        data: result.data || result
      };
    } catch (error) {
      return {
        success: false,
        error: `Network error: ${error.message}`
      };
    }
  }

}

// Export singleton instance
export const viewsMaxApi = new ViewsMaxApiService();
export type { ApiResponse, AuthSession, Thumbnail, CopyThumbnail, Title, Script, ScriptResearch, LibraryComponent, Project, Model };
