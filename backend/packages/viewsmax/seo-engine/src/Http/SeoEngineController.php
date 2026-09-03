<?php

namespace ViewsMax\SeoEngine\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use ViewsMax\SeoEngine\Models\SeoArticle;
use ViewsMax\SeoEngine\Models\SeoBacklinkProspect;
use ViewsMax\SeoEngine\Models\SeoKeyword;
use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Support\OutboundUrl;

/**
 * User-scoped SEO API: each user configures a profile per OFFER (competitors,
 * their WordPress blog, cadence) and curates that profile's keywords, article
 * queue, and backlink prospects. Publishing itself stays with the pipeline —
 * the API can move an article between `review` and `queued`, never straight to
 * `published`.
 */
class SeoEngineController
{
    /** The caller's profiles (one per offer). */
    public function profiles(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SeoProfile::where('user_id', $request->user()->id)->get()
                ->map(fn (SeoProfile $p) => $this->profilePayload($p)),
        ]);
    }

    /** Create or update the profile for one of the caller's offers. */
    public function upsertProfile(Request $request, int $offerId): JsonResponse
    {
        $offerModel = config('seo-engine.offer_model');
        $offer = $offerModel::where('id', $offerId)->where('user_id', $request->user()->id)->first();
        if (! $offer) {
            return response()->json(['success' => false, 'message' => 'Offer not found.'], 404);
        }

        $data = $request->validate([
            'competitors' => 'required|array|min:1|max:10',
            'competitors.*' => 'string|max:255',
            'wp_url' => ['nullable', 'url', 'max:255', $this->publicUrl()],
            'wp_username' => 'nullable|string|max:255',
            'wp_app_password' => 'nullable|string|max:255',
            'articles_per_week' => 'sometimes|integer|min:0|max:21',
            'auto_publish' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ]);

        // Normalise competitor entries down to bare domains.
        $data['competitors'] = collect($data['competitors'])
            ->map(fn ($d) => strtolower(preg_replace('#^https?://(www\.)?|/.*$#', '', trim($d))))
            ->filter()->unique()->values()->all();

        // Never blank a stored app password when the field is omitted/empty
        // (the UI can't render it back — it's encrypted at rest).
        if (empty($data['wp_app_password'])) {
            unset($data['wp_app_password']);
        }

        $profile = SeoProfile::updateOrCreate(
            ['user_id' => $request->user()->id, 'tracking_event_id' => $offer->id],
            $data
        );

        return response()->json(['success' => true, 'data' => $this->profilePayload($profile->fresh())]);
    }

    public function keywords(Request $request, int $profileId): JsonResponse
    {
        $profile = $this->ownProfile($request, $profileId);

        return response()->json([
            'success' => true,
            'data' => $profile->keywords()->latest()->limit(500)->get(),
        ]);
    }

    public function articles(Request $request, int $profileId): JsonResponse
    {
        $profile = $this->ownProfile($request, $profileId);

        return response()->json([
            'success' => true,
            'data' => $profile->articles()->with('keyword')->latest()->limit(200)->get(),
        ]);
    }

    public function prospects(Request $request, int $profileId): JsonResponse
    {
        $profile = $this->ownProfile($request, $profileId);

        return response()->json([
            'success' => true,
            'data' => $profile->prospects()->orderByDesc('domain_rank')->limit(500)->get(),
        ]);
    }

    public function updateKeyword(Request $request, int $id): JsonResponse
    {
        $keyword = SeoKeyword::findOrFail($id);
        $this->authorizeRow($request, $keyword->seo_profile_id);
        $keyword->update($request->validate([
            'status' => ['required', Rule::in([SeoKeyword::STATUS_DISCOVERED, SeoKeyword::STATUS_SKIPPED])],
        ]));

        return response()->json(['success' => true]);
    }

    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $article = SeoArticle::findOrFail($id);
        $this->authorizeRow($request, $article->seo_profile_id);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([SeoArticle::STATUS_REVIEW, SeoArticle::STATUS_QUEUED])],
            'title' => 'sometimes|string|max:255',
            'meta_description' => 'sometimes|nullable|string|max:320',
            'category' => ['sometimes', 'nullable', Rule::in(SeoArticle::CATEGORIES)],
            'html' => 'sometimes|string|max:200000',
            'featured_image_url' => ['sometimes', 'nullable', 'url', 'max:2048', $this->publicUrl()],
        ]);

        // Content edits only make sense pre-publish: a published article already
        // lives on WordPress and re-editing here would silently diverge from it.
        $contentKeys = array_intersect(array_keys($data), ['title', 'meta_description', 'category', 'html', 'featured_image_url']);
        if ($contentKeys && ! in_array($article->status, [SeoArticle::STATUS_REVIEW, SeoArticle::STATUS_QUEUED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only articles awaiting review or publish can be edited.',
            ], 422);
        }

        // Re-queuing (approve, or retry after a failure) starts a fresh publish
        // attempt — drop the stale error so the UI stops showing it.
        if (($data['status'] ?? null) === SeoArticle::STATUS_QUEUED) {
            $data['error'] = null;
        }

        $article->update($data);

        return response()->json(['success' => true, 'data' => $article->fresh()]);
    }

    public function updateProspect(Request $request, int $id): JsonResponse
    {
        $prospect = SeoBacklinkProspect::findOrFail($id);
        $this->authorizeRow($request, $prospect->seo_profile_id);
        $prospect->update($request->validate([
            'status' => ['sometimes', Rule::in([
                SeoBacklinkProspect::STATUS_NEW, SeoBacklinkProspect::STATUS_CONTACTED,
                SeoBacklinkProspect::STATUS_WON, SeoBacklinkProspect::STATUS_REJECTED,
            ])],
            'notes' => 'sometimes|nullable|string|max:2000',
        ]));

        return response()->json(['success' => true]);
    }

    /**
     * Set one status on many prospects at once. Rows the caller doesn't own
     * are skipped rather than erroring — a stale UI selection shouldn't void
     * the whole batch — and the response reports how many actually changed.
     */
    public function bulkUpdateProspects(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'status' => ['required', Rule::in([
                SeoBacklinkProspect::STATUS_NEW, SeoBacklinkProspect::STATUS_CONTACTED,
                SeoBacklinkProspect::STATUS_WON, SeoBacklinkProspect::STATUS_REJECTED,
            ])],
        ]);

        $updated = SeoBacklinkProspect::whereIn('id', $data['ids'])
            ->whereHas('profile', fn ($q) => $q->where('user_id', $request->user()->id))
            ->update(['status' => $data['status']]);

        return response()->json(['success' => true, 'data' => ['updated' => $updated]]);
    }

    private function ownProfile(Request $request, int $profileId): SeoProfile
    {
        return SeoProfile::where('id', $profileId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function authorizeRow(Request $request, ?int $profileId): void
    {
        abort_unless(
            $profileId && SeoProfile::where('id', $profileId)->where('user_id', $request->user()->id)->exists(),
            404
        );
    }

    /** Profile as the SPA needs it — app password never leaves the server. */
    private function profilePayload(SeoProfile $p): array
    {
        return [
            'id' => $p->id,
            'tracking_event_id' => $p->tracking_event_id,
            'competitors' => $p->competitors,
            'wp_url' => $p->wp_url,
            'wp_username' => $p->wp_username,
            'has_wp_password' => ! empty($p->wp_app_password),
            'articles_per_week' => $p->articles_per_week,
            'auto_publish' => $p->auto_publish,
            'enabled' => $p->enabled,
        ];
    }

    /**
     * The worker fetches these URLs server-side, so a private or loopback
     * address would be an SSRF. Reject it here with a clear message rather
     * than failing later inside the queue.
     */
    private function publicUrl(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && ! OutboundUrl::isPublic((string) $value)) {
                $fail("The {$attribute} must be a public http(s) address.");
            }
        };
    }
}
