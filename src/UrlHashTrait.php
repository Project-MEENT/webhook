<?php

namespace Meent\WebHook;

use Meent\WebHook\Exception\RuntimeException;

trait UrlHashTrait
{
    final public function hashUrl($url, $algorithm)
    {
        $data = $this->normalizeUrl($url);

        return hash($algorithm, $data);
    }

    public function normalizeUrl($url): string
    {
        if (! filter_var((string) $url, FILTER_VALIDATE_URL)) {
            throw RuntimeException::create("Provided URL '$url' is not valid");
        }

        $parts = parse_url($url);

        $data = $parts['scheme'] . ($parts['scheme'] === 'http' ? 's' : '')
            . '://'
            . $parts['host']
            . (array_key_exists('port', $parts) ? ':' . $parts['port'] : '')
            . (array_key_exists('path', $parts) ? rtrim($parts['path'], '/') : '')
        ;

        return strtolower($data);
    }
}
