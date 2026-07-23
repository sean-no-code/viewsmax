<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\ShortLink;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Shortlink → offer bridge: when a composer shortlink points at one of the
 * user's offers, a TrackingLink is minted on that offer automatically and the
 * /l/{slug} redirect appends ?trk={parameter_id} — so the existing pixel
 * attribution takes over the moment the visitor lands on the offer page.
 */
class ShortLinkOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->offer = Offer::create([
            'user_id' => $this->user->id,
            'name' => 'My Course',
            'offer_url' => 'https://course.example/join',
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function createPost(array $overrides = []): int
    {
        return $this->withHeaders($this->auth())->postJson('/api/posts', array_merge([
            'caption' => 'Join here: https://course.example/join today!',
            'platforms' => ['x', 'linkedin'],
            'status' => 'draft',
            'shorten_links' => true,
        ], $overrides))->json('id');
    }

    public function test_offer_destination_mints_a_tracking_link(): void
    {
        Queue::fake();
        $postId = $this->createPost();

        $short = ShortLink::where('post_id', $postId)->firstOrFail();
        $this->assertNotNull($short->tracking_link_id);

        $trackingLink = TrackingLink::findOrFail($short->tracking_link_id);
        $this->assertSame($this->offer->id, $trackingLink->tracking_event_id);
        $this->assertEqualsCanonicalizing(['x', 'linkedin'], $trackingLink->placements);
        $this->assertNotEmpty($trackingLink->parameter_id);
    }

    public function test_redirect_appends_trk_for_offer_links(): void
    {
        Queue::fake();
        $postId = $this->createPost();
        $short = ShortLink::where('post_id', $postId)->firstOrFail();
        $trk = TrackingLink::findOrFail($short->tracking_link_id)->parameter_id;

        $this->get('/l/'.$short->slug)
            ->assertRedirect('https://course.example/join?trk='.$trk);
    }

    public function test_offer_match_ignores_query_and_trailing_slash(): void
    {
        Queue::fake();
        $postId = $this->createPost([
            'caption' => 'Join https://COURSE.example/join/?utm_source=x now',
        ]);

        $this->assertNotNull(ShortLink::where('post_id', $postId)->firstOrFail()->tracking_link_id);
    }

    public function test_non_offer_destination_gets_no_tracking_link(): void
    {
        Queue::fake();
        $postId = $this->createPost([
            'caption' => 'Read this https://elsewhere.example/article',
        ]);

        $short = ShortLink::where('post_id', $postId)->firstOrFail();
        $this->assertNull($short->tracking_link_id);
        $this->assertSame(0, TrackingLink::count());
        $this->get('/l/'.$short->slug)->assertRedirect('https://elsewhere.example/article');
    }

    public function test_resaving_the_post_reuses_the_tracking_link(): void
    {
        Queue::fake();
        $postId = $this->createPost();

        $this->withHeaders($this->auth())->putJson("/api/posts/{$postId}", [
            'caption' => 'Still here: https://course.example/join !',
            'platforms' => ['x', 'linkedin'],
            'status' => 'draft',
            'shorten_links' => true,
        ])->assertOk();

        $this->assertSame(1, TrackingLink::count());
        $this->assertSame(1, ShortLink::where('post_id', $postId)->count());
    }
}
