<?php

namespace Meent\WebHook;

use Laminas\Diactoros\ServerRequestFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Meent\WebHook\Controller\AdminController;
use Meent\WebHook\Solid\OidcClientConfig;
use Meent\WebHook\Solid\Session;
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

// Create FileSystems
$dataFileSystemAdapter = new LocalFilesystemAdapter($config->get('api_storage_path'));
$dataFilesystem = new Filesystem($dataFileSystemAdapter);

$clientFilesystemAdapter = new LocalFilesystemAdapter($config->get('solid_storage_path'));
$clientFilesystem = new Filesystem($clientFilesystemAdapter);

// Create PSR Request and Response objects
$request = ServerRequestFactory::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);

$errorResponse = new ErrorResponse();

$path = $request->getUri()->getPath();
$pathParts = array_values(array_filter(explode('/', $path)));
$rootPath = $pathParts[0] ?? '';

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

switch ($accept[0]) {
    case 'application/xhtml+xml':
    case 'text/html':
        $outputType = 'html';
    break;

    case 'application/json':
    default:
        $outputType = 'json';
    break;
}

switch ($rootPath) {
    case 'api':
        $clientRedirectUri = $request->getUri()->withFragment('')->withQuery('')->__toString();
        $solidClientFactory = new SolidClientFactory($config, $clientFilesystem, $clientRedirectUri);
        $controller = new \Meent\WebHook\Controller\ApiController($dataFilesystem, $solidClientFactory, $errorResponse);
        try {
            $response = $controller->handleRequest($request);
        } catch (FilesystemException $exception) {
            $response = $errorResponse->badGateway('Write Failed','Failed to write data: ' . $exception->getMessage());
        } catch (\Exception $exception) {
            $response = $errorResponse->internalServerError('Unexpected Error','An unexpected error occurred: ' . $exception->getMessage());
        }
    break;

    case 'admin':
        $clientRedirectUri = $request->getUri()->withFragment('')->withQuery('')->__toString();
        $solidClientFactory = new SolidClientFactory($config, $clientFilesystem, $clientRedirectUri);
        $solidClient = $solidClientFactory->create(SolidClientFactory::REQUIRE_NEW_AUTHENTICATION);

        $controller = new AdminController(
            $solidClient,
            Session::current(),
            $config->get(Config::KEY_ADMIN_WEBIDS),
            $errorResponse
        );

        $response = $controller->handleRequest($request);
    break;

    case '':
    case 'docs':
    case 'errors':
        $controller = new \Meent\WebHook\Controller\DocsController(new \League\CommonMark\GithubFlavoredMarkdownConverter(), $errorResponse);
        $response = $controller->handleRequest($request);
    break;

    default:
        $response['content'] = [[
            'detail' => "The requested resource '$path' was not found on this server.",
            'pointer' => '#not-found',
        ]];
        $response['status'] = 404;
        $response['type'] = '/errors/';
        $response['title'] = 'Not found';
    break;
}

$output = ob_get_clean();

if ($output) {
    if ($outputType === 'html') {
        $output = urldecode($output);
    }

    $response = $errorResponse->internalServerError('Unexpected Output','The response caused unexpected output: ' . $output);
}

$content = $response['content'] ?? null;

if ($outputType === 'html') {
    if (! isset($response['headers']['Content-Type'])) {
        $response['headers']['Content-Type'] = ['text/html; charset=utf-8'];
    }

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
        'title' => $response['title'] ?: $response['type'],
    ];

    if (! empty($response['content'])) {
        $body[$key] = $response['content'];
    }

    try {
        $content = json_encode($body, JSON_PRETTY_PRINT  | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
            'type' => '/errors/',
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
