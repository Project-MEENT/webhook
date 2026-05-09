<?php

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
use Facile\OpenIDClient\Client\ClientInterface as OidcClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\IssuerInterface;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\RegistrationServiceBuilder;
use Facile\OpenIDClient\Service\RegistrationService;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder;
use Facile\OpenIDClient\Token\TokenSetInterface;
use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Meent\WebHook\Exception\SolidException;
use Meent\WebHook\UrlHashTrait;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;

class SolidClient
{
    use UrlHashTrait;

    private array $config;

    private AuthorizationService $authorizationService;
    private Client $httpClient;
    private ClientBuilder $oidcClientBuilder;
    private DpopProofFactory $dpopProofFactory;
    private FilesystemOperator $filesystem;
    private Graph $graph;
    private IdTokenVerifierBuilder $idTokenVerifierBuilder;
    private IssuerBuilder $issuerBuilder;
    private RegistrationService $registration;
    private Session $session;

    public function __construct(RequestInterface $request)
    {
        // @FIXME: Config and Dependencies should be injected
        $this->createConfig($request);

        $this->session = Session::current();

        $this->createDependencies($this->config['httpClientConfig'], $this->config['storageLocation']);

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

    final public function fetchStorageUrls($webIdUrl)
    {
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

            $storageUrls = array_map(static function ($storageUrl) {
                if (filter_var($storageUrl, FILTER_VALIDATE_URL)) {
                    echo "Found storage URL in WebID Profile: $storageUrl\n";
                } else {
                    echo "Invalid storage URL in WebID Profile: $storageUrl\n";
                }
            }, $storageUrls);
        }

        return $storageUrls;
    }

    // @FIXME: There is a scenario where, if the page is opened with a WebID, and there has not yet been a consent call, we will get a 403!

    final public function connectWebId($webIdUrl)
    {
        $redirectAuthorizationUri = '';

        if (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
            throw SolidException::create("Provided WebID '$webIdUrl' is not a valid URL");
        }

        $issuer = $this->createIssuerFromWebIdUrl($webIdUrl);

        $oidcClient = $this->createOidcClientFromIssuer($issuer, $webIdUrl);

        $offlineModeHandled = false;

        if ($this->config['useOfflineAccess'] === true) {
            $accessToken = $this->handleOfflineAccess($oidcClient, $issuer, $webIdUrl);

            if (is_string($accessToken) && $accessToken !== '') {
                $offlineModeHandled = true;
            }
        }

        if ($offlineModeHandled === false) {
            $redirectAuthorizationUri = $this->getRedirectAuthorizationUri($oidcClient, $issuer);
        }

        return $redirectAuthorizationUri;
    }

    final public function handleRedirect($queryParams): string
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
        if ($this->config['useCsrfCheck'] === true) {
            $expectedState = $this->session->get('oauth_state');

            if ($state !== $expectedState) {
                $message = vsprintf(
                    'CSRF Check Failed. Received state "%s" does not match stored state "%s" from session', [
                    'returned_state' => $state,
                    'expected_state' => $expectedState,
                ]);

                throw SolidException::create($message);
            }

            $this->session->remove('oauth_state');
        }

        // In callback mode, issuer is recovered exclusively from signed state.
        $issuerUrl = $this->getIssuerUrlFromState($state);
        $issuer = $this->createIssuerFromUrl($issuerUrl);

        $oidcClient = $this->createOidcClientFromIssuerUrl($issuerUrl);

        // -------------------------------------------------------------------------
        /*/ RFC9449 - DPoP - Section 5. DPoP Access Token Request /*/
        // The token request must include a DPoP header with a valid proof JWT (see RFC9449 Section 4.2 for proof syntax).

        $tokenSet = $this->getTokenSet($authorizationCode, $oidcClient);

        $idTokenClaims = $this->getTokenClaims($tokenSet, $oidcClient);

        // Extract webid claim (Solid-OIDC Section 7, Section 8.1).
        $webIdUrl = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;

