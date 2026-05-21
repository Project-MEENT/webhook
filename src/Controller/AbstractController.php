<?php

namespace Meent\WebHook\Controller;

use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Exception\RuntimeException;
use Psr\Http\Message\RequestInterface;

abstract class AbstractController
{
    protected const SUBJECT_ROOT = '__ROOT__';
    protected const EMPTY_CONTENT = [
        'footer' => '',
        'header' => '',
        'main' => '',
        'script' => '',
        'style' => '',
        'title' => '',
    ];

    protected ErrorResponse $errorResponse;

    abstract public function handleRequest(RequestInterface $request);

    protected function getContents(string $subject)
    {
        $contentPath = __DIR__ . '/../content/' . $subject . '.html';

        $contents = file_get_contents($contentPath);

        if ($contents === false) {
            throw new RuntimeException('Error: Failed to read content for "' . $subject . '"');
        }

        return $contents;
    }

    final protected function handleAllowedHttpMethods($allowedMethods)
    {
        natcasesort($allowedMethods);

        $methods = implode(', ', $allowedMethods);

        return [
            'headers' => ['Allow' => [$methods], 'Access-Control-Allow-Methods' => [$methods]],
            'status' => 204,
        ];
    }

    final protected function handleMethodNotAllowed($request, $allowedMethods)
    {
        $requestMethod = $request->getMethod();

        return $this->errorResponse->methodNotAllowed('Method not allowed',"Method $requestMethod is not allowed, MUST be "
            . (count($allowedMethods) > 1 ? 'one of ' : '')
            . implode(', ', $allowedMethods),
        );
    }

    final protected function handleNotFound(RequestInterface $request)
    {
        $requestUri = $request->getUri()->getPath();

        return $this->errorResponse->notFound('Not found',"The requested resource '$requestUri' was not found on this server.");
    }

    final protected function splitUriPath(RequestInterface $request): array
    {
        $path = $request->getUri()->getPath();

        $allParts = explode('/', $path);
        $uriParts = array_filter($allParts);

        return array_values($uriParts);
    }
}
