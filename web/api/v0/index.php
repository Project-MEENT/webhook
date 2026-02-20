<?php

ob_start();

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
                $response['headers'][] = 'Access-Control-Allow-Methods: OPTIONS, HEAD, POST';
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
    // Assume error
    // @TODO: Add error handling. What should the response be?
}

http_response_code($response['status']);

array_walk($response['headers'], function ($header) {
    header($header);
});

echo trim($response['body']);
