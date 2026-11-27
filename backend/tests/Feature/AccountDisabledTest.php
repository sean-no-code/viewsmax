<?php

namespace Tests\Feature;

use App\Models\McpToolInvocation;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * users.disabled_at switches an account off: every entry point answers
 * "account disabled, contact support" (UserAccess::ACCOUNT_DISABLED) and
 * nothing is done on the user's behalf until the flag is cleared. It outranks
 * every other rule, the admin and promotional exemptions included.
 */
class AccountDisabledTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Your ViewsMax account has been disabled. Please contact support.';

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken];
    }

    private function mcpCall(User $user, string $tool): TestResponse
    {
        $plain = 'vmx_'.$user->generateTokenString();
        $user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $plain), 'abilities' => ['mcp']]);

        return $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
    }

    public function test_a_disabled_user_is_denied_with_the_contact_support_message(): void
    {
        $denial = UserAccess::denial(User::factory()->disabled()->create());

        $this->assertSame(UserAccess::ACCOUNT_DISABLED, $denial->code);
        $this->assertSame(self::MESSAGE, $denial->message);
        $this->assertSame(403, $denial->status());
        $this->assertSame(
            ['success' => false, 'code' => 'account_disabled', 'message' => self::MESSAGE, 'url' => ''],
            $denial->toArray(),
        );
    }

    public function test_the_flag_outranks_every_other_rule(): void
    {
        $users = [
            'paying' => User::factory()->disabled()->withPlan('active')->create(),
            'admin' => User::factory()->disabled()->admin()->create(),
            'promotional' => User::factory()->disabled()->promotional()->create(),
            'unverified' => User::factory()->disabled()->unverified()->create(),
            'lapsed' => User::factory()->disabled()->expired()->withPlan('cancelled')->create(),
        ];

        foreach ($users as $kind => $user) {
            $this->assertSame(UserAccess::ACCOUNT_DISABLED, UserAccess::denial($user)?->code, $kind);
            $this->assertFalse(UserAccess::allowsBackgroundWork($user), $kind);
        }
    }

    public function test_clearing_the_flag_restores_the_account(): void
    {
        $user = User::factory()->disabled()->withPlan('active')->create();

        $user->forceFill(['disabled_at' => null])->save();

        $this->assertNull(UserAccess::denial($user->fresh()));
    }

    public function test_the_flag_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $user->fill(['disabled_at' => now()]);

        $this->assertNull($user->disabled_at);
        $this->assertFalse($user->isDisabled());
    }

    /** REST delivery: 403 on everything, billing and profile included; only sign-out still works. */
    public function test_rest_api_refuses_every_endpoint_but_logout(): void
    {
        $headers = $this->bearer(User::factory()->disabled()->withPlan('active')->create());

        foreach (['/api/posts', '/api/profile', '/api/plans'] as $path) {
            $this->withHeaders($headers)->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'account_disabled')
                ->assertJsonPath('message', self::MESSAGE)
                ->assertJsonMissingPath('error');
        }

        $this->withHeaders($headers)->postJson('/api/logout')->assertOk();
    }

    public function test_login_is_refused_and_issues_no_token(): void
    {
        $user = User::factory()->disabled()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'account_disabled')
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, $user->tokens()->count());
    }

    /** Without the password nobody learns the account is disabled. */
    public function test_login_with_a_wrong_password_still_says_invalid_credentials(): void
    {
        $user = User::factory()->disabled()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials');
    }

    /** MCP delivery: the message as a plain tool result at HTTP 200, logged as a refusal. */
    public function test_mcp_tool_calls_answer_with_the_message_instead_of_running(): void
    {
        $user = User::factory()->disabled()->withPlan('active')->create();

        $this->mcpCall($user, 'list_connected_accounts')
            ->assertOk()
            ->assertJsonMissingPath('error')
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.0.text', self::MESSAGE);

        $row = McpToolInvocation::where('user_id', $user->id)->sole();
        $this->assertTrue((bool) $row->is_error);
        $this->assertSame(self::MESSAGE, $row->error);
    }

    /** Web delivery (agent signup pages, OAuth consent): a 403 page, not a redirect to an empty url. */
    public function test_web_pages_answer_403_with_the_message(): void
    {
        $user = User::factory()->disabled()->create(['signup_source' => 'agent']);

        $this->actingAs($user, 'web')->get(route('register.setup'))
            ->assertForbidden()
            ->assertSee('account has been disabled');
    }
}
