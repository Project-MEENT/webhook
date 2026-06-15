<?php

namespace Meent\WebHook\Solid;

use EasyRdf\Graph;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface as OidcClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\IssuerInterface;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\RegistrationService;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder;
use Facile\OpenIDClient\Token\TokenSetInterface;
use GuzzleHttp\Client as HttpClient;
use League\Flysystem\FilesystemOperator;
use Meent\WebHook\Exception\SolidException;
use Meent\WebHook\Session;
use Meent\WebHook\UrlHashTrait;
use Psr\Http\Message\ResponseInterface;

class SolidClient
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    public const ISSUER_METADATA_FILE = 'issuer_metadata.json';

    private AuthorizationService $authorizationService;
    private HttpClient $httpClient;
    private ClientBuilder $oidcClientBuilder;
    private DpopProofFactory $dpopProofFactory;
    private FilesystemOperator $filesystem;
    private Graph $graph;
    private IdTokenVerifierBuilder $idTokenVerifierBuilder;
    private IssuerBuilder $issuerBuilder;
    private OidcClientConfig $oidcConfig;
    private RegistrationService $registration;
    private SolidClientConfig $config;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(
        SolidClientConfig $config,
        OidcClientConfig $oidcConfig,
        AuthorizationService $authorizationService,
        ClientBuilder $oidcClientBuilder,
        DpopProofFactory $dpopProofFactory,
        FilesystemOperator $filesystem,
        Graph $graph,
        HttpClient $httpClient,
        IdTokenVerifierBuilder $idTokenVerifierBuilder,
        IssuerBuilder $issuerBuilder,
        RegistrationService $registration,
    ) {
        $this->authorizationService = $authorizationService;
        $this->config = $config;
        $this->dpopProofFactory = $dpopProofFactory;
        $this->filesystem = $filesystem;
        $this->graph = $graph;
        $this->httpClient = $httpClient;
        $this->idTokenVerifierBuilder = $idTokenVerifierBuilder;
        $this->issuerBuilder = $issuerBuilder;
        $this->oidcClientBuilder = $oidcClientBuilder;
        $this->oidcConfig = $oidcConfig;
        $this->registration = $registration;

        if (! RdfNamespace::get('solid')) {
            RdfNamespace::set('solid', 'http://www.w3.org/ns/solid/terms#');
        }
        if (! RdfNamespace::get('pim')) {
            RdfNamespace::set('pim', 'http://www.w3.org/ns/pim/space#');
        }
        if (! RdfNamespace::get('space')) {
            RdfNamespace::set('space', 'http://www.w3.org/ns/pim/space#');
        }
    }

    final public function fetchStorageUrls($webIdUrl): array
    {
        if (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided WebID '$webIdUrl' is not a valid URL");
        }

        $storageUrls = [];

        $profile = $this->getWebIdProfile($webIdUrl);

        // Grab the storage root URL from the WebID Profile
        // @NOTE: There can be more than one Storage URI
        $property = null;

        if ($profile->hasProperty('pim:storage')) {
            $property = 'pim:storage';
        }

        if ($profile->hasProperty('space:storage')) {
            $property = 'space:storage';
        }

        if ($property) {
            $resources = $profile->allResources($property);
            $uris = array_map(static function (Resource $issuer) {
                return $issuer->getUri();
            }, $resources);
            $storageUrls = array_unique($uris);

            $storageUrls = array_filter($storageUrls, static function ($storageUrl) {
                return filter_var($storageUrl, FILTER_VALIDATE_URL);
            });
        }

        return $storageUrls;
    }

    final public function connectWebId($webIdUrl, Session $session): string
    {
        $redirectAuthorizationUri = '';

        if (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided WebID '$webIdUrl' is not a valid URL");
        }

        $issuer = $this->createIssuerFromWebIdUrl($webIdUrl);

        $oidcClient = $this->createOidcClientFromIssuer($issuer);

        $offlineModeHandled = false;

        $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

        // On first connect, there is no grant, so skip directly to interactive auth flow.
        if ($this->oidcConfig->useOffline() === true && ! $this->filesystem->fileExists($offlineGrantFile)) {
            try {
                $accessToken = $this->handleOfflineAccess($oidcClient, $issuer, $webIdUrl);

                if (is_string($accessToken) && $accessToken !== '') {
                    $offlineModeHandled = true;
                }
            } catch (SolidException $e) {
                // If offline refresh fails, continue with interactive authorization flow.
                $offlineModeHandled = false;
            }
        }

        if ($offlineModeHandled === false) {
            $redirectAuthorizationUri = $this->getRedirectAuthorizationUri($oidcClient, $issuer, $webIdUrl, $session);
        }

        return $redirectAuthorizationUri;
    }

    final public function handleRedirect($queryParams, Session $session, string $redirectUrl): string
    {
        // Step 3. Exchange code for access token

        // At this point the user is redirected back to the application from the authorization server.
        // The authorization server will redirect the user back to the application with a code or error parameter.

        if (isset($queryParams['error'])) {
            // The error parameter is set when something has gone wrong on the OP side.
            throw SolidException::create('Provider returned an error ' . $queryParams['error']);
        }

        /*/  rfc7636 - PKCE - Section 4.4.  Server Returns the Code /*/
        // The authorization response must include a non-empty authorization code.
        if (! isset($queryParams['code'])) {
            throw SolidException::create('Provider did not return an authorization code');
        }

        if (! isset($queryParams['state'])) {
            throw SolidException::create('Provider did not return a "state" parameter ');
        }

        $authorizationCode = $queryParams['code'];
        $state = $queryParams['state'];

        // In callback mode, issuer is recovered exclusively from signed state.

        // CSRF: validate state matches what we sent (OIDC Core Section 3.1.2.7).
        if ($this->config->get(SolidClientConfig::USE_CSRF) === true) {
            $expectedState = $session->get('oauth_state');

            if ($state !== $expectedState) {
                $message = vsprintf(
                    'CSRF Check Failed. Received state "%s" does not match stored state "%s" from session', [
                    'returned_state' => $state,
                    'expected_state' => $expectedState,
                ]);

                throw SolidException::create($message);
            }

            $session->remove('oauth_state');
        }

        // In callback mode, issuer is recovered exclusively from signed state.
        $issuerUrl = $this->getIssuerUrlFromState($state);
        // At this point, post redirect, the oidcClient SHOULD already be registered
        // @CHECKME: Shouldn't we "somehow" check the issuer in the state against the issuer we came from?
        //           (how do we know which issuer we are redirected back from?)
        $issuer = $this->createIssuerFromUrl($issuerUrl);

        $oidcClient = $this->createOidcClientFromIssuer($issuer);

        // -------------------------------------------------------------------------
        /*/ RFC9449 - DPoP - Section 5. DPoP Access Token Request /*/
        // The token request must include a DPoP header with a valid proof JWT (see RFC9449 Section 4.2 for proof syntax).

        $codeVerifier = null;
        if ($this->config->get(SolidClientConfig::USE_PKCE) === true) {
            /*/ rfc7636 - PKCE - Section 4.5.  Client Sends the Authorization Code and the Code Verifier to the Token Endpoint /*/
            $codeVerifier = $session->get('pkce_code_verifier');
            $hasValidCodeVerifier = is_string($codeVerifier) && $codeVerifier !== '';
            if (! $hasValidCodeVerifier) {
                throw SolidException::create('Client has no valid PKCE code_verifier for this authorization response ' . $codeVerifier);
            }
        }

        try {
            $tokenSet = $this->getTokenSet(
                $oidcClient,
                $authorizationCode,
                $redirectUrl,
                $codeVerifier
            );
            $session->remove('pkce_code_verifier');
        } catch (\Throwable $e) {
            $session->remove('pkce_code_verifier');
            throw $e;
        }

        $idTokenClaims = $this->getTokenClaims($oidcClient, $tokenSet);

        // Extract webid claim (Solid-OIDC Section 7, Section 8.1).
        $webIdUrl = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;

        if (! is_string($webIdUrl) || ! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create('Provider did not return a valid WebID URL claim in id_token');
        }

        // @CHECKME: Do we need to check the provided WebID against (what?)

        // -------------------------------------------------------------------------
        // Persist tokens for offline operation — written directly to the grant file,
        // Store refresh_token server-side; never expose to browser (OIDC Core Section 12).
        if ($this->oidcConfig->useOffline() === true) {
            $refreshToken = $tokenSet->getRefreshToken();
            if (! is_string($refreshToken) || $refreshToken === '') {
                throw SolidException::create(
                    'Provider did not return a refresh_token. Ensure offline_access is requested and consent is granted.'
                );
            }

            $expiresIn = $tokenSet->getExpiresIn();
            // @CHECKME: Not sure which should come first, the expiry form the token or from the id_token
            if ($expiresIn > 0) {
                $tokenExpiry = time() + $expiresIn;
            } elseif (isset($idTokenClaims['exp']) && is_numeric($idTokenClaims['exp'])) {
                $tokenExpiry = (int) $idTokenClaims['exp'];
            } else {
                $tokenExpiry = time() + 3600;
            }

            $grant = array_filter([
                'solid_access_token' => $tokenSet->getAccessToken(),
                'solid_refresh_token' => $refreshToken,
                'solid_token_expiry' => $tokenExpiry,
                'solid_webid' => $webIdUrl,
            ], static function ($value) {
                return $value !== null && $value !== '';
            });

            $this->saveOfflineGrant($issuer, $webIdUrl, $grant);
        }

        return $webIdUrl;
    }

    final public function fetchResource($webIdUrl, $resourceUrl): ResponseInterface
    {
        if (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided WebID '$webIdUrl' is not a valid URL");
        }

        if (! filter_var($resourceUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided resource URL '$resourceUrl' is not a valid URL");
        }

        $resourceRequest = $this->createResourceRequest('GET', $webIdUrl, $resourceUrl);

        try {
            return $this->httpClient->send($resourceRequest);
        } catch (\Psr\Http\Client\RequestExceptionInterface $e) {
            $message = $e->getResponse()->getBody()->getContents();
            throw SolidException::create("Could not fetch resource '$resourceUrl': $message", $e);
        }
    }

    final public function storeResource($webIdUrl, $resourceUrl, $resource = null, $contentType = null): ResponseInterface
    {
        if (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided WebID '$webIdUrl' is not a valid URL");
        }

        if (! filter_var($resourceUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided resource URL '$resourceUrl' is not a valid URL");
        }

        $putRequest = $this->createResourceRequest('PUT', $webIdUrl, $resourceUrl, $resource, $contentType);

        try {
            return $this->httpClient->send($putRequest);
        } catch (\Psr\Http\Client\RequestExceptionInterface $e) {
            $message = $e->getResponse()->getBody()->getContents();
            throw SolidException::create("Could not store resource '$resourceUrl': $message", $e);
        }
    }

    final public function isWebIdConnected($webIdUrl): bool
    {
        $issuer = $this->createIssuerFromWebIdUrl($webIdUrl);

        $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

        return $this->filesystem->fileExists($offlineGrantFile);
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function createIssuerFromUrl($issuerUrl): IssuerInterface
    {
        $issuerUrl = rtrim($issuerUrl, '/');

        $openidDiscoveryUrl = $issuerUrl . '/.well-known/openid-configuration';

        try {
            $issuer = $this->issuerBuilder->build($openidDiscoveryUrl);
        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
            throw SolidException::create('Failed to discover issuer metadata', $e);
        }

        return $issuer;
    }

    private function createIssuerFromWebIdUrl($webIdUrl): IssuerInterface
    {
        $profile = $this->getWebIdProfile($webIdUrl);

        $issuers = [];
        if ($profile->hasProperty('solid:oidcIssuer')) {
            $resources = $profile->allResources('solid:oidcIssuer');
            $uris = array_map(static function (Resource $issuer) {
                return $issuer->getUri();
            }, $resources);
            $issuers = array_unique($uris);
        }

        $issuers = array_values(array_filter($issuers, static function ($issuerUrl) {
            return is_string($issuerUrl) && filter_var($issuerUrl, FILTER_VALIDATE_URL);
        }));

        if ($issuers === []) {
            throw SolidException::create(
                "Could not resolve an OIDC issuer from '$webIdUrl'. Please provide a Solid WebID URL that advertises solid:oidcIssuer."
            );
        }

        // @FIXME: If there is more than one issuer, the user should be able to choose which on to use
        $issuerUrl = reset($issuers);

        if (is_string($issuerUrl) && filter_var($issuerUrl, FILTER_VALIDATE_URL)) {
            return $this->createIssuerFromUrl($issuerUrl);
        } else {
            throw SolidException::create("Resolved issuer URL '$issuerUrl' is not a valid URL");
        }
    }

    private function createOidcClientFromIssuer(IssuerInterface $issuer): OidcClientInterface
    {
        $registeredClaims = $this->getClaims($issuer);

        // @KLUDGE: The OIDC Client library send the first redirect_uri, ignoring
        //          regardless of which redirect_uri has been set in the grant.
        //          So we need to make sure the desired URL is the first in the array.
        $redirectUri = $this->config->get(SolidClientConfig::REDIRECT_URI);

        $registeredClaims['redirect_uris'] = array_filter($registeredClaims['redirect_uris'] ?? [], static function ($uri) use ($redirectUri) {
            return $uri !== $redirectUri;
        });

        array_unshift($registeredClaims['redirect_uris'], $redirectUri);

        $clientMetadata = ClientMetadata::fromArray($registeredClaims);

        return $this->oidcClientBuilder
            ->setClientMetadata($clientMetadata)
            ->setIssuer($issuer)
            ->build();
    }

    private function getClaims(IssuerInterface $issuer): array
    {
        $localClientId = $this->oidcConfig->get(OidcClientConfig::CLIENT_ID);
        $issuerMetadata = $issuer->getMetadata()->toArray();
        // @KLUDGE: Can not use `$issuer->getMetadata()->getTokenEndpointAuthMethodsSupported();`
        //          as the Solid Server does not return an array but a single value
        $supportedAuthMethods = $issuerMetadata['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        if (! is_array($supportedAuthMethods)) {
            $supportedAuthMethods = [$supportedAuthMethods];
        }

        if (is_string($localClientId)
            && filter_var($localClientId, FILTER_VALIDATE_URL)
            && in_array('none', $supportedAuthMethods, true)
        ) {
            // Client ID Document mode: use the local config directly, no registration.
            return $this->oidcConfig->toArray();
        }

        // Check if our oidcClient is already registered, if not, register it and store the metadata for future use
        $registeredClaims = $this->getRegisteredClaims($issuer);

        if (empty($registeredClaims) || ! isset($registeredClaims['client_id'])) {
            $registeredClaims = $this->registerClaims($issuer);

            if (! isset($registeredClaims['client_id'])) {
                throw SolidException::create(
                    'Dynamic registration response did not include a client_id. Cannot build OIDC client for issuer.'
                );
            }

            $issuerMetaDataFilePath = $this->getIssuerMetaDataFilePath($issuer);
            $fileContents = Utility::jsonEncode($registeredClaims);

            $this->filesystem->write($issuerMetaDataFilePath, $fileContents);
        }

        return $registeredClaims;
    }

    private function getIssuerMetaDataFilePath(IssuerInterface $issuer)
    {
        $issuerUrl = $issuer->getMetadata()->getIssuer();
        $issuerHash = $this->hashUrl($issuerUrl, 'sha256');

        return $issuerHash . '/' . self::ISSUER_METADATA_FILE;
    }

    private function getGrantFilePath(IssuerInterface $issuer, $webIdUrl)
    {
        $issuerUrl = $issuer->getMetadata()->getIssuer();
        $issuerHash = $this->hashUrl($issuerUrl, 'sha256');
        $webIdHash = $this->hashUrl($webIdUrl, 'sha1');

        return $issuerHash . '/' . $webIdHash . '.json';
    }

    private function getOfflineGrant(IssuerInterface $issuer, $webIdUrl)
    {
        $offlineGrant = [];

        $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

        if ($this->filesystem->fileExists($offlineGrantFile)) {
            $contents = $this->filesystem->read($offlineGrantFile);
            $storedGrant = Utility::jsonDecode($contents);

            if (is_array($storedGrant)) {
                $offlineGrant = $storedGrant;
            }
        }

        return $offlineGrant;
    }

    private function getRegisteredClaims(IssuerInterface $issuer): array
    {
        $registeredClaims = [];

        $issuerMetadataFile = $this->getIssuerMetaDataFilePath($issuer);

        $issuerMetadataFileExists = $this->filesystem->fileExists($issuerMetadataFile);

        if ($issuerMetadataFileExists) {
            // Client already registered, reading metadata from file
            $fileContents = $this->filesystem->read($issuerMetadataFile);
            $decoded = Utility::jsonDecode($fileContents);
            // json_decode can return null for a literal JSON null value; only accept arrays.
            if (is_array($decoded)) {
                $registeredClaims = $decoded;
            }
        }

        return $registeredClaims;
    }

    private function getTokenClaims(OidcClientInterface $oidcClient, TokenSetInterface $tokenSet): array
    {
        $idToken = $tokenSet->getIdToken(); // Unencrypted id_token, if returned

        if (is_string($idToken) && $idToken !== '') {
            try {
                $idTokenClaims = $this->getVerifiedClaims($oidcClient, $tokenSet);
            } catch (\Facile\JoseVerifier\Exception\ExceptionInterface $e) {
                // Fallback: decode the JWT claims without trusting the signature (i.e. no sig check)
                $idTokenClaims = [
                    'error' => $e->getMessage(),
                ];

                $idTokenClaims = array_merge($idTokenClaims, Utility::decodeUnsafeJwt($idToken));
            }
        } else {
            throw SolidException::create('User is not authenticated, No id_token returned');
        }

        return $idTokenClaims;
    }

    private function getVerifiedClaims(OidcClientInterface $oidcClient, TokenSetInterface $tokenSet)
    {
        // Validate ID Token and bind identity to Solid rules.
        // Perform OIDC ID Token validation (iss, aud, exp, signature/JWKs) (OIDC Core Section 3.1.3.7).
        $accessToken = $tokenSet->getAccessToken(); // Access token, if returned
        $idToken = $tokenSet->getIdToken(); // Unencrypted id_token, if returned

        $verifier = $this->idTokenVerifierBuilder->build($oidcClient);

        if (is_string($accessToken) && $accessToken !== '') {
            $verifier = $verifier->withAccessToken($accessToken);
        }

        return $verifier->verify($idToken);
    }

    private function getRedirectAuthorizationUri(
        OidcClientInterface $oidcClient,
        IssuerInterface $issuer,
        $webIdUrl,
        $session,
    ) {
        $issuerUrl = $issuer->getMetadata()->getIssuer();

        // At this point there is a registered oidcClient, but it is not authenticated yet.
        // Step 2. Check if user is authenticated
        $authorizationRequestParams = [];

        // Add Issuer URL as "state" value, so it can be retrieved after redirect
        $header = Utility::base64UrlJsonEncode(['alg' => 'HS256', 'typ' => 'JWT']);

        $payload = Utility::base64UrlJsonEncode([
            'exp' => time() + $this->config->get(SolidClientConfig::EXPIRATION_TIME),
            'issr' => $issuerUrl,
        ]);

        $signature = Utility::createSignature($header . '.' . $payload, $this->config->get(SolidClientConfig::STATE_SIGNING_KEY));
        $state = vsprintf("%s.%s.%s", [
            $header,
            $payload,
            $signature
        ]);

        if ($this->config->get(SolidClientConfig::USE_CSRF) === true) {
            $session->set('oauth_state', $state);
        }

        $authorizationRequestParams['state'] = $state;

        if ($this->config->get(SolidClientConfig::USE_PKCE) === true) {
            /*/ rfc7636 - PKCE - Section 4.1.  Client Creates a Code Verifier /*/
            // 32 random bytes base64url-encoded → 43-char verifier in the allowed unreserved set.
            $codeVerifier = Utility::base64UrlEncode(random_bytes(32));
            $session->set('pkce_code_verifier', $codeVerifier);

            /*/ rfc7636 - PKCE - Section 4.2.  Client Creates the Code Challenge /*/
            $codeVerifierHash = hash('sha256', $codeVerifier, true);
            $codeChallenge = Utility::base64UrlEncode($codeVerifierHash);

            /*/ rfc7636 - PKCE - Section 4.3.  Client Sends the Code Challenge with the Authorization Request /*/
            $authorizationRequestParams['code_challenge'] = $codeChallenge;
            $authorizationRequestParams['code_challenge_method'] = 'S256'; // RFC7636: clients capable of S256 MUST use S256.
        }

        if ($this->oidcConfig->useOffline() === true) {
            // offline_access requires explicit consent so the OP actually issues a refresh token (OIDC Core Section 11).
            $grant = $this->getOfflineGrant($issuer, $webIdUrl);
            if (empty($grant['solid_refresh_token'])) {
                // Only Request when a reusable refresh grant is not available yet.
                $authorizationRequestParams['prompt'] = 'consent';
            }
            $authorizationRequestParams['scope'] = 'openid webid offline_access';
        }

        return $this->authorizationService->getAuthorizationUri($oidcClient, $authorizationRequestParams);
    }

    private function getIssuerUrlFromState($state)
    {
        $parts = explode('.', $state);

        if (count($parts) !== 3) {
            $error = 'State must be a compact JWT';
        } else {
            $header = Utility::base64UrlJsonDecode($parts[0]);
            $payload = Utility::base64UrlJsonDecode($parts[1]);

            $expectedSignature = Utility::createSignature($parts[0] . '.' . $parts[1], $this->config->get(SolidClientConfig::STATE_SIGNING_KEY));

            if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
                $error = 'State JWT must use HS256';
            } elseif (! is_array($payload) || ! isset($payload['issr'], $payload['exp'])) {
                $error = 'State JWT is missing required claims';
            } elseif (! hash_equals($expectedSignature, $parts[2])) {
                $error = 'State JWT signature is invalid';
            } elseif (! is_numeric($payload['exp']) || (int) $payload['exp'] < time()) {
                $error = 'State JWT is expired';
            } elseif (! is_string($payload['issr']) || ! filter_var($payload['issr'], FILTER_VALIDATE_URL)) {
                $error = 'State JWT issuer claim is invalid';
            }
        }

        if (isset($error) || ! isset($payload)) {
            $message = 'Invalid or expired state' . ($error ?? 'Could not parse JWT Payload');
            throw SolidException::create($message);
        }

        $issuerUrl = rtrim($payload['issr'], '/');

        if (filter_var($issuerUrl, FILTER_VALIDATE_URL)) {
            return $issuerUrl;
        } else {
            throw SolidException::create("Resolved issuer '$issuerUrl' is not a valid URL");
        }
    }

    private function getTokenSet(
        OidcClientInterface $oidcClient,
        $authorizationCode,
        $redirectUrl,
        ?string $codeVerifier = null,
    ): TokenSetInterface {
        $params = [
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUrl,
        ];

        if ($this->config->get(SolidClientConfig::USE_PKCE) === true && is_string($codeVerifier) && $codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }

        try {
            // Use explicit grant() so this example fully controls what gets sent to the token endpoint.
            $tokenSet = $this->authorizationService->grant($oidcClient, $params);
        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
            // InvalidArgumentException(Invalid metadata content)
            if ($e->getPrevious()) {
                // Response could not be parsed as JSON
                $trace = $e->getPrevious()->getTrace();
                $responseBody = $trace[0]['args'][0] ?? 'Could not retrieve response body';
            } else {
                // Parse was successful, but the response is not an array
                $responseBody = null;
            }

            /*/ rfc7636 - PKCE - Section 4.6.  Server Verifies code_verifier before Returning the Tokens /*/
            // On success, the token endpoint returns tokens; on PKCE mismatch, it returns invalid_grant.
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                $message = 'Token endpoint rejected code_verifier (invalid_grant — PKCE mismatch) ' . $responseBody;
            } else {
                $message = 'Failed to exchange authorization code for access token';
            }

            throw SolidException::create($message, $e);
        }

        return $tokenSet;
    }

    private function getWebIdProfile($webIdUrl): Resource
    {
        $graph = clone $this->graph;

        // Grab Issuer from WebId Profile
        $webIdResponse = $this->httpClient->get($webIdUrl);
        $webIDContent = $webIdResponse->getBody()->getContents();
        $format = explode(';', $webIdResponse->getHeaderLine('Content-Type'))[0] ?? null;
        $graph->parse($webIDContent, $format, $webIdUrl);

        /* @KLUDGE: The provided WebID might be different from  what is present in the WebID Profile.
         * If that is the case, there are a few options... The most prominent of which are that the Pod Provider
         * expect the URL to have either `#me` at the end of the URL. So if th URL does no, we add it
         */
        if (! str_contains($webIdUrl, '#')) {
            $webIdUrl .= '#me';
        }

        $profile = $graph->resource($webIdUrl);

        $primaryTopic = $profile->primaryTopic();

        if ($primaryTopic) {
            $profile = $primaryTopic;
        }

        return $profile;
    }

    private function handleOfflineAccess(OidcClientInterface $oidcClient, IssuerInterface $issuer, $webIdUrl)
    {
        $accessToken = null;

        $grant = $this->getOfflineGrant($issuer, $webIdUrl);

        $storedAccessToken = $grant['solid_access_token'] ?? null;
        $storedExpiry = $grant['solid_token_expiry'] ?? null;
        $hasValidAccessToken = is_string($storedAccessToken)
            && $storedAccessToken !== ''
            && is_numeric($storedExpiry)
            && (int) $storedExpiry > time() + 60;

        $storedRefreshToken = $grant['solid_refresh_token'] ?? null;
        $hasRefreshToken = is_string($storedRefreshToken) && $storedRefreshToken !== '';

        // Offline mode: reuse previously granted consent

        if ($hasValidAccessToken) {
            // Reusing stored access token until it expires
            $accessToken = $storedAccessToken;
        } elseif ($hasRefreshToken) {
            // Stored access token has expired (or is missing); refresh with the persisted refresh token.
            try {
                $tokenSet = $this->authorizationService->refresh($oidcClient, $storedRefreshToken);
                // Refresh token exchange succeeded; offline consent is being reused.
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                // @KLUDGE: Stored offline grant could not be refreshed (see $e->getMessage()) we can not fall back to interactive login
                //          Remove the stored grants before error out.
                $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

                if ($this->filesystem->fileExists($offlineGrantFile)) {
                    $this->filesystem->delete($offlineGrantFile);
                }

                throw SolidException::create('Stored offline grant could not be refreshed. Reconnect WebID to renew consent.', $e);
            }

            $idToken = $tokenSet->getIdToken();
            if (is_string($idToken) && $idToken !== '') {
                try {
                    $idTokenClaims = $this->getVerifiedClaims($oidcClient, $tokenSet);
                } catch (\Facile\JoseVerifier\Exception\ExceptionInterface $e) {
                    // @KLUDGE: refreshed id_token verification failed: $e->getMessage(); continuing with unverified claims',
                    // @FIXME: Unsafe JWTs should only be enabled for specific servers, not all
                    $idTokenClaims = Utility::decodeUnsafeJwt($idToken);
                }

                $refreshedWebId = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;
                if (is_string($refreshedWebId) && $refreshedWebId !== '') {
                    $grant['solid_webid'] = $refreshedWebId;
                }
            }

            $accessToken = $tokenSet->getAccessToken();
            $expiresIn = $tokenSet->getExpiresIn();

            $grant['solid_access_token'] = $accessToken;
            $grant['solid_refresh_token'] = $tokenSet->getRefreshToken() ?: $storedRefreshToken;
            $grant['solid_token_expiry'] = $expiresIn > 0 ? time() + $expiresIn : time() + 3600;

            $this->saveOfflineGrant($issuer, $webIdUrl, $grant);
        } else {
            throw SolidException::create('No persisted offline grant with refresh_token is available. Reconnect WebID once to grant offline access.');
        }

        return $accessToken;
    }

    private function registerClaims(IssuerInterface $issuer)
    {
        // Client not registered, registering oidcClient...
        $clientConfig = $this->oidcConfig->toArray();
        // Register oidcClient with the issuer (dynamic registration; cached per-issuer hash).
        try {
            return $this->registration->register($issuer, $clientConfig, $this->oidcConfig->get(OidcClientConfig::INITIAL_ACCESS_TOKEN));
        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
            // InvalidArgumentException(Issuer does not support dynamic oidcClient registration)
            // RuntimeException(Unable to encode oidcClient metadata | Unable to register OpenID oidcClient | Registration response did not return a client_id field)
            throw SolidException::create('Dynamic registration failed', $e);
        }
    }

    private function saveOfflineGrant(IssuerInterface $issuer, $webIdUrl, $grant)
    {
        $grant['saved_at'] = time();
        $encodedGrant = Utility::jsonEncode($grant);

        $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);
        $this->filesystem->write($offlineGrantFile, $encodedGrant);
    }

    private function createResourceRequest($method, $webIdUrl, $resourceUrl, $resource = null, $contentType = null)
    {
        $issuer = $this->createIssuerFromWebIdUrl($webIdUrl);
        $oidcClient = $this->createOidcClientFromIssuer($issuer);

        // Load the persisted grant from disk and resolve a valid access token from it.
        $accessToken = $this->handleOfflineAccess($oidcClient, $issuer, $webIdUrl);

        if (! is_string($accessToken) || $accessToken === '') {
            throw SolidException::create('No valid access token available to fetch resource. Reconnect WebID to obtain consent/tokens.');
        }

        // Build DPoP proof for this specific request target and method.
        // Solid Protocol Section 5.3: servers MUST reject PUT, POST and PATCH without Content-Type with 400.
        $headers = [
            'Authorization' => 'DPoP ' . $accessToken,
        ];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $resourceRequest = new \GuzzleHttp\Psr7\Request($method, $resourceUrl, $headers, $resource);
        $dpopProof = $this->dpopProofFactory->createProofForRequest($resourceRequest);

        return $resourceRequest->withHeader('DPoP', $dpopProof);
    }
}
