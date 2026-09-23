<?php

namespace Meent\WebHook\Controller\Api;

use Meent\WebHook\Controller\ApiController;
use Meent\WebHook\MacInformation;
use Psr\Http\Message\ServerRequestInterface;

class RegisterController extends ApiController
{
    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function handleRequest(ServerRequestInterface $request): array
    {
        $requestMethod = $request->getMethod();
        $queryParams = $request->getQueryParams();

        $version = $this->getRequestedVersion($request);

        if ($version > 0.2) {
            $allowedMethods = ['POST'];
            if ($version >= 0.4) {
                $allowedMethods[] = 'GET';
            }

            switch ($requestMethod) {
                case 'GET':
                    if ($version >= 0.4) {
                        $webIdUrl = $queryParams['webid'] ?? '';

                        $replace = ['{webid}' => $webIdUrl];
                        $formContents = file_get_contents(__DIR__ . '/../../content/forms/register.html');
                        $form = str_replace(array_keys($replace), $replace, $formContents);

                        $content = $this->createContent(
                            'Register Dongle',
                            '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>',
                            "<section>$form</section><section><output></output></section>",
                            'forms/form.js',
                        );

                        $response = ['content' => $content, 'status' => 200];
                    } else {
                        $response = $this->handleMethodNotAllowed($request, $allowedMethods);
                    }
                break;

                case 'PATCH':
                    $response = $this->handleMethodNotAllowed($request, $allowedMethods);
                break;

                case 'HEAD':
                case 'OPTIONS':
                    $response = $this->handleAllowedHttpMethods($allowedMethods);
                break;

                case 'POST':
                    $response = $this->handleRegisterPost($request);
                break;

                case 'PUT':
                    // @TODO: Add PUT method to update WebID (requires API key)
                    $response = $this->errorResponse->notImplemented('Method not implemented', "Method '{$request->getMethod()}' is not implemented, MUST be" . (count($allowedMethods) > 1 ? 'one of ' : '') . implode(', ', $allowedMethods));
                break;
            }
        } else {
            $response = $this->handleNotFound($request);
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function handleRegisterPost(ServerRequestInterface $request): array
    {
        $input = $request->getBody()->getContents();
        $version = $this->getRequestedVersion($request);

        $webId = trim($input);

        if (empty($webId)) {
            $response = $this->errorResponse->unprocessableEntity('No data received', 'No data received');
        } elseif (filter_var($webId, FILTER_VALIDATE_URL) === false) {
            $response = $this->errorResponse->unprocessableEntity('Invalid URL', "Provided WebID '$webId' is not a valid URL");
        } else {
            $webIdHash = $this->hashUrl($webId, 'sha1');
            $webIdExists = $this->filesystem->directoryExists($webIdHash);

            if ($webIdExists && $version <= 0.4) {
                $response = $this->errorResponse->conflict('WebID already registered', "The provided WebID '$webId' has already been registered");
            } else {
                $apiKey = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

                if ($version >= 0.5) {
                    // @FIXME: How can we check whether we ARE a trusted client?
                    //         Is it enought to check if there is a *.mac file with the webid in it?
                    //         Or should we create a webid-hash.mac file with the MAC in it (and check that)?
                    // If we are a trusted client, we are always "connected"
                    $secret = $request->getHeaderLine('X-Client-Secret');
                    $secretHash = hash_hmac('sha256', $secret, '', true);

                    if (empty($secret)) {
                        $response = $this->errorResponse->unauthorized('Missing secret header', 'X-Client-Secret header is missing (or empty)');
                    } else{
                        // @TODO: The MacInformation class should be injected, not created
                        $macInformation = new MacInformation($this->filesystem);
                        $macs = $macInformation->getMacsForWebId($webId);

                        if ($macs === []) {
                            $response = $this->errorResponse->notFound("Could not find MAC for given WebID '$webId'");
                        } elseif (count($macs) !== 1) {
                            return $this->errorResponse->conflict('Multiple MAC addresses for given WebID');
                        } elseif (! $this->filesystem->fileExists('keys/' . $macs[0] . '.secret')) {
                            $response = $this->errorResponse->notFound("Could not find secret for given MAC '$macs[0]'");
                        } elseif ($this->filesystem->read('keys/' . $macs[0] . '.secret') !== $secretHash) { // @TODO: Use hash_equals() for secret comparison
                            $response = $this->errorResponse->forbidden('Invalid secret', "Provided secret is not valid for given WebID '$webId'");
                        }
                    }
                } elseif ($version >= 0.4) {
                    $isConnected = $this->solidClient->isWebIdConnected($webId);

                    if (! $isConnected) {
                        $connectionUrl = $this->getBaseUrl() . '/api/consent?webid=' . urlencode($webId);
                        $response = $this->errorResponse->proxyAuthenticationRequired('WebID Authentication Required', "The provided WebID '$webId' is not yet connected. To connect this WebID, visit: $connectionUrl", '#webid-not-connected');
                        // @CHECKME: Add Location header?
                        // $response['headers']['Location'] = [$connectionUrl];
                    }
                }

                if (! isset($response)) {
                    // Authentication has succeeded. Reuse one key and consolidate legacy duplicates.
                    // @TODO: This should live in MacInformation class
                    $apiKeys = [];
                    foreach ($this->filesystem->listContents('keys') as $file) {
                        $path = $file->path();
                        if ($file->isFile() && str_ends_with($path, '.key')
                            && $this->filesystem->read($path) === $webId
                        ) {
                            $apiKeys[] = basename($path, '.key');
                        }
                    }

                    sort($apiKeys, SORT_STRING);
                    $alreadyRegistered = $webIdExists || $apiKeys !== [];
                    $apiKey = array_shift($apiKeys);

                    if ($apiKey === null) {
                        // Avoid accidental key collision
                        do {
                            $apiKey = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                        } while ($this->filesystem->fileExists('keys/' . $apiKey . '.key'));

                        $this->filesystem->write('keys/' . $apiKey . '.key', $webId);
                    }

                    foreach ($apiKeys as $duplicate) {
                        $this->filesystem->delete('keys/' . $duplicate . '.key');
                    }

                    if (! $webIdExists) {
                        $this->filesystem->createDirectory($webIdHash);
                    }

                    if ($alreadyRegistered) {
                        $response = [
                            'content' => ['api_key' => $apiKey, 'webid' => $webId],
                            'status' => 200,
                            'title' => 'WebID already registered',
                        ];
                    } else {
                        $response = [
                            'content' => ['api_key' => $apiKey, 'webid' => $webId],
                            'status' => 201,
                            'title' => 'WebID registered',
                        ];
                    }
                }
            }
        }

        return $response;
    }
}
