<?php

namespace Meent\WebHook\Solid;

final class SolidClientConfig
{
    private string $stateSigningKey;
    private int $expirationTime;
    private bool $useCsrf;
    private bool $usePkce;

    public function __construct(
        bool $useCsrf,
        bool $usePkce,
        int $expirationTime,
        string $stateSigningKey,
    ) {
        $this->stateSigningKey = $stateSigningKey;
        $this->expirationTime = $expirationTime;
        $this->useCsrf = $useCsrf;
        $this->usePkce = $usePkce;
    }

    public function expirationTime(): int
    {
        return $this->expirationTime;
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
