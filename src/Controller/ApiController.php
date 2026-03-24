<?php

namespace Meent\WebHook\Controller;

use Psr\Http\Message\RequestInterface;

class ApiController
{
    final public function handleRequest(RequestInterface $request, $response)
    {
        $requestUri = $request->getUri()->getPath();
        $requestMethod = $request->getMethod();
        $uriRoot = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');

        switch ($requestUri) {
            case '':
            case '/api/v0/':
                $response['content'] = "For more information, visit $uriRoot";
                $response['title'] = 'EnergyID Webhook';
                $response['type'] = '/api/';
            break;
            case '/api/v0/data/':
                switch ($requestMethod) {
                    case 'GET':
                    case 'PATCH':
                    case 'PUT':
                        $response['content'] = [
                            [
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
                        } catch (\JsonException $e) {
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
                    break;
                }
            break;

            default:
                $response['content'] = [[
                    'detail' => 'The requested resource "' . $requestUri . '" was not found on this server.',
                    'pointer' => '#not-found',
                ]];
                $response['status'] = 404;
                $response['title'] = 'Not found';
                $response['type'] = '/errors/';
            break;
        }

        return $response;
    }
}
