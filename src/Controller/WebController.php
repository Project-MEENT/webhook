<?php

namespace Meent\WebHook\Controller;

use Psr\Http\Message\RequestInterface;

class WebController
{
    final public function handleRequest(RequestInterface $request, $response)
    {
        $requestUri = $request->getUri()->getPath();
        $acceptHeader = $request->getHeaderLine('Accept');

        $response['type'] = '/content/';
        $response['content'] = [];
        switch ($requestUri) {
            case '/':
            case '/index.html':
                if (str_contains($acceptHeader, 'application/json')) {
                    $response['title'] = 'EnergyID Webhook';
                } else {
                    $response['content'] = file_get_contents(__DIR__ . '/../index.html');
                }

            break;
        }

        return $response;
    }
}
