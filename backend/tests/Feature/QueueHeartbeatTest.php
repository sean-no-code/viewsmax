<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeatJob;
use App\Mail\QueueHeartbeatAlertMail;
use App\Models\QueueHeartbeat;
use App\Services\QueueHeartbeatMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'monitoring.queue_heartbeat.enabled' => true,
            'monitoring.queue_heartbeat.stuck_after_minutes' => 10,
            'monitoring.queue_heartbeat.alert_email' => 'ops@example.com',
            'monitoring.queue_heartbeat.alert_cooldown_minutes' => 60,
            'monitoring.monitor_allowed_ips' => '127.0.0.1,::1',
        ]);
    }

    public function test_tick_dispatches_a_heartbeat_job(): void
    {
        Queue::fake();

        $this->artisan('queue:heartbeat')->assertExitCode(0);

        $this->assertDatabaseCount('queue_heartbeats', 1);
        Queue::assertPushed(QueueHeartbeatJob::class, 1);
    }

    public function test_processed_heartbeat_is_stamped(): void
    {
        // QUEUE_CONNECTION=sync in phpunit.xml: the job runs inline.
        $this->artisan('queue:heartbeat')->assertExitCode(0);

        $this->assertNotNull(QueueHeartbeat::sole()->processed_at);
        $this->assertSame(QueueHeartbeatMonitor::STATUS_OK, app(QueueHeartbeatMonitor::class)->status()['status']);
    }

    public function test_disabled_heartbeat_does_nothing(): void
    {
        Queue::fake();
        config(['monitoring.queue_heartbeat.enabled' => false]);

        $this->artisan('queue:heartbeat')->assertExitCode(0);

        $this->assertDatabaseCount('queue_heartbeats', 0);
        Queue::assertNothingPushed();
    }

    public function test_stuck_queue_emails_the_admin_once_then_sends_a_recovery(): void
    {
        Mail::fake();

        // Dispatched 20 minutes ago, never processed: the workers are dead.
        $stuck = QueueHeartbeat::create(['dispatched_at' => now()->subMinutes(20)]);

        $this->artisan('queue:heartbeat --no-dispatch')->assertExitCode(0);

        Mail::assertSent(QueueHeartbeatAlertMail::class, fn ($mail) => $mail->event === QueueHeartbeatMonitor::EVENT_STUCK
            && $mail->hasTo('ops@example.com')
            && $mail->status['status'] === QueueHeartbeatMonitor::STATUS_STUCK);
        $this->assertNotNull($stuck->refresh()->alerted_at);

        // Still stuck five minutes later: inside the cooldown, no second email.
        $this->travel(5)->minutes();
        $this->artisan('queue:heartbeat --no-dispatch')->assertExitCode(0);
        Mail::assertSent(QueueHeartbeatAlertMail::class, 1);

        // Workers are back: a fresh heartbeat gets processed → recovery email, once.
        QueueHeartbeat::create(['dispatched_at' => now(), 'processed_at' => now()]);
        $this->artisan('queue:heartbeat --no-dispatch')->assertExitCode(0);
        $this->artisan('queue:heartbeat --no-dispatch')->assertExitCode(0);

        Mail::assertSent(QueueHeartbeatAlertMail::class, fn ($mail) => $mail->event === QueueHeartbeatMonitor::EVENT_RECOVERED);
        Mail::assertSent(QueueHeartbeatAlertMail::class, 2);
        $this->assertNotNull($stuck->refresh()->recovered_at);
    }

    public function test_no_alert_email_when_no_address_is_configured(): void
    {
        Mail::fake();
        config(['monitoring.queue_heartbeat.alert_email' => null]);
        QueueHeartbeat::create(['dispatched_at' => now()->subMinutes(20)]);

        $this->artisan('queue:heartbeat --no-dispatch')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_monitor_url_is_hidden_from_other_ips(): void
    {
        config(['monitoring.monitor_allowed_ips' => '10.0.0.0/8']);

        $this->getJson('/api/queue-monitor')->assertNotFound();
    }

    public function test_monitor_url_reports_status_to_an_allowed_ip(): void
    {
        $this->getJson('/api/queue-monitor')
            ->assertStatus(503)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('status', QueueHeartbeatMonitor::STATUS_UNKNOWN);

        QueueHeartbeat::create(['dispatched_at' => now()->subMinute(), 'processed_at' => now()]);
        $this->getJson('/api/queue-monitor')->assertOk()
            ->assertJsonPath('status', QueueHeartbeatMonitor::STATUS_OK);

        // Dispatched but never processed, and nothing processed lately → stuck.
        QueueHeartbeat::query()->delete();
        QueueHeartbeat::create(['dispatched_at' => now()->subMinutes(15)]);
        $this->getJson('/api/queue-monitor')->assertStatus(503)
            ->assertJsonPath('status', QueueHeartbeatMonitor::STATUS_STUCK)
            ->assertJsonPath('heartbeat.overdue', 1);

        // Nothing dispatched for two intervals → the scheduler is down.
        QueueHeartbeat::query()->delete();
        QueueHeartbeat::create(['dispatched_at' => now()->subMinutes(30), 'processed_at' => now()->subMinutes(30)]);
        $this->getJson('/api/queue-monitor')->assertStatus(503)
            ->assertJsonPath('status', QueueHeartbeatMonitor::STATUS_DOWN);
    }
}
