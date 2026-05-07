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

    final public function handleRequest(RequestInterface $request, $response)
    {
        $converter = $this->converter;

        $acceptHeader = $request->getHeaderLine('Accept');

        $response['type'] = '/docs/';
        $response['content'] = [];

        $subject = $this->getRequestedSubject($request);

        switch ($subject) {
            case self::SUBJECT_ROOT:
                $content = file_get_contents(__DIR__ . '/../../README.md');

                if (str_contains($acceptHeader, 'application/json')) {
                    $response['content'] = $content;
                    $response['title'] = 'MEENT Webhook Documentation';
                } else {
                    $readmeContent = $this->parseReadme($content, $response);

                    $template = $this->getContents('template');

                    $response['content'] = vsprintf($template, [
                        'footer' => '',
                        'header' => $converter->convert($readmeContent['description']),
                        'main' => '<section>' . $converter->convert($readmeContent['content']) . '</section>',
                        'script' => '',
                        'style' => 'h2 {width: 100%;}',
                        'title' => $readmeContent['title'],
                    ]);

                    $response['title'] = '';
                }
                break;

            case self::SUBJECT_CONTENT:
                if (str_contains($acceptHeader, 'application/json')) {
                    $response['title'] = 'MEENT Webhook';
                } else {
                    $contents = $this->getContents('index');
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

    private function parseReadme($markdown, $response)
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
