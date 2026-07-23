<?php

namespace Tests\Unit;

use App\Services\Social\Providers\InstagramProvider;
use Tests\TestCase;

/**
 * The Instagram authorize URL must force re-authentication, otherwise a user
 * connecting a SECOND account is silently handed the account already logged in
 * on the device (same user_id) and storeAccount overwrites the first row —
 * so only one Instagram account ever exists.
 */
class InstagramAuthUrlTest extends TestCase
{
    public function test_authorize_url_forces_reauth_so_a_second_account_can_be_added(): void
    {
        $url = (new InstagramProvider)->getAuthorizationUrl('https://app.test/callback', 'state-123');

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

        $this->assertSame('true', $params['force_reauth'] ?? null);
        $this->assertSame('state-123', $params['state'] ?? null);
        $this->assertSame('code', $params['response_type'] ?? null);
    }
}
