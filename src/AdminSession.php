<?php

namespace Meent\WebHook;

class AdminSession
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    private const SESSION_KEY_AUTHENTICATED_AT = 'admin_authenticated_at';
    private const SESSION_KEY_AUTHENTICATED_WEBID = 'admin_webid';
    private const SESSION_MAX_AGE = 3600;
    private const SESSION_KEY_CSRF_TOKEN = 'admin_csrf_token';

    private Session $session;
    private array $webIds;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(Session $session, array $webIds)
    {
        $this->webIds = array_map([$this, 'normalizeUrl'], $webIds);
        $this->session = $session;
    }

    final public function csrfToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY_CSRF_TOKEN);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY_CSRF_TOKEN, $token);
        }

        return $token;
    }

    final public function csrfTokenIsValid($token): bool
    {
        $isValid = false;

        $stored = $this->session->get(self::SESSION_KEY_CSRF_TOKEN);

        if (is_string($token) && is_string($stored) && $stored !== '') {
            $isValid = hash_equals($stored, $token);
        }

        if ($isValid) {
            $this->session->remove(self::SESSION_KEY_CSRF_TOKEN);
        }

        return $isValid;
    }

    final public function isAdmin(string $webId): bool
    {
        return in_array(
            $this->normalizeUrl($webId),
            $this->webIds,
            true
        );
    }

    final public function isAuthenticated(): ?string
    {
        $adminWebId = null;

        $authenticatedAt = $this->session->get(self::SESSION_KEY_AUTHENTICATED_AT);
        $sessionWebId = $this->session->get(self::SESSION_KEY_AUTHENTICATED_WEBID);

        if (is_string($sessionWebId)
            && $sessionWebId !== ''
            && $this->isAdmin($sessionWebId)
            && is_int($authenticatedAt)
            && (time() - $authenticatedAt) < self::SESSION_MAX_AGE
        ) {
            $adminWebId = $sessionWebId;
        }

        return $adminWebId;
    }

    final public function start(string $authenticatedWebId): void
    {
        session_regenerate_id(true);

        $this->session->set(self::SESSION_KEY_AUTHENTICATED_AT, time());
        $this->session->set(self::SESSION_KEY_AUTHENTICATED_WEBID, $this->normalizeUrl($authenticatedWebId));
    }

    final public function stop(): void
    {
        $this->session->remove(self::SESSION_KEY_AUTHENTICATED_WEBID);
        $this->session->remove(self::SESSION_KEY_AUTHENTICATED_AT);
    }
}
