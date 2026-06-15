<?php

namespace Meent\WebHook;

use Psr\Http\Message\RequestInterface;

class DevelopmentMiddleware
{
    /**
     * To be able to use ".localhost" as TLD in local development, for instance
     * webhook.localhost, or id-a0b1c2d3e4f5.solid.localhost.
     *
     * Usually anything ending in .localhost gets routed to the local machine.
     *
     * This is what we want on the host, so all apps can be opened in the browser.
     *
     * However, this causes issues inside docker images, as they make calls to themselves
     * rather to another machine.
     *
     * To resove this, the CURLOPT_CONNECT_TO option is used to tell curl to
     * connect to the upstream host instead of the local host.
     */
    final public static function localhost(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $host = strtolower($request->getUri()->getHost());
                $parts = explode('.', $host);

                if (array_pop($parts) === 'localhost' && count($parts) > 0) {
                    $upstreamHost = array_pop($parts);
                    $port = $request->getUri()->getPort();

                    if (! is_int($port)) {
                        $port = $request->getUri()->getScheme() === 'https' ? 443 : 80;
                    }

                    $existingConnectTo = $options['curl'][CURLOPT_CONNECT_TO] ?? [];

                    if (! is_array($existingConnectTo)) {
                        $existingConnectTo = [$existingConnectTo];
                    }

                    $connectTo = vsprintf('%s:%d:%s:%d', [
                        $host,
                        $port,
                        $upstreamHost,
                        $port,
                    ]);

                    $mergedConnectTo = array_merge($existingConnectTo, [$connectTo]);
                    $uniqueConnectTo = array_unique($mergedConnectTo);

                    $options['curl'][CURLOPT_CONNECT_TO] = array_values($uniqueConnectTo);
                }

                return $handler($request, $options);
            };
        };
    }
}
