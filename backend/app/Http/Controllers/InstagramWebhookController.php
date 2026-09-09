<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInstagramInboundEventJob;
use App\Models\AutomationEvent;
use App\Services\Automations\AutomationLog;
use App\Services\Automations\InstagramEventNormalizer;
use App\Services\Automations\InstagramWebhookSignature;
use Illuminate\Http\Request;

/**
 * Instagram (Instagram Login) webhooks for comment / message automations.
 *
 * GET  = Meta's verification handshake (echo hub.challenge when the verify
 *        token matches).
 * POST = signed event batches. We verify X-Hub-Signature-256 over the RAW
 *        body, record each event once (UNIQUE platform+event_id makes Meta's
 *        redeliveries no-ops), queue processing and answer 200 immediately —
 *        Meta retries slow or failing endpoints.
 *
 * Public routes: the signature IS the authentication.
 */
class InstagramWebhookController extends Controller
{
    public function verify(Request $request)
    {
        // PHP turns "hub.mode" into "hub_mode" in the query bag; accept both.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        $expected = (string) config('social.platforms.instagram.webhook_verify_token');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            AutomationLog::warning('webhook verify rejected', ['mode' => $mode, 'ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        AutomationLog::info('webhook verify ok', ['ip' => $request->ip()]);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, InstagramWebhookSignature $signature, InstagramEventNormalizer $normalizer)
    {
        $raw = $request->getContent();

        if (! $signature->verify($raw, $request->header('X-Hub-Signature-256'))) {
            AutomationLog::warning('webhook signature mismatch', ['ip' => $request->ip(), 'body_length' => strlen($raw)]);

            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            AutomationLog::warning('webhook body not JSON', ['ip' => $request->ip(), 'body_length' => strlen($raw)]);

            return response()->json(['success' => true]);
        }

        $events = $normalizer->normalize($payload);
        AutomationLog::debug('webhook received', [
            'entries' => count($payload['entry'] ?? []),
            'events' => count($events),
            'event_ids' => array_map(fn ($e) => $e->eventId, $events),
            'payload' => $raw,
        ]);

        $queued = 0;
        foreach ($events as $event) {
            $row = AutomationEvent::firstOrCreate(
                ['platform' => $event->platform, 'event_id' => $event->eventId],
                [
                    'account_platform_id' => $event->accountPlatformId,
                    'event_type' => $event->type,
                    'sender_id' => $event->senderId,
                    'payload' => $event->toArray(),
                    'status' => AutomationEvent::STATUS_RECEIVED,
                    'received_at' => now(),
                ]
            );

            if (! $row->wasRecentlyCreated) {
                AutomationLog::info('event duplicate skipped (redelivery)', AutomationLog::context(event: $event));

                continue;
            }

            AutomationLog::info('event stored', AutomationLog::context(event: $event) + ['automation_event_id' => $row->id]);
            ProcessInstagramInboundEventJob::dispatch($row->id);
            $queued++;
        }

        return response()->json(['success' => true, 'received' => count($events), 'queued' => $queued]);
    }
}
