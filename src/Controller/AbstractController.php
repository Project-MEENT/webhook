<?php

namespace Meent\WebHook\Controller;

use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Exception\RuntimeException;
use Psr\Http\Message\ServerRequestInterface;

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

    abstract public function handleRequest(ServerRequestInterface $request);

    final protected function createContent(string $title, string $description, string $body = '', $scripts = null): string
    {
        $context = array_merge(self::EMPTY_CONTENT, [
            'header' => $description,
            'main' => $body,
            'title' => $title,
        ]);

        if( ! empty($scripts)) {

            if (is_string($scripts)) {
                $scripts = [$scripts];
            }

            array_walk($scripts, static function ($script) use (&$context) {
                $path = __DIR__ . '/../content/' . $script;

                if (! file_exists($path)) {
                    throw RuntimeException::create('Script file not found: ' . $path);
                }

                $contents = file_get_contents($path);
                $context['script'] .= "\n/*/ $script /*/\n$contents";
            });
        }

        $template = $this->getContents('template');

        return vsprintf($template, $context);
    }

    final protected function getContents(string $subject)
    {
        $contentPath = __DIR__ . '/../content/' . $subject . '.html';

        $contents = file_get_contents($contentPath);

        if ($contents === false) {
            throw RuntimeException::create('Error: Failed to read content for "' . $subject . '"');
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

    final protected function handleNotFound(ServerRequestInterface $request)
    {
        $requestUri = $request->getUri()->getPath();

        return $this->errorResponse->notFound('Not found',"The requested resource '$requestUri' was not found on this server.");
    }

    final protected function splitUriPath(ServerRequestInterface $request): array
    {
        $path = $request->getUri()->getPath();

        $allParts = explode('/', $path);
        $uriParts = array_filter($allParts);

        return array_values($uriParts);
    }
}
