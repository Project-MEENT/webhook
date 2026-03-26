<?php

namespace Meent\WebHook\Controller;

use Psr\Http\Message\RequestInterface;

class WebController extends AbstractController
{
    private const SUBJECT_CONTENT = 'content';
    private const SUBJECT_ERROR = 'errors';

    final public function handleRequest(RequestInterface $request, $response)
    {
        $acceptHeader = $request->getHeaderLine('Accept');

        $response['type'] = '/content/';
        $response['content'] = [];

        $subject = $this->getRequestedSubject($request);

        switch ($subject) {
            case self::SUBJECT_ROOT:
            case self::SUBJECT_CONTENT:
                if (str_contains($acceptHeader, 'application/json')) {
                    $response['title'] = 'EnergyID Webhook';
                } else {
                    $contents = $this->getContents($subject);
                    $response['content'] = $contents;
                }
            break;

            case self::SUBJECT_ERROR:
                if (str_contains($acceptHeader, 'application/json')) {
                    $response['title'] = 'Errors';
                } else {
                    $contents = $this->getContents($subject);
                    $response['content'] = $contents;
                }
            break;

            default:
                $response = $this->handleNotFound($request, $response);
            break;
        }

        return $response;
    }

    private function getContents(string $subject)
    {
        if ($subject === self::SUBJECT_ROOT || $subject = self::SUBJECT_CONTENT) {
            $subject = 'index';
        }

        $contentPath = __DIR__ . '/../content/' . $subject . '.html';

        $contents = file_get_contents($contentPath);

        if ($contents === false) {
            throw new \RuntimeException('Error: Failed to read content for "' . $subject . '"');
        }

        return $contents;
    }

    private function getRequestedSubject(RequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if (count($parts) === 0
            || (count($parts) === 1 && $parts[0] = self::SUBJECT_CONTENT)
        ) {
            $subject = self::SUBJECT_ROOT;
        } elseif (count($parts) === 1) {
            $subject = $parts[0];
        } else {
            $subject = implode('/', $parts);
        }

        return $subject;
    }
}
