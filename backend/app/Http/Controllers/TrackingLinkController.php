<?php

namespace App\Http\Controllers;

use App\Models\TrackingLink;
use App\Models\Offer;
use App\Models\Video;
use App\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * @group Tracking Links
 *
 * Tracked links tied to an offer. Place them in videos, posts, emails, or
 * bios; ViewsMax records clicks and attributes conversions back to the link
 * (and the post/platform) that drove them.
 */
class TrackingLinkController extends Controller
{
    protected $trackingService;

    public function __construct(TrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * List links for a specific event.
     */
    public function index($eventId)
    {
        // Ensure user owns the event
        $event = Auth::user()->offers()->findOrFail($eventId);

        return $event->links()->latest()->get();
    }

    /**
     * Create a new link.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'tracking_event_id' => [
                'required', 
                'integer',
                // Ensure the event belongs to the user
                function ($attribute, $value, $fail) {
                    if (!Offer::where('id', $value)->where('user_id', Auth::id())->exists()) {
                        $fail('The selected offer is invalid.');
                    }
                }
            ],
            'youtube_video_id' => [
                'nullable', 
                'string',
                // Ensure video belongs to user
                function ($attribute, $value, $fail) {
                    if ($value) {
                         $exists = Video::where('youtube_video_id', $value)
                            ->whereHas('channel', function($query) {
                                $query->where('user_id', Auth::id());
                            })->exists();
                        
                        if (!$exists) {
                            $fail('The selected video is invalid or does not belong to you.');
                        }
                    }
                }
            ], 
            'placement' => ['nullable', 'string', Rule::in([
                TrackingLink::PLACEMENT_VIDEO,
                TrackingLink::PLACEMENT_EMAIL,
                TrackingLink::PLACEMENT_X,
                TrackingLink::PLACEMENT_LINKEDIN,
                TrackingLink::PLACEMENT_PODCAST,
                TrackingLink::PLACEMENT_BLOG,
                TrackingLink::PLACEMENT_WEBSITE,
                TrackingLink::PLACEMENT_TIKTOK,
                TrackingLink::PLACEMENT_AD,
                TrackingLink::PLACEMENT_INSTAGRAM,
                TrackingLink::PLACEMENT_BEEHIIV,
                TrackingLink::PLACEMENT_OTHER
            ])],
            'placements' => 'nullable|array|max:8',
            'placements.*' => ['string', Rule::in(TrackingLink::PLACEMENTS)],
            'beehiiv_post_id' => 'nullable|string|max:255',
            'x_post_id' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! app(\App\Services\XPublishedPostsService::class)->existsFor(Auth::id(), $value)) {
                    $fail('The selected X post was not published through your account.');
                }
            }],
            'instagram_media_id' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! app(\App\Services\InstagramPublishedPostsService::class)->existsFor(Auth::id(), $value)) {
                    $fail('The selected Instagram post was not published through your account.');
                }
            }],
            'name' => 'nullable|string',
            'description' => 'nullable|string|max:255',
        ]);

        // Multi-platform links: `placements` is the declared list; the legacy
        // single `placement` mirrors its first entry.
        if (!empty($data['placements'])) {
            $data['placements'] = array_values(array_unique($data['placements']));
            $data['placement'] = $data['placements'][0];
        }

        // Placement is driven by the reach source when present (and joins the
        // declared list so multi-platform links keep it visible).
        if (!empty($data['youtube_video_id'])) {
            $data['placement'] = TrackingLink::PLACEMENT_VIDEO;
        } elseif (!empty($data['beehiiv_post_id'])) {
            $data['placement'] = TrackingLink::PLACEMENT_BEEHIIV;
        } else {
             $data['placement'] = $data['placement'] ?? TrackingLink::PLACEMENT_OTHER;
        }
        if (!empty($data['placements']) && !in_array($data['placement'], $data['placements'], true)) {
            array_unshift($data['placements'], $data['placement']);
        }

        // Security: Check ownership of event
        $event = Auth::user()->offers()->findOrFail($data['tracking_event_id']);

        $link = $this->trackingService->createLink($event->id, $data);

        return response()->json($link, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $link = TrackingLink::whereHas('event', function($q) {
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        $data = $request->validate([
            'name' => 'nullable|string',
            'placement' => 'sometimes|string',
            'placements' => 'sometimes|array|max:8',
            'placements.*' => ['string', Rule::in(TrackingLink::PLACEMENTS)],
            'youtube_video_id' => [
                'nullable', 
                'string',
                function ($attribute, $value, $fail) {
                    if ($value) {
                         $exists = Video::where('youtube_video_id', $value)
                            ->whereHas('channel', function($query) {
                                $query->where('user_id', Auth::id());
                            })->exists();
                        
                        if (!$exists) {
                            $fail('The selected video is invalid or does not belong to you.');
                        }
                    }
                }
            ],
            'beehiiv_post_id' => 'nullable|string|max:255',
            'x_post_id' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! app(\App\Services\XPublishedPostsService::class)->existsFor(Auth::id(), $value)) {
                    $fail('The selected X post was not published through your account.');
                }
            }],
            'instagram_media_id' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value && ! app(\App\Services\InstagramPublishedPostsService::class)->existsFor(Auth::id(), $value)) {
                    $fail('The selected Instagram post was not published through your account.');
                }
            }],
            'description' => 'nullable|string',
        ]);

        // The legacy single placement mirrors the declared list's first entry.
        if (!empty($data['placements'])) {
            $data['placements'] = array_values(array_unique($data['placements']));
            $data['placement'] = $data['placements'][0];
        }

        return $this->trackingService->updateLink($link, $data);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $link = TrackingLink::whereHas('event', function($q) {
            $q->where('user_id', Auth::id());
        })->findOrFail($id);
        
        $link->delete();

        return response()->noContent();
    }
}
