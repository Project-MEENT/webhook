<?php

namespace Meent\WebHook\Controller;

use Meent\WebHook\Exception\RuntimeException;
use Psr\Http\Message\RequestInterface;

abstract class AbstractController
{
    protected const SUBJECT_ROOT = '__ROOT__';

    abstract public function handleRequest(RequestInterface $request, array $response);

    protected function getContents(string $subject)
    {
        $contentPath = __DIR__ . '/../content/' . $subject . '.html';

        $contents = file_get_contents($contentPath);

        if ($contents === false) {
            throw new RuntimeException('Error: Failed to read content for "' . $subject . '"');
        }

        return $contents;
    }

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

    final protected function handleMethodNotImplemented($response, RequestInterface $request, array $allowedMethods)
    {
        $response['content'] = [[
            'detail' => "Method '{$request->getMethod()}' is not implemented, MUST be"
                . (count($allowedMethods) > 1 ? 'one of ' : '')
                . implode(', ', $allowedMethods),
            'pointer' => '#method-not-implemented',
        ]];
        $response['status'] = 501;
        $response['title'] = 'Method Not Implemented';
        $response['type'] = '/errors/';

        return $response;
    }

    final protected function handleNotFound(RequestInterface $request, array $response)
    {
        $requestUri = $request->getUri()->getPath();

        $response['content'] = [
            [
                'detail' => "The requested resource '$requestUri' was not found on this server.",
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
