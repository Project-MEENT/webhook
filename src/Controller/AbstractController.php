<?php

namespace Meent\WebHook\Controller;

use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\RequestInterface;

abstract class AbstractController
{
    protected const SUBJECT_ROOT = '__ROOT__';

    abstract public function handleRequest(RequestInterface $request, $response);

    final protected function handleAllowedHttpMethods($response, $allowedMethods)
    {
        natcasesort($allowedMethods);

        $methods = implode(', ', $allowedMethods);
        $response['headers']['Allow'] = [$methods];
        $response['headers']['Access-Control-Allow-Methods'] = [$methods];
        $response['status'] = 204;

        return $response;
    }

    final protected function handleMethodNotAllowed($response, $request, $allowedMethods)
    {
        $requestMethod = $request->getMethod();

        $response['content'] = [
            [
                'detail' => "Method $requestMethod is not allowed, MUST be "
                    . (count($allowedMethods) > 1 ? 'one of ' : '')
                    . implode(', ', $allowedMethods),
                'pointer' => '#method-not-allowed',
            ]
        ];
        $response['status'] = 405;
        $response['title'] = 'Method not allowed';
        $response['type'] = '/errors/';

        return $response;
    }

    final protected function handleNotFound(RequestInterface $request, $response)
    {
        $requestUri = $request->getUri()->getPath();

        $response['content'] = [
            [
                'detail' => 'The requested resource "' . $requestUri . '" was not found on this server.',
                'pointer' => '#not-found',
            ]
        ];
        $response['status'] = 404;
        $response['title'] = 'Not found';
        $response['type'] = '/errors/';

        return $response;
    }

    final protected function splitUriPath(RequestInterface $request): array
    {
        $path = $request->getUri()->getPath();

        $allParts = explode('/', $path);
        $uriParts = array_filter($allParts);

        return array_values($uriParts);
    }
}
