<?php

namespace Meent\WebHook\Solid;

use Meent\WebHook\AbstractConfig;

class OidcClientConfig extends AbstractConfig
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public const INITIAL_ACCESS_TOKEN = 'client_initial_access_token';

    final public const CONTACTS = 'contacts';
    final public const POLICY_URI = 'policy_uri';
    final public const SOFTWARE_ID = 'software_id';
    final public const SOFTWARE_VERSION = 'software_version';
    final public const CLIENT_ID = 'client_id';
    final public const CLIENT_NAME = 'client_name';
    final public const CLIENT_URI = 'client_uri';
    final public const GRANT_TYPES = 'grant_types';
    final public const LOGO_URI = 'logo_uri';
    final public const REDIRECT_URIS = 'redirect_uris';
    final public const SCOPE = 'scope';
    final public const TOS_URI = 'tos_uri';

    final public const METADATA_FILE = self::CLIENT_ID . '.json';

    final public const REQUIRE_NEW_AUTHENTICATION = false;
    final public const REUSE_STORED_AUTHENTICATION = true;

    private ?string $initialAccessToken;
    private bool $useOffline;

    //////////////////////////// GETTERS AND SETTERS \\\\\\\\\\\\\\\\\\\\\\\\\\\

    final protected function getOptionalKeys(): array
    {
        // RFC-7591 OAuth 2.0 Dynamic Client Registration Section 2.Client Metadata
        // https://datatracker.ietf.org/doc/html/rfc7591
        return [
            self::INITIAL_ACCESS_TOKEN => 'Initial Access Token for dynamic client registration (optional, only needed if the OP requires it)',

            self::GRANT_TYPES => 'Space-delimited list of OAuth 2.0 grant type strings that the client intends to use.',
            // By default, this is "authorization_code" (see RFC-7591 OAuth 2.0, Section 4.1.)
            // OpenID Connect Core 1.0 Section 5.4. Requesting Claims using Scope Values
            self::SCOPE => 'List of Scope (see RFC6749 OAuth 2.0 Section 3.3) that specify which access is requested for Access Tokens.',

            // Solid-OIDC / RFC-7591 / draft-ietf-oauth-client-id-metadata-document
            self::CLIENT_ID => 'URI for the client metadata document when using self-issued Solid client identifiers.',
            self::CLIENT_URI => 'URL of a web page with human-readable information about the client.',
            self::CONTACTS => 'Array of strings representing ways to contact people responsible for this client (typically email addresses).',
            self::LOGO_URI => 'URL for an image representing the client that should be shown to the end-user during approval.',
            self::POLICY_URI => 'URL for a human-readable privacy policy document which describes how the client organization collects, uses, retains, and discloses personal data.',
            self::TOS_URI => 'URL for a web page with human-readable terms of service (ToS) describing the contractual relationship the end-user enters into when authorizing the client.',
            self::SOFTWARE_ID =>  'A unique identifier (for instance a Universally Unique Identifier (UUID)) assigned by the client developer or software publisher used by registration endpoints to identify the client software to be dynamically registered.',
             // Unlike client_id, which is issued by the authorization server and SHOULD vary between instances, the software_id SHOULD remain the same for all instances of the client software. The software_id SHOULD remain the same across multiple updates or versions of the same piece of software . The value of this field is not intended for human consumption',
            self::SOFTWARE_VERSION => 'A version identifier for the client software.',
            // The value of the "software_version" SHOULD change on any update to the client software identified by the same "software_id".
        ];
    }

    final protected function getRequiredKeys(): array
    {
        return [
            self::CLIENT_NAME => '',
            // RFC6749 OAuth 2.0 Section 2.2. Client Registration
            self::REDIRECT_URIS => 'Array of redirection URIs for use in redirect-based flow (like the authorization code flow)',
        ];
    }

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(
        $values,
        ?bool $useOffline = self::REUSE_STORED_AUTHENTICATION,
    ) {
        $this->initialAccessToken = $values[self::INITIAL_ACCESS_TOKEN] ?? null;
        unset($values[self::INITIAL_ACCESS_TOKEN]);

        $this->useOffline = $useOffline;

        $values[self::GRANT_TYPES] = $values[self::GRANT_TYPES] ?? 'authorization_code';
        $values[self::SCOPE] = $values[self::SCOPE] ?? 'openid webid';

        if ($this->useOffline === true) {
            // grant_types must include refresh_token to receive one (OIDC Core Section 11 / offline_access)
            if (! str_contains($values[self::GRANT_TYPES], 'refresh_token')) {
                $values[self::GRANT_TYPES] .= ' refresh_token';
            }

            if (! str_contains($values[self::SCOPE], 'offline_access')) {
                $values[self::SCOPE] .= ' offline_access';
            }
        }

        $this->setValues($values);
    }

    final public function initialAccessToken(): string
    {
        return $this->initialAccessToken;
    }

    final public function useOffline(): bool
    {
        return $this->useOffline;
    }
}
