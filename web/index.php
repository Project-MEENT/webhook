<?php

namespace Meent\WebHook;

use GuzzleHttp\HandlerStack;
use Laminas\Diactoros\ServerRequestFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Meent\WebHook\Controller\AdminController;
use Meent\WebHook\Controller\ApiController;
use Meent\WebHook\Controller\DocsController;
use Meent\WebHook\Solid\OidcClientConfig;
use Meent\WebHook\Solid\SolidClientConfig;
use Meent\WebHook\Solid\SolidClientFactory;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('session.serialize_handler', 'php_serialize');
ob_start();
session_set_save_handler(new \SessionHandler(), true);
session_start();

require_once __DIR__ . '/../vendor/autoload.php';

$config = Config::fromFile(__DIR__ . '/../config.php');

$httpClientConfig = [
    'headers' => [
        'User-Agent' => $config->get(Config::CLIENT_NAME),
    ]
];

if (getenv('ACCEPT_LOCALHOST_DOMAIN') !== '') {
    // Allow .localhost domains for local development with Docker Compose
    $httpClientConfig['handler'] = HandlerStack::create();
    $httpClientConfig['handler']->push(DevelopmentMiddleware::localhost());

    putenv('ACCEPT_SELF_SIGNED_CERTIFICATES=true');
}

if (getenv('ACCEPT_SELF_SIGNED_CERTIFICATES') !== '') {
    // Allow self-signed certificates for local development
    $httpClientConfig['verify'] = false;
    $httpClientConfig['verify_host'] = false;
    $httpClientConfig['verify_peer'] = false;
}

// Create FileSystems
$dataFileSystemAdapter = new LocalFilesystemAdapter($config->get('api_storage_path'));
$dataFilesystem = new Filesystem($dataFileSystemAdapter);

$clientFilesystemAdapter = new LocalFilesystemAdapter($config->get('solid_storage_path'));
$clientFilesystem = new Filesystem($clientFilesystemAdapter);

// Create PSR Request and Response objects
$request = ServerRequestFactory::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);

$errorResponse = new ErrorResponse();

$session = Session::current();
$adminSession = new AdminSession($session, $config->get(Config::ADMIN_WEBIDS));

$acceptHeader = $request->getHeaderLine('Accept');

$queryParams = $request->getQueryParams();
if (isset($queryParams['accept'])) {
    $acceptHeader = $queryParams['accept'];
    unset($queryParams['accept']);
    $request = $request
        ->withHeader('Accept', $acceptHeader)
        ->withQueryParams($queryParams);
}

if (isset($queryParams['api-key'])) {
    $apiKey = $queryParams['api-key'];
    unset($queryParams['api-key']);
    $request = $request
        ->withHeader('Authorization', 'Bearer ' . $apiKey)
        ->withQueryParams($queryParams);
}

$accept = array_map(static function ($value) {
    // @TODO: Sort by quality?
    return explode(';', $value)[0];
}, explode(',', $acceptHeader));

$outputType = match ($accept[0]) {
    'application/xhtml+xml', 'text/html' => 'html',
    'application/json' => 'json',
    default => 'json',
};

$pathParts = array_values(
    array_filter(
        explode('/', $request->getUri()->getPath())
    )
);
$rootPath = $pathParts[0] ?? '';

// Clean up no longer needed variables
unset($accept, $acceptHeader, $apiKey, $pathParts, $queryParams);

$baseUrl = $request->getUri()->withPath('')->withFragment('')->withQuery('');

