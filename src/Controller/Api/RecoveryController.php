<?php

namespace Meent\WebHook\Controller\Api;

use Meent\WebHook\Controller\ApiController;
use Meent\WebHook\Exception\RuntimeException;
use Psr\Http\Message\ServerRequestInterface;

class RecoveryController extends ApiController
{
    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function handleRequest(ServerRequestInterface $request): array
    {
        $requestMethod = $request->getMethod();

        switch ($requestMethod) {
            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = ['POST'];
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;

            case 'POST':
                if (! isset($this->httpClient)) {
                    throw new \RuntimeException('Required dependency HTTP Client is not set');
                }

                $response = $this->handlePost($request);
            break;

            default:
                $response = $this->handleMethodNotAllowed($request, ['POST']);
            break;
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function handlePost(ServerRequestInterface $request): array
    {
        $webId = $request->getParsedBody()['webid']
            ?? $request->getQueryParams()['webid']
            ?? '';

        $webId = trim($webId);

        if (empty($webId)) {
            return $this->errorResponse->badRequest('Missing WebID','WebID is required to initiate admin authentication.');
        } elseif (! filter_var($webId, FILTER_VALIDATE_URL)) {
            $response = $this->errorResponse->unprocessableEntity('Invalid URL', "Provided WebID '$webId' is not a valid URL");
        } else {
            try {
                $grant = $this->recoverGrant($webId);
            } catch (\Throwable $e) {
                $response = $this->errorResponse->badGateway('Auth recovery failed', 'Could not recover auth for WebID ' . $webId . ': ' . $e->getMessage());
            }

            if (isset($grant)) {
                $this->solidClient->persistGrantForWebId($webId, $grant);

                $response = [
                    'content' => [
                        'webid' => $webId,
                        'message' => 'Auth recovered successfully.',
                    ],
                    'status' => 200,
                    'title' => 'Auth recovered',
                ];
            }
        }

        return $response;
    }

    /**
     * Recover a fresh offline grant for a WebID by asking the Solid Pod
     * provider to re-issue tokens for this client + WebID.
     *
     * This mirrors the write-time recovery in DataController::storeResource()
     * but is decoupled from a write, so it can be triggered on demand (e.g.
     * from the admin dashboard) for any registered WebID.
     */
    private function recoverGrant(string $webIdUrl): array
    {
        $response = $this->httpClient->request('POST', '', [
            'form_params' => [
                'client_id' => $this->solidClient->getClientId(),
                'web_id' => $webIdUrl,
            ],
        ]);

        $contents = $response->getBody()->getContents();
        $grant = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($grant) || empty($grant['access_token']) || empty($grant['refresh_token'])) {
            throw RuntimeException::create('Recovery response did not contain usable token(s).');
        }

        $recoveredGrant = [
            'solid_access_token' => $grant['access_token'],
            'solid_refresh_token' => $grant['refresh_token'],
            'solid_token_expiry' => time() + ($grant['expires_in'] ?? 3600),
            'solid_webid' => $grant['webId'] ?? $webIdUrl,
        ];

        if (isset($grant['refresh_expires_in'])) {
            $recoveredGrant['solid_refresh_token_expiry'] = time() + $grant['refresh_expires_in'];
        }

        return $recoveredGrant;
    }
}
