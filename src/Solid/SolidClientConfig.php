<?php

namespace Meent\WebHook\Solid;

use Meent\WebHook\AbstractConfig;

class SolidClientConfig extends AbstractConfig
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public const EXPIRATION_TIME = 'expiration_time';
    final public const REDIRECT_URI = 'redirect_uri';
    final public const STATE_SIGNING_KEY = 'state_signing_key';
    final public const USE_CSRF = 'use_csrf';
    final public const USE_PKCE = 'use_pkce';

    //////////////////////////// GETTERS AND SETTERS \\\\\\\\\\\\\\\\\\\\\\\\\\\

    protected function getOptionalKeys(): array
    {
        return [
            self::USE_CSRF => '(bool) ',
            self::USE_PKCE => '(bool) ',
        ];
    }

    protected function getRequiredKeys(): array
    {
        return [
            self::EXPIRATION_TIME => '(int) ',
            self::REDIRECT_URI => '(string) ',
            self::STATE_SIGNING_KEY => '(string) ',
        ];
    }

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct($values)
    {
        $values[self::USE_CSRF] = $values[self::USE_CSRF] ?? true;
        $values[self::USE_PKCE] = $values[self::USE_PKCE] ?? true;

        $this->setValues($values);
    }
}
