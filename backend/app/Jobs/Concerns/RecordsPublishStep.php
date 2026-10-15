<?php

namespace App\Jobs\Concerns;

use App\Models\PostTarget;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Persist the step a publish job is about to run in the target's meta, so a
 * job killed by $timeout — which never reaches its catch block — can still
 * name the call it stalled in. TikTok's meta.log records each call after the
 * fact; this marker is written before, which is the only thing that survives
 * a kill.
 */
trait RecordsPublishStep
{
    /**
     * Save `meta.step` before an outbound call. Returns the saved meta for
     * jobs that keep a local copy of it.
     */
    protected function recordStep(PostTarget $target, string $step): array
    {
        $meta = $target->meta ?? [];
        $meta['step'] = ['name' => $step, 'at' => now()->toIso8601String()];
        $target->forceFill(['meta' => $meta])->save();

        return $meta;
    }

    /**
     * The recorded step when the queue killed the job on $timeout; null for
     * any other failure, which carries its own message.
     */
    protected function timedOutStep(?Throwable $e, PostTarget $target): ?string
    {
        return $e instanceof TimeoutExceededException ? ($target->meta['step']['name'] ?? null) : null;
    }
}
