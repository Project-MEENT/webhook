<?php

namespace Meent\WebHook;

trait UrlHashTrait
{
    final public function hashUrl($url, $algorithm)
    {
        $parts = parse_url($url);

        $data = $parts['scheme'] . ($parts['scheme'] === 'http' ? 's' : '')
            . '://'
            . $parts['host']
            . (array_key_exists('port', $parts) ? ':' . $parts['port'] : '')
            . (array_key_exists('path', $parts) ? rtrim($parts['path'], '/') : '');

        $data = strtolower($data);

        return hash($algorithm, $data);
    }
}
