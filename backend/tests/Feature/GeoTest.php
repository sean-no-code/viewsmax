<?php

namespace Tests\Feature;

use App\Services\GeoLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/geo tells the SPA whether to show the cookie banner: only visitors
 * in the EU/EEA, UK or Switzerland need it. Unknown location fails safe (show).
 */
class GeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_eu_visitor_requires_consent(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'country' => 'Germany', 'countryCode' => 'DE'])]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson('/api/geo')
            ->assertOk()
            ->assertJson(['country_code' => 'DE', 'cookie_consent_required' => true]);
    }

    public function test_non_eu_visitor_does_not_require_consent(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'country' => 'United States', 'countryCode' => 'US'])]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson('/api/geo')
            ->assertOk()
            ->assertJson(['country_code' => 'US', 'cookie_consent_required' => false]);
    }

    public function test_cdn_country_header_wins_without_a_lookup(): void
    {
        Http::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders(['CloudFront-Viewer-Country' => 'gb'])
            ->getJson('/api/geo')
            ->assertOk()
            ->assertJson(['country_code' => 'GB', 'cookie_consent_required' => true]);

        Http::assertNothingSent();
    }

    public function test_unknown_location_fails_safe_to_showing_the_banner(): void
    {
        Http::fake(['ip-api.com/*' => Http::response('boom', 500)]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson('/api/geo')
            ->assertOk()
            ->assertJson(['country_code' => null, 'cookie_consent_required' => true]);

        // Private / local addresses are never looked up.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/geo')
            ->assertOk()
            ->assertJson(['cookie_consent_required' => true]);
    }

    public function test_lookup_is_cached_per_ip(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'country' => 'France', 'countryCode' => 'FR'])]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson('/api/geo')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson('/api/geo')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_consent_country_list_covers_eea_uk_and_switzerland(): void
    {
        $geo = new GeoLocationService;

        foreach (['DE', 'FR', 'IE', 'NO', 'IS', 'LI', 'GB', 'CH'] as $code) {
            $this->assertTrue($geo->requiresCookieConsent($code), $code);
        }
        foreach (['US', 'AU', 'TH', 'BR'] as $code) {
            $this->assertFalse($geo->requiresCookieConsent($code), $code);
        }
    }
}
