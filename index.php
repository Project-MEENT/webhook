<?php

/**
 * In the finished product, the P1 dongle will write linked-data directly to the Solid Pod.
 *
 * However, for development purposes, we will create a webhook endpoint that the P1 dongle can write to.
 * This endpoint will then process the incoming data and write it to the Solid Pod on behalf of the user.
 *
 * This gives us a working space to develop an ontology in and provides a working example of the logic the dongle will eventually have to implement.
 *
 * As this webhook is only short-lived, it should not receive more than minimal attention.
 * It should also, where possible, mirror the behavior of the real endpoint (see the Solid Specs for more info).
 */

ob_start();

$response = [
    'body' => '',
    'headers' => [],
    'status' => 200,
];

switch ($_SERVER['REQUEST_URI'] ?? '') {
    case '/':
        $fileStream = fopen(__FILE__, 'rb');
        fseek($fileStream, __COMPILER_HALT_OFFSET__);
        $response['body'] = stream_get_contents($fileStream);
        break;
    case '/favicon.ico':
        header('Location: https://favicon-emoji.pother.ca/%F0%9F%AA%9D');
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
    //Assume error
    // @TODO: Add error handling. What should the response be?
}

http_response_code($response['status']);

array_walk($response['headers'], function ($header) {
    header($header);
});

echo trim($response['body']);
die();

__halt_compiler();
<!DOCTYPE html>
<html lang="en">
<meta charset="UTF-8">
<title>🕸️🪝</title>
<style>html {background: black}h1 {text-align: center}</style>
<h1>🕸️🪝</h1>
</html>
