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

    /** Click the magic link from the last verification email (agent links land on this host). */
    private function clickVerifyLink(string $email)
    {
        $mail = Mail::sent(VerifyEmailMail::class, fn ($m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, 'no verification email was sent');
        $this->assertStringStartsWith(url('/verify-email'), (string) $mail->verifyUrl, 'agent signups verify on the API host');

        return $this->get($mail->verifyUrl);
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

        $this->post('/register', self::FORM)->assertRedirect(route('register.verify'));

        $user = User::where('email', 'jane@example.com')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('agent', $user->signup_source);
        $this->assertSame('Claude', $user->signup_client);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->promo_expires_at);
        $this->assertNotNull($user->marketing_consented_at);
        $this->assertTrue($user->hasRole('customer'));

        // Verify first: setup is gated until the emailed link is clicked.
        $this->get(route('register.setup'))->assertRedirect(route('register.verify'));
        $this->get(route('register.verify'))->assertOk()->assertSee('jane@example.com')->assertSee('connecting Claude');
        $this->clickVerifyLink('jane@example.com')->assertRedirect(route('register.setup'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        // Setup page names the agent and still knows where to go next.
        $this->get(route('register.setup'))->assertOk()->assertSee('Grant Claude access')->assertSee('Skip for now')
            ->assertSee('First thing to ask Claude')->assertSee('top outlier videos');
        $this->assertSameUrl($authorizeUrl, $this->get(route('register.continue'))->headers->get('Location'));
    }

    public function test_full_chain_authorize_register_connect_continue_returns_to_consent(): void
    {
        $authorizeUrl = $this->authorizeUrl('Claude');
        $this->get($authorizeUrl)->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->post('/register', self::FORM)->assertRedirect(route('register.verify'));
        $this->clickVerifyLink('jane@example.com')->assertRedirect(route('register.setup'));
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
        $this->post('/register', self::FORM)->assertRedirect(route('register.verify'));
        $this->clickVerifyLink('jane@example.com')->assertRedirect(route('register.setup'));

        // e.g. a provider callback that arrived without the session cookie went
        // through the guest redirect and replaced Laravel's intended URL.
        $this->session(['url.intended' => url(route('connect.callback', 'youtube').'?code=x')]);

        $this->get(route('register.setup'))->assertOk()->assertSee('Grant Claude access');
        $this->assertSameUrl($authorizeUrl, $this->get(route('register.continue'))->headers->get('Location'));
        $this->assertNull(session(WebRegisterController::SESSION_AUTHORIZE_URL));
    }

    public function test_signup_without_a_pending_agent_has_no_client_and_continues_to_the_app(): void
    {
        $this->post('/register', self::FORM)->assertRedirect(route('register.verify'));
        $this->clickVerifyLink('jane@example.com')->assertRedirect(route('register.setup'));

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'signup_source' => 'agent', 'signup_client' => null]);
        // No agent to go back to, so no "first thing to ask" suggestion.
        $this->get(route('register.setup'))->assertOk()->assertDontSee('First thing to ask');
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

    public function test_unverified_user_is_parked_on_the_verify_page_and_can_resend(): void
    {
        $user = User::factory()->create(['email' => 'new@example.com', 'email_verified_at' => null, 'signup_source' => 'agent']);

        $this->actingAs($user, 'web')->get(route('register.setup'))->assertRedirect(route('register.verify'));
        $this->actingAs($user, 'web')->get(route('connect.start', 'x'))->assertRedirect(route('register.verify'));
        $this->actingAs($user, 'web')->get(route('register.verify'))
            ->assertOk()
            ->assertSee('new@example.com')
            ->assertSee(route('register.resend'), false);

        $this->actingAs($user, 'web')->post(route('register.resend'))
            ->assertRedirect(route('register.verify'))
            ->assertSessionHas('status', 'Verification email sent to new@example.com.');
        $this->clickVerifyLink('new@example.com')->assertRedirect(route('register.setup'));

        $verified = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($verified, 'web')->get(route('register.verify'))->assertRedirect(route('register.setup'));
    }

    public function test_verify_link_signs_in_a_fresh_browser_and_rejects_bad_tokens(): void
    {
        $user = User::factory()->create(['email' => 'fresh@example.com', 'email_verified_at' => null, 'signup_source' => 'agent']);
        $token = $user->createEmailVerificationToken();

        // No session at all (link opened elsewhere): verified, logged in, on to setup.
        $this->get(route('verify-email.web', ['token' => $token]))->assertRedirect(route('register.setup'));
        $this->assertAuthenticatedAs($user->fresh(), 'web');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get(route('verify-email.web', ['token' => 'nope']))->assertRedirect(route('register.verify'))->assertSessionHas('error');
    }

    public function test_app_signups_still_verify_in_the_spa(): void
    {
        $this->postJson('/api/register', ['name' => 'App User', 'email' => 'app@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertStatus(201);

        $mail = Mail::sent(VerifyEmailMail::class, fn ($m) => $m->hasTo('app@example.com'))->sole();
        $this->assertStringStartsWith(rtrim(config('app.frontend_url'), '/').'/verify-email?token=', $mail->verifyUrl);
        $this->assertEmailLinksTo($mail, $mail->verifyUrl);
    }

    public function test_agent_signup_email_links_to_the_api_host(): void
    {
        $this->post('/register', self::FORM);

        $mail = Mail::sent(VerifyEmailMail::class, fn ($m) => $m->hasTo(self::FORM['email']))->sole();
        $this->assertEmailLinksTo($mail, $mail->verifyUrl);
    }

    /** The rendered email carries the link in both the button and the copy-paste text. */
    private function assertEmailLinksTo(VerifyEmailMail $mail, string $url): void
    {
        $html = $mail->render();
        $escaped = e($url);

        $this->assertStringContainsString('href="'.$escaped.'"', $html, 'button has no link');
        $this->assertSame(2, substr_count($html, $escaped), 'link missing from the button or the text');
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
