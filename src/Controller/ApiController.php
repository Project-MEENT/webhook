<?php

namespace Meent\WebHook\Controller;

use League\Flysystem\Filesystem;
use Psr\Http\Message\RequestInterface;

class ApiController
{
    private const API_DATA = 'data';
    private const API_ROOT = '__ROOT__';

    private const AVAILABLE_VERSIONS = [
        'v0.1',
        'v0.2',
    ];

    private Filesystem $filesystem;

    final public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    final public function handleRequest(RequestInterface $request, $response)
    {
        $response['type'] = '/api/';

        $subject = $this->getRequestedSubject($request);

        switch ($subject) {
            case '':
            case self::API_ROOT:
                $response = $this->handleRootRequest($request, $response);
            break;

            case self::API_DATA:
                $response = $this->handleDataRequest($request, $response);
            break;

            default:
                $response = $this->handleNotFound($request, $response);
            break;
        }

        return $response;
    }

    private function getLatestVersion()
    {
        $versions = self::AVAILABLE_VERSIONS;

        usort($versions, 'version_compare');

        return end($versions);
    }

    private function getRequestedObject(RequestInterface $request)
    {
        $subject = $this->getRequestedSubject($request);
        $path = $request->getUri()->getPath();

        $pathOffset = strpos($path, $subject) + strlen($subject);
        $object = substr($path, $pathOffset);

        return ltrim($object, '/');
    }

    private function getRequestedSubject(RequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw new \Exception('Invalid path');
        } elseif (count($parts) === 1) {
            $subject = self::API_ROOT;
        } else {
            $versions = self::AVAILABLE_VERSIONS;
            $versions[] = 'latest';
            $versions[] = 'v0';

            if (in_array($parts[1], $versions)) {
                $subject = $parts[2] ?? self::API_ROOT;
            } else {
                $subject = $parts[1];
            }
        }

        return $subject;
    }

    private function getRequestedVersion(RequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw new \Exception('Invalid path');
        } elseif (
            count($parts) === 1
            || ($parts[1] === 'v0' || $parts[1] === 'latest')
            || (! preg_match('/^v[0-9]+\.[0-9]+$/', $parts[1]))
        ) {
            $version = $this->getLatestVersion();
        } elseif (in_array($parts[1], self::AVAILABLE_VERSIONS)) {
            $version = $parts[1];
        } else {
            throw new \Exception('Invalid version');
        }

        return (float) ltrim($version, 'v');
    }

    private function handleAllowedHttpMethods($response,  $allowedMethods)
    {
        natcasesort($allowedMethods);

        $methods = implode(', ', $allowedMethods);
        $response['headers']['Allow'] = [$methods];
        $response['headers']['Access-Control-Allow-Methods'] = [$methods];
        $response['status'] = 204;

        return $response;
    }

    private function handleDataRequest(RequestInterface $request, $response)
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
                    $response = $this->handleDateGet($request, $response);
                    break;
                }
            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
            break;

            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = array_merge($allowedMethods, ['HEAD', 'OPTIONS']);
                $response = $this->handleAllowedHttpMethods($response, $allowedMethods);
            break;

            case 'POST':
                $input = file_get_contents('php://input');
                $response = $this->handleDataPost($request, $response, $input);
            break;
        }

        return $response;
    }

    private function handleDateGet(RequestInterface $request, $response)
    {
        $filePath = $this->getRequestedObject($request);

        $isValidPath = strpos($filePath, '/') === false || ! str_ends_with($filePath, '.data');
        if ($isValidPath) {
            $response['content'] = [[
                'detail' => 'Invalid path',
                'pointer' => '#invalid-path',
            ]];
            $response['status'] = 400;
            $response['title'] = 'Invalid path';
            $response['type'] = '/errors/';
        } elseif (! $this->filesystem->fileExists($filePath)) {
            $response['content'] = [[
                'detail' => "The requested resource '" . $filePath . "' was not found on this server.",
                'pointer' => '#not-found',
            ]];
            $response['status'] = 404;
            $response['title'] = 'Not found';
            $response['type'] = '/errors/';
        } else {
            $contents = $this->filesystem->read($filePath);
            $response['content'] = $contents;
            $response['status'] = 200;
            $response['title'] = 'File Contents';
        }

        return $response;
    }

    private function handleDataPost($request, $response, $input)
    {
        $version = $this->getRequestedVersion($request);

        // @TODO: Check Authentication

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
        } else {

            try {
                $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // Data is not JSON, write as-is
                $data = $input;
            }

            // Check which Solid Pod to write to

            // Connect to Solid Pod (using ? see Solid Specs)

            // Write data to Solid Pod (@TODO: Decide on path / resource container)
            $message = 'Records written';
            if ($version >= 0.2) {
                $timestamp = date('Ymd/His');
                $id = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
                $filePath = "$timestamp.$id.data";

                $message .= ' to ' . $filePath;

                $this->filesystem->write($filePath, $data);
            }

            // Return success
            /* @TODO: Add link to URL on Solid Pod . '' */
            $response['content'] = $data;
            $response['status'] = 201;
            $response['title'] = $message;
        }

        return $response;
    }

    private function handleMethodNotAllowed($response, $request, $allowedMethods)
    {
        $requestMethod = $request->getMethod();

        $response['content'] = [[
            'detail' => "Method $requestMethod is not allowed, MUST be "
                . (count($allowedMethods) > 1 ? 'one of ' : '')
                . implode(', ', $allowedMethods),
            'pointer' => '#method-not-allowed',
        ]];
        $response['status'] = 405;
        $response['title'] = 'Method not allowed';
        $response['type'] = '/errors/';

        return $response;
    }

    private function handleNotFound(RequestInterface $request, $response)
    {
        $requestUri = $request->getUri()->getPath();

        $response['content'] = [[
            'detail' => 'The requested resource "' . $requestUri . '" was not found on this server.',
            'pointer' => '#not-found',
        ]];
        $response['status'] = 404;
        $response['title'] = 'Not found';
        $response['type'] = '/errors/';

        return $response;
    }

    private function handleRootRequest(RequestInterface $request, $response)
    {
        $uriRoot = $request->getUri()->getScheme() . '://'
            . $request->getUri()->getHost()
            . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');

        $response['content'] = "For more information, visit $uriRoot";
        $response['title'] = 'EnergyID Webhook';

        return $response;
    }

    private function splitUriPath(RequestInterface $request): array
    {
        $path = $request->getUri()->getPath();

        $allParts = explode('/', $path);
        $uriParts = array_filter($allParts);

        return array_values($uriParts);
    }
}
