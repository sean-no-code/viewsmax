<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Services\Automations\AutomationLog;
use App\Services\Automations\AutomationMessageBuilder;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;
use Throwable;

/**
 * Execute one automation run: optional public reply under the comment, then
 * the DM (private reply for comments, direct message for story replies and
 * DMs). Sends aren't idempotent, so a run only ever executes while
 * `pending`; retries are reserved for rate limiting.
 */
class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [30, 120, 600];

    public $timeout = 90;

    public function __construct(public int $runId, public int $socialAccountId = 0) {}

    /** Per-account throttle (see AppServiceProvider). */
    public function middleware(): array
    {
        return [new RateLimited('instagram-automations')];
    }

    public function handle(SocialProviderManager $manager, AutomationMessageBuilder $builder): void
    {
        $run = AutomationRun::with('automation.socialAccount')->find($this->runId);
        if (! $run || ! $run->isPending()) {
            return;
        }

        $automation = $run->automation;
        $ctx = AutomationLog::context($automation, $run);

        if (! $automation || ! $automation->isLive()) {
            $this->finish($run, AutomationRun::STATUS_SKIPPED, AutomationRun::ERROR_STOPPED, dm: AutomationRun::DM_SKIPPED);
            AutomationLog::info('run skipped: automation stopped', $ctx);

            return;
        }

        $account = $automation->socialAccount;
        if (! $account) {
            $this->finish($run, AutomationRun::STATUS_FAILED, 'Instagram account disconnected.', dm: AutomationRun::DM_FAILED);
            AutomationLog::error('run failed: account missing', $ctx);

            return;
        }

        /** @var InstagramProvider $provider */
        $provider = $manager->for('instagram');
        $account = $provider->ensureFreshToken($account);

        if ($account->status !== SocialAccount::STATUS_CONNECTED || ! $account->hasScopes(Automation::REQUIRED_IG_SCOPES)) {
            $this->needsReauth($run, $automation);

            return;
        }

        // 1. Public reply under the comment (comment trigger only). A failure
        //    here never blocks the DM.
        $replyOk = null;
        if ($automation->isCommentTrigger() && $automation->reply_enabled && ($text = $automation->pickReplyText())) {
            $reply = $provider->replyToComment($account, $run->event_id, $text);
            $replyOk = $reply->success;
            $run->forceFill([
                'reply_status' => $reply->success ? AutomationRun::REPLY_SENT : AutomationRun::REPLY_FAILED,
                'reply_remote_id' => $reply->remoteCommentId,
            ])->save();
        } elseif ($automation->isCommentTrigger()) {
            $run->forceFill(['reply_status' => AutomationRun::REPLY_SKIPPED])->save();
        }

        // 2. The DM.
        $message = $builder->build($automation, $run);
        $result = $automation->isCommentTrigger()
            ? $provider->sendPrivateReply($account, $run->event_id, $message)
            : $provider->sendMessage($account, $run->sender_id, $message);

        if ($result->success) {
            $run->forceFill(['dm_status' => AutomationRun::DM_SENT, 'dm_remote_id' => $result->messageId])->save();
            $this->finish($run, $replyOk === false ? AutomationRun::STATUS_PARTIAL : AutomationRun::STATUS_COMPLETED, null);
            $automation->forceFill(['last_run_at' => now(), 'last_error' => null])->save();
            AutomationLog::info('run completed', $ctx + ['dm_remote_id' => $result->messageId, 'reply' => $run->reply_status]);

            return;
        }

        if ($result->isAuthError()) {
            $this->needsReauth($run, $automation);

            return;
        }

        if ($result->isOutsideWindow()) {
            $this->finish($run, AutomationRun::STATUS_FAILED, AutomationRun::ERROR_OUTSIDE_WINDOW, dm: AutomationRun::DM_FAILED);
            AutomationLog::warning('run failed: outside messaging window', $ctx);

            return;
        }

        if ($result->isRateLimited() && $this->attempts() < $this->tries) {
            $delay = $this->backoff[$this->attempts() - 1] ?? 600;
            AutomationLog::warning('dm rate limited — releasing', $ctx + ['attempt' => $this->attempts(), 'delay' => $delay]);
            $this->release($delay);

            return;
        }

        $this->finish($run, AutomationRun::STATUS_FAILED, (string) $result->error, dm: AutomationRun::DM_FAILED);
        $automation->forceFill(['last_error' => Str::limit((string) $result->error, 1000)])->save();
        AutomationLog::error('run failed: dm error', $ctx + ['error' => $result->error, 'code' => $result->errorCode]);
    }

    public function failed(?Throwable $e): void
    {
        $run = AutomationRun::find($this->runId);
        if ($run && $run->isPending()) {
            $this->finish($run, AutomationRun::STATUS_FAILED, $e?->getMessage() ?? 'Job failed.', dm: AutomationRun::DM_FAILED);
        }
        AutomationLog::error('run job failed', ['run_id' => $this->runId, 'error' => $e?->getMessage()]);
    }

    private function needsReauth(AutomationRun $run, Automation $automation): void
    {
        $this->finish($run, AutomationRun::STATUS_FAILED, AutomationRun::ERROR_NEEDS_REAUTH, dm: AutomationRun::DM_FAILED);
        $automation->forceFill(['last_error' => 'Instagram needs reconnecting — comments & messages permission missing or expired.'])->save();
        AutomationLog::error('run failed: needs reauth', AutomationLog::context($automation, $run));
    }

    private function finish(AutomationRun $run, string $status, ?string $error, ?string $dm = null): void
    {
        $attributes = [
            'status' => $status,
            'error' => $error !== null ? Str::limit($error, 1000) : null,
            'executed_at' => now(),
        ];
        if ($dm !== null) {
            $attributes['dm_status'] = $dm;
        }

        $run->forceFill($attributes)->save();
    }
}
