<?php

namespace Meent\WebHook\Controller;

use League\CommonMark\ConverterInterface;
use Meent\WebHook\ErrorResponse;
use Psr\Http\Message\ServerRequestInterface;

class DocsController extends AbstractController
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private const SUBJECT_CONTENT = 'docs';
    private const SUBJECT_ERROR = 'errors';

    private ConverterInterface $converter;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(ConverterInterface $converter, ErrorResponse $errorResponse)
    {
        $this->converter = $converter;
        $this->errorResponse = $errorResponse;
    }

    final public function handleRequest(ServerRequestInterface $request): array
    {
        $converter = $this->converter;

        $acceptHeader = $request->getHeaderLine('Accept');

        $subject = $this->getRequestedSubject($request);

        switch ($subject) {
            case self::SUBJECT_ROOT:
                $fileContent = file_get_contents(__DIR__ . '/../../README.md');
                $markdown = $this->parseMarkdown($fileContent);

                if (str_contains($acceptHeader, 'application/json')) {
                    $response = ['content' => $fileContent, 'status' => 200, 'title' => $markdown['title']];
                } else {
                    $content = $this->createContent(
                        $markdown['title'],
                        $converter->convert($markdown['description']),
                        '<section>' . $converter->convert($markdown['content']) . '</section>',
                    );
                    $response = ['content' => $content, 'status' => 200, 'title' => ''];
                }
            break;

            case self::SUBJECT_CONTENT:
                $fileContent = file_get_contents(__DIR__ . '/../../docs/usage.md');
                $markdown = $this->parseMarkdown($fileContent);

                if (str_contains($acceptHeader, 'application/json')) {
                    $response = [
                        'content' => [
                            'html' => $converter->convert($fileContent)->getContent(),
                            'markdown' => $fileContent,
                        ],
                        'status' => 200,
                        'title' => $markdown['title'],
                    ];
                } else {
                    $content = $this->createContent(
                        $markdown['title'],
                        $converter->convert($markdown['description']),
                        '<section>' . $converter->convert($markdown['content']) . '</section>',
                    );
                    $response = ['content' => $content, 'status' => 200, 'title' => ''];
                }
            break;

            case self::SUBJECT_ERROR:
                if (str_contains($acceptHeader, 'application/json')) {
                    $response = ['status' => 200, 'title' => 'Errors'];
                } else {
                    $contents = $this->getContents($subject);
                    $response = ['content' => $contents, 'status' => 200];
                }
            break;

            default:
                $response = $this->handleNotFound($request);
            break;
        }

        if (! isset($response['type'])) {
            $response['type'] = '/docs/';
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function parseMarkdown($markdown)
    {
        $response = ['content' => '', 'description' => '', 'title' => ''];

        $replace = [
            './docs/usage.md' => '/docs/',
        ];

        $lines = explode("\n", $markdown);

        foreach ($lines as $line) {
            if ($response['title'] === '' && str_starts_with($line, '# ')) {
                $response['title'] = substr($line, 2);
            } elseif ($response['content'] === '' && ! str_starts_with($line, '## ')) {
                $response['description'] .= $line . "\n";
            } else {
                $response['content'] .= str_replace(array_keys($replace), $replace, $line) . "\n";
            }
        }

        return $response;
    }

    private function getRequestedSubject(ServerRequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if (count($parts) === 0) {
            $subject = self::SUBJECT_ROOT;
        } elseif (count($parts) === 1) {
            $subject = $parts[0];
        } else {
            $subject = implode('/', $parts);
        }

        return $subject;
    }
}
