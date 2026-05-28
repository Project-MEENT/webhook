<?php

namespace Meent\WebHook\Controller;

use EasyRdf\Graph;
use League\Flysystem\FilesystemOperator;
use Meent\WebHook\AdminSession;
use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Exception;
use Meent\WebHook\Exception\SolidException;
use Meent\WebHook\Record;
use Meent\WebHook\Session;
use Meent\WebHook\Solid\SolidClient;
use Meent\WebHook\UrlHashTrait;
use Psr\Http\Message\RequestInterface;

class ApiController extends AbstractController
{
    use UrlHashTrait;

    private const AVAILABLE_SUBJECTS = [
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

    private AdminSession $adminSession;
    private FilesystemOperator $filesystem;
    private Session $session;
    private SolidClient $solidClient;

    final public function __construct(
        FilesystemOperator $filesystem,
        SolidClient $solidClient,
        Session $session,
        AdminSession $adminSession,
        ErrorResponse $errorResponse
    ) {
        $this->adminSession = $adminSession;
        $this->errorResponse = $errorResponse;
        $this->filesystem = $filesystem;
        $this->session = $session;
        $this->solidClient = $solidClient;
    }

    final public function handleRequest(RequestInterface $request)
    {
        try {
            $subject = $this->getRequestedSubject($request);
            $version = $this->getRequestedVersion($request);
        } catch (Exception $e) {
            return $this->errorResponse->notFound('API Version Not Found','API version not found: '. $e->getMessage() . '. MUST be one of ' . implode(', ', self::AVAILABLE_VERSIONS), '#invalid-api-version');
        }

        switch ($subject) {
            case self::SUBJECT_CONSENT:
                if ($version >= 0.4) {
                    $response = $this->handleConsentRequest($request);
                } else {
                    $response = [
                        'status' => 200,
                        'title' => '',
                    ];
                }
            break;
            case self::SUBJECT_DATA:
                $response = $this->handleDataRequest($request);
            break;

            case self::SUBJECT_REGISTER:
                $response = $this->handleRegisterRequest($request);
            break;

            case self::SUBJECT_ROOT:
                $response = $this->handleRootRequest($request);
            break;

            default:
                $response = $this->handleNotFound($request);
            break;
        }

        $response['headers']['API-Version'] = ["v$version"];

        if (! isset($response['type'])) {
            $response['type'] = '/api/';
        }

        return $response;
    }

    private function checkAuthorization(RequestInterface $request)
    {
        $auth = $request->getHeaderLine('Authorization');

        if ($this->adminSession->isAuthenticated() !== '') {
            $response = [];
        } elseif (empty($auth)) {
            $response = $this->errorResponse->unauthorized('API key missing','Missing API key');
        } elseif (! str_starts_with($auth, 'Bearer ')) {
            $response = $this->errorResponse->badRequest('Invalid Authorization header',"Invalid Authorization header format, expected 'Bearer {api-key}'", '#invalid-auth-header',
            );
        } elseif ($this->filesystem->fileExists('keys/' . substr($auth, 7) . '.key') === false) {
            $response = $this->errorResponse->unauthorized('Invalid API key','The provided API key is invalid');
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

    private function handleConsentRequest(RequestInterface $request)
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
                    ?? null;

                if (isset($queryParams['error'])) {
                    $response = $this->errorResponse->badGateway('Provider Error','The Provider returned an error: "' . urldecode($queryParams['error']) . '"');
                } elseif ($isRedirect) {
                    $currentUrl = $request->getUri()->withFragment('')->withQuery('')->__toString();
                    $webIdUrl = $this->solidClient->handleRedirect($queryParams, $this->session, $currentUrl);
                    $redirectUri = $this->getBaseUrl($request) . '/api/consent?connected=' . urlencode($webIdUrl);
                } elseif (! $webIdUrl) {
                    $form = file_get_contents(__DIR__ . '/../content/forms/consent.html');

                    $content = $this->createContent(
                        'Provide consent',
                        '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>',
                        "<section>$form</section><section><output></output></section>",
                        'forms/form.js',
                    );
                } elseif (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
                    $response = $this->errorResponse->unprocessableEntity('Invalid URL',"Provided WebID '$webIdUrl' is not a valid URL");
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
                    $response = [
                        'status' =>  302,
                        'headers' => ['Location' => [$redirectUri]],
                    ];

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

    private function handleDataRequest(RequestInterface $request)
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
                    $response = $this->handleDataGet($request);
                    break;
                }
            case 'PATCH':
            case 'PUT':
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;

            case 'HEAD':
            case 'OPTIONS':
                $allowedMethods = array_merge($allowedMethods, ['HEAD', 'OPTIONS']);
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;

            case 'POST':
                $input = file_get_contents('php://input');
                $response = $this->handleDataPost($request, $input);
            break;
        }

        return $response;
    }

    private function handleDataGet(RequestInterface $request)
    {
        $version = $this->getRequestedVersion($request);
        $queryParams = $request->getQueryParams();

        if ($version >= 0.4 && ! $request->getHeaderLine('Authorization') && ! isset($queryParams['webid'])) {
            $form = file_get_contents(__DIR__ . '/../content/forms/data.html');

            $content = $this->createContent(
                'Post content',
                '<p>To write data to a Solid Pod, please provide authentication and data</p>',
                "<section>$form</section><section><output></output></section>",
                'forms/form.js',
            );

            $response = ['content' => $content, 'status' => 200];

            return $response;
        }

        if ($version >= 0.3) {
            $authError = $this->checkAuthorization($request);

            if ($authError !== []) {
                return $authError;
            } elseif ($version >= 0.4) {
                $auth = $request->getHeaderLine('Authorization');
                $apiKey = substr($auth, 7);
            }
        }

        $filePath = $this->getRequestedObject($request);
        if (! empty($apiKey)) {
            $webId = $this->filesystem->read('keys/' . $apiKey . '.key');
        } elseif (isset($queryParams['webid'])) {
            $webId = $queryParams['webid'];
        } else {
            $webId = null;
        }

        if ($webId) {
            $storageUrls = $this->solidClient->fetchStorageUrls($webId);

            if ($storageUrls !== []) {
                // @TODO: Instead of using the first URL, the user should be asked which one to use when registering
                $storageUrlRoot = reset($storageUrls);
                $storageUrl = rtrim($storageUrlRoot, '/');
            }

            if (empty($storageUrl)) {
                return $this->errorResponse->unprocessableEntity('No Storage URL','No Storage URL found for the WebID, cannot read data from Solid Pod');
            }

            $resourceUrl = vsprintf('%s/%s', [
                'root' => $storageUrl,
                'path' => $filePath,
            ]);

            try {
                $solidResponse = $this->solidClient->fetchResource($webId, $resourceUrl);
            } catch (SolidException $e) {
                return $this->errorResponse->badGateway('Error fetching resource from Solid Pod','Error fetching resource from Solid Pod: ' . $e->getMessage(), '#solid-fetch-error');
            }

            $response = [
                'content' => $solidResponse->getBody()->getContents(),
                'status' => 200,
                'headers' => ['Content-Type' => [$solidResponse->getHeaderLine('Content-Type')]],
            ];

            return $response;
        }

        $isInvalidPath = strpos($filePath, '/') === false || ! str_ends_with($filePath, '.data');
        if ($isInvalidPath) {
            $response = $this->errorResponse->badRequest('Invalid path','Invalid path');
        } elseif (! $this->filesystem->fileExists($filePath)) {
            $response = $this->errorResponse->notFound('Not found',"The requested resource '$filePath' was not found on this server.");
        } else {
            $response = [
                'content' => $this->filesystem->read($filePath),
                'status' => 200,
                'title' => 'File Contents',
            ];
        }

        return $response;
    }

    private function handleDataPost(RequestInterface $request, $input)
    {
        $version = $this->getRequestedVersion($request);

        if ($version >= 0.3) {
            // When a request is received, it MUST have an "Authorization" header with a "Bearer" scheme:
            //      Authorization: Bearer {api-key}
            $authError = $this->checkAuthorization($request);

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
            return $this->errorResponse->unprocessableEntity('No data received','No data received');
        } else {
            // Check which Solid Pod to write to
            if (isset($apiKey)) {
                $webId = $this->filesystem->read('keys/' . $apiKey . '.key');
            }

            $message = 'Records written';

            if ($version >= 0.2) {
                $dateTime = new \DateTimeImmutable('now');
                $dateTime->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
                $timestamp = $dateTime->format('Ymd.His');

                $id = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
                if ($version >= 0.3) {
                    // When data is received, it is stored in `/{webid-hash}/{timestamp}.{id}.data`
                    $filePath = vsprintf("%s/%s.%s.data", [
                        'webIdHash' => $this->hashUrl($webId, 'sha1'),
                        'timestamp' => $timestamp,
                        $id,
                    ]);
                } else {
                    $filePath = "$timestamp.$id.data";
                }

                // Received data is written locally as-is
                $this->filesystem->write($filePath, $input);

                if ($version >= 0.4) {
                    try {
                        $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        return $this->errorResponse->unprocessableEntity('Invalid JSON','Provided data is not valid JSON: ' . $e->getMessage());
                    }

                    // The received JSON data MUST contain a "timestamp" value, which indicates the time the data was recorded.
                    // If we do not have a timestamp, the data can not be used in a time series, but it can also not be stored in the Pod
                    // as the timestamp is needed to build the resource path.
                    if (! isset($data['timestamp'])) {
                        return $this->errorResponse->unprocessableEntity('Missing timestamp','Missing required "timestamp" value in the provided data');
                    } elseif (! strtotime($data['timestamp']) && ! strtotime('@'.$data['timestamp'])) {
                        return $this->errorResponse->unprocessableEntity('Invalid timestamp','Provided "timestamp" is not a valid timestamp.');
                    } else {
                        $timestamp = $data['timestamp'];
                        if (is_numeric($timestamp)) {
                            $timestamp = '@' . $timestamp;
                        }
                        $dateTime = new \DateTimeImmutable($timestamp);
                        $dateTime->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
                        $timestamp = $dateTime->format('Ymd.His');

                        // @FIXME: Read StorageUrl from persistent configuration instead of resolving it on every request.
                        if (empty($storageUrl)) {
                            $storageUrls = $this->solidClient->fetchStorageUrls($webId);

                            if ($storageUrls !== []) {
                                // @KLUDGE: As there is no user available here, we cannot ask them which storage to use
                                $storageUrlRoot = reset($storageUrls);
                                $storageUrl = rtrim($storageUrlRoot, '/');
                                // @FIXME: Store $storageUrl (where?)
                            }

                            if (empty($storageUrl)) {
                                return $this->errorResponse->unprocessableEntity('No Storage URL','No Storage URL found for the WebID, cannot store data in Solid Pod');
                            }
                        }

                        $url = vsprintf('%s/%s/%s/%s', [
                            'root' => $storageUrl,
                            'path' => 'MEENT/p1',
                            'container' => $dateTime->format('Ymd'),
                            'resource' => $timestamp . '.ttl',
                        ]);

                        try {
                            $graph = new Graph($url);
                            $record = new Record($graph);

                            $turtle = $record
                                ->populate((array) $data)
                                ->serialise('turtle')
                            ;
                        } catch (\Exception $e) {
                            return $this->errorResponse->internalServerError('Data conversion error','Could not convert data to Turtle: ' . $e->getMessage());
                        }

                        try {
                            $result = $this->solidClient->storeResource($webId, $url, $turtle, 'text/turtle');
                        } catch (SolidException $e) {
                            return $this->errorResponse->badGateway('Solid write error','Could not write resource to Solid Pod: ' . $e->getMessage());
                        }

                        if (isset($result)) {
                            // Remove local copy, as the raw data has been saved in the Pod
                            try {
                                $this->filesystem->delete($filePath);
                            } catch (\Exception $e) {
                                // We do not care if the delete fails, as the "retry" logic can handle this later
                                // The retry logic should first check if the file hasn't already been written to the remote.
                            }

                            // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                            $redirectUri = $url;
                        }
                    }
                } elseif ($version >= 0.3) {
                    // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                    $redirectUri = $this->getBaseUrl($request) . '/api/data/' . $filePath;
                } else {
                    $data = $input;
                    $message .= ' to ' . $filePath;
                    $statuscode = 201;
                }
            } else {
                $data = $input;
                $statuscode = 201;
            }

            // Return success
            /* @TODO: Add link to URL on Solid Pod . '' */

            if (isset($redirectUri)) {
                // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2);
                $response = ['content' => null, 'headers' => ['Location' => [$redirectUri]], 'status' => 201, 'title' => $message];
            } else {
                $response = ['content' => $data, 'status' => $statuscode, 'title' => $message];
            }
        }

        return $response;
    }

    private function handleRegisterPost(RequestInterface $request)
    {
        $input = $request->getBody()->getContents();
        $version = $this->getRequestedVersion($request);

        $webId = trim($input);

        if (empty($webId)) {
            $response = $this->errorResponse->unprocessableEntity('No data received','No data received');
        } elseif (filter_var($webId, FILTER_VALIDATE_URL) === false) {
            $response = $this->errorResponse->unprocessableEntity('Invalid URL',"Provided WebID '$webId' is not a valid URL");
        } else {
            $webIdHash = $this->hashUrl($webId, 'sha1');
            $exists = $this->filesystem->directoryExists($webIdHash);

            if ($exists) {
                $response = $this->errorResponse->conflict('WebID already registered',"The provided WebID '$webId' has already been registered, use PUT for updates");
            } else {
                $apiKey = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                $filePath = 'keys/' . $apiKey . '.key';

                $isConnected = true;
                if ($version >= 0.4) {
                    $isConnected = $this->solidClient->isWebIdConnected($webId);

                    if (! $isConnected) {
                        $connectionUrl = $request->getUri()->withPath('/api/consent')->withQuery('webid=' . urlencode($webId));
                        $response = $this->errorResponse->proxyAuthenticationRequired('WebID Authentication Required', "The provided WebID '$webId' is not yet connected. To connect this WebID, visit: $connectionUrl", '#webid-not-connected');
                        // @CHECKME: Add Location header?
                        // $response['headers']['Location'] = [$connectionUrl];
                    }
                }

                if ($isConnected === true) {
                    $this->filesystem->write($filePath, $webId);
                    $this->filesystem->createDirectory($webIdHash);

                    $response = [
                        'content' => ['api_key' => $apiKey, 'webid' => $webId],
                        'status' => 201,
                        'title' => 'WebID registered',
                    ];
                }
            }
        }

        return $response;
    }

    private function handleRegisterRequest(RequestInterface $request)
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

                        $replace = [
                            '{webid}' => $webIdUrl,
                        ];
                        $formContents = file_get_contents(__DIR__ . '/../content/forms/register.html');
                        $form = str_replace(array_keys($replace), $replace, $formContents);

                        $content = $this->createContent(
                            'Provide consent',
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
                    $response = $this->errorResponse->notImplemented('Method not implemented',"Method '{$request->getMethod()}' is not implemented, MUST be" . (count($allowedMethods) > 1 ? 'one of ' : '') . implode(', ', $allowedMethods));
                break;
            }
        } else {
            $response = $this->handleNotFound($request);
        }

        return $response;
    }

    private function handleRootRequest(RequestInterface $request)
    {
        $uriRoot = $this->getBaseUrl($request);
        $apiRoot = $uriRoot . '/api/';

        $data = [
            'available_versions' => self::AVAILABLE_VERSIONS,
            'available_endpoints' => [
                'api_url' => $apiRoot,
                'consent_url' => $apiRoot . self::SUBJECT_CONSENT,
                'data_url' => $apiRoot . self::SUBJECT_DATA,
                'registration_url' => $apiRoot . self::SUBJECT_REGISTER,

            ],
            'documentation_url' => "$uriRoot/docs",
        ];

        return ['content' => $data, 'title' => 'MEENT Webhook API', 'status' => 200];
    }
}
