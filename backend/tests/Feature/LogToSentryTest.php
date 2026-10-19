<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Tests\TestCase;

/**
 * Sentry is wired to the exception handler (Integration::handles), so for a long
 * time only report()ed and uncaught exceptions reached it — a catch block that
 * merely logged reached nobody but error.log. config/logging.php now puts the
 * 'sentry' channel in every stack at error level, which these tests pin down:
 *
 * - an error-level log becomes a Sentry event even though nothing threw;
 * - warnings and info do NOT (the level gate), or ordinary noise would alert;
 * - a log line carrying the exception OBJECT is skipped, because the handler
 *   already sent that exception — otherwise one failure bills two events.
 */
class LogToSentryTest extends TestCase
{
    use RefreshDatabase;

    /** Captured events, newest last: ['message' => ...] or ['exception' => ...]. */
    private array $captured = [];

    /** Swap the Sentry hub for a spy and rebuild the log channels around it. */
    private function spyOnSentry(): void
    {
        $this->captured = [];

        $hub = Mockery::mock(HubInterface::class)->shouldIgnoreMissing();
        // doWrite() wraps the capture in withScope(); run the callback so the
        // capture actually happens.
        $hub->shouldReceive('withScope')->andReturnUsing(fn (callable $cb) => $cb(new Scope));
        $hub->shouldReceive('captureMessage')->andReturnUsing(function ($message) {
            $this->captured[] = ['message' => $message];

            return null;
        });
        $hub->shouldReceive('captureException')->andReturnUsing(function ($e) {
            $this->captured[] = ['exception' => $e];

            return null;
        });

        $this->app->instance(HubInterface::class, $hub);

        // The handler resolves the hub when the channel is built, so drop any
        // channel built before the swap.
        foreach (['sentry', 'split', 'stack', 'mail'] as $channel) {
            Log::forgetChannel($channel);
        }
    }

    private function messages(): array
    {
        return array_column(array_filter($this->captured, fn ($c) => isset($c['message'])), 'message');
    }

    public function test_the_sentry_channel_is_in_the_default_stack(): void
    {
        // The whole point is that a plain Log::error reaches Sentry, which only
        // holds while the default channel's stack contains 'sentry'.
        $default = config('logging.default');
        $this->assertContains('sentry', config("logging.channels.{$default}.channels"));
        $this->assertSame('error', config('logging.channels.sentry.level'));
    }

    public function test_an_error_log_reaches_sentry_without_an_exception(): void
    {
        $this->spyOnSentry();

        Log::error('Stripe verification failed — leaving subscription untouched', ['user_id' => 7]);

        $this->assertContains('Stripe verification failed — leaving subscription untouched', $this->messages());
    }

    public function test_warnings_and_info_do_not_reach_sentry(): void
    {
        $this->spyOnSentry();

        Log::warning('thumbnail fetch failed (non-fatal)');
        Log::info('[channel-ingest] done');

        $this->assertSame([], $this->captured);

        // ...and the spy really is live, so the assertion above means something.
        Log::error('a real failure');
        $this->assertSame(['a real failure'], $this->messages());
    }

    public function test_a_log_carrying_the_exception_object_is_not_sent_twice(): void
    {
        // This is the shape Laravel's exception handler logs with, and that
        // exception has already gone to Sentry via report(). Capturing it here
        // as well would bill two events for one failure.
        $this->spyOnSentry();

        Log::error('Something broke', ['exception' => new \RuntimeException('boom')]);

        $this->assertSame([], $this->captured);

        // Same message without the object does reach Sentry — it is the
        // exception context that suppresses it, not the level or the message.
        Log::error('Something broke');
        $this->assertSame(['Something broke'], $this->messages());
    }

    public function test_a_validation_error_does_not_reach_sentry(): void
    {
        // The API error renderer logs every 422 at error level so it lands in
        // error.log and Slack. A user submitting a bad form is not an issue to
        // alert on, and the renderer tags the record with the exception to keep
        // it out of Sentry.
        $this->spyOnSentry();

        $this->postJson('/api/login', [])->assertStatus(422);

        $this->assertSame([], $this->messages());

        Log::error('a real failure');
        $this->assertSame(['a real failure'], $this->messages());
    }
}
