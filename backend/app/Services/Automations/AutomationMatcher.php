<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Services\Automations\Data\InboundEvent;
use App\Services\Automations\Data\MatchResult;
use Illuminate\Support\Collection;

/**
 * Find THE automation an inbound event fires. The live set for the account +
 * trigger is small, so filtering happens in PHP (JSON columns aren't
 * queryable on the SQLite test DB anyway). Exactly one automation wins per
 * event — specific post > any post, keywords > any word, then lowest id —
 * so a person never receives two DMs for one comment.
 */
class AutomationMatcher
{
    public function match(InboundEvent $event): MatchResult
    {
        $accountIds = SocialAccount::query()
            ->where('platform', $event->platform)
            ->where('platform_account_id', $event->accountPlatformId)
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return MatchResult::ignored(MatchResult::NO_ACCOUNT);
        }

        /** @var Collection<int, Automation> $candidates */
        $candidates = Automation::query()
            ->live()
            ->whereIn('social_account_id', $accountIds)
            ->where('trigger_type', $event->type)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return MatchResult::ignored(MatchResult::NO_LIVE_AUTOMATIONS);
        }

        // Track the most specific reason so the ledger explains a near miss.
        $reason = MatchResult::NO_LIVE_AUTOMATIONS;
        $matches = [];

        foreach ($candidates as $automation) {
            if ($event->isThreadReply() && ! $automation->include_replies) {
                $reason = MatchResult::THREAD_REPLY;

                continue;
            }

            if (! $automation->matchesMedia($event->mediaId)) {
                $reason = MatchResult::MEDIA_MISMATCH;

                continue;
            }

            $keyword = $automation->matchKeyword($event->text);
            if ($keyword === null) {
                $reason = MatchResult::KEYWORD_MISMATCH;

                continue;
            }

            if ($this->inCooldown($automation, $event)) {
                $reason = MatchResult::COOLDOWN;

                continue;
            }

            $matches[] = [$automation, $keyword];
        }

        if ($matches === []) {
            return MatchResult::ignored($reason);
        }

        usort($matches, fn ($a, $b) => [$b[0]->specificity(), $a[0]->id] <=> [$a[0]->specificity(), $b[0]->id]);

        [$winner, $keyword] = $matches[0];

        return MatchResult::matched($winner, $keyword === KeywordMatcher::ANY ? KeywordMatcher::ANY : $keyword);
    }

    /** Has this sender already fired this automation inside the cooldown window? */
    protected function inCooldown(Automation $automation, InboundEvent $event): bool
    {
        if ((int) $automation->cooldown_hours <= 0) {
            return false;
        }

        return AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('sender_id', $event->senderId)
            ->where('event_id', '!=', $event->eventId)
            ->where('created_at', '>=', now()->subHours((int) $automation->cooldown_hours))
            ->exists();
    }
}
