<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** `user:erase {email}`: the staff entry point to App\Services\UserErasure. */
class EraseUserCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.stripe.secret' => null, 'services.klaviyo.api_key' => null, 'services.kit.api_secret' => null]);
        $this->app->instance(StripeService::class, Mockery::mock(StripeService::class));
    }

    private function userWithPost(string $email = 'gone@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->posts()->create(['caption' => 'hi', 'media' => [], 'status' => Post::STATUS_DRAFT]);
        $user->createToken('mcp');

        return $user;
    }

    public function test_dry_run_prints_the_plan_and_deletes_nothing(): void
    {
        $user = $this->userWithPost();

        $this->artisan('user:erase', ['email' => 'gone@example.com', '--dry-run' => true])
            ->expectsOutputToContain('posts')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(1, $user->posts()->count());
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_declining_the_confirmation_deletes_nothing(): void
    {
        $user = $this->userWithPost();

        $this->artisan('user:erase', ['email' => 'gone@example.com'])
            ->expectsConfirmation('Erase gone@example.com (id '.$user->id.')? This cannot be undone.', 'no')
            ->assertExitCode(0);

        $this->assertSame(1, $user->posts()->count());
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_yes_erases_without_a_prompt(): void
    {
        $user = $this->userWithPost();

        $this->artisan('user:erase', ['email' => 'Gone@Example.com', '--yes' => true])
            ->expectsOutputToContain('Erased')
            ->assertExitCode(0);

        $this->assertSame(0, Post::where('user_id', $user->id)->count());
        $tomb = User::withTrashed()->find($user->id);
        $this->assertTrue($tomb->trashed());
        $this->assertSame("erased+{$user->id}@erased.invalid", $tomb->email);
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('user:erase', ['email' => 'nobody@example.com', '--yes' => true])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_an_already_erased_user_is_reported_not_rerun(): void
    {
        $user = $this->userWithPost();
        $this->artisan('user:erase', ['email' => 'gone@example.com', '--yes' => true])->assertExitCode(0);

        $this->artisan('user:erase', ['email' => "erased+{$user->id}@erased.invalid", '--yes' => true])
            ->expectsOutputToContain('already erased')
            ->assertExitCode(0);
    }

    public function test_admins_are_refused(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
        $admin->posts()->create(['caption' => 'hi', 'media' => [], 'status' => Post::STATUS_DRAFT]);

        $this->artisan('user:erase', ['email' => 'boss@example.com', '--yes' => true])
            ->expectsOutputToContain('admin')
            ->assertExitCode(1);

        $this->assertFalse(User::byEmail('boss@example.com')->first()->trashed());
    }
}