        // -------------------------------------------------------------------------
        // Persist tokens for offline operation.
        // Store refresh_token server-side in session; never expose to browser (OIDC Core Section 12).
        if ($this->config['useOfflineAccess'] === true) {
            $expiresIn = $tokenSet->getExpiresIn();
            // @CHECKME: Not sure which should come first, the expiry form the token or from the id_token
            if ($expiresIn > 0) {
                $tokenExpiry = time() + $expiresIn;
            } elseif (isset($idTokenClaims['exp']) && is_numeric($idTokenClaims['exp'])) {
                $tokenExpiry = (int) $idTokenClaims['exp'];
            } else {
                $tokenExpiry = time() + 3600;
            }

            $this->session->set('solid_access_token', $tokenSet->getAccessToken());
            $this->session->set('solid_refresh_token', $tokenSet->getRefreshToken());
            $this->session->set('solid_token_expiry', $tokenExpiry);
            $this->session->set('solid_webid', $webIdUrl);
        }

        $accessToken = $tokenSet->getAccessToken(); // Access token, if returned

        if ($this->config['useOfflineAccess'] === true) {
            $this->saveOfflineGrant($issuer, $webIdUrl);
        }

        return $issuerUrl;
    }

    final public function storeResource($resourceUrl, $resource)
    {
        $accessToken = $this->getAccessToken();

        // If there is no "offline" access, this only works after we have been redirected from the Issuer
        // @FIXME: Do we need POST or PUT? Do we expect to only create new resources for each call? Or append them to an existing resource?
        $headers = [
            // 'Accept' => 'text/turtle, application/ld+json', (?)
            'Authorization' => 'DPoP ' . $accessToken,
        ];
        $resourceRequest = new \GuzzleHttp\Psr7\Request('POST || PUT (?)', $resourceUrl, $headers, $resource);
        $dpopProof = $this->dpopProofFactory->createProofForRequest($resourceRequest);
        $this->session->set('last_dpop_proof', $dpopProof);

        $resourceRequest = $resourceRequest->withHeader('DPoP', $dpopProof);

        try {
            return $this->httpClient->send($resourceRequest);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw SolidException::create("Could not write resource to $resourceUrl", $e);
        }
    }

    private function createConfig(RequestInterface $request)
    {
        // -----------------------------------------------------------------------------
        $storageLocation = __DIR__ . '/../../build/storage/';

        // -----------------------------------------------------------------------------
        $clientConfigFile = 'client_id.json';

        // @TODO: Replace generated Client ID with static JSON at $clientServer . '/' . $clientConfigFile;
        $clientId = Utility::base64UrlEncode(random_bytes(32));
        $clientName = 'MEENT Solid P1 Dongle Webhook';
        $clientRedirectUri = $request->getUri()->withFragment('')->withQuery('')->__toString();
        $clientRedirectUris = [
            $clientRedirectUri,
        ];
        // @FIXME: Client Secret should not be hard-coded but come from the client_id.json file, or generated for first use
        $clientSecret = 'my-client-secret';

        // -----------------------------------------------------------------------------
        $stateSigningKey = $clientSecret; // @FIXME: Use separate secret (i.e. private key) for signing, so it can be rotated
        $stateTtlSeconds = 300;

        // -----------------------------------------------------------------------------
        // For certain issuers (like https://solidcommunity.net) PKCE is required, even for  server-to-server calls
        // @FIXME: PKCE use should be stored in the server offline grant or metadata JSON
        $usePkce = true;

        // -----------------------------------------------------------------------------
        $useCsrfCheck = true;

        // -----------------------------------------------------------------------------
        $useOfflineAccess = true;

        // -----------------------------------------------------------------------------
        $httpClientConfig = [
            // Allow self-signed certificates for local development
            // 'verify' => false,
            // 'verify_host' => false,
            // 'verify_peer' => false,
        ];

        $this->config = [
            'client' => [
                'ConfigFile' => $clientConfigFile,
                'Id' => $clientId,
                'Name' => $clientName,
                'RedirectUri' => $clientRedirectUri,
                'RedirectUris' => $clientRedirectUris,
                'Secret' => $clientSecret,
            ],
            'httpClientConfig' => $httpClientConfig,
            'state' => [
                'SigningKey' => $stateSigningKey,
                'TtlSeconds' => $stateTtlSeconds,
            ],
            'storageLocation' => $storageLocation,
            'useCsrfCheck' => $useCsrfCheck,
            'useOfflineAccess' => $useOfflineAccess,
            'usePkce' => $usePkce,
        ];
    }

    private function createDependencies($httpClientConfig, $storageLocation)
    {
        // -----------------------------------------------------------------------------
        $httpClient = new Client($httpClientConfig);

        // -----------------------------------------------------------------------------
        // Create FileSystem
        // -----------------------------------------------------------------------------
        if ($storageLocation) {
            $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter($storageLocation);
        } else {
            $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        }

        $filesystem = new Filesystem($adapter);

        // -----------------------------------------------------------------------------
        // Create Cache Store
        // -----------------------------------------------------------------------------
        if ($filesystem && class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\Flysystem')) {
            $store = new \MatthiasMullie\Scrapbook\Adapters\Flysystem($filesystem);
        } elseif (class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\MemoryStore')) {
            $store = new \MatthiasMullie\Scrapbook\Adapters\MemoryStore();
        } else {
            $store = null;
        }

        if ($store) {
            // simple-cache implementation
            $cache = new \MatthiasMullie\Scrapbook\Psr16\SimpleCache($store);
        }

        // -----------------------------------------------------------------------------
        // Create OIDC Client
        // -----------------------------------------------------------------------------
        $metadataProviderBuilder = new MetadataProviderBuilder();
        $issuerBuilder = new IssuerBuilder();

        $metadataProviderBuilder->setHttpClient($httpClient);
        $issuerBuilder = $issuerBuilder->setMetadataProviderBuilder($metadataProviderBuilder);

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

        // RFC9449 - DPoP - Section 5.  DPoP Access Token Request
        // Initialise DPoP key pair (stored in session so the same key is reused across the redirect round-trip).
        if (
            ! $this->session->has(DpopProofFactory::SESSION_KEY)
            || ! is_array($this->session->get(DpopProofFactory::SESSION_KEY))
            || ! isset($this->session->get(DpopProofFactory::SESSION_KEY)['kty'])) {
            $jwk = JWKFactory::createECKey('P-256');
            $this->session->set(DpopProofFactory::SESSION_KEY, $jwk->all());
        } else {
            $jwk = new JWK($this->session->get(DpopProofFactory::SESSION_KEY));
        }

        $dpopProofFactory = new DpopProofFactory(
            $jwk,
            new JWSBuilder(new AlgorithmManager([new ES256()])),
            new CompactSerializer()
        );

        /*/ RFC9449 - DPoP - Section 5: DPoP proof is injected automatically by DpopAuthMethod /*/
        $sessionHandler = $this->session;
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

        $oidcClientBuilder = new ClientBuilder();
        $oidcClientBuilder = $oidcClientBuilder
            ->setAuthMethodFactory($dpopAuthMethodFactory)
            ->setHttpClient($httpClient);

        // ---------------------------------------------------------------------
        $registrationServiceBuilder = new RegistrationServiceBuilder();
        $registration = $registrationServiceBuilder->build();

        $authorizationServiceBuilder = new AuthorizationServiceBuilder();
        $authorizationService = $authorizationServiceBuilder
            ->setHttpClient($httpClient)
            ->build();

        // ---------------------------------------------------------------------
        $graph = new Graph();

        $this->authorizationService = $authorizationService;
        $this->dpopProofFactory = $dpopProofFactory;
        $this->filesystem = $filesystem;
        $this->graph = $graph;
        $this->httpClient = $httpClient;
        $this->idTokenVerifierBuilder = new IdTokenVerifierBuilder();
        $this->issuerBuilder = $issuerBuilder;
        $this->oidcClientBuilder = $oidcClientBuilder;
        $this->registration = $registration;
    }

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

        // @FIXME: If there is more than one issuer, the user should be able to choose which on to use
        $issuerUrl = reset($issuers);

        return $this->createIssuerFromUrl($issuerUrl);
    }

    private function createOidcClientFromIssuer(IssuerInterface $issuer, $webIdUrl)
    {
        $filesystem = $this->filesystem;

        $issuerConfig = $issuer->getMetadata()->toArray();
        $issuerUrl = $issuerConfig['issuer'];

        // Register oidcClient with the issuer (dynamic registration; cached per-issuer hash).
        $issuerHash = $this->hashUrl($issuerUrl, 'sha256');

        // If the issuer requires pre-registration, use the initial access token provided during that process to register the oidcClient.
        $initialTokens = [$issuerHash => null];

        // Check if our oidcClient is already registered, if not, register it and store the metadata for future use
        $clientMetadataFile = $issuerHash . '/issuer_metadata.json';

        $clientMetadataFileExists = $filesystem->fileExists($clientMetadataFile);

        if ($clientMetadataFileExists) {
            // Client already registered, reading metadata from file
            $fileContents = $filesystem->read($clientMetadataFile);
            $registeredClaims = json_decode($fileContents, true, 512, JSON_THROW_ON_ERROR);
        } else {
            // Client not registered, registering oidcClient...
            $clientConfig = $this->getClientConfig();
            try {
                $registeredClaims = $this->registration->register($issuer, $clientConfig, $initialTokens[$issuerHash]);
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                // InvalidArgumentException(Issuer does not support dynamic oidcClient registration)
                // RuntimeException(Unable to encode oidcClient metadata | Unable to register OpenID oidcClient | Registration response did not return a client_id field)
                throw SolidException::create('Dynamic registration failed', $e);
            }

            $fileContents = json_encode($registeredClaims,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $filesystem->write($clientMetadataFile, $fileContents);
        }

        $clientMetadata = ClientMetadata::fromArray($registeredClaims);

        if ($this->config['useOfflineAccess'] === true) {
            $offlineGrant = [];

            $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

            if ($filesystem->fileExists($offlineGrantFile)) {
                $contents = $filesystem->read($offlineGrantFile);
                $storedGrant = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($storedGrant)) {
                    $offlineGrant = $storedGrant;
                }
            }

            if ($offlineGrant !== []
                && ! $this->session->has('solid_refresh_token')
                && ! $this->session->has('solid_access_token')
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
                        $this->session->set($key, $offlineGrant[$key]);
                    }
                }

                $sessionKey = DpopProofFactory::SESSION_KEY;
                if (isset($offlineGrant[$sessionKey]) && is_array($offlineGrant[$sessionKey])) {
                    $this->session->set($sessionKey, $offlineGrant[$sessionKey]);
                }
            }
        }

        return $this->oidcClientBuilder
            ->setClientMetadata($clientMetadata)
            ->setIssuer($issuer)
            ->build();
    }

    private function createOidcClientFromIssuerUrl($issuerUrl)
    {
        $openidDiscoveryUrl = $issuerUrl . '/.well-known/openid-configuration';
        // At this point, post redirect, the oidcClient SHOULD already be registered
        // @CHECKME: Shouldn't we "somehow" check the issuer in the state against the issuer we came from?
        //           (how do we know which issuer we are redirected back from?)
        $issuerHash = $this->hashUrl($issuerUrl, 'sha256');
        $clientMetadataFile = $issuerHash . '/issuer_metadata.json';
        $fileContents = $this->filesystem->read($clientMetadataFile);
        $registeredClaims = json_decode($fileContents, true, 512, JSON_THROW_ON_ERROR);
        $clientMetadata = ClientMetadata::fromArray($registeredClaims);

        try {
            $issuer = $this->issuerBuilder->build($openidDiscoveryUrl);
        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
            throw SolidException::create('Failed to discover issuer metadata', $e);
        }

        return $this->oidcClientBuilder
            ->setClientMetadata($clientMetadata)
            ->setIssuer($issuer)
            ->build();
    }

    private function getGrantFilePath(IssuerInterface $issuer, $webIdUrl)
    {
        $issuerConfig = $issuer->getMetadata()->toArray();
        $issuerUrl = $issuerConfig['issuer'];
        $issuerHash = $this->hashUrl($issuerUrl, 'sha256');
        $webIdHash = $this->hashUrl($webIdUrl, 'sha1');

        return $issuerHash . '/' . $webIdHash . '.json';
    }

    private function getTokenClaims(TokenSetInterface $tokenSet, OidcClientInterface $oidcClient): array
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

    private function getClientConfig()
    {
        $clientConfigFile = $this->config['client']['ConfigFile'];
        $clientId = $this->config['client']['Id'];
        $clientName = $this->config['client']['Name'];
        $clientRedirectUris = $this->config['client']['RedirectUris'];
        $clientSecret = $this->config['client']['Secret'];

        $filesystem = $this->filesystem;

        if (! $filesystem->fileExists($clientConfigFile)) {
            // Client metadata file not found, creating...
            $data = [
                'client_id' => $clientId,
                'client_name' => $clientName,
                'client_secret' => $clientSecret,
                'redirect_uris' => $clientRedirectUris,
                'token_endpoint_auth_method' => 'client_secret_basic', // the auth method for the token endpoint
            ];

            if ($this->config['useOfflineAccess'] === true) {
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

        // Reading oidcClient metadata from file
        $json = $filesystem->read($clientConfigFile);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function getRedirectAuthorizationUri(OidcClientInterface $oidcClient, IssuerInterface $issuer)
    {
        $issuerConfig = $issuer->getMetadata()->toArray();
        $issuerUrl = $issuerConfig['issuer'];

        // At this point there is a registered oidcClient, but it is not authenticated yet.
        // Step 2. Check if user is authenticated
        $authorizationRequestParams = [];

        // Add Issuer URL as "state" value, so it can be retrieved after redirect
        $header = Utility::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR));
        $payload = Utility::base64UrlEncode(json_encode([
            'exp' => time() + $this->config['state']['TtlSeconds'],
            'issr' => $issuerUrl,
        ], JSON_THROW_ON_ERROR));
        $signature = Utility::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload,
            $this->config['state']['SigningKey'],
            true));
        $state = $header . '.' . $payload . '.' . $signature;

        if ($this->config['useCsrfCheck'] === true) {
            $this->session->set('oauth_state', $state);
        }

        $authorizationRequestParams['state'] = $state;

        if ($this->config['usePkce'] === true) {
            /*/ rfc7636 - PKCE - Section 4.1.  Client Creates a Code Verifier /*/
            // 32 random bytes base64url-encoded → 43-char verifier in the allowed unreserved set.
            $codeVerifier = Utility::base64UrlEncode(random_bytes(32));
            $this->session->set('pkce_code_verifier', $codeVerifier);

            /*/ rfc7636 - PKCE - Section 4.2.  Client Creates the Code Challenge /*/
            $codeVerifierHash = hash('sha256', $codeVerifier, true);
            $codeChallenge = Utility::base64UrlEncode($codeVerifierHash);

            /*/ rfc7636 - PKCE - Section 4.3.  Client Sends the Code Challenge with the Authorization Request /*/
            $authorizationRequestParams['code_challenge'] = $codeChallenge;
            $authorizationRequestParams['code_challenge_method'] = 'S256'; // RFC7636: clients capable of S256 MUST use S256.

            if ($this->config['useOfflineAccess'] === true) {
                // offline_access requires explicit consent so the OP actually issues a refresh token (OIDC Core Section 11).
                $authorizationRequestParams['prompt'] = 'consent';
                $authorizationRequestParams['scope'] = 'openid webid offline_access';
            }
        }

        return $this->authorizationService->getAuthorizationUri($oidcClient, $authorizationRequestParams);
    }

    private function getIssuerUrlFromState($state)
    {
        $parts = explode('.', $state);

        if (count($parts) !== 3) {
            $error = 'State must be a compact JWT';
        } else {
            $header = json_decode(Utility::base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode(Utility::base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);

            $expectedSignature = Utility::base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1],
                $this->config['state']['SigningKey'],
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

        return rtrim($payload['issr'], '/');
    }

    private function getTokenSet(
        string $authorizationCode,
        OidcClientInterface $oidcClient
    ): TokenSetInterface {
        $params = [
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['client']['RedirectUri'],
        ];

        if ($this->config['usePkce'] === true) {
            /*/ rfc7636 - PKCE - Section 4.5.  Client Sends the Authorization Code and the Code Verifier to the Token Endpoint /*/
            $codeVerifier = $this->session->get('pkce_code_verifier');
            $hasValidCodeVerifier = is_string($codeVerifier) && $codeVerifier !== '';
            if (! $hasValidCodeVerifier) {
                throw SolidException::create('Client has no valid PKCE code_verifier for this authorization response ' . $codeVerifier);
            }

            $params['code_verifier'] = $codeVerifier; // rfc7636 - PKCE - Section 4.5
        }

        try {
            // Use explicit grant() so this example fully controls what gets sent to the token endpoint.
            $tokenSet = $this->authorizationService->grant($oidcClient, $params);

            $this->session->remove('pkce_code_verifier');
        } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
            $this->session->remove('pkce_code_verifier');
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

        $sessionAccessToken = $this->session->get('solid_access_token');
        $sessionExpiry = $this->session->get('solid_token_expiry');
        $hasSessionAccessToken = is_string($sessionAccessToken)
            && $sessionAccessToken !== ''
            && is_numeric($sessionExpiry)
            && (int) $sessionExpiry > time() + 60;

        $sessionRefreshToken = $this->session->get('solid_refresh_token');
        $hasRefreshToken = is_string($sessionRefreshToken) && $sessionRefreshToken !== '';

        // Offline mode: reuse previously granted consent

        if ($hasSessionAccessToken) {
            // Reusing stored access token until it expires
            $accessToken = $sessionAccessToken;
        } elseif ($hasRefreshToken) {
            // Stored access token has expired (or is missing); refresh with the persisted refresh token.
            try {
                $accessToken = $this->refreshTokens($oidcClient, $sessionRefreshToken);

                $this->saveOfflineGrant($issuer, $webIdUrl);
                // Refresh token exchange succeeded; offline consent is being reused.
            } catch (\Facile\OpenIDClient\Exception\ExceptionInterface $e) {
                // @KLUDGE: Stored offline grant could not be refreshed: $e->getMessage(); fall back to interactive login
                $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

                if ($this->filesystem->fileExists($offlineGrantFile)) {
                    $this->filesystem->delete($offlineGrantFile);
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
                    $this->session->remove($key);
                }
            }
        }

        return $accessToken;
    }

    private function refreshTokens(OidcClientInterface $oidcClient, $persistedRefreshToken)
    {
        $tokenSet = $this->authorizationService->refresh($oidcClient, $persistedRefreshToken);

        $accessToken = $tokenSet->getAccessToken();
        $expiresIn = $tokenSet->getExpiresIn();
        if ($expiresIn > 0) {
            $tokenExpiry = time() + $expiresIn;
        } else {
            $tokenExpiry = time() + 3600;
        }

        $this->session->set('solid_access_token', $accessToken);
        $this->session->set('solid_refresh_token', $tokenSet->getRefreshToken() ?: $persistedRefreshToken);
        $this->session->set('solid_token_expiry', $tokenExpiry);

        $idToken = $tokenSet->getIdToken();
        if (is_string($idToken) && $idToken !== '') {
            try {
                $idTokenClaims = $this->getVerifiedClaims($oidcClient, $tokenSet);
            } catch (\Facile\JoseVerifier\Exception\ExceptionInterface $e) {
                // @FIXME: Unsafe JWTs should only be enabled for specific servers, not all
                $idTokenClaims = Utility::decodeUnsafeJwt($idToken);
                // @KLUDGE: refreshed id_token verification failed: $e->getMessage(); continuing with unverified claims',
            }

            $refreshedWebId = $idTokenClaims['webid'] ?? $idTokenClaims['sub'] ?? null;
            if (is_string($refreshedWebId) && $refreshedWebId !== '') {
                $this->session->set('solid_webid', $refreshedWebId);
            }
        }

        return $accessToken;
    }

    private function saveOfflineGrant(IssuerInterface $issuer, $webIdUrl)
    {
        $session = $this->session;

        $snapshot = [
            DpopProofFactory::SESSION_KEY => $session->get(DpopProofFactory::SESSION_KEY),
            'saved_at' => time(),
            'solid_access_token' => $session->get('solid_access_token'),
            'solid_refresh_token' => $session->get('solid_refresh_token'),
            'solid_resource_url' => $session->get('solid_resource_url'),
            'solid_storage_root' => $session->get('solid_storage_root'),
            'solid_token_expiry' => $session->get('solid_token_expiry'),
            'solid_webid' => $session->get('solid_webid'),
        ];

        $grant = array_filter($snapshot, static function ($value): bool {
            return $value !== null && $value !== '';
        });

        $offlineGrantFile = $this->getGrantFilePath($issuer, $webIdUrl);

        $encode = json_encode($grant, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->filesystem->write($offlineGrantFile, $encode);
    }
}
