<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Providers\GoogleBusinessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs the real GoogleBusinessProvider — no faked responses — against a local
 * mock built from Google's own discovery documents for the Business Profile
 * APIs. The mock rejects any request that breaks Google's schema (unknown
 * field, wrong enum value, missing auth), so a pass means every request we
 * send is one Google documents as valid.
 *
 * Skipped unless GOOGLE_BUSINESS_CONTRACT_URL is set. To run:
 *
 *   php tests/contract/google-business/build-spec.php /tmp/gbp.json
 *   npx @stoplight/prism-cli@5.14.2 mock /tmp/gbp.json -p 4020 > /tmp/gbp-prism.log 2>&1 &
 *   GOOGLE_BUSINESS_CONTRACT_URL=http://127.0.0.1:4020 GOOGLE_BUSINESS_CONTRACT_LOG=/tmp/gbp-prism.log \
 *     php artisan test --filter=GoogleBusinessContractTest
 *
 * GOOGLE_BUSINESS_CONTRACT_LOG is the mock's log: each test fails if the mock
 * logged a violation in one of our requests.
 */
class GoogleBusinessContractTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private int $logOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) env('GOOGLE_BUSINESS_CONTRACT_URL', '');
        if ($this->base === '') {
            $this->markTestSkipped('Set GOOGLE_BUSINESS_CONTRACT_URL to a mock of the Business Profile APIs to run.');
        }

        config([
            'social.platforms.google_business.enabled' => true,
            'social.platforms.google_business.client_id' => 'contract-client',
            'social.platforms.google_business.client_secret' => 'contract-secret',
            'social.platforms.google_business.api_base' => $this->base,
        ]);

        $log = env('GOOGLE_BUSINESS_CONTRACT_LOG');
        $this->logOffset = $log && is_file($log) ? filesize($log) : 0;
    }

    protected function tearDown(): void
    {
        $log = env('GOOGLE_BUSINESS_CONTRACT_LOG');
        if ($this->base !== '' && $log && is_file($log)) {
            $new = (string) file_get_contents($log, false, null, $this->logOffset);
            preg_match_all('/Violation: (request\.\S+ .*)/', $new, $m);
            $this->assertSame([], $m[1], 'The mock logged schema violations in our requests.');
        }

        parent::tearDown();
    }

    private function account(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'google_business',
            'platform_account_id' => 'locations/2020',
            'name' => 'Corner Bakery',
            'access_token' => 'ya29.contract',
            'refresh_token' => '1//contract',
            'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['post_parent' => 'accounts/1010/locations/2020', 'language_code' => 'en'],
        ]);
    }

    private function publish(array $options, array $media = []): void
    {
        $post = new SocialPost(['content' => 'Fresh sourdough at 7am every day.', 'media' => $media]);
        $post->options = $options;

        $result = (new GoogleBusinessProvider)->publish($this->account(User::factory()->create()), $post);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertNotEmpty($result->remotePostId);
    }

    public function test_listing_accounts_and_locations_matches_googles_schema(): void
    {
        $accounts = (new GoogleBusinessProvider)->storeLocations(User::factory()->create(), new OAuthResult('ya29.contract', '1//contract', 3600));

        $this->assertNotEmpty($accounts);
        $this->assertStringStartsWith('accounts/', (string) $accounts->first()->meta('post_parent'));
    }

    public function test_update_with_photo_and_button_matches_googles_schema(): void
    {
        $this->publish(
            ['cta_type' => 'ORDER', 'cta_url' => 'https://cornerbakery.example/order'],
            [['type' => 'image', 'url' => 'https://cdn.example/bread.jpg']],
        );
    }

    public function test_call_button_matches_googles_schema(): void
    {
        $this->publish(['cta_type' => 'CALL']);
    }

    public function test_event_matches_googles_schema(): void
    {
        $this->publish([
            'topic_type' => 'EVENT',
            'event_title' => 'Bread tasting',
            'event_start' => '2026-11-01T18:00',
            'event_end' => '2026-11-01T21:00',
            'cta_type' => 'SIGN_UP',
            'cta_url' => 'https://cornerbakery.example/rsvp',
        ]);
    }

    public function test_offer_matches_googles_schema(): void
    {
        $this->publish([
            'topic_type' => 'OFFER',
            'event_title' => '20% off croissants',
            'event_start' => '2026-11-01T07:00',
            'event_end' => '2026-11-07T12:00',
            'coupon_code' => 'CROISSANT20',
            'redeem_url' => 'https://cornerbakery.example/offer',
            'terms' => 'One per customer.',
        ], [['type' => 'image', 'url' => 'https://cdn.example/croissant.jpg']]);
    }
}
