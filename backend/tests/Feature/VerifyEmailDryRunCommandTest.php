<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerifyEmailDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_address' => 'ops@example.com']);
    }

    public function test_it_sends_the_app_users_email_to_the_admin_with_a_working_link(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'signup_source' => User::SIGNUP_SOURCE_APP, 'email_verified_at' => null]);

        $this->artisan('user:verify-email-dry-run', ['email' => 'jane@example.com'])
            ->expectsOutputToContain('Sent to ops@example.com')
            ->assertSuccessful();

        $mail = Mail::sent(VerifyEmailMail::class)->sole();
        $this->assertTrue($mail->hasTo('ops@example.com'));
        $this->assertFalse($mail->hasTo('jane@example.com'));
        $this->assertStringStartsWith(rtrim(config('app.frontend_url'), '/').'/verify-email?token=', $mail->verifyUrl);
        $this->assertStringContainsString('href="'.e($mail->verifyUrl).'"', $mail->render());
        $this->assertSame(1, DB::table('email_verification_tokens')->where('user_id', $user->id)->count());
    }

    public function test_agent_users_get_the_api_host_link(): void
    {
        User::factory()->create(['email' => 'agent@example.com', 'signup_source' => User::SIGNUP_SOURCE_AGENT]);

        $this->artisan('user:verify-email-dry-run', ['email' => 'agent@example.com'])->assertSuccessful();

        $mail = Mail::sent(VerifyEmailMail::class)->sole();
        $this->assertStringStartsWith(route('verify-email.web', [], true), $mail->verifyUrl);
    }

    public function test_to_option_overrides_the_admin_address(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->artisan('user:verify-email-dry-run', ['email' => 'jane@example.com', '--to' => 'me@example.com'])->assertSuccessful();

        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('me@example.com') && ! $m->hasTo('ops@example.com'));
    }

    public function test_it_fails_without_a_recipient_or_user(): void
    {
        config(['mail.admin_address' => null]);
        User::factory()->create(['email' => 'jane@example.com']);

        $this->artisan('user:verify-email-dry-run', ['email' => 'jane@example.com'])->assertFailed();

        config(['mail.admin_address' => 'ops@example.com']);
        $this->artisan('user:verify-email-dry-run', ['email' => 'nobody@example.com'])->assertFailed();

        Mail::assertNothingSent();
    }
}
