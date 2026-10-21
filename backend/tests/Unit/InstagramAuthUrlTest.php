<?php

namespace Tests\Unit;

use App\Services\Social\Providers\InstagramProvider;
use Tests\TestCase;

/**
 * The Instagram authorize URL must force re-authentication, otherwise a user
 * connecting a SECOND account is silently handed the account already logged in
 * on the device (same user_id) and storeAccount overwrites the first row —
 * so only one Instagram account ever exists. It must also hide the "Log in
 * with Facebook" option, whose token graph.instagram.com rejects.
 */
class InstagramAuthUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.instagram.client_id' => 'id', 'social.platforms.instagram.client_secret' => 'secret']);
    }

    /** @return array<string, string> */
    private function authorizeParams(): array
    {
        $url = (new InstagramProvider)->getAuthorizationUrl('https://app.test/callback', 'state-123');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

        return $params;
    }

    public function test_authorize_url_forces_reauth_so_a_second_account_can_be_added(): void
    {
        $params = $this->authorizeParams();

        $this->assertSame('true', $params['force_reauth'] ?? null);
        $this->assertSame('state-123', $params['state'] ?? null);
        $this->assertSame('code', $params['response_type'] ?? null);
        $this->assertSame('id', $params['client_id'] ?? null);
    }

    public function test_authorize_url_disables_the_facebook_login_option(): void
    {
        $this->assertSame('0', $this->authorizeParams()['enable_fb_login'] ?? null);
    }
}
