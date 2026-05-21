<?php

namespace Meent\WebHook\Controller;

use League\CommonMark\ConverterInterface;
use Meent\WebHook\ErrorResponse;
use Psr\Http\Message\RequestInterface;

class DocsController extends AbstractController
{
    private const SUBJECT_CONTENT = 'docs';
    private const SUBJECT_ERROR = 'errors';

    private ConverterInterface $converter;

    final public function __construct(ConverterInterface $converter, ErrorResponse $errorResponse)
    {
        $this->converter = $converter;
        $this->errorResponse = $errorResponse;
    }

    final public function handleRequest(RequestInterface $request)
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
                    $content = [
                        'header' => $converter->convert($markdown['description']),
                        'main' => '<section>' . $converter->convert($markdown['content']) . '</section>',
                        'title' => $markdown['title'],
                    ];

                    $content = array_merge(self::EMPTY_CONTENT, $content);
                    $template = $this->getContents('template');
                    $response = ['content' => vsprintf($template, $content), 'status' => 200, 'title' => ''];
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
                    $content = [
                        'header' => $converter->convert($markdown['description']),
                        'main' => '<section>' . $converter->convert($markdown['content']) . '</section>',
                        'title' => $markdown['title'],
                    ];

                    $content = array_merge(self::EMPTY_CONTENT, $content);
                    $template = $this->getContents('template');
                    $response = ['content' => vsprintf($template, $content), 'status' => 200, 'title' => ''];
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

    private function getRequestedSubject(RequestInterface $request)
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
