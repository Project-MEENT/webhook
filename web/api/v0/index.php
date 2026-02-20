<?php

ob_start();

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
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
        switch ($requestMethod) {
            case 'GET':
            case 'PATCH':
            case 'PUT':
                $response['body'] = [
                    'type' => $uriRoot . '/errors/',
                    'title' => 'Method not allowed',
                    'errors' => [
                        [
                            'detail' => "Method $requestMethod is not allowed, MUST be POST",
                            'pointer' => '#method-not-allowed',
                        ],
                    ],
                ];
                $response['headers']['Content-Type'] = 'application/problem+json';
                $response['status'] = 405;
                break;

            case 'HEAD':
            case 'OPTIONS':
                $response['headers']['Access-Control-Allow-Methods'] = 'OPTIONS, HEAD, POST';
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
                    $response['body'] = [
                        'type' => $uriRoot . '/errors/',
                        'title' => 'No data received',
                        'errors' => [
                            [
                                'detail' => 'No data received',
                                'pointer' => '#no-data-received',
                            ],
                        ],
                    ];
                    $response['headers']['Content-Type'] = 'application/problem+json';
                    $response['status'] = 422;
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
                $response['body'] = [
                    'type' => $uriRoot,
                    /* @TODO: Add link to URL on Solid Pod . ''*/
                    'title' => 'Records written',
                    'data' => $data,
                ];
                $response['status'] = 201;
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

    if (! isset($response['headers']['Content-Type'])) {
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
