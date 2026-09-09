<?php

namespace App\Jobs;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Services\Automations\AutomationLog;
use App\Services\Automations\AutomationMatcher;
use App\Services\Automations\KeywordMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Match one recorded inbound event against the account's live automations
 * and, on a hit, create the run and queue its execution. Idempotent: an
 * already-processed event is a no-op, and UNIQUE(automation_id, event_id)
 * on runs stops a retry from double-firing.
 */
class ProcessInstagramInboundEventJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public $timeout = 60;

    public function __construct(public int $automationEventId) {}

    public function handle(AutomationMatcher $matcher): void
    {
        $row = AutomationEvent::find($this->automationEventId);
        if (! $row || $row->status !== AutomationEvent::STATUS_RECEIVED) {
            return;
        }

        $event = $row->toInboundEvent();
        $result = $matcher->match($event);

        if (! $result->isMatch()) {
            $row->markIgnored($result->ignoreReason);
            AutomationLog::info('event ignored', AutomationLog::context(event: $event) + ['reason' => $result->ignoreReason]);

            return;
        }

        $automation = $result->automation;

        $run = AutomationRun::firstOrCreate(
            ['automation_id' => $automation->id, 'event_id' => $event->eventId],
            [
                'user_id' => $automation->user_id,
                'automation_event_id' => $row->id,
                'trigger_type' => $event->type,
                'sender_id' => $event->senderId,
                'sender_username' => $event->senderUsername,
                'media_id' => $event->mediaId,
                'inbound_text' => $event->text !== null ? Str::limit($event->text, 1000, '') : null,
                'matched_keyword' => $result->keyword === KeywordMatcher::ANY ? null : $result->keyword,
                'status' => AutomationRun::STATUS_PENDING,
            ]
        );

        $row->markMatched();

        if ($run->wasRecentlyCreated) {
            AutomationLog::info('run created', AutomationLog::context($automation, $run, $event) + ['matched_keyword' => $result->keyword]);
            ExecuteAutomationRunJob::dispatch($run->id, $automation->social_account_id);
        } else {
            AutomationLog::info('run already exists (redelivery)', AutomationLog::context($automation, $run, $event));
        }
    }

    public function failed(?Throwable $e): void
    {
        AutomationEvent::whereKey($this->automationEventId)->update([
            'status' => AutomationEvent::STATUS_FAILED,
            'processed_at' => now(),
        ]);
        AutomationLog::error('inbound event job failed', ['automation_event_id' => $this->automationEventId, 'error' => $e?->getMessage()]);
    }
}
