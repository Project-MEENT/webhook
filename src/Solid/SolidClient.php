<?php
/**
 * @noinspection PhpRedundantCatchClauseInspection
 * @noinspection DuplicatedCode
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpMultipleClassDeclarationsInspection
 * @noinspection PhpPossiblePolymorphicInvocationInspection
 * @noinspection ReturnTypeCanBeDeclaredInspection
 */

namespace Meent\WebHook\Solid;

use EasyRdf\Graph;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\AuthMethod\AuthMethodFactory;
use Facile\OpenIDClient\AuthMethod\ClientSecretBasic;
use Facile\OpenIDClient\AuthMethod\ClientSecretJwt;
use Facile\OpenIDClient\AuthMethod\ClientSecretPost;
use Facile\OpenIDClient\AuthMethod\None;
use Facile\OpenIDClient\AuthMethod\PrivateKeyJwt;
use Facile\OpenIDClient\AuthMethod\SelfSignedTLSClientAuth;
use Facile\OpenIDClient\AuthMethod\TLSClientAuth;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\RegistrationServiceBuilder;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder;
use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Meent\WebHook\Exception;
use Meent\WebHook\Exception\SolidException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

class SolidClient
{
    /**
     * @throws \Random\RandomException
     * @throws \League\Flysystem\FilesystemException
     * @throws \JsonException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \EasyRdf\Exception
     * @throws SolidException
     */
    final public function handleRequest(RequestInterface $request, ResponseInterface $response)
    {
        // =============================================================================
        // Config
        // -----------------------------------------------------------------------------
        $storageLocation = __DIR__ . '/../../build/storage/';

        // -----------------------------------------------------------------------------
        $clientConfigFile = 'client_id.json';

        $clientId = Utility::base64UrlEncode(random_bytes(32)); // $clientServer . '/' . $clientConfigFile;
        $clientName = 'MEENT Solid P1 Dongle Webhook';
        $clientRedirectUri = $request->getUri()->withFragment('')->withQuery('')->__toString();
        $clientRedirectUris = [
            $clientRedirectUri
        ];
        $clientSecret = 'my-client-secret';

        // -----------------------------------------------------------------------------
        $stateSigningKey = $clientSecret; // @TODO: Use separate secret (i.e. private key) for signing
        $stateTtlSeconds = 300;

        // -----------------------------------------------------------------------------
        // For certain issuers (like https://solidcommunity.net) PKCE is required, even for  server-to-server calls
        // @FIXME: Decide to either ALWAYS add PKCE, or only for know offeders and/or use PKCE as fallback on failing call.
        $usePkce = true;

        // -----------------------------------------------------------------------------
        $useCsrfCheck = true;

        // -----------------------------------------------------------------------------
        $useOfflineAccess = true;
        // =============================================================================


        // =============================================================================
        // Create HTTP Client
        // -----------------------------------------------------------------------------
        $httpClientConfig = [
            // Allow self-signed certificates for local development
            // 'verify' => false,
            // 'verify_host' => false,
            // 'verify_peer' => false,
        ];
        $httpClient = new Client($httpClientConfig);

        // -----------------------------------------------------------------------------
        // Create FileSystem
        // -----------------------------------------------------------------------------
        if ($storageLocation) {
            $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter($storageLocation);
        } else {
            $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        }

        $filesystem = new \League\Flysystem\Filesystem($adapter);

        // -----------------------------------------------------------------------------
        // Create Cache Store
        // -----------------------------------------------------------------------------
        if ($filesystem && class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\Flysystem')) {
            $store = new \MatthiasMullie\Scrapbook\Adapters\Flysystem($filesystem);
        } elseif(class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\MemoryStore')) {
            $store = new \MatthiasMullie\Scrapbook\Adapters\MemoryStore();
        } else {
            $store = null;
        }

        if ($store) {
            // simple-cache implementation
            $cache = new \MatthiasMullie\Scrapbook\Psr16\SimpleCache($store);
        }


        // -----------------------------------------------------------------------------
        /* Basic Usage */
        $metadataProviderBuilder = new MetadataProviderBuilder();
        $metadataProviderBuilder->setHttpClient($httpClient);
        $issuerBuilder = new IssuerBuilder();
        $issuerBuilder = $issuerBuilder->setMetadataProviderBuilder($metadataProviderBuilder);

        // Step 1. Create client for issuer
        $clientConfigFileExists = $filesystem->fileExists($clientConfigFile);
        if (! $clientConfigFileExists) {
            // Client metadata file not found, creating...
            $data = [
                'client_id' => $clientId,
                'client_name' => $clientName,
                'client_secret' => $clientSecret,
                'redirect_uris' => $clientRedirectUris,
                'token_endpoint_auth_method' => 'client_secret_basic', // the auth method for the token endpoint
            ];

            if ($useOfflineAccess === true) {
                // grant_types must include refresh_token to receive one (OIDC Core Section 11 / offline_access)
                $data['grant_types'] = ['authorization_code', 'refresh_token'];
                $data['scope'] = 'openid webid offline_access';
            }

            $filesystem->write($clientConfigFile, json_encode($data,
                JSON_PRETTY_PRINT
                | JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES // Don't escape slashes `/`.
            ));
        }

        // Reading client metadata from file
        $clientConfig = json_decode($filesystem->read($clientConfigFile), true, 512, JSON_THROW_ON_ERROR);
        // =============================================================================


        // =============================================================================
        // Handle requests
        // -----------------------------------------------------------------------------
        // Get issuer from HTTP param
        $issuerUrl = $request->getParsedBody()['issuer'] ?? $request->getQueryParams()['issuer'] ?? '';

        $webIdUrl = $request->getParsedBody()['webid'] ?? $request->getQueryParams()['webid'] ?? '';

        // Detect whether this request is the callback from the authorization server.
        // When the OP redirects back it always includes `code` (success) or `error` (failure).
        $isRedirect = isset($request->getQueryParams()['code']) || isset($request->getQueryParams()['error']);

        // -----------------------------------------------------------------------------
        /* <Caching> */
        if (isset($cache) && $cache instanceof CacheInterface) {
            $metadataProviderBuilder->setCache($cache)->setCacheTtl(86400 * 30); // Cache metadata for 30 days

            $jwksProviderBuilder = new JwksProviderBuilder();
            $jwksProviderBuilder
                // Do not cache JWKS in this PoC:
                // the local dev OP can rotate keys between runs, which causes false
                // "Invalid token signature" failures when stale JWK sets are reused.
                // ->withCache($cache)->withCacheTtl(86400)// Cache JWKS for 1 day
                ->build();

            $issuerBuilder->setJwksProviderBuilder($jwksProviderBuilder);
        }

        if ($issuerUrl !== '' && filter_var($issuerUrl, FILTER_VALIDATE_URL)) {
            $issuerUrl = rtrim($issuerUrl, '/');
            $openidDiscoveryUrl = $issuerUrl . '/.well-known/openid-configuration';
            try {
                $issuer = $issuerBuilder->build($openidDiscoveryUrl);
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                throw SolidException::create('Failed to discover issuer metadata', $e);
            }

            $issuerConfig = $issuer->getMetadata()->toArray();
        }

        $showOutput = ! empty($webIdUrl) || ! empty($issuerUrl) || ! empty($issuerConfig) || $isRedirect;

        // @FIXME: There is a scenario where, if the page is opened with a __WebID__, and there has not yet been a consent call, we will get a 403!

        // RFC9449 - DPoP - Section 5.  DPoP Access Token Request
        // Initialise DPoP key pair (stored in session so the same key is reused across the redirect round-trip).
        if (
            ! Session::current()->has(DpopProofFactory::SESSION_KEY)
            || ! is_array(Session::current()->get(DpopProofFactory::SESSION_KEY))
            || ! isset(Session::current()->get(DpopProofFactory::SESSION_KEY)['kty'])) {
            $jwk = JWKFactory::createECKey('P-256');
            Session::current()->set(DpopProofFactory::SESSION_KEY, $jwk->all());
        } else {
            $jwk = new JWK(Session::current()->get(DpopProofFactory::SESSION_KEY));
        }

        $dpopProofFactory = new DpopProofFactory(
            $jwk,
            new JWSBuilder(new AlgorithmManager([new ES256()])),
            new CompactSerializer()
        );

        /*/ RFC9449 - DPoP - Section 5: DPoP proof is injected automatically by DpopAuthMethod /*/
        $sessionHandler = Session::current();
        $methods = [
            new DpopAuthMethod(new ClientSecretBasic(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new ClientSecretJwt(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new ClientSecretPost(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new None(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new PrivateKeyJwt(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new TLSClientAuth(), $dpopProofFactory, $sessionHandler),
            new DpopAuthMethod(new SelfSignedTLSClientAuth(), $dpopProofFactory, $sessionHandler),
        ];
        unset($sessionHandler);
        // Initialise DPoP key pair (stored in session so the same key is reused across the redirect round-trip).
        $dpopAuthMethodFactory = new AuthMethodFactory($methods);

        $clientBuilder = new ClientBuilder();
        $clientBuilder = $clientBuilder
            ->setAuthMethodFactory($dpopAuthMethodFactory)
            ->setHttpClient($httpClient);

        if (! isset($issuer) && isset($webIdUrl) && filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            // Grab Issuer from WebId Profile
            if (! RdfNamespace::get('solid')) {
                RdfNamespace::set('solid', 'http://www.w3.org/ns/solid/terms#');
            }

            $client = new Client();
            $graph = new Graph();

            $webIdResponse = $client->get($webIdUrl);
            $content = $webIdResponse->getBody()->getContents();
            $format = explode(';', $webIdResponse->getHeaderLine('Content-Type'))[0] ?? null;
            $graph->parse($content, $format, $webIdUrl);


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
                $profile->primaryTopic();
            }

            $issuers = [];
            if ($profile->hasProperty('solid:oidcIssuer')) {
                $resources = $profile->allResources('solid:oidcIssuer');
                $uris = array_map(static function (Resource $issuer) {
                    return $issuer->getUri();
                }, $resources);
                $issuers = array_unique($uris);
            }

            // @FIXME: If there is more than one issuer, the user should be able to choose which on to use
            $issuerUrl = reset($issuers);

            $issuerUrl = rtrim($issuerUrl, '/');
            $openidDiscoveryUrl = $issuerUrl . '/.well-known/openid-configuration';
            try {
                $issuer = $issuerBuilder->build($openidDiscoveryUrl);
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                throw SolidException::create('Failed to discover issuer metadata', $e);
            }

            $issuerConfig = $issuer->getMetadata()->toArray();
        }


        if (isset($issuer)) {
            // Register client with the issuer (dynamic registration; cached per-issuer hash).
            $issuerHash = hash('sha256', $issuerUrl);

            // If the issuer requires pre-registration, use the initial access token provided during that process to register the client.
            $initialTokens = [$issuerHash => null];

            // Check if our client is already registered, if not, register it and store the metadata for future use
            $clientMetadataFile = $issuerHash . '/issuer_metadata.json';
            $clientMetadataFileExists = $filesystem->fileExists($clientMetadataFile);
            if ($clientMetadataFileExists) {
                // Client already registered, reading metadata from file
                $fileContents = $filesystem->read($clientMetadataFile);
                $registeredClaims = json_decode($fileContents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                // Client not registered, registering client...
                $registrationServiceBuilder = new RegistrationServiceBuilder();
                $registration = $registrationServiceBuilder->build();

                try {
                    $registeredClaims = $registration->register($issuer, $clientConfig, $initialTokens[$issuerHash]);
                } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                    // InvalidArgumentException(Issuer does not support dynamic client registration)
                    // RuntimeException(Unable to encode client metadata | Unable to register OpenID client | Registration response did not return a client_id field)
                    throw SolidException::create('Dynamic registration failed', $e);
                }

                $fileContents = json_encode($registeredClaims,
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $filesystem->write($clientMetadataFile, $fileContents);
            }

            $clientMetadata = ClientMetadata::fromArray($registeredClaims);

            if ($useOfflineAccess === true) {
                $offlineGrant = [];

                $path = $issuerHash . '/offline-grant.json';

                if ($filesystem->fileExists($path)) {
                    $contents = $filesystem->read($path);
                    $storedGrant = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                    if (is_array($storedGrant)) {
                        $offlineGrant = $storedGrant;
                    }
                }

                if (
                    $offlineGrant !== []
                    && ! Session::current()->has('solid_refresh_token')
                    && ! Session::current()->has('solid_access_token')
                ) {
                    foreach ([
                                 'solid_access_token',
                                 'solid_refresh_token',
                                 'solid_resource_url',
                                 'solid_storage_root',
                                 'solid_token_expiry',
                                 'solid_webid',
                             ] as $key) {
                        if (array_key_exists($key, $offlineGrant)) {
                            Session::current()->set($key, $offlineGrant[$key]);
                        }
                    }

                    $sessionKey = DpopProofFactory::SESSION_KEY;
                    if (isset($offlineGrant[$sessionKey]) && is_array($offlineGrant[$sessionKey])) {
                        Session::current()->set($sessionKey, $offlineGrant[$sessionKey]);
                    }
                }
            }

            $clientBuilder = $clientBuilder
                ->setClientMetadata($clientMetadata)
                ->setIssuer($issuer);

            $client = $clientBuilder->build();
        }

        $authorizationServiceBuilder = new AuthorizationServiceBuilder();
        $authorizationService = $authorizationServiceBuilder
            ->setHttpClient($httpClient)
            ->build();

        $accessToken = null;
        $offlineModeHandled = false;

        if (isset($client)) {
            // if (! $isRedirect) === isset($issuer) === isset($client)
            if ($useOfflineAccess === true) {
                $persistedAccessToken = Session::current()->get('solid_access_token');
                $persistedRefreshToken = Session::current()->get('solid_refresh_token');
                $persistedExpiry = Session::current()->get('solid_token_expiry');

                $hasReusableAccessToken = is_string($persistedAccessToken)
                    && $persistedAccessToken !== ''
                    && is_numeric($persistedExpiry)
                    && (int) $persistedExpiry > time() + 60;

                $hasRefreshToken = is_string($persistedRefreshToken) && $persistedRefreshToken !== '';

                if ($hasReusableAccessToken || $hasRefreshToken) {
                    // Offline mode: reuse previously granted consent

                    $idTokenClaims = [];

                    if ($hasReusableAccessToken) {
                        $accessToken = $persistedAccessToken;
                        // Reusing stored access token until', date('Y-m-d H:i:s', (int) $persistedExpiry));
                    } elseif ($hasRefreshToken) {
                        // Stored access token missing or expired; refreshing with the persisted refresh token.
                        try {
                            $refreshedTokenSet = $authorizationService->refresh($client, $persistedRefreshToken);

                            $accessToken = $refreshedTokenSet->getAccessToken();
                            $expiresIn = $refreshedTokenSet->getExpiresIn();
                            if ($expiresIn > 0) {
                                $tokenExpiry = time() + $expiresIn;
                            } else {
                                $tokenExpiry = time() + 3600;
                            }

                            Session::current()->set('solid_access_token', $accessToken);
                            Session::current()->set('solid_refresh_token',
                                $refreshedTokenSet->getRefreshToken() ?: $persistedRefreshToken);
                            Session::current()->set('solid_token_expiry', $tokenExpiry);

                            $refreshedIdToken = $refreshedTokenSet->getIdToken();
                            if (is_string($refreshedIdToken) && $refreshedIdToken !== '') {
                                try {
                                    $idTokenClaims = (new IdTokenVerifierBuilder())->build($client)
                                        ->withAccessToken($accessToken)
                                        ->verify($refreshedIdToken);
                                } catch (\Facile\JoseVerifier\Exception\ExceptionInterface $e) {
                                    $idTokenClaims = Utility::decodeUnsafeJwt($refreshedIdToken);
                                    // @KLUDGE: refreshed id_token verification failed: $e->getMessage(); continuing with unverified claims',
                                }

                                $refreshedWebId = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;
                                if (is_string($refreshedWebId) && $refreshedWebId !== '') {
                                    Session::current()->set('solid_webid', $refreshedWebId);
                                }
                            }

                            Utility::saveOfflineGrant(Session::current(), $filesystem, $issuerHash);
                            // Refresh token exchange succeeded; offline consent is being reused.
                        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                            $path = $issuerHash . '/offline-grant.json';

                            if ($filesystem->fileExists($path)) {
                                $filesystem->delete($path);
                            }

                            foreach ([
                                         DpopProofFactory::SESSION_KEY,
                                         'solid_access_token',
                                         'solid_refresh_token',
                                         'solid_resource_url',
                                         'solid_storage_root',
                                         'solid_token_expiry',
                                         'solid_webid',
                                     ] as $key) {
                                Session::current()->remove($key);
                            }
                            // @KLUDGE: Stored offline grant could not be refreshed: $e->getMessage(); falling back to interactive login
                        }
                    }

                    if (is_string($accessToken) && $accessToken !== '') {
                        Utility::saveOfflineGrant(Session::current(), $filesystem, $issuerHash);

                        $offlineModeHandled = true;
                    }
                }
            }

            if ($offlineModeHandled === false) {
                // At this point there is a registered client, but it is not authenticated yet.
                // Step 2. Check if user is authenticated
                $authorizationRequestParams = [];

                // Add Issuer URL as "state" value, so it can be retrieved after redirect
                $header = Utility::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
                $payload = Utility::base64UrlEncode(json_encode([
                    'exp' => time() + $stateTtlSeconds,
                    'issr' => $issuerUrl,
                ], JSON_THROW_ON_ERROR));
                $signature = Utility::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $stateSigningKey, true));
                $state = $header . '.' . $payload . '.' . $signature;

                if ($useCsrfCheck === true) {
                    Session::current()->set('oauth_state', $state);
                }

                $authorizationRequestParams['state'] = $state;

                if ($usePkce === true) {
                    /*/ rfc7636 - PKCE - Section 4.1.  Client Creates a Code Verifier /*/
                    // 32 random bytes base64url-encoded → 43-char verifier in the allowed unreserved set.
                    $codeVerifier = Utility::base64UrlEncode(random_bytes(32));
                    Session::current()->set('pkce_code_verifier', $codeVerifier);

                    /*/ rfc7636 - PKCE - Section 4.2.  Client Creates the Code Challenge /*/
                    $codeVerifierHash = hash('sha256', $codeVerifier, true);
                    $codeChallenge = Utility::base64UrlEncode($codeVerifierHash);

                    /*/ rfc7636 - PKCE - Section 4.3.  Client Sends the Code Challenge with the Authorization Request /*/
                    $authorizationRequestParams['code_challenge'] = $codeChallenge;
                    $authorizationRequestParams['code_challenge_method'] = 'S256'; // RFC7636: clients capable of S256 MUST use S256.

                    if ($useOfflineAccess === true) {
                        // offline_access requires explicit consent so the OP actually issues a refresh token (OIDC Core Section 11).
                        $authorizationRequestParams['prompt'] = 'consent';
                        $authorizationRequestParams['scope'] = 'openid webid offline_access';
                    }
                }

                $redirectAuthorizationUri = $authorizationService->getAuthorizationUri($client, $authorizationRequestParams);
            }
        }

        if ($isRedirect) {
            // Step 3. Exchange code for access token

            // At this point the user is redirected back to the application from the authorization server.
            // The authorization server will redirect the user back to the application with a code or error parameter.

            // The error parameter is set when something has gone wrong on the OP side.
            if (isset($request->getQueryParams()['error'])) {
                throw SolidException::create('Provider returned an error ' . $request->getQueryParams()['error']);
            }

            /*/  rfc7636 - PKCE - Section 4.4.  Server Returns the Code /*/
            // The authorization response must include a non-empty authorization code.
            $authorizationCode = $request->getQueryParams()['code'] ?? null;
            if (! is_string($authorizationCode) || $authorizationCode === '') {
                throw SolidException::create('Provider did not return a valid authorization code ' . $authorizationCode);
            }

            $stateToken = $request->getQueryParams()['state'] ?? null;
            if (! is_string($stateToken) || $stateToken === '') {
                throw SolidException::create('Callback is missing "state" parameter ' . 'Missing state parameter');
            }

            // CSRF: validate state matches what we sent (OIDC Core Section 3.1.2.7).
            if ($useCsrfCheck === true) {
                $expectedState = Session::current()->get('oauth_state');
                if ($stateToken !== $expectedState) {
                    $message = vsprintf(
                        'CSRF Check Failed. Received state "%s" does not match stored state "%s" from session', [
                        'returned_state' => $stateToken,
                        'expected_state' => $expectedState,
                    ]);

                    throw SolidException::create($message);
                }
                Session::current()->remove('oauth_state');
            }

            $parts = explode('.', $stateToken);

            if (count($parts) !== 3) {
                $error = 'State must be a compact JWT';
            } else {
                $header = json_decode(Utility::base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
                $payload = json_decode(Utility::base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);

                $expectedSignature = Utility::base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $stateSigningKey,
                    true));

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

            if ($usePkce === true) {
                /*/ rfc7636 - PKCE - Section 4.5.  Client Sends the Authorization Code and the Code Verifier to the Token Endpoint /*/
                $codeVerifier = Session::current()->get('pkce_code_verifier');
                $hasValidCodeVerifier = is_string($codeVerifier) && $codeVerifier !== '';
                if (! $hasValidCodeVerifier) {
                    throw SolidException::create('Client has no valid PKCE code_verifier for this authorization response ' . $codeVerifier);
                }
            }

            // In callback mode, issuer is recovered exclusively from signed state.
            $issuerUrl = rtrim($payload['issr'], '/');
            $openidDiscoveryUrl = $issuerUrl . '/.well-known/openid-configuration';
            // At this point, post redirect, the client SHOULD already be registered
            // @CHECKME: The "offline PoC" does not grab the issuer from the JWT (it was hard-coded).
            //           What was the correct/secure way of doing that?
            //           Also, shouldn't we "somehow" check the issuer in the state against the issuer we cam from?
            //           (how do we know which issuer we are redirected back from?)
            $issuerHash = hash('sha256', $issuerUrl);
            $clientMetadataFile = $issuerHash . '/issuer_metadata.json';
            $fileContents = $filesystem->read($clientMetadataFile);
            $registeredClaims = json_decode($fileContents, true, 512, JSON_THROW_ON_ERROR);
            $clientMetadata = ClientMetadata::fromArray($registeredClaims);

            try {
                $issuer = $issuerBuilder->build($openidDiscoveryUrl);
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                throw SolidException::create('Failed to discover issuer metadata', $e);
            }

            $clientBuilder = $clientBuilder
                ->setClientMetadata($clientMetadata)
                ->setIssuer($issuer);

            $client = $clientBuilder->build();

            // -------------------------------------------------------------------------
            /*/ RFC9449 - DPoP - Section 5. DPoP Access Token Request /*/
            // The token request must include a DPoP header with a valid proof JWT (see RFC9449 Section 4.2 for proof syntax).

            /*/ rfc7636 - PKCE - Section 4.6.  Server Verifies code_verifier before Returning the Tokens /*/
            // On success, the token endpoint returns tokens; on PKCE mismatch, it returns invalid_grant.
            $params = [
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $clientRedirectUri,
            ];

            if ($usePkce === true) {
                $params['code_verifier'] = $codeVerifier; // rfc7636 - PKCE - Section 4.5
            }

            try {
                // Use explicit grant() so this example fully controls what gets sent to the token endpoint.
                $tokenSet = $authorizationService->grant($client, $params);

                Session::current()->remove('pkce_code_verifier');
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                Session::current()->remove('pkce_code_verifier');
                // InvalidArgumentException(Invalid metadata content)
                if ($e->getPrevious()) {
                    // Response could not be parsed as JSON
                    $trace = $e->getPrevious()->getTrace();
                    $responseBody = $trace[0]['args'][0] ?? 'Could not retrieve response body';
                } else {
                    // Parse was successful, but the response is not an array
                    $responseBody = null;
                }

                if (str_contains($e->getMessage(), 'invalid_grant')) {
                    $message = 'Token endpoint rejected code_verifier (invalid_grant — PKCE mismatch) ' . $responseBody;
                } else {
                    $message = 'Failed to exchange authorization code for access token';
                }

                throw SolidException::create($message, $e);
            }

            $idTokenVerified = false;
            $idToken = $tokenSet->getIdToken(); // Unencrypted id_token, if returned

            // Validate ID Token and bind identity to Solid rules.
            //   - Perform OIDC ID Token validation (iss, aud, exp, signature/JWKs) (OIDC Core Section 3.1.3.7).
            //   - Extract webid claim (Solid-OIDC Section 7, Section 8.1).
            if ($idToken) {
                try {
                    $verifier = (new IdTokenVerifierBuilder())->build($client);

                    $accessToken = $tokenSet->getAccessToken(); // Access token, if returned
                    if (is_string($accessToken) && $accessToken !== '') {
                        $verifier = $verifier->withAccessToken($accessToken);
                    }

                    $idTokenClaims = $verifier->verify($idToken);
                    $idTokenVerified = true;
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

            // Extract webid claim (Solid-OIDC Section 7, Section 8.1).
            $webIdUrl = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;

            // -------------------------------------------------------------------------
            // Persist tokens for offline operation.
            // Store refresh_token server-side in session; never expose to browser (OIDC Core Section 12).
            if ($useOfflineAccess === true) {
                $expiresIn = $tokenSet->getExpiresIn();
                // @CHECKME: Not sure which should come first, the expiry form the token or from the id_token
                if ($expiresIn > 0) {
                    $tokenExpiry = time() + $expiresIn;
                } elseif (isset($idTokenClaims['exp']) && is_numeric($idTokenClaims['exp'])) {
                    $tokenExpiry = (int) $idTokenClaims['exp'];
                } else {
                    $tokenExpiry = time() + 3600;
                }

                Session::current()->set('solid_access_token', $tokenSet->getAccessToken());
                Session::current()->set('solid_refresh_token', $tokenSet->getRefreshToken());
                Session::current()->set('solid_token_expiry', $tokenExpiry);
                Session::current()->set('solid_webid', $webIdUrl);

                Utility::saveOfflineGrant(Session::current(), $filesystem, $issuerHash);
            }
        }

        if ($webIdUrl && filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            if (! RdfNamespace::get('pim')) {
                RdfNamespace::set('pim', 'http://www.w3.org/ns/pim/space#');
            }

            if (! RdfNamespace::get('space')) {
                RdfNamespace::set('space', 'http://www.w3.org/ns/pim/space#');
            }

            // Parse the WebID Profile
            $graph = new Graph();

            $webIdResponse = $httpClient->get($webIdUrl);
            $content = $webIdResponse->getBody()->getContents();

            $format = explode(';', $webIdResponse->getHeaderLine('Content-Type'))[0] ?? null;
            $graph->parse($content, $format, $webIdUrl);

            // @CHECKME: The original PoC also checked for WebID URL without the hash:
            //           $profileDocumentUrl = explode('#', $webId, 2)[0];
            //           Is that needed/expected?
            $profile = $graph->resource($webIdUrl);

            // Grab the storage root URL from the WebID Profile
            // @NOTE: There can be more than one Storage URI
            $property = null;

            $primaryTopic = $profile->primaryTopic();
            if ($primaryTopic) {
                $profile = $primaryTopic;
            }

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

                if ($storageUrls !== []) {
                    // @NOTE: The first storage URL is used for the demo, for production this is not correct behavior
                    $storageUrl = reset($storageUrls);

                    if (filter_var($storageUrl, FILTER_VALIDATE_URL)) {
                        $privateContainerUrl = rtrim($storageUrl, '/') . '/private/';
                    }
                }
            }
        }

        // If there is no "offline" access, this only works after we have been redirected from the Issuer
        if (isset($privateContainerUrl)) {
            $resourceRequest = new \GuzzleHttp\Psr7\Request('GET', $privateContainerUrl, [
                'Authorization' => 'DPoP ' . $accessToken, // 'Accept' => 'text/turtle, application/ld+json',
            ]);
            $dpopProof = $dpopProofFactory->createProofForRequest($resourceRequest);
            Session::current()->set('last_dpop_proof', $dpopProof);

            $resourceRequest = $resourceRequest->withHeader('DPoP', $dpopProof);

            try {
                $resourceResponse = $httpClient->send($resourceRequest);
                $resourceBody = $resourceResponse->getBody()->getContents();
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                throw SolidException::create("Could not fetch protected resource from $privateContainerUrl", $e);
            }
        }

        if (! empty($redirectAuthorizationUri)) {
            $response = $response
                ->withStatus(302)
                ->withHeader('Location', $redirectAuthorizationUri);
        } else {
            $clientConfigFileExists = $clientConfigFileExists ? '✅' : '☑';
            $clientMetadataFileExists = isset($clientMetadataFileExists) && $clientMetadataFileExists === false
                    ? '☑'
                    : '✅';
            $idTokenVerified = isset($idTokenVerified)
                ? $idTokenVerified === false
                    ? '❌ <strong>(WARNING: id_token signature verification failed, claims should not be trusted!)<strong>'
                    : '✅'
                : '<em>(Not available without session)</em>';
            $pkceUsage = $usePkce ? 'enabled 🔛' : 'disabled 📴';

            $content = <<<HTML
                <ul>
                    <li>WebID: <a href="$webIdUrl">$webIdUrl</a></li>
                    <li>Issuer URL: <a href="$issuerUrl">$issuerUrl</a></li>
                    <li>Client Data File: exists $clientConfigFileExists</li>
                    <li>Issuer Metadata File: exists $clientConfigFileExists</li>
                    <li>ID Token claims: $idTokenVerified</li>
                    <li>PKCE usage $pkceUsage</li>
                    <li>Protected Resource<pre>$resourceBody</pre></li>
                </ul>
HTML;

            $response->getBody()->write($content);
        }

        return $response;
    }
}
