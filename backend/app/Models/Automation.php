<?php

namespace App\Models;

use App\Services\Automations\KeywordMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * A ManyChat-style automation: when someone comments on a post / replies to
 * a story / sends a DM (optionally containing keywords), answer with an
 * optional public reply and a DM (text, or a card with a tracked button).
 */
class Automation extends Model
{
    use SoftDeletes;

    public const TRIGGER_COMMENT = 'comment';
    public const TRIGGER_STORY_REPLY = 'story_reply';
    public const TRIGGER_DM = 'dm';
    public const TRIGGERS = [self::TRIGGER_COMMENT, self::TRIGGER_STORY_REPLY, self::TRIGGER_DM];

    public const STATUS_LIVE = 'live';
    public const STATUS_STOPPED = 'stopped';
    public const STATUSES = [self::STATUS_LIVE, self::STATUS_STOPPED];

    public const POST_MATCH_SPECIFIC = 'specific';
    public const POST_MATCH_ANY = 'any';
    public const POST_MATCHES = [self::POST_MATCH_SPECIFIC, self::POST_MATCH_ANY];

    public const KEYWORD_ANY = 'any';
    public const KEYWORD_CONTAINS = 'contains';
    public const KEYWORD_EXACT = 'exact';
    public const KEYWORD_MODES = [self::KEYWORD_ANY, self::KEYWORD_CONTAINS, self::KEYWORD_EXACT];

    /** Instagram scopes the account must carry before an automation can run. */
    public const REQUIRED_IG_SCOPES = [
        'instagram_business_manage_comments',
        'instagram_business_manage_messages',
    ];

    /** Instagram limits. */
    public const DM_TEXT_MAX = 1000;
    public const CARD_TITLE_MAX = 80;
    public const CARD_SUBTITLE_MAX = 80;
    public const BUTTON_LABEL_MAX = 20;
    public const MAX_POSTS = 50;
    public const MAX_KEYWORDS = 30;
    public const MAX_REPLY_TEXTS = 5;

    protected $fillable = [
        'user_id',
        'social_account_id',
        'platform',
        'name',
        'trigger_type',
        'status',
        'post_match',
        'posts',
        'include_replies',
        'keyword_mode',
        'keywords',
        'cooldown_hours',
        'reply_enabled',
        'reply_texts',
        'dm_text',
        'dm_subtitle',
        'dm_image_url',
        'dm_button_label',
        'dm_button_url',
        'last_run_at',
        'last_error',
    ];

    protected $casts = [
        'posts' => 'array',
        'keywords' => 'array',
        'reply_texts' => 'array',
        'include_replies' => 'boolean',
        'reply_enabled' => 'boolean',
        'cooldown_hours' => 'integer',
        'last_run_at' => 'datetime',
    ];

    protected $attributes = [
        'platform' => 'instagram',
        'name' => 'Untitled',
        'status' => self::STATUS_STOPPED,
        'keyword_mode' => self::KEYWORD_ANY,
        'cooldown_hours' => 24,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function shortLinks(): HasMany
    {
        return $this->hasMany(ShortLink::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_LIVE);
    }

    /**
     * Runs / DMs sent / clicked counts for the index. CTR = clicked / dms_sent
     * (per recipient, so it can never exceed 100%).
     */
    public function scopeWithStats(Builder $query): Builder
    {
        return $query->withCount([
            'runs',
            'runs as dms_sent_count' => fn (Builder $q) => $q->where('dm_status', AutomationRun::DM_SENT),
            'runs as clicked_count' => fn (Builder $q) => $q->whereNotNull('clicked_at'),
        ]);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isCommentTrigger(): bool
    {
        return $this->trigger_type === self::TRIGGER_COMMENT;
    }

    /** With a button the DM is sent as a card (title = dm_text, <= 80 chars). */
    public function hasButton(): bool
    {
        return filled($this->dm_button_url) && filled($this->dm_button_label);
    }

    /** @return array<int, string> */
    public function mediaIds(): array
    {
        return collect($this->posts ?? [])
            ->map(fn ($post) => is_array($post) ? ($post['id'] ?? null) : $post)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /** Comment trigger: does this event's media fall under the post filter? */
    public function matchesMedia(?string $mediaId): bool
    {
        if (! $this->isCommentTrigger() || $this->post_match !== self::POST_MATCH_SPECIFIC) {
            return true;
        }

        return $mediaId !== null && in_array((string) $mediaId, $this->mediaIds(), true);
    }

    /** The matched keyword ("*" for any), or null when the text doesn't match. */
    public function matchKeyword(?string $text): ?string
    {
        return KeywordMatcher::match($this->keyword_mode, $this->keywords ?? [], $text);
    }

    /** One of reply_texts at random — identical replies get flagged as spam. */
    public function pickReplyText(): ?string
    {
        $texts = array_values(array_filter(array_map('trim', $this->reply_texts ?? [])));

        return $texts ? Arr::random($texts) : null;
    }

    /**
     * Match priority when several live automations fit one event: a specific
     * post beats "any post", keywords beat "any word". Higher wins.
     */
    public function specificity(): int
    {
        $score = 0;
        if ($this->isCommentTrigger() && $this->post_match === self::POST_MATCH_SPECIFIC) {
            $score += 2;
        }
        if ($this->keyword_mode !== self::KEYWORD_ANY) {
            $score += 1;
        }

        return $score;
    }

    /** clicked / dms_sent, or null when nothing was sent (needs withStats). */
    public function ctr(): ?float
    {
        $sent = (int) ($this->dms_sent_count ?? 0);
        if ($sent === 0) {
            return null;
        }

        return round(((int) ($this->clicked_count ?? 0)) / $sent, 4);
    }

    /** "User comments on a specific Post or Reel and comment contains price, link". */
    public function triggerSummary(): string
    {
        $keywords = implode(', ', $this->keywords ?? []);
        $condition = match ($this->keyword_mode) {
            self::KEYWORD_CONTAINS => $keywords !== '' ? " and {subject} contains {$keywords}" : '',
            self::KEYWORD_EXACT => $keywords !== '' ? " and {subject} is exactly {$keywords}" : '',
            default => '',
        };

        return match ($this->trigger_type) {
            self::TRIGGER_COMMENT => 'User comments on '
                .($this->post_match === self::POST_MATCH_SPECIFIC ? 'a specific Post or Reel' : 'any Post or Reel')
                .str_replace('{subject}', 'comment', $condition),
            self::TRIGGER_STORY_REPLY => 'User replies to any Story'.str_replace('{subject}', 'message', $condition),
            default => 'User sends a DM'.str_replace('{subject}', 'message', $condition),
        };
    }
}
