<?php

namespace Meent\WebHook\Solid;

class OidcClientConfig
{
    public const METADATA_FILE = 'client_id.json';

    public const REQUIRE_NEW_AUTHENTICATION = false;
    public const REUSE_STORED_AUTHENTICATION = true;

    private ?string $clientId;
    private string $clientName;
    private ?string $initialAccessToken;
    private bool $useOffline;

    /** @var string[] */
    private array $grantTypes;
    /** @var string[] */
    private array $redirectUris;
    /** @var string[] */
    private array $scope;

    final public function __construct(
        string $clientName,
        array $redirectUris,
        ?bool $useOffline = self::REUSE_STORED_AUTHENTICATION,
        ?string $clientId = null,
        ?string $initialAccessToken = null,
    ) {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->initialAccessToken = $initialAccessToken;
        $this->redirectUris = array_values($redirectUris);
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

    final public function toArray()
    {
        return array_filter([
            'client_id' => $this->clientId(),
            'client_name' => $this->clientName(),
            'grant_types' => $this->grantTypes(),
            'initial_access_token' => $this->initialAccessToken(),
            'redirect_uris' => $this->redirectUris(),
            'scope' => $this->scope(),
        ], static function ($value) {
            return ! empty($value);
        });
    }

    final public function useOffline()
    {
        return $this->useOffline;
    }
}
