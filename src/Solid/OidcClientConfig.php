<?php

namespace Meent\WebHook\Solid;

final class OidcClientConfig
{
    public const METADATA_FILE = 'client_metadata.json';

    public const REQUIRE_NEW_AUTHENTICATION = false;
    public const REUSE_STORED_AUTHENTICATION = true;

    private ?string $clientId;
    private string $clientName;
    private string $clientSecret;
    private ?string $initialAccessToken;
    private string $redirectUri;
    private bool $useOffline;

    /** @var string[] */
    private array $grantTypes;
    /** @var string[] */
    private array $redirectUris;
    /** @var string[] */
    private array $scope;

    final public function __construct(
        string $clientName,
        string $clientSecret,
        string $redirectUri,
        array $redirectUris,
        ?string $clientId,
        ?bool $useOffline = self::REUSE_STORED_AUTHENTICATION,
        ?string $initialAccessToken = null,
    ) {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->redirectUris = array_values($redirectUris);
        $this->initialAccessToken = $initialAccessToken;
        $this->useOffline = $useOffline;

        $this->grantTypes = ['authorization_code'];
        $this->scope = ['openid', 'webid'];

        if ($this->useOffline === true) {
            // grant_types must include refresh_token to receive one (OIDC Core Section 11 / offline_access)
            $this->grantTypes[] = 'refresh_token';
            $this->scope[] = 'offline_access';
        }
    }

    final public function clientId(): ?string
    {
        return $this->clientId;
    }

    final public function clientName(): string
    {
        return $this->clientName;
    }

    final public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    final public function grantTypes()
    {
        return $this->grantTypes;
    }

    final public function initialAccessToken()
    {
        return $this->initialAccessToken;
    }

    /**
     * @return string[]
     */
    final public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    final public function scope()
    {
        return implode(' ', $this->scope);
    }

    final public function useOffline()
    {
        return $this->useOffline;
    }
}
