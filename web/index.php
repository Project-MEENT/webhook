<?php

use Laminas\Diactoros\ServerRequestFactory;

ob_start();

require_once __DIR__ . '/../vendor/autoload.php';

$request = ServerRequestFactory::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);
$uriRoot = '';
if (! empty($_SERVER['HTTP_HOST'])) {
    $uriRoot = 'https://' . $_SERVER['HTTP_HOST'];
}

$response = [
    'headers' => [],
    'status' => 200,
    'title' => '',
];

$path = $request->getUri()->getPath();
$requestMethod = $request->getMethod();

$acceptHeader = $request->getHeaderLine('Accept');
$queryParams = $request->getQueryParams();
if (isset($queryParams['accept'])) {
    $acceptHeader = $queryParams['accept'];
    unset($queryParams['accept']);
    $request = $request
        ->withQueryParams($queryParams)
        ->withHeader('Accept', $acceptHeader);
}

$accept = array_map(static function ($value) {
    // @TODO: Sort by quality?
    return explode(';', $value)[0];
}, explode(',', $acceptHeader));

switch ($accept[0]) {
    case 'application/json':
        $outputType = 'json';
    break;

    case 'application/xhtml+xml':
    case 'text/html':
    default:
        $outputType = 'html';
    break;
}

switch ($path) {
    case '/api/v0/':
        $response['content'] = "For more information, visit $uriRoot";
        $response['title'] = 'EnergyID Webhook';
        $response['type'] = $uriRoot;
    break;
    case '/api/v0/data/':
        switch ($requestMethod) {
            case 'GET':
            case 'PATCH':
            case 'PUT':
                $response['content'] = [[
                        'detail' => "Method $requestMethod is not allowed, MUST be POST",
                        'pointer' => '#method-not-allowed',
                    ],
                ];
                $response['status'] = 405;
                $response['title'] = 'Method not allowed';
                $response['type'] = '/errors/';
            break;

            case 'HEAD':
            case 'OPTIONS':
                $response['headers']['Access-Control-Allow-Methods'] = ['OPTIONS, HEAD, POST'];
                $response['status'] = 204;
            break;

            case 'POST':
                // Receive incoming data
                $input = file_get_contents('php://input');

                // Check Authentication

                // @TODO: Convert to Linked-Data once ontology is decided upon
                if (empty($input)) {
                    // @TODO: Validate that the incoming data format and content is correct.
                    // For now, we'll accept any data that is not empty.
                    // Later on actual validation of the incoming data will be needed
                    // (otherwise we cannot convert it to Linked Data.
                    $response['content'] = [[
                        'detail' => 'No data received',
                        'pointer' => '#no-data-received',
                    ]];
                    $response['status'] = 422;
                    $response['title'] = 'No data received';
                    $response['type'] = '/errors/';
                    break;
                }

                try {
                    $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    // Data is not JSON, write as-is
                    $data = $input;
                }


                // Check which Solid Pod to write to

                // Connect to Solid Pod (using ? see Solid Specs)

                // Write data to Solid Pod (@TODO: Decide on path / resource container)

                // Return success
                /* @TODO: Add link to URL on Solid Pod . '' */
                $response['content'] = $data;
                $response['status'] = 201;
                $response['title'] = 'Records written';
                $response['type'] = '/data/';
            break;
        }
    break;

    default:
        $response['content'] = [[
            'detail' => 'The requested resource "' . $path . '" was not found on this server.',
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
        $output = htmlentities(urldecode($output));
    }

    $response['content'] = [[
        'detail' => 'The response caused unexpected output: ' . $output,
        'pointer' => '#unexpected-output',
    ]];
    $response['status'] = 500;
    $response['title'] = 'Unexpected Output';
    $response['type'] = '/errors/';
}

$content = $response['content'];

if ($outputType === 'html') {
    $response['headers']['Content-Type'] = ['text/html; charset=utf-8'];

    if (is_array($content) || $response['title'] !== '') {
        $template = '<!-- @TODO: HTML CONTENT --> %s: <pre><code>%s</code></pre> %s';
        $body = '<pre><code>' . htmlentities(json_encode($content,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) . '</code></pre>';
        $content = vsprintf($template, [
            $response['title'] ?? 'Response',
            $body,
            $response['type'] ?? $request->getUri()->getPath(),
        ]);
    }
} else {
    $response['headers']['Content-Type'] = ['application/json'];

    if ($response['type'] === '/errors/') {
        $response['title'] = empty($response['title']) ? 'Error' : $response['title'];
        $response['headers']['Content-Type'] = ['application/problem+json'];
        $contentType = 'errors';
    } else {
        $contentType = 'data';
    }

    $body = [
        'type' => $response['type'],
        'title' => $response['title'] ?: $response['type'],
        $contentType => $response['content'],
    ];

    try {
        $content = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
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

array_walk($response['headers'], static function ($values, $name) {
    array_walk($values, static function ($value) use ($name) {
        header(sprintf('%s: %s', $name, $value), false);
    });
});

echo trim($content);
exit;