if (! $clientFilesystem->fileExists(OidcClientConfig::METADATA_FILE)) {
    // Client metadata file not found, creating...
    $values = [
        OidcClientConfig::CLIENT_ID => (string) $baseUrl->withPath('/' . OidcClientConfig::METADATA_FILE),
        OidcClientConfig::CLIENT_NAME => $config->get(Config::CLIENT_NAME),
        OidcClientConfig::CLIENT_URI => (string) $baseUrl,
        OidcClientConfig::REDIRECT_URIS => [
            (string) $baseUrl->withPath('/api/consent'),
            (string) $baseUrl->withPath('/admin'),
        ],
    ];

    $initialAccessToken = $config->get(OidcClientConfig::INITIAL_ACCESS_TOKEN);
    if ($initialAccessToken !== null) {
        $clientMetadata[OidcClientConfig::INITIAL_ACCESS_TOKEN] = $initialAccessToken;
    }

    $oidcClientConfig = new OidcClientConfig($values);

    $clientMetadataString = json_encode($oidcClientConfig->toArray(),
        JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES // Don't escape slashes `/`.
    );

    $clientFilesystem->write(OidcClientConfig::METADATA_FILE, $clientMetadataString);
} else {
    $clientMetadataString = $clientFilesystem->read(OidcClientConfig::METADATA_FILE);
    $clientMetadata = json_decode($clientMetadataString, true, 512, JSON_THROW_ON_ERROR);

    $initialAccessToken = $config->get(OidcClientConfig::INITIAL_ACCESS_TOKEN);
    if ($initialAccessToken !== null) {
        $clientMetadata[OidcClientConfig::INITIAL_ACCESS_TOKEN] = $initialAccessToken;
    }

    $oidcClientConfig = OidcClientConfig::fromArray($clientMetadata);
}

switch ($rootPath) {
    case OidcClientConfig::METADATA_FILE:
        // Solid Client ID Document (Solid-OIDC §5).
        // @TODO: This should only happen when we are in Client Metadata Document "mode"
        //        (i.e. Static instead of Dynamic Client Registration "mode")
        // @TODO: Support content negotiation (JSONLD) `@context https://www.w3.org/ns/solid/oidc-context.jsonld`
        //
        // @FIXME: This breaks, as JSON output is wrapped in an envelope.
        //
        // How to resolve so the Content-Type is JSON but there is no envelope?
        //
        // Currently resolved by setting the Content-Type header and $outputType,
        // to avoid the envelope path AND have the correct Content-Type but this
        // feels hacky (i.e. using unexpected side effects).
        $response = [
            'content' => $clientFilesystem->read(OidcClientConfig::METADATA_FILE),
            'headers' => ['Content-Type' => ['application/json']],
            'status' => 200,
        ];
    break;

    case 'api':
        $solidClientConfig = new SolidClientConfig([
            SolidClientConfig::EXPIRATION_TIME => $config->get(Config::JWT_TTL),
            SolidClientConfig::REDIRECT_URI => $baseUrl->withPath('/api/consent'),
            SolidClientConfig::STATE_SIGNING_KEY => $config->get(Config::STATE_SIGNING_KEY),
        ]);

        $solidClientFactory = new SolidClientFactory($config, $clientFilesystem, $oidcClientConfig);
        $solidClient = $solidClientFactory->create($solidClientConfig, $httpClientConfig);

        $controller = new ApiController(
            $dataFilesystem,
            $solidClient,
            $errorResponse
        );

        $controller->setAdminSession($adminSession);
        $controller->setSession($session);

        try {
            $response = $controller->handleRequest($request);
        } catch (FilesystemException $exception) {
            $response = $errorResponse->internalServerError('Write Failed', 'Failed to write data: ' . $exception->getMessage());
        } catch (\Throwable $exception) {
            $response = $errorResponse->internalServerError('Unexpected Error', 'An unexpected error occurred: ' . $exception->getMessage());
        }
    break;

    case 'admin':
        $solidClientConfig = new SolidClientConfig([
            SolidClientConfig::EXPIRATION_TIME => $config->get(Config::JWT_TTL),
            SolidClientConfig::REDIRECT_URI => $baseUrl->withPath('/admin'),
            SolidClientConfig::STATE_SIGNING_KEY => $config->get(Config::STATE_SIGNING_KEY),
        ]);

        $solidClientFactory = new SolidClientFactory($config, $clientFilesystem, $oidcClientConfig);
        $solidClient = $solidClientFactory->create($solidClientConfig, $httpClientConfig);
        $webIdInformationService = new WebIdInformation($clientFilesystem, $dataFilesystem);

        $controller = new AdminController(
            $solidClient,
            $session,
            $adminSession,
            $webIdInformationService,
            $errorResponse,
            $config
        );

        $response = $controller->handleRequest($request);
    break;

    case '':
    case 'docs':
    case 'errors':
        $controller = new DocsController(
            new \League\CommonMark\GithubFlavoredMarkdownConverter(),
            $errorResponse
        );
        $response = $controller->handleRequest($request);
    break;

    default:
        $path = $request->getUri()->getPath();
        $response = $errorResponse->notFound('Not found',"The requested resource '$path' was not found on this server.");
    break;
}

