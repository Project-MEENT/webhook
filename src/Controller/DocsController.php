<?php

namespace Meent\WebHook\Controller;

use League\CommonMark\ConverterInterface;
use Psr\Http\Message\RequestInterface;

class DocsController extends AbstractController
{
    private const SUBJECT_CONTENT = 'docs';
    private const SUBJECT_ERROR = 'errors';

    private ConverterInterface $converter;

    public function __construct(ConverterInterface $converter)
    {
        $this->converter = $converter;
    }

    final public function handleRequest(RequestInterface $request, array $response)
    {
        $converter = $this->converter;

        $acceptHeader = $request->getHeaderLine('Accept');

        $response['type'] = '/docs/';
        $response['content'] = [];

        $subject = $this->getRequestedSubject($request);

        switch ($subject) {
            case self::SUBJECT_ROOT:
                $fileContent = file_get_contents(__DIR__ . '/../../README.md');
                $markdown = $this->parseMarkdown($fileContent, $response);

                if (str_contains($acceptHeader, 'application/json')) {
                    $response['content'] = $fileContent;
                    $response['title'] = $markdown['title'];
                } else {
                    $content = [
                        'header' => $converter->convert($markdown['description']),
                        'main' => '<section>' . $converter->convert($markdown['content']) . '</section>',
                        'title' => $markdown['title'],
                    ];

                    $content = array_merge(self::EMPTY_CONTENT, $content);
                    $template = $this->getContents('template');
                    $response['content'] = vsprintf($template, $content);

                    $response['title'] = '';
                }
                break;

            case self::SUBJECT_CONTENT:
                $fileContent = file_get_contents(__DIR__ . '/../../docs/usage.md');
                $markdown = $this->parseMarkdown($fileContent, $response);

                if (str_contains($acceptHeader, 'application/json')) {
                    $response['content'] = [
                        'markdown' => $fileContent,
                        'html' => $converter->convert($fileContent)->getContent(),
                    ];
                    $response['title'] = $markdown['title'];
                } else {
                    $content = [
                        'header' => $converter->convert($markdown['description']),
                        'main' => '<section>' . $converter->convert($markdown['content']) . '</section>',
                        'title' => $markdown['title'],
                    ];

                    $content = array_merge(self::EMPTY_CONTENT, $content);
                    $template = $this->getContents('template');
                    $response['content'] = vsprintf($template, $content);

                    $response['title'] = '';
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

    private function parseMarkdown($markdown, $response)
    {
        $response['content'] = '';
        $response['description'] = '';
        $response['title'] = '';

        $lines = explode("\n", $markdown);

        foreach ($lines as $line) {
            if ($response['title'] === '' && str_starts_with($line, '# ')) {
                $response['title'] = substr($line, 2);
            } elseif ($response['content'] === '' && ! str_starts_with($line, '## ')) {
                $response['description'] .= $line . "\n";
            } else {
                $response['content'] .= $line . "\n";
            }
        }

        $replace =[
            './docs/usage.md' => '/docs/',
        ];

        $response['content'] = str_replace(array_keys($replace), $replace, $response['content']);

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
