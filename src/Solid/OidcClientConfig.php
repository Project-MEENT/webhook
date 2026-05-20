<?php

namespace Meent\WebHook\Solid;

final class OidcClientConfig
{
    public const METADATA_FILE = 'client_metadata.json';

    private ?string $clientId;
    private string $clientName;
    private string $clientSecret;
    private string $configFile;
    private string $redirectUri;
    /** @var string[] */
    private array $redirectUris;

    final public function __construct(
        string $clientName,
        string $clientSecret,
        string $redirectUri,
        array $redirectUris,
        ?string $clientId,
    ) {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->redirectUris = array_values($redirectUris);
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

    final public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * @return string[]
     */
    final public function redirectUris(): array
    {
        return $this->redirectUris;
    }
}
