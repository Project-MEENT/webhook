<?php

namespace Meent\WebHook\Solid;

use EasyRdf\Graph;
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
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;

class SolidClientFactory
{
    final public function create(RequestInterface $request): SolidClient
    {
        $httpClientConfig = [
            // Allow self-signed certificates for local development
            // 'verify' => false,
            // 'verify_host' => false,
            // 'verify_peer' => false,
        ];

        $storageLocation = __DIR__ . '/../../build/storage/';
        $dependencies = $this->createDependencies($httpClientConfig, $storageLocation);

        $config = $this->createConfig($request, $dependencies['filesystem']);

        return new SolidClient($config, $dependencies);
    }

    final public function createConfig(RequestInterface $request, FilesystemOperator $filesystem): array
    {
        // -----------------------------------------------------------------------------
        $clientConfigFile = 'client_metadata.json';

        $clientConfig = [];
        if ($filesystem->fileExists($clientConfigFile)) {
            try {
                $contents = json_decode($filesystem->read($clientConfigFile), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($contents)) {
                    $clientConfig = $contents;
                }
            } catch (\JsonException $e) {
                // Invalid persisted JSON; regenerate below.
            }
        }

        // Keep client_id only when explicitly configured (static registration or Client URI "${clientServer}/${clientConfigFile}")
        $clientId = $clientConfig['client_id'] ?? null;
        $clientName = $clientConfig['client_name'] ?? 'MEENT Solid P1 Dongle Webhook';
        if (isset($clientConfig['redirect_uris'])) {
            $clientRedirectUris = $clientConfig['redirect_uris'];
            $clientRedirectUri = reset($clientRedirectUris);
        } else {
            $clientRedirectUri = $request->getUri()->withFragment('')->withQuery('')->__toString();
            $clientRedirectUris = [ $clientRedirectUri ];
        }
        $clientSecret = $clientConfig['client_secret'] ?? Utility::base64UrlEncode(random_bytes(32));

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
        return [
            'client' => [
                'ConfigFile' => $clientConfigFile,
                'Id' => $clientId,
                'Name' => $clientName,
                'RedirectUri' => $clientRedirectUri,
                'RedirectUris' => $clientRedirectUris,
                'Secret' => $clientSecret,
            ],
            'state' => [
                'SigningKey' => $stateSigningKey,
                'TtlSeconds' => $stateTtlSeconds,
            ],
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
        // Initialise DPoP key pair — persisted to disk so the same key is reused across requests.
        // A single per-server key is valid: DPoP keys are client keys, not per-user.
        $dpopJwkFile = rtrim($storageLocation, '/') . '/dpop_jwk.json';

        if (file_exists($dpopJwkFile)) {
            $jwkData = json_decode(file_get_contents($dpopJwkFile), true, 512, JSON_THROW_ON_ERROR);
        }

        if (! isset($jwkData) || ! is_array($jwkData) || ! isset($jwkData['kty'])) {
            $jwk = JWKFactory::createECKey('P-256');
            file_put_contents($dpopJwkFile, json_encode($jwk->all(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $jwk = new JWK($jwkData);
        }

        $dpopProofFactory = new DpopProofFactory(
            $jwk,
            new JWSBuilder(new AlgorithmManager([new ES256()])),
            new CompactSerializer()
        );

        /*/ RFC9449 - DPoP - Section 5: DPoP proof is injected automatically by DpopAuthMethod /*/
        $methods = [
            new DpopAuthMethod(new ClientSecretBasic(), $dpopProofFactory),
            new DpopAuthMethod(new ClientSecretJwt(), $dpopProofFactory),
            new DpopAuthMethod(new ClientSecretPost(), $dpopProofFactory),
            new DpopAuthMethod(new None(), $dpopProofFactory),
            new DpopAuthMethod(new PrivateKeyJwt(), $dpopProofFactory),
            new DpopAuthMethod(new TLSClientAuth(), $dpopProofFactory),
            new DpopAuthMethod(new SelfSignedTLSClientAuth(), $dpopProofFactory),
        ];
        // Initialise DPoP key pair (persisted to disk so the same key is reused across requests).
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

        return [
            'authorizationService' => $authorizationService,
            'dpopProofFactory' => $dpopProofFactory,
            'filesystem' => $filesystem,
            'graph' => $graph,
            'httpClient' => $httpClient,
            'idTokenVerifierBuilder' => new IdTokenVerifierBuilder(),
            'issuerBuilder' => $issuerBuilder,
            'oidcClientBuilder' => $oidcClientBuilder,
            'registration' => $registration,
        ];
    }
}
