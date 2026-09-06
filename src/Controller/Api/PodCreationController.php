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

                $response = $this->handleRegisterPost($request);
            break;
        }

        return $response;
    }

    private function handleRegisterPost(ServerRequestInterface $request): array
    {
        $input = $request->getBody()->getContents();

        $mac = trim($input);
        $filePath = 'keys/' . $mac . '.mac';

        if (empty($mac)) {
            $response = $this->errorResponse->unprocessableEntity('No data received', 'No data received');
        // @TODO: Check what the MAC format is we receive. If it matches PHP's filter_var, then we can use it.'
        // } elseif (filter_var($mac, FILTER_VALIDATE_MAC) === false) {
        //     $response = $this->errorResponse->unprocessableEntity('Invalid MAC', "Provided MAC Address '$mac' is not valid");
        } elseif ($this->filesystem->fileExists($filePath) === true) {
            $response = $this->errorResponse->conflict('MAC already registered', "The provided MAC Address '$mac' has already been registered");
        } else {
            try {
                $response = $this->httpClient->request('POST', '', [
                    'form_params' => ['password' => $mac],
                ]);
                $webId = $response->getHeaderLine('Location');
            } catch (\Throwable $e) {
                $response = $this->errorResponse->badGateway('Error creating Solid Pod', 'Error creating Solid Pod: ' . $e->getMessage(), '#solid-create-error');
            }

            if (empty($webId)) {
                var_dump($response);
                die;
            } else {
                var_dump($response->getBody()->getContents());
                die;
                $this->filesystem->write($filePath, $webId);

                $response = [
                    'content' => ['webid' => $webId],
                    'status' => 201,
                    'title' => 'Solid Pod created',
                ];
            }
        }

        return $response;
    }
}