$output = ob_get_clean();

if ($output) {
    if ($outputType === 'html') {
        $output = urldecode($output);
    }

    $response = $errorResponse->internalServerError('Unexpected Output', 'The response caused unexpected output: ' . $output);
}

$content = $response['content'] ?? null;

if (isset($response['headers']['Content-Type'])) {
    // As the content-type is already set, just output the provided $content.
    // @KLUDGE: See the @FIXME at the `switch ($rootPath)` case for OidcClientConfig::METADATA_FILE (+/- line 145)
} else if ($outputType === 'html') {
    $response['headers']['Content-Type'] = ['text/html; charset=utf-8'];

    if (is_array($content) || ! empty($response['title'])) {
        if (isset($response['type']) && $response['type'] === '/errors/') {
            $body = '<ul>';
            foreach ($content as $error) {
                $body .= vsprintf('<li><strong>%s</strong>: %s</li>', [
                    '<a href="' . htmlentities($response['type'] . $error['pointer']) . '">' . htmlentities($error['pointer']) . '</a>',
                    htmlentities(urldecode($error['detail'])),
                ]);
            }
            $body .= '</ul>';
        } else {
            $body = '<pre><code>' . htmlentities(json_encode($content,
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '</code></pre>';
        }

        $template = file_get_contents(__DIR__ . '/../src/content/template.html');

        $content = vsprintf($template, [
            'footer' => '<p>' . ($response['type'] ?? '/' . $request->getUri()->getPath() . '/') . '</p>',
            'header' => 'Webhook to write data from a P1 dongle to a Solid Pod.',
            'main' => '<section><h2>' . ($response['title'] ?? 'Response') . '</h2>' . $body . '</section>',
            'script' => '',
            'style' => 'h2 {width: 100%;}',
            'title' => 'MEENT Solid P1 Dongle Webhook',
        ]);
    }
} else {
    $response['headers']['Content-Type'] = ['application/json'];

    if (empty($response['type']) || $response['type'] === '/errors/') {
        $response['title'] = empty($response['title']) ? 'Error' : $response['title'];
        $response['headers']['Content-Type'] = ['application/problem+json'];
        $key = 'errors';
    } else {
        $key = 'data';
    }

    $body = [
        'type' => $response['type'] ?? '/errors/',
        'title' => $response['title'] ?? $response['type'],
    ];

    if (! empty($response['content'])) {
        $body[$key] = $response['content'];
    }

    try {
        $content = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (\JsonException $e) {
        $body = <<<'JSON'
        {
            "type": "%s",
            "title": "Response Encoding Failed",
            "errors": [{
                "detail": "Failed to convert response to JSON: %s",
                "pointer": "#response-encoding-failed"
             }]
        }
JSON;

        $content = vsprintf($body, [
            // @TODO: Encode message to not break JSON
            'json-error' => $e->getMessage(),
        ]);
        $response['headers']['Content-Type'] = ['application/problem+json'];
        $response['status'] = 500;
    }
}

http_response_code($response['status']);

if (! isset($response['headers']) || ! is_array($response['headers'])) {
    $response['headers'] = [];
}

array_walk($response['headers'], static function ($values, $name) {
    array_walk($values, static function ($value) use ($name) {
        header(sprintf('%s: %s', $name, $value), false);
    });
});
header_remove('X-Powered-By');
echo trim((string) $content);
exit;
