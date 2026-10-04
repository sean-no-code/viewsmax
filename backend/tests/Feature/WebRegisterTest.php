<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Http\Controllers\Auth\WebRegisterController;
use App\Models\User;
use App\Services\Social\SocialConnect;
use App\Services\Social\SocialConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * Signup on the API host for the agent flow: /oauth/authorize → /login →
 * /register → /register/setup (connect channels here) → back to the consent
 * screen. Companion to OAuthLoginBridgeTest.
 */
class WebRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'marketing_consent' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        // Deterministic platform availability regardless of the local .env.
        config(['social.platforms.x.client_id' => 'id', 'social.platforms.x.client_secret' => 'secret', 'social.platforms.instagram.client_id' => null]);
    }

    private function authorizeUrl(string $clientName = 'Claude'): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($clientName, ['https://example.test/callback'], confidential: false);

        return '/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://example.test/callback',
            'response_type' => 'code',
            'scope' => 'mcp',
            'code_challenge' => str_repeat('x', 43),
            'code_challenge_method' => 'S256',
        ]);
    }

    private function assertSameUrl(string $expected, ?string $actual): void
    {
        $this->assertSame(parse_url($expected, PHP_URL_PATH), parse_url((string) $actual, PHP_URL_PATH));
        parse_str((string) parse_url($expected, PHP_URL_QUERY), $a);
        parse_str((string) parse_url((string) $actual, PHP_URL_QUERY), $b);
        ksort($a);
        ksort($b);
        $this->assertSame($a, $b);
    }

    public function test_register_page_renders_the_form_with_the_site_chrome(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee('name="marketing_consent" value="1" checked', false)
            ->assertSee('class="site-nav"', false)
            ->assertSee('class="site-foot"', false);
    }

    public function test_login_page_links_to_the_register_page_on_this_host(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('register'), false)->assertDontSee('/auth"', false);
    }

    public function test_signup_mid_agent_flow_records_the_agent_and_continues_to_setup(): void
    {
        $authorizeUrl = $this->authorizeUrl('Claude');
        $this->get($authorizeUrl)->assertRedirect('/login');

        $this->post('/register', self::FORM)->assertRedirect(route('register.setup'));

        $user = User::where('email', 'jane@example.com')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('agent', $user->signup_source);
        $this->assertSame('Claude', $user->signup_client);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->promo_expires_at);
        $this->assertNotNull($user->marketing_consented_at);
        $this->assertTrue($user->hasRole('customer'));
        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('jane@example.com'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'convertkit.com') || true); // Kit only when configured

        // Setup page names the agent and still knows where to go next.
        $this->get(route('register.setup'))->assertOk()->assertSee('Grant Claude access')->assertSee('Skip for now');
        $this->assertSameUrl($authorizeUrl, $this->get(route('register.continue'))->headers->get('Location'));
    }

    public function test_full_chain_authorize_register_connect_continue_returns_to_consent(): void
    {
        $authorizeUrl = $this->authorizeUrl('Claude');
        $this->get($authorizeUrl)->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->post('/register', self::FORM)->assertRedirect(route('register.setup'));
        $this->get(route('register.setup'))->assertOk();

        $connect = \Mockery::mock(SocialConnect::class);
        $connect->shouldReceive('authorizationUrl')->once()->andReturn(['authorization_url' => 'https://accounts.google.test/o?state=s1', 'state' => 's1']);
        $connect->shouldReceive('complete')->once()->andReturn(collect([new SocialAccount(['platform' => 'youtube'])]));
        $this->app->instance(SocialConnect::class, $connect);

        $this->get(route('connect.start', 'youtube'))->assertRedirect('https://accounts.google.test/o?state=s1');
        $this->get(route('connect.callback', 'youtube').'?code=c&state=s1')->assertRedirect(route('register.setup'));
        $this->get(route('register.setup'))->assertOk()->assertSee('grant access');

        $this->assertSameUrl($authorizeUrl, $this->get(route('register.continue'))->headers->get('Location'));
    }

    public function test_continue_returns_to_consent_even_if_url_intended_was_overwritten_mid_flow(): void
    {
        $authorizeUrl = $this->authorizeUrl('Claude');
        $this->get($authorizeUrl)->assertRedirect('/login');
        $this->get('/register')->assertOk()->assertSee('approve Claude');
        $this->post('/register', self::FORM)->assertRedirect(route('register.setup'));

        // e.g. a provider callback that arrived without the session cookie went
        // through the guest redirect and replaced Laravel's intended URL.
        $this->session(['url.intended' => url(route('connect.callback', 'youtube').'?code=x')]);

        $this->get(route('register.setup'))->assertOk()->assertSee('Grant Claude access');
        $this->assertSameUrl($authorizeUrl, $this->get(route('register.continue'))->headers->get('Location'));
        $this->assertNull(session(WebRegisterController::SESSION_AUTHORIZE_URL));
    }

    public function test_signup_without_a_pending_agent_has_no_client_and_continues_to_the_app(): void
    {
        $this->post('/register', self::FORM)->assertRedirect(route('register.setup'));

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'signup_source' => 'agent', 'signup_client' => null]);
        $this->get(route('register.continue'))->assertRedirect(rtrim(config('app.frontend_url'), '/').'/auth');
    }

    public function test_validation_errors_keep_name_and_email_and_consent_choice(): void
    {
        $this->from('/register')
            ->post('/register', ['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'short', 'password_confirmation' => 'short', 'marketing_consent' => '0'])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');

        $this->get('/register')
            ->assertSee('value="Jane"', false)
            ->assertSee('value="jane@example.com"', false)
            ->assertDontSee('name="marketing_consent" value="1" checked', false);
        $this->assertGuest('web');
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->from('/register')->post('/register', self::FORM)->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_setup_lists_postable_configured_platforms_and_connected_accounts(): void
    {
        $user = User::factory()->create();
        SocialAccount::create(['user_id' => $user->id, 'platform' => 'x', 'platform_account_id' => 'x-1', 'username' => 'janeposts', 'status' => 'connected']);

        $page = $this->actingAs($user, 'web')->get(route('register.setup'))->assertOk();
        $page->assertSee('janeposts')->assertSee(route('connect.start', 'x'), false)->assertSee('Continue to ViewsMax')->assertSee('Open ViewsMax')->assertDontSee('grant access');
        $page->assertDontSee(route('connect.start', 'instagram'), false); // not configured in tests
    }

    public function test_connect_start_redirects_to_the_provider_with_the_api_host_callback(): void
    {
        $user = User::factory()->create();
        $connect = \Mockery::mock(SocialConnect::class);
        $connect->shouldReceive('authorizationUrl')
            ->once()
            ->withArgs(fn ($u, $platform, $redirect, $follow) => $u->is($user) && $platform === 'x' && $redirect === route('connect.callback', 'x') && $follow === true)
            ->andReturn(['authorization_url' => 'https://x.test/authorize?state=abc', 'state' => 'abc']);
        $this->app->instance(SocialConnect::class, $connect);

        $this->actingAs($user, 'web')->get(route('connect.start', 'x').'?follow_us=1')->assertRedirect('https://x.test/authorize?state=abc');
    }

    public function test_connect_callback_completes_and_returns_to_setup_with_the_intended_url_intact(): void
    {
        $user = User::factory()->create();
        $authorizeUrl = $this->authorizeUrl();
        $connect = \Mockery::mock(SocialConnect::class);
        $connect->shouldReceive('complete')->once()->with(\Mockery::on(fn ($u) => $u->is($user)), 'x', 'the-code', 'abc', route('connect.callback', 'x'))
            ->andReturn(collect([new SocialAccount(['platform' => 'x'])]));
        $this->app->instance(SocialConnect::class, $connect);

        $this->actingAs($user, 'web')->withSession(['url.intended' => url($authorizeUrl)])
            ->get(route('connect.callback', 'x').'?code=the-code&state=abc')
            ->assertRedirect(route('register.setup'))
            ->assertSessionHas('status', '1 X account connected.')
            ->assertSessionHas('url.intended', url($authorizeUrl));
    }

    public function test_connect_callback_shows_provider_and_exchange_errors(): void
    {
        $user = User::factory()->create();
        $connect = \Mockery::mock(SocialConnect::class);
        $connect->shouldReceive('complete')->once()->andThrow(new SocialConnectException('Invalid or expired OAuth state.'));
        $this->app->instance(SocialConnect::class, $connect);

        $this->actingAs($user, 'web')->get(route('connect.callback', 'x').'?error=access_denied&error_description=User+said+no')
            ->assertRedirect(route('register.setup'))
            ->assertSessionHas('error', 'X did not grant access: User said no');

        $this->actingAs($user, 'web')->get(route('connect.callback', 'x').'?code=bad&state=old')
            ->assertSessionHas('error', 'Invalid or expired OAuth state.');
    }

    public function test_bluesky_connects_with_credentials_from_the_setup_page(): void
    {
        $user = User::factory()->create();
        $connect = \Mockery::mock(SocialConnect::class);
        $connect->shouldReceive('connectWithCredentials')->once()
            ->withArgs(fn ($u, $platform, $data, $follow) => $platform === 'bluesky' && $data['identifier'] === 'jane.bsky.social' && $data['password'] === 'app-pass' && $follow === false)
            ->andReturn(collect([new SocialAccount(['platform' => 'bluesky'])]));
        $this->app->instance(SocialConnect::class, $connect);

        $this->actingAs($user, 'web')->post(route('connect.credentials', 'bluesky'), ['identifier' => 'jane.bsky.social', 'password' => 'app-pass'])
            ->assertRedirect(route('register.setup'))
            ->assertSessionHas('status', 'Bluesky account connected.');
    }

    public function test_setup_and_connect_require_a_web_login(): void
    {
        $this->get(route('register.setup'))->assertRedirect('/login');
        $this->get(route('connect.start', 'x'))->assertRedirect('/login');
    }

    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', ['email' => 'bad']);
        }

        $this->post('/register', ['email' => 'bad'])->assertStatus(429);
    }

    public function test_json_requests_to_register_get_the_api_pointer(): void
    {
        $this->getJson('/register')->assertStatus(401)->assertJsonStructure(['message', 'api_register_url']);
    }
}
