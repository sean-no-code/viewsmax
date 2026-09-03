<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyUserEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_user_verified(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com', 'email_verified_at' => null]);

        $this->artisan('user:verify', ['email' => 'dev@example.com'])->assertSuccessful();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->create(['email' => 'dev@example.com', 'email_verified_at' => null]);

        $this->artisan('user:verify', ['email' => 'dev@example.com'])->assertFailed();

        $this->assertNull($user->refresh()->email_verified_at);
    }
}
