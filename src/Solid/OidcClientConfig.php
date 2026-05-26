<?php

namespace Meent\WebHook\Solid;

final class OidcClientConfig
{
    public const METADATA_FILE = 'client_metadata.json';

    private ?string $clientId;
    private string $clientName;
    private string $clientSecret;
    private string $configFile;
    private ?string $initialAccessToken;
    private string $redirectUri;

    /** @var string[] */
    private array $redirectUris;

    final public function __construct(
        string $clientName,
        string $clientSecret,
        string $redirectUri,
        array $redirectUris,
        ?string $clientId,
        ?string $initialAccessToken = null,
    ) {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->redirectUris = array_values($redirectUris);
        $this->initialAccessToken = $initialAccessToken;
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
}
