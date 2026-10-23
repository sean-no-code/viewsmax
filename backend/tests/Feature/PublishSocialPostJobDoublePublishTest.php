<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A create call whose outcome was never learned must not be re-run
 * by a queue retry — LinkedIn cannot be asked whether the post exists, so a
 * second POST /rest/posts would double-post. The job stamps the target before
 * the create and refuses to create again until a manual retry lifts the stamp.
 */
class PublishSocialPostJobDoublePublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialPostTarget $target;

    /** POST /rest/posts attempts, counted by hand: a faked call that throws is never recorded. */
    private int $createCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.linkedin.client_id' => 'id', 'social.platforms.linkedin.client_secret' => 'secret']);

        $this->user = User::factory()->create();
        $account = $this->user->socialAccounts()->create([
            'platform' => 'linkedin',
            'platform_account_id' => 'abc',
            'name' => 'Jane',
            'access_token' => 'tok',
            'token_expires_at' => now()->addDays(30),
            'metadata' => ['author_urn' => 'urn:li:person:abc'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $post = SocialPost::create([
            'user_id' => $this->user->id,
            'content' => 'hello',
            'media' => [],
            'status' => SocialPost::STATUS_QUEUED,
        ]);
        $this->target = $post->targets()->create([
            'social_account_id' => $account->id,
            'platform' => 'linkedin',
            'status' => SocialPostTarget::STATUS_PENDING,
        ]);
    }

    private function runJob(): void
    {
        (new PublishSocialPostJob($this->target->id))->handle(app(SocialProviderManager::class));
    }

    /** The create call dies without a reply on the first attempt, then succeeds. */
    private function fakeLinkedInDyingOnce(): void
    {
        Http::fake([
            'api.linkedin.com/rest/posts' => function () {
                if (++$this->createCalls === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']);
            },
        ]);
    }

    public function test_a_retry_after_an_unknown_outcome_does_not_create_the_post_again(): void
    {
        $this->fakeLinkedInDyingOnce();

        try {
            $this->runJob();
            $this->fail('The timeout should propagate so the queue retries');
        } catch (ConnectionException) {
        }

        $this->target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $this->target->status);
        $this->assertNotEmpty(data_get($this->target->response, PublishSocialPostJob::ATTEMPT_MARKER));

        // Queue retry: the stamp is there, so no second create.
        $this->runJob();

        $this->target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $this->target->status);
        $this->assertSame(
            'This post may have already been published to LinkedIn; check the account before retrying.',
            $this->target->error
        );
        $this->assertSame(1, $this->createCalls);
        $this->assertSame(SocialPost::STATUS_FAILED, $this->target->post->fresh()->status);
    }

    public function test_a_manual_retry_lifts_the_guard_and_publishes(): void
    {
        $this->fakeLinkedInDyingOnce();

        try {
            $this->runJob();
        } catch (ConnectionException) {
        }
        $this->runJob(); // queue retry stops on the stamp
        $this->assertStringContainsString('may have already been published', $this->target->fresh()->error);

        // The user checked LinkedIn, nothing is there: retry from the UI. The
        // queue is sync in tests, so the job runs inside the request.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken, 'Accept' => 'application/json'])
            ->postJson('/api/social/posts/'.$this->target->social_post_id.'/retry')
            ->assertOk()
            ->assertJsonPath('message', '1 target(s) re-queued.');

        $this->target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_PUBLISHED, $this->target->status, (string) $this->target->error);
        $this->assertSame('urn:li:share:1', $this->target->remote_post_id);
        $this->assertNull(data_get($this->target->response, PublishSocialPostJob::ATTEMPT_MARKER));
        $this->assertSame(2, $this->createCalls);
    }

    public function test_a_successful_publish_leaves_no_stamp_behind(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1'])]);

        $this->runJob();

        $this->target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_PUBLISHED, $this->target->status);
        $this->assertNull(data_get($this->target->response, PublishSocialPostJob::ATTEMPT_MARKER));
    }

    public function test_a_transient_answer_drops_the_stamp_so_the_queue_may_retry_the_create(): void
    {
        $manager = $this->createMock(SocialProviderManager::class);
        $provider = $this->getMockBuilder(\App\Services\Social\Providers\LinkedInProvider::class)->onlyMethods(['publish'])->getMock();
        $provider->method('publish')->willThrowException(new TransientPublishException('LinkedIn rate limit hit during publish — retrying automatically.'));
        $manager->method('for')->willReturn($provider);

        try {
            (new PublishSocialPostJob($this->target->id))->handle($manager);
            $this->fail('A transient failure must propagate for the queue to retry');
        } catch (TransientPublishException) {
        }

        $this->target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $this->target->status);
        $this->assertStringContainsString('rate limit', $this->target->error);
        $this->assertNull(data_get($this->target->response, PublishSocialPostJob::ATTEMPT_MARKER));
    }
}
