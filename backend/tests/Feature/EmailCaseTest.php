<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An email address is one account whatever its capitalisation. Postgres (and
 * the sqlite test database) compare the users.email unique index and the
 * `unique:users` rule case-sensitively, which once let "Jane@Example.com" sign
 * up next to "jane@example.com" and then fail to log in as either.
 */
class EmailCaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
    }

    private function payload(string $email): array
    {
        return ['name' => 'Jane', 'email' => $email, 'password' => 'password123', 'password_confirmation' => 'password123'];
    }

    public function test_registration_stores_the_address_lowercased(): void
    {
        $this->postJson('/api/register', $this->payload('  Jane.Doe@Example.COM '))
            ->assertStatus(201)
            ->assertJsonPath('email', 'jane.doe@example.com');

        $this->assertDatabaseHas('users', ['email' => 'jane.doe@example.com']);
        Mail::assertSent(VerifyEmailMail::class, fn ($m) => $m->hasTo('jane.doe@example.com'));
    }

    public function test_api_registration_rejects_an_existing_address_in_another_case(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/register', $this->payload('JANE@Example.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_agent_registration_rejects_an_existing_address_in_another_case(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->from('/register')->post('/register', $this->payload('Jane@Example.com'))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_login_and_password_reset_find_the_account_whatever_the_case(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => Hash::make('password123'), 'email_verified_at' => now()]);

        $this->postJson('/api/login', ['email' => 'Jane@EXAMPLE.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/forgot-password', ['email' => 'JANE@example.com'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'jane@example.com']);
    }

    public function test_migration_lowercases_existing_rows_but_leaves_real_duplicates_alone(): void
    {
        // Bypass the mutator to recreate rows written before it existed.
        $mixed = User::factory()->create(['email' => 'placeholder1@example.com']);
        $dupeA = User::factory()->create(['email' => 'placeholder2@example.com']);
        $dupeB = User::factory()->create(['email' => 'dupe@example.com']);
        DB::table('users')->where('id', $mixed->id)->update(['email' => 'Mixed@Example.com']);
        DB::table('users')->where('id', $dupeA->id)->update(['email' => 'Dupe@Example.com']);

        (require database_path('migrations/2026_10_21_190000_lowercase_user_emails.php'))->up();

        $this->assertSame('mixed@example.com', $mixed->fresh()->email);
        $this->assertSame('Dupe@Example.com', $dupeA->fresh()->email, 'a duplicate pair is left for a person to merge');
        $this->assertSame('dupe@example.com', $dupeB->fresh()->email);
    }
}
