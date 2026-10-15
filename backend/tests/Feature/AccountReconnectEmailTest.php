<?php

namespace Tests\Feature;

use App\Jobs\SendReconnectEmailJob;
use App\Mail\AccountNeedsReconnectMail;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The user is emailed the moment a connected account first needs
 * reconnecting (SocialAccount::markNeedsReauth), once per episode, with the
 * platform, the account, the cause and a link to the Connections page.
 */
class AccountReconnectEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function account(string $status = SocialAccount::STATUS_CONNECTED): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Acme Marketing',
            'username' => 'acme',
            'access_token' => 'tok',
            'status' => $status,
        ]);
    }

    public function test_first_flip_to_needs_reauth_queues_one_email(): void
    {
        Queue::fake();
        $account = $this->account();

        $account->markNeedsReauth('LinkedIn token refresh was rejected — reconnect the account.');

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        Queue::assertPushed(SendReconnectEmailJob::class, 1);
        Queue::assertPushed(SendReconnectEmailJob::class, fn (SendReconnectEmailJob $job) => $job->socialAccountId === $account->id);
    }

    public function test_no_second_email_while_the_account_stays_in_needs_reauth(): void
    {
        Queue::fake();
        $account = $this->account();

        $account->markNeedsReauth('first');
        $account->markNeedsReauth('second');
        $account->fresh()->markNeedsReauth('third');

        Queue::assertPushed(SendReconnectEmailJob::class, 1);
        $this->assertSame('third', $account->fresh()->last_error);
    }

    public function test_already_needs_reauth_account_sends_nothing(): void
    {
        Queue::fake();
        $account = $this->account(SocialAccount::STATUS_NEEDS_REAUTH);

        $account->markNeedsReauth('still dead');

        Queue::assertNothingPushed();
    }

    public function test_reconnecting_and_failing_again_opens_a_new_episode(): void
    {
        Queue::fake();
        $account = $this->account();

        $account->markNeedsReauth('dead');
        $account->update(['status' => SocialAccount::STATUS_CONNECTED, 'last_error' => null]);
        $account->markNeedsReauth('dead again');

        Queue::assertPushed(SendReconnectEmailJob::class, 2);
    }

    public function test_email_names_the_platform_account_cause_and_connections_page(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://app.example.test/']);
        $account = $this->account(SocialAccount::STATUS_NEEDS_REAUTH);
        $account->update(['last_error' => 'LinkedIn token refresh was rejected — reconnect the account.']);

        (new SendReconnectEmailJob($account->id))->handle();

        Mail::assertSent(AccountNeedsReconnectMail::class, function (AccountNeedsReconnectMail $mail) use ($account) {
            $html = $mail->render();

            return $mail->hasTo($this->user->email)
                && $mail->account->is($account)
                && str_contains($mail->envelope()->subject, 'LinkedIn')
                && str_contains($mail->envelope()->subject, 'Acme Marketing')
                && str_contains($html, 'LinkedIn')
                && str_contains($html, 'Acme Marketing')
                && str_contains($html, 'LinkedIn token refresh was rejected')
                && str_contains($html, 'https://app.example.test/dashboard/connections');
        });
    }

    public function test_job_sends_nothing_if_the_account_was_reconnected_or_removed_first(): void
    {
        Mail::fake();
        $account = $this->account();

        (new SendReconnectEmailJob($account->id))->handle();
        (new SendReconnectEmailJob($account->id + 999))->handle();

        Mail::assertNothingSent();
    }

    public function test_end_to_end_with_a_sync_queue_delivers_the_email(): void
    {
        Mail::fake();
        $account = $this->account();

        $account->markNeedsReauth('X token refresh was rejected — reconnect the account.');

        Mail::assertSent(AccountNeedsReconnectMail::class, fn ($mail) => $mail->hasTo($this->user->email));
    }
}
