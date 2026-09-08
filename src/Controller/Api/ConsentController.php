<?php

namespace Meent\WebHook\Controller\Api;

use Meent\WebHook\Controller\ApiController;
use Psr\Http\Message\ServerRequestInterface;

class ConsentController extends ApiController
{
    final public function handleRequest(ServerRequestInterface $request): array
    {
        $requestMethod = $request->getMethod();
        $queryParams = $request->getQueryParams();
        $allowedMethods = ['GET', 'POST'];

        switch ($requestMethod) {
            case 'GET':
            case 'POST':
                // @FIXME: Try/Catch + Error handling

                $redirectUri = '';

                // Detect whether this request is the callback from the authorization server.
                // When the OP redirects back it always includes `code` (success) or `error` (failure).
                $isRedirect = isset($queryParams['code']);
                $webIdConnected = isset($queryParams['connected']);
                $webIdUrl = $request->getParsedBody()['webid']
                    ?? $queryParams['webid']
                    ?? $queryParams['connected']
                    ?? null
                ;

                if ( ! isset($this->session)) {
                    throw new \RuntimeException('Cannot handle request before Session is set');
                } elseif (isset($queryParams['error'])) {
                    $response = $this->errorResponse->badGateway('Provider Error', 'The Provider returned an error: "' . urldecode($queryParams['error']) . '"');
                } elseif ($isRedirect) {
                    $currentUrl = $request->getUri()->withFragment('')->withQuery('')->__toString();
                    $webIdUrl = $this->solidClient->handleRedirect($queryParams, $this->session, $currentUrl);

                    $storageUrls = $this->solidClient->fetchStorageUrls($webIdUrl);

                    if ($storageUrls === []) {
                        return $this->errorResponse->unprocessableEntity('No Storage URL',
                            'No Storage URL found for the WebID, cannot write data to Solid Pod');
                    // } else if (count($storageUrls) > 1) {
                    // @FIXME: Instead of using the first URL, the user should be asked which one to use
                    } else {
                        $storageUrl = reset($storageUrls);
                    }

                    $this->createContainer($storageUrl, $webIdUrl);

                    $redirectUri = $this->getBaseUrl($request) . '/api/consent?connected=' . urlencode($webIdUrl);
                } elseif (! $webIdUrl) {
                    $form = file_get_contents(__DIR__ . '/../../content/forms/consent.html');

                    $content = $this->createContent(
                        'Provide consent',
                        '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>',
                        "<section>$form</section><section><output></output></section>",
                        'forms/form.js',
                    );
                } elseif (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
                    $response = $this->errorResponse->unprocessableEntity('Invalid URL', "Provided WebID '$webIdUrl' is not a valid URL");
                } elseif ($webIdConnected || $this->solidClient->isWebIdConnected($webIdUrl)) {
                    $content = $this->createContent(
                        'Consent Provided',
                        "<p>Your P1 dongle can now be connected to your Solid Pod, using WebID <a href='$webIdUrl'>$webIdUrl</a></p>",
                    );
                } else {
                    $redirectUri = $this->solidClient->connectWebId($webIdUrl, $this->session);
                }

                // Create Response
                if (! empty($redirectUri)) {
                    $response = ['headers' => ['Location' => [$redirectUri]], 'status' => 302];
                } elseif (isset($content)) {
                    $response = ['content' => $content, 'status' => 200];
                }
            break;

            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;

            case 'DELETE':
            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;
        }

        return $response;
    }
}
