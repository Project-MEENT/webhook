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
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\RegistrationServiceBuilder;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilder;
use GuzzleHttp\Client as HttpClient;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use League\Flysystem\FilesystemOperator;
use MatthiasMullie\Scrapbook\Adapters\Flysystem;
use MatthiasMullie\Scrapbook\Adapters\MemoryStore;
use MatthiasMullie\Scrapbook\Psr16\SimpleCache;
use Meent\WebHook\Config;
use Psr\SimpleCache\CacheInterface;

class SolidClientFactory
{
    public const DPOP_JWK_FILE = 'dpop_jwk.json';

    private Config $config;
    private FilesystemOperator $filesystem;
    private OidcClientConfig  $oidcClientConfig;

    final public function __construct(
        Config $config,
        FilesystemOperator $filesystem,
        OidcClientConfig $oidcClientConfig,
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->oidcClientConfig = $oidcClientConfig;
    }

    final public function create(SolidClientConfig $solidClientConfig): SolidClient
    {
        $httpClientConfig = [
            // Allow self-signed certificates for local development
            // 'verify' => false,
            // 'verify_host' => false,
            // 'verify_peer' => false,
        ];

        $dpopJwkFile = self::DPOP_JWK_FILE;
        $useCsrf = true;
        $usePkce = true;

        $cache = $this->createCache($this->filesystem);

        $httpClient = new HttpClient($httpClientConfig);

        $issuerBuilder = $this->createIssuerBuilder($httpClient, $this->config, $cache);
        $dpopProofFactory = $this->createDpopProofFactory($this->filesystem, $dpopJwkFile);
        $dpopAuthMethodFactory = $this->createDpopAuthMethodFactory($dpopProofFactory);
        $oidcClientBuilder = $this->createOidcClientBuilder($dpopAuthMethodFactory, $httpClient);

        $registrationServiceBuilder = new RegistrationServiceBuilder();
        $registration = $registrationServiceBuilder->build();

        $authorizationService = $this->createAuthorizationServiceBuild($httpClient);

        return new SolidClient(
            $solidClientConfig,
            $this->oidcClientConfig,
            $authorizationService,
            $oidcClientBuilder,
            $dpopProofFactory,
            $this->filesystem,
            new Graph(),
            $httpClient,
            new IdTokenVerifierBuilder(),
            $issuerBuilder,
            $registration,
        );
    }

    private function createAuthorizationServiceBuild(HttpClient $httpClient): AuthorizationService
    {
        $authorizationServiceBuilder = new AuthorizationServiceBuilder();

        return $authorizationServiceBuilder
            ->setHttpClient($httpClient)
            ->build();
    }

    private function createCache(FilesystemOperator $filesystem): ?SimpleCache
    {
        if (class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\Flysystem')) {
            $store = new Flysystem($filesystem);
        } elseif (class_exists('\\MatthiasMullie\\Scrapbook\\Adapters\\MemoryStore')) {
            $store = new MemoryStore();
        } else {
            $store = null;
        }

        if ($store) {
            // simple-cache implementation
            $cache = new SimpleCache($store);
        } else {
            $cache = null;
        }

        return $cache;
    }

    private function createDpopAuthMethodFactory(DpopProofFactory $dpopProofFactory): AuthMethodFactory
    {
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
        return new AuthMethodFactory($methods);
    }

    private function createDpopProofFactory(FilesystemOperator $filesystem, string $dpopJwkFile): DpopProofFactory
    {
        $jwk = $this->createJwk($filesystem, $dpopJwkFile);

        return new DpopProofFactory(
            $jwk,
            new JWSBuilder(new AlgorithmManager([new ES256()])),
            new CompactSerializer()
        );
    }

    private function createIssuerBuilder(
        HttpClient $httpClient,
        Config $config,
        ?SimpleCache $cache,
    ): IssuerBuilder {
        $issuerBuilder = new IssuerBuilder();
        $jwksProviderBuilder = new JwksProviderBuilder();
        $metadataProviderBuilder = new MetadataProviderBuilder();

        $metadataProviderBuilder->setHttpClient($httpClient);
        $issuerBuilder = $issuerBuilder->setMetadataProviderBuilder($metadataProviderBuilder);

        if ($cache instanceof CacheInterface) {
            $metadataProviderBuilder->setCache($cache)->setCacheTtl($config->get(Config::METADATA_CACHE_TTL));
            // Do not cache JWKS in this PoC:
            // the local dev OP can rotate keys between runs, which causes false
            // "Invalid token signature" failures when stale JWK sets are reused.
            // $jwksProviderBuilder = $jwksProviderBuilder
            //     ->withCache($cache)
            //     ->withCacheTtl(86400)// Cache JWKS for 1 day
            // ;

            $issuerBuilder->setJwksProviderBuilder($jwksProviderBuilder);
        }

        return $issuerBuilder;
    }

    private function createJwk(FilesystemOperator $filesystem, string $dpopJwkFile): JWK
    {
        // RFC9449 - DPoP - Section 5.  DPoP Access Token Request
        // Initialise DPoP key pair — persisted to disk so the same key is reused across requests.
        // A single per-server key is valid: DPoP keys are client keys, not per-user.
        if ($filesystem->fileExists($dpopJwkFile)) {
            $json = $filesystem->read($dpopJwkFile);
            $jwkData = Utility::jsonDecode($json);
        }

        if (empty($jwkData) || ! is_array($jwkData) || ! isset($jwkData['kty'])) {
            $jwk = JWKFactory::createECKey('P-256');
            $jwkData = Utility::jsonEncode($jwk->all(), true);
            $filesystem->write($dpopJwkFile, $jwkData);
        } else {
            $jwk = new JWK($jwkData);
        }

        return $jwk;
    }

    private function createOidcClientBuilder(
        AuthMethodFactory $dpopAuthMethodFactory,
        HttpClient $httpClient
    ): ClientBuilder {
        $oidcClientBuilder = new ClientBuilder();

        return $oidcClientBuilder
            ->setAuthMethodFactory($dpopAuthMethodFactory)
            ->setHttpClient($httpClient);
    }
}
