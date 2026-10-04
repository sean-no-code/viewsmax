<?php

namespace Tests\Unit;

use App\Support\OAuthIntendedClient;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OAuthIntendedClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_client_name_from_an_intended_authorize_url(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://example.test/cb'], confidential: false);

        $this->assertSame('Claude', OAuthIntendedClient::nameFrom('http://api.test/oauth/authorize?client_id='.$client->id.'&scope=mcp'));
        $this->assertTrue(OAuthIntendedClient::isAuthorizeUrl('http://api.test/oauth/authorize?client_id='.$client->id));
    }

    public function test_ignores_urls_that_are_not_authorize_requests_or_carry_bad_ids(): void
    {
        $this->assertNull(OAuthIntendedClient::nameFrom(null));
        $this->assertNull(OAuthIntendedClient::nameFrom('http://api.test/dashboard?client_id=abc'));
        $this->assertNull(OAuthIntendedClient::nameFrom('http://api.test/oauth/authorize?client_id=../x'));
        $this->assertNull(OAuthIntendedClient::nameFrom('http://api.test/oauth/authorize?client_id=00000000-0000-0000-0000-000000000000'));
        $this->assertFalse(OAuthIntendedClient::isAuthorizeUrl('http://api.test/dashboard'));
    }
}
