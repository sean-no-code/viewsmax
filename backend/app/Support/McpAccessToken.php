<?php

namespace App\Support;

use App\Http\Controllers\Oauth\McpOAuthMetadataController;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Laravel\Passport\Bridge\AccessToken;
use League\OAuth2\Server\CryptKeyInterface;
use SensitiveParameter;

/**
 * Passport's access token, with this MCP server added as an audience.
 *
 * Both directories ask for it. OpenAI: "Configure your authorization server to
 * copy that value into the access token (commonly the `aud` claim) so your MCP
 * server can verify the token was minted for it and nobody else." Claude's
 * sample server verifies that "`aud` equals the `resource` value you advertise
 * in the PRM".
 *
 * The client id stays the FIRST audience entry: league/oauth2-server reads
 * `oauth_client_id` from `aud[0]` when it validates a request, so reordering
 * would break every authenticated call.
 *
 * Everything else mirrors league's own AccessTokenTrait::convertToJWT(), which
 * is private and can't be extended.
 */
class McpAccessToken extends AccessToken
{
    private ?CryptKeyInterface $signingKey = null;

    public function setPrivateKey(
        #[SensitiveParameter]
        CryptKeyInterface $privateKey
    ): void {
        $this->signingKey = $privateKey;

        parent::setPrivateKey($privateKey);
    }

    public function toString(): string
    {
        $contents = $this->signingKey?->getKeyContents() ?? '';

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($contents, $this->signingKey?->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );

        $now = new DateTimeImmutable;

        return $configuration->builder()
            ->permittedFor($this->getClient()->getIdentifier(), McpOAuthMetadataController::resource())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
