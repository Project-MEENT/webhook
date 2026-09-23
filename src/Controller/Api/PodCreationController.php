<?php

namespace Meent\WebHook\Controller\Api;

use Meent\WebHook\Controller\ApiController;
use Psr\Http\Message\ServerRequestInterface;

class PodCreationController extends ApiController
{
    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function handleRequest(ServerRequestInterface $request): array
    {
        $requestMethod = $request->getMethod();
        $queryParams = $request->getQueryParams();

        $allowedMethods = ['GET', 'POST'];

        switch ($requestMethod) {
            case 'GET':
                $macAddress = $queryParams['mac'] ?? '';

                $replace = ['{mac}' => $macAddress];
                /*
                 * Colon after every four digits:       0000:0ABB:28FC
                 * Colon after every two digits:        00:00:0A:BB:28:FC
                 * Extra space after every four digits: 0000 0ABB 28FC
                 * Extra space after every two digits:  00 00 0A BB 28 FC
                 * Period after every four digits:      0000.0ABB.28FC
                 * Period after every two digits:       00.00.0A.BB.28.FC
                 * Without any separator:               00000ABB28FC
                 */
                $formContents = file_get_contents(__DIR__ . '/../../content/forms/pod-creation.html');
                $form = str_replace(array_keys($replace), $replace, $formContents);

                $content = $this->createContent(
                    'Create Solid Pod',
                    '<p>To create a Solid Pod, please provide the MAC address of your P1 Dongle</p>',
                    "<section>$form</section><section><output></output></section>",
                    'forms/form.js',
                );

                $response = ['content' => $content, 'status' => 200];
            break;

            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = array_merge($allowedMethods, ['HEAD', 'OPTIONS']);
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;

            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;

            case 'POST':
                if (! isset($this->httpClient)) {
                    throw new \RuntimeException('Required dependency HTTP Client is not set');
                }

                $response = $this->handlePost($request);
            break;
        }

        return $response;
    }

    private function handlePost(ServerRequestInterface $request): array
    {
        $input = $request->getBody()->getContents();

        $mac = trim($input);
        $secret = $request->getHeaderLine('X-Client-Secret');
        $secretHash = hash_hmac('sha256', $secret, '', true);

        $secretExists = $this->filesystem->fileExists('keys/' . $mac . '.secret');
        $macExists = $this->filesystem->fileExists('keys/' . $mac . '.mac');

        if (empty($mac)) {
            $response = $this->errorResponse->unprocessableEntity('No data received', 'No data received');
        // @TODO: Check what the MAC format is we receive. If it matches PHP's filter_var, then we can use it.'
        // } elseif (filter_var($mac, FILTER_VALIDATE_MAC) === false) {
        //     $response = $this->errorResponse->unprocessableEntity('Invalid MAC', "Provided MAC Address '$mac' is not valid");
        } elseif (empty($secret)) {
            $response = $this->errorResponse->unauthorized('Missing secret header', 'X-Client-Secret header is missing (or empty)');
        } elseif ($macExists) {
            if(! $secretExists) {
                $response = $this->errorResponse->notFound('Could not find secret for given MAC', "The provided MAC Address '$mac' has already been registered, but no secret is present");
            } elseif ($this->filesystem->read('keys/' . $mac . '.secret') !== $secretHash) {
                $response = $this->errorResponse->forbidden('Invalid secret', "Provided secret is not valid for given MAC '$mac'");
            } else {
                $webId = $this->filesystem->read('keys/' . $mac . '.mac');

                $response = [
                    'content' => ['webid' => $webId],
                    'status' => 200,
                    'title' => 'Solid Pod exists',
                ];
            }
        } else {
            try {
                $clientResponse = $this->httpClient->request('POST', '', [
                    'form_params' => [
                        'client_id' => $this->solidClient->getClientId(),
                        'password' => $mac,
                    ],
                ]);

                $contents = $clientResponse->getBody()->getContents();

                $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

                if (! isset($json['webId'], $json['storageUrl'], $json['access_token'], $json['refresh_token'])) {
                    $response = $this->errorResponse->badGateway(
                        'Error creating Solid Pod',
                        "Error creating Solid Pod: response does not contain 'webId', 'storageUrl', 'access_token' and 'refresh_token' keys: ".$contents,
                        '#solid-create-error'
                    );
                } else {
                    $grant = [
                        'solid_access_token' => $json['access_token'],
                        'solid_refresh_token' => $json['refresh_token'],
                        'solid_token_expiry' => time() + ($json['expires_in'] ?? 3600),
                        'solid_webid' => $json['webId'],
                        'saved_at' => time(),
                    ];

                    if (isset($json['refresh_expires_in'])) {
                        $grant['solid_refresh_token_expiry'] = time() + $json['refresh_expires_in'];
                    }

                    $this->solidClient->persistGrantForWebId($json['webId'], $grant);

                    $this->createContainer($json['storageUrl'], $json['webId']);

                    $this->filesystem->write('keys/' . $mac . '.mac', $json['webId']);
                    $this->filesystem->write('keys/' . $mac . '.secret', $secretHash);

                    $response = [
                        'content' => ['webid' => $json['webId']],
                        'status' => 201,
                        'title' => 'Solid Pod created',
                    ];
                }
            } catch (\Throwable $e) {
                $response = $this->errorResponse->badGateway('Error creating Solid Pod', 'Error creating Solid Pod: ' . $e->getMessage(), '#solid-create-error');
            }
        }

        return $response;
    }
}
