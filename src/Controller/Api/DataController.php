<?php

namespace Meent\WebHook\Controller\Api;

use EasyRdf\Graph;
use Meent\WebHook\Controller\ApiController;
use Meent\WebHook\Exception\SolidException;
use Meent\WebHook\Record;
use Psr\Http\Message\ServerRequestInterface;

class DataController extends ApiController
{
    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function handleRequest(ServerRequestInterface $request): array
    {
        $requestMethod = $request->getMethod();
        $version = $this->getRequestedVersion($request);

        $allowedMethods = ['POST'];
        if ($version >= 0.2) {
            $allowedMethods[] = 'GET';
        }

        switch ($requestMethod) {
            case 'GET':
                if ($version >= 0.2) {
                    $response = $this->handleGetRequest($request);
                    break;
                }
            // nobreak
            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;

            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = array_merge($allowedMethods, ['HEAD', 'OPTIONS']);
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;

            case 'POST':
                $input = file_get_contents('php://input');
                $response = $this->handlePostRequest($request, $input);
            break;
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function checkAuthorization(ServerRequestInterface $request): array
    {
        $auth = $request->getHeaderLine('Authorization');
        $apiKey = substr($auth, 7); // 7 chars = `Bearer `

        if (! isset($this->adminSession)) {
            throw new \RuntimeException('Cannot handle request before AdminSession is set');
        } elseif ($this->adminSession->isAuthenticated() === '') {
            $response = $this->errorResponse->unauthorized('Not authenticated', 'No active session found, please log in first');
        } elseif (empty($auth)) {
            $response = $this->errorResponse->unauthorized('Missing Authorization header', 'Authorization header is missing (or empty)');
        } elseif (! str_starts_with($auth, 'Bearer ')) {
            $response = $this->errorResponse->badRequest('Invalid Authorization header', 'Invalid Authorization header format, expected "Bearer {api-key}"', '#invalid-auth-header');
        } elseif (! $apiKey || $this->filesystem->fileExists('keys/' . $apiKey . '.key') === false) {
            $response = $this->errorResponse->unauthorized('Invalid API key', 'The provided API key is invalid');
        } else {
            $response = [];
        }

        return $response;
    }

    private function checkMac($mac, $apiKey): array
    {
        if (empty($mac)) {
            $response = $this->errorResponse->unauthorized('Missing MAC header', 'X-MAC-Address header is missing (or empty)');
        } elseif ($this->filesystem->fileExists('keys/' . $apiKey . '.mac') === false) {
            // @FIXME: How to check MAC against key?
            $response = $this->errorResponse->unauthorized('Invalid API key', 'The provided API key is invalid');
        } else {
            $response = [];
        }

        return $response;
    }

    private function handleGetRequest(ServerRequestInterface $request): array
    {
        $version = $this->getRequestedVersion($request);
        $queryParams = $request->getQueryParams();

        if ($version >= 0.4 && ! $request->getHeaderLine('Authorization') && ! isset($queryParams['webid'])) {
            $form = file_get_contents(__DIR__ . '/../../content/forms/data.html');

            $content = $this->createContent(
                'Post content',
                '<p>To write data to a Solid Pod, please provide authentication and data</p>',
                "<section>$form</section><section><output></output></section>",
                'forms/form.js',
            );

            return ['content' => $content, 'status' => 200];
        }

        if ($version >= 0.3) {
            $authError = $this->checkAuthorization($request);

            if ($authError !== []) {
                return $authError;
            } elseif ($version >= 0.4) {
                $auth = $request->getHeaderLine('Authorization');
                $apiKey = substr($auth, 7); // 7 chars = `Bearer `
            }
        }

        if (! empty($apiKey)) {
            $webIdUrl = $this->filesystem->read('keys/' . $apiKey . '.key');
        } elseif (isset($queryParams['webid'])) {
            $webIdUrl = $queryParams['webid'];
        } else {
            $webIdUrl = null;
        }

        $filePath = $this->getRequestedObject($request);

        if ($webIdUrl) {
            $storageFilePath = vsprintf('/storage-urls/%s.url', [
                'webIdHash' => $this->hashUrl($webIdUrl, 'sha1'),
            ]);
            $storageUrl = $this->filesystem->read($storageFilePath);

            if (empty($storageUrl)) {
                return $this->errorResponse->unprocessableEntity('No Storage URL', 'No Storage URL found for the WebID, cannot read data from Solid Pod');
            }

            $resourceUrl = vsprintf('%s/%s', [
                'root' => $storageUrl,
                'path' => $filePath,
            ]);

            try {
                $solidResponse = $this->solidClient->fetchResource($webIdUrl, $resourceUrl);
            } catch (SolidException $e) {
                return $this->errorResponse->badGateway('Error fetching resource from Solid Pod', 'Error fetching resource from Solid Pod: ' . $e->getMessage(), '#solid-fetch-error');
            }

            return [
                'content' => $solidResponse->getBody()->getContents(),
                'status' => 200,
                'headers' => ['Content-Type' => [$solidResponse->getHeaderLine('Content-Type')]],
            ];
        }

        $isInvalidPath = ! str_contains($filePath, '/') || ! str_ends_with($filePath, '.data');

        if ($isInvalidPath) {
            $response = $this->errorResponse->badRequest('Invalid path', 'Invalid path');
        } elseif (! $this->filesystem->fileExists($filePath)) {
            $response = $this->errorResponse->notFound('Not found', "The requested resource '$filePath' was not found on this server.");
        } else {
            $response = [
                'content' => $this->filesystem->read($filePath),
                'status' => 200,
                'title' => 'File Contents',
            ];
        }

        return $response;
    }

    private function handlePostRequest(ServerRequestInterface $request, $input): array
    {
        $version = $this->getRequestedVersion($request);

        if ($version >= 0.3) {
            // When a request is received, it MUST have an "Authorization" header with a "Bearer" scheme:
            //      Authorization: Bearer {api-key}
            $authError = $this->checkAuthorization($request);

            if ($authError !== []) {
                return $authError;
            } else {
                $auth = $request->getHeaderLine('Authorization');
                $apiKey = substr($auth, 7); // 7 chars = `Bearer `

                if ($version >= 0.5) {
                    // To prevent API key brute force attack, the MAC address must also be provided
                    $mac = $request->getHeaderLine('X-MAC-Address');
                    $authError = $this->checkMac($mac, $apiKey);

                    if ($authError !== []) {
                        return $authError;
                    }
                }
            }
        }

        // @TODO: Convert to Linked-Data once ontology is decided upon
        if (empty($input)) {
            // @TODO: Validate that the incoming data format and content is correct.
            // For now, we'll accept any data that is not empty.
            // Later on actual validation of the incoming data will be needed
            // (otherwise we cannot convert it to Linked Data).
            return $this->errorResponse->unprocessableEntity('No data received', 'No data received');
        } else {
            // Check which Solid Pod to write to
            if (isset($apiKey)) {
                $webIdUrl = $this->filesystem->read('keys/' . $apiKey . '.key');
            }

            $message = 'Records written';

            if ($version >= 0.2) {
                $dateTime = new \DateTimeImmutable('now');
                $dateTime->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
                $timestamp = $dateTime->format('Ymd.His');

                $id = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
                if ($version >= 0.3) {
                    // When data is received, it is stored in `/{webid-hash}/{timestamp}.{id}.data`
                    $filePath = vsprintf('%s/%s.%s.data', [
                        'webIdHash' => $this->hashUrl($webIdUrl, 'sha1'),
                        'timestamp' => $timestamp,
                        $id,
                    ]);
                } else {
                    $filePath = "$timestamp.$id.data";
                }

                // Received data is written locally as-is
                $this->filesystem->write($filePath, $input);

                if ($version >= 0.4) {
                    try {
                        $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        return $this->errorResponse->unprocessableEntity('Invalid JSON', 'Provided data is not valid JSON: ' . $e->getMessage());
                    }

                    // The received JSON data MUST contain a "timestamp" value, which indicates the time the data was recorded.
                    // If we do not have a timestamp, the data can not be used in a time series, but it can also not be stored in the Pod
                    // as the timestamp is needed to build the resource path.
                    if (! isset($data['timestamp'])) {
                        return $this->errorResponse->unprocessableEntity('Missing timestamp', 'Missing required "timestamp" value in the provided data');
                    } elseif (! strtotime($data['timestamp']) && ! strtotime('@' . $data['timestamp'])) {
                        return $this->errorResponse->unprocessableEntity('Invalid timestamp', 'Provided "timestamp" is not a valid timestamp.');
                    } else {
                        $timestamp = $data['timestamp'];
                        if (is_numeric($timestamp)) {
                            $timestamp = '@' . $timestamp;
                        }

                        $dateTime = new \DateTimeImmutable($timestamp);
                        $dateTime->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
                        $timestamp = $dateTime->format('Ymd.His');

                        $storageFilePath = vsprintf('/storage-urls/%s.url', [
                            'webIdHash' => $this->hashUrl($webIdUrl, 'sha1'),
                        ]);

                        $storageUrl = $this->filesystem->read($storageFilePath);

                        if (empty($storageUrl)) {
                            return $this->errorResponse->unprocessableEntity('No Storage URL', 'No Storage URL found for the WebID, cannot store data in Solid Pod');
                        }

                        $url = vsprintf('%s/%s/%s/%s', [
                            'root' => $storageUrl,
                            'path' => 'MEENT/p1',
                            'container' => $dateTime->format('Ymd'),
                            'resource' => $timestamp . '.ttl',
                        ]);

                        try {
                            $graph = new Graph($url);
                            $record = new Record($graph);

                            $turtle = $record
                                ->populate((array) $data)
                                ->serialise('turtle')
                            ;
                        } catch (\Throwable $e) {
                            return $this->errorResponse->internalServerError('Data conversion error', 'Could not convert data to Turtle: ' . $e->getMessage());
                        }

                        try {
                            $result = $this->solidClient->storeResource($webIdUrl, $url, $turtle, 'text/turtle');
                        } catch (SolidException $e) {
                            return $this->errorResponse->badGateway('Solid write error', 'Could not write resource to Solid Pod: ' . $e->getMessage());
                        }

                        // Remove local copy, as the raw data has been saved in the Pod
                        try {
                            $this->filesystem->delete($filePath);
                        } catch (\Throwable $e) {
                            // We do not care if the delete fails, as the "retry" logic can handle this later
                            // The retry logic should first check if the file hasn't already been written to the remote.
                        }

                        // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                        $redirectUri = $url;
                    }
                } elseif ($version >= 0.3) {
                    // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                    $redirectUri = $this->getBaseUrl($request) . '/api/data/' . $filePath;
                } else {
                    $data = $input;
                    $message .= ' to ' . $filePath;
                    $statuscode = 201;
                }
            } else {
                $data = $input;
                $statuscode = 201;
            }

            // Return success
            /* @TODO: Add link to URL on Solid Pod . '' */

            if (isset($redirectUri)) {
                // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2);
                $response = ['content' => null, 'headers' => ['Location' => [$redirectUri]], 'status' => 201, 'title' => $message];
            } else {
                $response = ['content' => $data, 'status' => $statuscode, 'title' => $message];
            }
        }

        return $response;
    }
}
