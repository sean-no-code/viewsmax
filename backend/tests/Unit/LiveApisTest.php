<?php

namespace Tests\Unit;

use App\Support\LiveApis;
use Tests\TestCase;

/**
 * One switch decides whether paid provider services (Anthropic, Gemini, …)
 * call the real API: always in production/staging, never on a dev box or in
 * the suite unless USE_LIVE_IN_APIS_DEVELOPMENT=true.
 */
class LiveApisTest extends TestCase
{
    public function test_dev_environments_are_canned_by_default(): void
    {
        config(['app.live_apis_in_development' => false]);

        foreach (['local', 'development', 'testing'] as $env) {
            config(['app.env' => $env]);
            $this->assertFalse(LiveApis::enabled(), "{$env} must not spend by default");
        }
    }

    public function test_the_flag_turns_live_calls_on_in_dev_environments(): void
    {
        config(['app.live_apis_in_development' => true]);

        foreach (['local', 'development', 'testing'] as $env) {
            config(['app.env' => $env]);
            $this->assertTrue(LiveApis::enabled());
        }
    }

    public function test_other_environments_are_always_live(): void
    {
        config(['app.live_apis_in_development' => false]);

        foreach (['production', 'staging'] as $env) {
            config(['app.env' => $env]);
            $this->assertTrue(LiveApis::enabled());
        }
    }
}
