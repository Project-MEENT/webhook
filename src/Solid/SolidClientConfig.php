<?php

namespace Meent\WebHook\Solid;

final class SolidClientConfig
{
    private string $redirectUri;
    private string $stateSigningKey;
    private int $expirationTime;
    private bool $useCsrf;
    private bool $usePkce;

    public function __construct(
        bool $useCsrf,
        bool $usePkce,
        int $expirationTime,
        string $stateSigningKey,
        string $redirectUri,
    ) {
        $this->stateSigningKey = $stateSigningKey;
        $this->expirationTime = $expirationTime;
        $this->useCsrf = $useCsrf;
        // For certain issuers (like https://solidcommunity.net) PKCE is required, even for server-to-server calls.
        // @FIXME: PKCE use should be stored in the server offline grant or metadata JSON.
        $this->usePkce = $usePkce;
        $this->redirectUri = $redirectUri;
    }

    public function expirationTime(): int
    {
        return $this->expirationTime;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    public function stateSigningKey(): string
    {
        return $this->stateSigningKey;
    }

    public function useCsrf(): bool
    {
        return $this->useCsrf;
    }

    public function usePkce(): bool
    {
        return $this->usePkce;
    }
}
