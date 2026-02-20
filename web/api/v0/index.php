<?php

ob_start();

$uriRoot = '';
if (! empty($_SERVER['HTTP_HOST'])) {
    $uriRoot = 'https://' . $_SERVER['HTTP_HOST'];
}

$response = [
    'body' => '',
    'headers' => [],
    'status' => 200,
];

switch ($_SERVER['REQUEST_URI'] ?? '') {
    case '/':
        // @TODO: Show information about the webhook, add content negotiation
        break;
    default:
        switch ($_SERVER['REQUEST_METHOD'] ?? '') {
            case 'GET':
            case 'PATCH':
            case 'PUT':
                $response['status'] = 405;
                $response['body'] = 'Method not allowed, MUST be POST';
                break;

            case 'HEAD':
            case 'OPTIONS':
                $response['headers']['Access-Control-Allow-Methods'] = 'OPTIONS, HEAD, POST';
                $response['status'] = 204;
                break;

            case 'POST':
                // Receive incoming data

                // Check Authentication

                // @TODO: Convert to Linked-Data once ontology is decided upon

                // Check which Solid Pod to write to

                // Connect to Solid Pod (using ? see Solid Specs)

                // Write data to Solid Pod (@TODO: Decide on path / resource container)
                break;
        }

        break;
}

$output = ob_get_clean();

if ($output) {
    $response['body'] = [
        'type' => $uriRoot . '/errors/',
        'title' => 'Unexpected Output',
        'errors' => [
            [
                'detail' => 'The response caused unexpected output:' . htmlentities($output),
                'pointer' => '#unexpected-output',
            ],
        ],
    ];

    $response['status'] = 500;
}

$body = $response['body'];

try {
    $body = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    if ($response['headers']['Content-Type'] !== 'application/problem+json') {
        $response['headers']['Content-Type'] = 'application/json';
    }
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

    $response['body'] = vsprintf($body, [
        'type' => $uriRoot . '/errors/',
        'json-error' => $e->getMessage(),
    ]);
    $response['headers']['Content-Type'] = 'application/problem+json';
    $response['status'] = 500;
}

http_response_code($response['status']);

array_walk($response['headers'], static function (string $value, string $key) {
    header("$key: $value");
});

echo trim($body);
