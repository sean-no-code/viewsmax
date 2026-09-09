<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Automations\AutomationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Tests\TestCase;

/**
 * Automations log to their own channel (storage/logs/automations-*.log),
 * can be switched off with AUTOMATIONS_LOG_ENABLED=false, and never leak
 * tokens.
 */
class AutomationLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_wrapper_writes_to_the_automations_channel_with_prefix(): void
    {
        Log::shouldReceive('channel')->once()->with('automations')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('[Automations] event stored', ['event_id' => 'c1']);

        AutomationLog::info('event stored', ['event_id' => 'c1']);
    }

    public function test_channel_writes_to_a_rotating_automations_file_by_default(): void
    {
        $handlers = Log::channel('automations')->getHandlers();

        $this->assertNotEmpty($handlers);
        $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);
        $this->assertStringContainsString('automations', (string) $handlers[0]->getUrl());
    }

    public function test_disabling_resolves_the_channel_to_the_null_handler(): void
    {
        config(['logging.channels.automations.channels' => ['null']]);
        Log::forgetChannel('automations');

        $handlers = Log::channel('automations')->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(NullHandler::class, $handlers[0]);
    }

    public function test_redact_strips_secrets_and_truncates_long_bodies(): void
    {
        $out = AutomationLog::redact([
            'access_token' => 'IGQ...',
            'nested' => ['client_secret' => 's', 'X-Hub-Signature-256' => 'sha256=abc', 'keep' => 1],
            'body' => str_repeat('x', 5000),
            'text' => 'hello',
        ]);

        $this->assertSame('[redacted]', $out['access_token']);
        $this->assertSame('[redacted]', $out['nested']['client_secret']);
        $this->assertSame('[redacted]', $out['nested']['X-Hub-Signature-256']);
        $this->assertSame(1, $out['nested']['keep']);
        $this->assertLessThan(2100, mb_strlen($out['body']));
        $this->assertStringEndsWith('[truncated]', $out['body']);
        $this->assertSame('hello', $out['text']);
    }

    public function test_context_collects_ids_and_drops_nulls(): void
    {
        $user = User::factory()->create();
        $account = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'instagram', 'platform_account_id' => 'ig-1',
            'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $ctx = AutomationLog::context(account: $account);

        $this->assertSame(['account_id' => $account->id, 'ig_user_id' => 'ig-1', 'user_id' => $user->id], $ctx);
    }
}
