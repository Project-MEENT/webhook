<?php

namespace Meent\WebHook\Controller;

use League\Flysystem\FilesystemOperator;
use Meent\WebHook\Exception;
use Meent\WebHook\Solid\Session;
use Meent\WebHook\Solid\SolidClientFactory;
use Meent\WebHook\UrlHashTrait;
use Psr\Http\Message\RequestInterface;

class ApiController extends AbstractController
{
    use UrlHashTrait;

    const AVAILABLE_SUBJECTS = [
        self::SUBJECT_CONSENT,
        self::SUBJECT_DATA,
        self::SUBJECT_REGISTER,
    ];

    private const AVAILABLE_VERSIONS = [
        'v0.1', // No-op
        'v0.2', // Store without API key
        'v0.3', // Store with registered API key
        'v0.4', // Provide consent
    ];

    private const SUBJECT_CONSENT = 'consent';
    private const SUBJECT_DATA = 'data';
    private const SUBJECT_REGISTER = 'register';

    private FilesystemOperator $filesystem;

    final public function __construct(FilesystemOperator $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    final public function handleRequest(RequestInterface $request, array $response)
    {
        $response['type'] = '/api/';

        $subject = $this->getRequestedSubject($request);
        $version = $this->getRequestedVersion($request);

        $response['headers']['API-Version'] = ["v$version"];

        switch ($subject) {
            case self::SUBJECT_CONSENT:
                if ($version >= 0.4) {
                    $response = $this->handleConsentRequest($request, $response);
                }
            break;
            case self::SUBJECT_DATA:
                $response = $this->handleDataRequest($request, $response);
            break;

            case self::SUBJECT_REGISTER:
                $response = $this->handleRegisterRequest($request, $response);
            break;

            case self::SUBJECT_ROOT:
                $response = $this->handleRootRequest($request, $response);
            break;

            default:
                $response = $this->handleNotFound($request, $response);
            break;
        }

        return $response;
    }

    private function checkAuthorization(RequestInterface $request, $response)
    {
        $auth = $request->getHeaderLine('Authorization');

        if (empty($auth)) {
            $response['content'] = [[
                'detail' => 'Missing API key',
                'pointer' => '#api-key-missing',
            ]];
            $response['status'] = 401;
            $response['title'] = 'Missing API key';
            $response['type'] = '/errors/';
        } elseif (! str_starts_with($auth, 'Bearer ')) {
            $response['content'] = [[
                'detail' => "Invalid Authorization header format, expected 'Bearer {api-key}'",
                'pointer' => '#invalid-auth-header',
            ]];
            $response['status'] = 400;
            $response['title'] = 'Invalid Authorization header';
            $response['type'] = '/errors/';
        } elseif ($this->filesystem->fileExists('keys/' . substr($auth, 7) . '.key') === false) {
            $response['content'] = [[
                'detail' => 'Invalid API key',
                'pointer' => '#invalid-api-key',
            ]];
            $response['status'] = 401;
            $response['title'] = 'The provided API key is invalid';
            $response['type'] = '/errors/';
        } else {
            $response = [];
        }

        return $response;
    }

    private function getBaseUrl(RequestInterface $request)
    {
        return $request->getUri()->getScheme() . '://'
            . $request->getUri()->getHost()
            . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
    }

    private function getLatestVersion()
    {
        $versions = self::AVAILABLE_VERSIONS;

        usort($versions, 'version_compare');

        return end($versions);
    }

    private function getRequestedObject(RequestInterface $request)
    {
        $subject = $this->getRequestedSubject($request);
        $path = $request->getUri()->getPath();

        $pathOffset = strpos($path, $subject) + strlen($subject);
        $object = substr($path, $pathOffset);

        return ltrim($object, '/');
    }

    private function getRequestedSubject(RequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw new Exception('Invalid path');
        } elseif (count($parts) === 1) {
            $subject = self::SUBJECT_ROOT;
        } else {
            $versions = self::AVAILABLE_VERSIONS;
            $versions[] = 'latest';
            $versions[] = 'v0';

            if (in_array($parts[1], $versions)) {
                $subject = $parts[2] ?? self::SUBJECT_ROOT;
            } else {
                $subject = $parts[1];
            }
        }

        return $subject;
    }

    private function getRequestedVersion(RequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw new Exception('Invalid path');
        } elseif (
            (count($parts) === 1 && ! $request->hasHeader('API-Version'))
            || (count($parts) > 1 && ($parts[1] === 'v0' || $parts[1] === 'latest'))
            || (count($parts) > 1 && in_array($parts[1], self::AVAILABLE_SUBJECTS, true))
        ) {
            $version = $this->getLatestVersion();
        } elseif (count($parts) > 1 && in_array($parts[1], self::AVAILABLE_VERSIONS)) {
            $version = $parts[1];
        } elseif (
            ($request->hasHeader('API-Version') && in_array($request->getHeaderLine('API-Version'), self::AVAILABLE_VERSIONS))
            || ($request->hasHeader('API Version') && in_array($request->getHeaderLine('API Version'), self::AVAILABLE_VERSIONS))
        ) {
            $version = ltrim($request->getHeaderLine('API-Version'), 'v');
        } elseif (in_array($parts[1], self::AVAILABLE_SUBJECTS, true)) {
            $version = $this->getLatestVersion();
        } else {
            throw new Exception('Invalid version');
        }

        return (float) ltrim($version, 'v');
    }

    private function handleConsentRequest(RequestInterface $request, $response)
    {
        $requestMethod = $request->getMethod();
        $queryParams = $request->getQueryParams();
        $allowedMethods = ['GET', 'POST'];

        switch ($requestMethod) {
            case 'GET':
            case 'POST':
                // @FIXME: Try/Catch + Error handling

                $showForm = false;

                // Detect whether this request is the callback from the authorization server.
                // When the OP redirects back it always includes `code` (success) or `error` (failure).
                $isRedirect = isset($queryParams['code']) || isset($queryParams['error']);
                $webIdUrl = $request->getParsedBody()['webid'] ?? $queryParams['webid'] ?? '';

                $solidClientFactory = new SolidClientFactory();
                $solidClient = $solidClientFactory->create($request);

                if ($isRedirect) {
                    $issuerUrl = $solidClient->handleRedirect($queryParams, Session::current());
                    // @TODO: Redirect to self with ?webid=$webId to remove token query-params
                } elseif ($webIdUrl !== '') {
                    $redirectAuthorizationUri = $solidClient->connectWebId($webIdUrl, Session::current());
                } else {
                    $showForm = true;
                }

                // Create Response
                if (! empty($redirectAuthorizationUri)) {
                    $response['status'] = 302;
                    $response['headers']['Location'] = [$redirectAuthorizationUri];
                } else {
                    $template = $this->getContents('template');

                    $content = [
                        'footer' => '',
                        'header' => '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>',
                        'main' => '<section></section>',
                        'script' => '',
                        'style' => "h2 { width: 100%; }\ntitle { display: inline; }",
                        'title' => 'Provide consent',
                    ];

                    if ($showForm) {
                        $content['main'] = <<<'HTML'
                <section>
                    <form enctype="application/x-www-form-urlencoded" method="POST">
                    <fieldset><legend>WebID URL</legend>
                        <label>Please provide the URL of your Solid WebID:
                            <input
                                name="webid"
                                placeholder="https://idp.example.com/"
                                required
                                type="url"
                                value=""
                            />
                        </label>
                    </fieldset>
                        <button>Connect</button>
                    </form>
                </section>
HTML;
                    } else {
                        $content['header'] = '<p>Your P1 dongle can now be connected to your Solid Pod</p>';
                        $content['title'] = 'Consent Provided';
                    }

                    $response['content'] = vsprintf($template, $content);
                }
            break;

            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($response, $allowedMethods);
            break;

            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
            break;
        }

        return $response;
    }

    private function handleDataRequest(RequestInterface $request, $response)
    {
        $requestMethod = $request->getMethod();
        $version = $this->getRequestedVersion($request);

        $allowedMethods = ['POST'];
        if ($version >= 0.2) {
            $allowedMethods[] = 'GET';
        }

        switch ($requestMethod) {
            case 'GET':
                if ($version >= 0.2) {
                    $response = $this->handleDataGet($request, $response);
                    break;
                }
            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
            break;

            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = array_merge($allowedMethods, ['HEAD', 'OPTIONS']);
                $response = $this->handleAllowedHttpMethods($response, $allowedMethods);
            break;

            case 'POST':
                $input = file_get_contents('php://input');
                $response = $this->handleDataPost($request, $response, $input);
            break;
        }

        return $response;
    }

    private function handleDataGet(RequestInterface $request, $response)
    {
        $version = $this->getRequestedVersion($request);


        if ($version >= 0.3) {
            $authError = $this->checkAuthorization($request, $response);

            if ($authError !== []) {
                return $authError;
            }
        }

        $filePath = $this->getRequestedObject($request);

        $isValidPath = strpos($filePath, '/') === false || ! str_ends_with($filePath, '.data');
        if ($isValidPath) {
            $response['content'] = [[
                'detail' => 'Invalid path',
                'pointer' => '#invalid-path',
            ]];
            $response['status'] = 400;
            $response['title'] = 'Invalid path';
            $response['type'] = '/errors/';
        } elseif (! $this->filesystem->fileExists($filePath)) {
            $response['content'] = [[
                'detail' => "The requested resource '$filePath' was not found on this server.",
                'pointer' => '#not-found',
            ]];
            $response['status'] = 404;
            $response['title'] = 'Not found';
            $response['type'] = '/errors/';
        } else {
            $contents = $this->filesystem->read($filePath);
            $response['content'] = $contents;
            $response['status'] = 200;
            $response['title'] = 'File Contents';
        }

        return $response;
    }

    private function handleDataPost(RequestInterface $request, $response, $input)
    {
        $version = $this->getRequestedVersion($request);

        if ($version >= 0.3) {
            // When a request is received, it MUST have an "Authorization" header with a "Bearer" scheme:
            //      Authorization: Bearer {api-key}
            $authError = $this->checkAuthorization($request, $response);

            if ($authError !== []) {
                return $authError;
            } else {
                $auth = $request->getHeaderLine('Authorization');
                $apiKey = substr($auth, 7);
            }
        }

        // @TODO: Convert to Linked-Data once ontology is decided upon
        if (empty($input)) {
            // @TODO: Validate that the incoming data format and content is correct.
            // For now, we'll accept any data that is not empty.
            // Later on actual validation of the incoming data will be needed
            // (otherwise we cannot convert it to Linked Data.
            $response['content'] = [[
                'detail' => 'No data received',
                'pointer' => '#no-data-received',
            ]];
            $response['status'] = 422;
            $response['title'] = 'No data received';
            $response['type'] = '/errors/';
        } else {
            // Data is written as-is
            $data = $input;

            // Check which Solid Pod to write to
            if (isset($apiKey)) {
                $webId = $this->filesystem->read('keys/' . $apiKey . '.key');
            }

            // Connect to Solid Pod (using ? see Solid Specs)

            // Write data to Solid Pod (@TODO: Decide on path / resource container)
            $message = 'Records written';
            if ($version >= 0.2) {
                $id = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
                if ($version >= 0.3) {
                    // When data is received, it is stored in `/{webid-hash}/{timestamp}.{id}.data`
                    $filePath = vsprintf("%s/%s.%s.data", [
                        'webIdHash' => $this->hashUrl($webId, 'sha1'),
                        'timestamp' => date('Ymd.His'),
                        $id,
                    ]);
                } else {
                    $timestamp = date('Ymd/His');
                    $filePath = "$timestamp.$id.data";
                }

                $this->filesystem->write($filePath, $data);

                if ($version >= 0.3) {
                    $url = $this->getBaseUrl($request) . '/api/data/' . $filePath;
                    $data = null;
                    // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                    $response['headers']['Location'] = [$url];
                } else {
                    $message .= ' to ' . $filePath;
                }
            }

            // Return success
            /* @TODO: Add link to URL on Solid Pod . '' */
            $response['content'] = $data;
            $response['status'] = 201;
            $response['title'] = $message;
        }

        return $response;
    }

    private function handleRegisterPost(RequestInterface $request, $response)
    {
        $input = $request->getBody()->getContents();
        $webId = trim($input);

        if (empty($webId)) {
            $response['content'] = [[
                'detail' => 'No data received',
                'pointer' => '#no-data-received',
            ]];
            $response['status'] = 422;
            $response['title'] = 'No data received';
            $response['type'] = '/errors/';
        } elseif (filter_var($webId, FILTER_VALIDATE_URL) === false) {
            $response['content'] = [[
                'detail' => "Provided WebID '$webId' is not a valid URL",
                'pointer' => '#invalid-url',
            ]];
            $response['status'] = 422;
            $response['title'] = 'Invalid URL';
            $response['type'] = '/errors/';
        } else {
            $webIdHash = $this->hashUrl($webId, 'sha1');
            $exists = $this->filesystem->directoryExists($webIdHash);

            if ($exists) {
                $response['content'] = [[
                    'detail' => "The provided WebID '$webId' has already been registered, use PUT for updates",
                    'pointer' => '#webid-already-registered',
                ]];
                $response['status'] = 409;
                $response['title'] = 'WebID already registered';
                $response['type'] = '/errors/';
            } else {
                $apiKey = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

                $filePath = 'keys/' . $apiKey . '.key';
                $this->filesystem->write($filePath, $webId);

                $this->filesystem->createDirectory($webIdHash);

                $response['content'] = [
                    'api_key' => $apiKey,
                    'webid' => $webId,
                ];
                $response['status'] = 201;
                $response['title'] = 'WebID registered';
            }
        }

        return $response;
    }

    private function handleRegisterRequest(RequestInterface $request, $response)
    {
        $requestMethod = $request->getMethod();
        $version = $this->getRequestedVersion($request);

        if ($version > 0.2) {
            $allowedMethods = ['POST'];

            switch ($requestMethod) {
                case 'GET':
                case 'PATCH':
                    $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
                break;

                case 'HEAD':
                case 'OPTIONS':
                    $response = $this->handleAllowedHttpMethods($response, $allowedMethods);
                break;

                case 'POST':
                    $response = $this->handleRegisterPost($request, $response);
                break;

                case 'PUT':
                    // @TODO: Add PUT method to update WebID (requires API key)
                    $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
                break;
            }
        } else {
            $response = $this->handleNotFound($request, $response);
        }

        return $response;
    }

    private function handleRootRequest(RequestInterface $request, $response)
    {
        $uriRoot = $this->getBaseUrl($request);

        $response['content'] = "For more information, visit $uriRoot";
        $response['title'] = 'MEENT Webhook';

        return $response;
    }
}
