<?php

namespace Meent\WebHook\Controller;

use EasyRdf\Graph;
use League\Flysystem\FilesystemOperator;
use Meent\WebHook\Exception;
use Meent\WebHook\Exception\SolidException;
use Meent\WebHook\Record;
use Meent\WebHook\Solid\Session;
use Meent\WebHook\Solid\SolidClientFactory;
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

    private FilesystemOperator $filesystem;
    private SolidClientFactory $solidClientFactory;

    final public function __construct(FilesystemOperator $filesystem, SolidClientFactory $solidClientFactory)
    {
        $this->filesystem = $filesystem;
        $this->solidClientFactory = $solidClientFactory;
    }

    final public function handleRequest(RequestInterface $request, array $response)
    {
        $response['type'] = '/api/';

        try {
            $subject = $this->getRequestedSubject($request);
            $version = $this->getRequestedVersion($request);
        } catch (Exception $e) {
            $response['content'] = [[
                'detail' => 'API version not found: '
                    . $e->getMessage() . '. MUST be one of '
                    . implode(', ', self::AVAILABLE_VERSIONS),
                'pointer' => '#invalid-api-version',
            ]];
            $response['status'] = 404;
            $response['title'] = 'API Version Not Found';
            $response['type'] = '/errors/';

            return $response;
        }

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

                $redirectUri = '';

                // Detect whether this request is the callback from the authorization server.
                // When the OP redirects back it always includes `code` (success) or `error` (failure).
                $isRedirect = isset($queryParams['code']);
                $webIdConnected = isset($queryParams['connected']);
                $webIdUrl = $request->getParsedBody()['webid']
                    ?? $queryParams['webid']
                    ?? $queryParams['connected']
                    ?? null;

                $solidClient = $this->solidClientFactory->create(SolidClientFactory::REUSE_STORED_AUTHENTICATION);

                if (isset($queryParams['error'])) {
                    $response['content'] = [[
                        'detail' => 'The Provider returned an error: "' . urldecode($queryParams['error']) . '"',
                        'pointer' => '#provider-error',
                    ]];
                    $response['status'] = 502;
                    $response['title'] = 'Invalid URL';
                    $response['type'] = '/errors/';
                } elseif ($isRedirect) {
                    $webIdUrl = $solidClient->handleRedirect($queryParams, Session::current());
                    $redirectUri = $this->getBaseUrl($request) . '/api/consent?connected=' . urlencode($webIdUrl);
                } elseif (! $webIdUrl) {
                    $form = file_get_contents(__DIR__ . '/../content/forms/consent.html');

                    $content['header'] = '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>';
                    $content['main'] = "<section>$form</section><section><output></output></section>";
                    $content['script'] = file_get_contents(__DIR__ . '/../content/forms/form.js');
                    $content['title'] = 'Provide consent';
                } elseif (! filter_var($webIdUrl, FILTER_VALIDATE_URL)) {
                    $response['content'] = [[
                        'detail' => "Provided WebID '$webIdUrl' is not a valid URL",
                        'pointer' => '#invalid-url',
                    ]];
                    $response['status'] = 422;
                    $response['title'] = 'Invalid URL';
                    $response['type'] = '/errors/';
                } elseif ($webIdConnected || $solidClient->isWebIdConnected($webIdUrl)) {
                    $content['header'] = "<p>Your P1 dongle can now be connected to your Solid Pod, using WebID <a href='$webIdUrl'>$webIdUrl</a></p>";
                    $content['title'] = 'Consent Provided';
                } else {
                    $redirectUri = $solidClient->connectWebId($webIdUrl, Session::current());
                }

                // Create Response
                if (! empty($redirectUri)) {
                    $response['status'] = 302;
                    $response['headers']['Location'] = [$redirectUri];
                } elseif (isset($content)) {
                    $content = array_merge(self::EMPTY_CONTENT, $content);
                    $template = $this->getContents('template');
                    $response['content'] = vsprintf($template, $content);
                }
            break;

            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($response, $allowedMethods);
            break;

            case 'DELETE':
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
        $queryParams = $request->getQueryParams();

        if ($version >= 0.4 && ! $request->getHeaderLine('Authorization')) {
            $template = $this->getContents('template');
            $form = file_get_contents(__DIR__ . '/../content/forms/data.html');

            $content = [
                'header' => '<p>To write data to a Solid Pod, please provide authentication and data</p>',
                'main' => "<section>$form</section><section><output></output></section>",
                'script' => file_get_contents(__DIR__ . '/../content/forms/form.js'),
                'title' => 'Post content',
            ];

            $content = array_merge(self::EMPTY_CONTENT, $content);
            $template = $this->getContents('template');
            $response['content'] = vsprintf($template, $content);

            return $response;
        }

        if ($version >= 0.3) {
            $authError = $this->checkAuthorization($request, $response);

            if ($authError !== []) {
                return $authError;
            } elseif ($version >= 0.4) {
                $auth = $request->getHeaderLine('Authorization');
                $apiKey = substr($auth, 7);
            }
        }

        $filePath = $this->getRequestedObject($request);

        if (isset($apiKey)) {
            $webId = $this->filesystem->read('keys/' . $apiKey . '.key');

            $solidClient = $this->solidClientFactory->create(SolidClientFactory::REUSE_STORED_AUTHENTICATION);

            $storageUrls = $solidClient->fetchStorageUrls($webId);

            if ($storageUrls !== []) {
                // @TODO: Instead of using the first URL, the user should be asked which one to use when registering
                $storageUrlRoot = reset($storageUrls);
                $storageUrl = rtrim($storageUrlRoot, '/');
            }

            if (empty($storageUrl)) {
                $response['content'] = [[
                    'detail' => 'No Storage URL found for the WebID, cannot read data from Solid Pod',
                    'pointer' => '#no-storage-url',
                ]];
                $response['status'] = 422;
                $response['title'] = 'No Storage URL';
                $response['type'] = '/errors/';

                return $response;
            }

            $resourceUrl = vsprintf('%s/%s', [
                'root' => $storageUrl,
                'path' => $filePath,
            ]);

            try {
                $solidResponse = $solidClient->fetchResource($webId, $resourceUrl);
            } catch (SolidException $e) {
                $response['content'] = [[
                    'detail' => 'Error fetching resource from Solid Pod: ' . $e->getMessage(),
                    'pointer' => '#solid-fetch-error',
                ]];
                $response['status'] = 502;
                $response['title'] = 'Error fetching resource from Solid Pod';
                $response['type'] = '/errors/';

                return $response;
            }

            $resource = $solidResponse->getBody()->getContents();
            $response['content'] = $resource;
            $response['status'] = 200;
            $response['headers']['Content-Type'] = [$solidResponse->getHeaderLine('Content-Type')];


            return $response;
        }

        $isInvalidPath = strpos($filePath, '/') === false || ! str_ends_with($filePath, '.data');
        if ($isInvalidPath) {
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
                        $response['content'] = [[
                            'detail' => 'Provided data is not valid JSON: ' . $e->getMessage(),
                            'pointer' => '#invalid-json',
                        ]];
                        $response['status'] = 422;
                        $response['title'] = 'Invalid JSON';
                        $response['type'] = '/errors/';

                        return $response;
                    }

                    // The received JSON data MUST contain a "timestamp" value, which indicates the time the data was recorded.
                    // If we do not have a timestamp, the data can not be used in a time series, but it can also not be stored in the Pod
                    // as the timestamp is needed to build the resource path.
                    if (! isset($data['timestamp'])) {
                        $response['content'] = [[
                            'detail' => 'Missing required "timestamp" value in the provided data',
                            'pointer' => '#missing-timestamp',
                        ]];
                        $response['status'] = 422;
                        $response['title'] = 'Missing timestamp';
                        $response['type'] = '/errors/';

                        return $response;
                    } elseif (! strtotime($data['timestamp']) && ! strtotime('@'.$data['timestamp'])) {
                        $response['content'] = [[
                            'detail' => 'Provided "timestamp" is not a valid timestamp.',
                            'pointer' => '#invalid-timestamp',
                        ]];
                        $response['status'] = 422;
                        $response['title'] = 'Invalid timestamp';
                        $response['type'] = '/errors/';

                        return $response;
                    } else {
                        $timestamp = $data['timestamp'];
                        if (is_numeric($timestamp)) {
                            $timestamp = '@' . $timestamp;
                        }
                        $dateTime = new \DateTimeImmutable($timestamp);
                        $dateTime->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
                        $timestamp = $dateTime->format('Ymd.His');

                        $solidClient = $this->solidClientFactory->create(SolidClientFactory::REUSE_STORED_AUTHENTICATION);

                        // @FIXME: Read StorageUrl from persistent configuration instead of resolving it on every request.
                        if (empty($storageUrl)) {
                            $storageUrls = $solidClient->fetchStorageUrls($webId);

                            if ($storageUrls !== []) {
                                // @KLUDGE: As there is no user available here, we cannot ask them which storage to use
                                $storageUrlRoot = reset($storageUrls);
                                $storageUrl = rtrim($storageUrlRoot, '/');
                                // @FIXME: Store $storageUrl (where?)
                            }

                            if (empty($storageUrl)) {
                                $response['content'] = [[
                                    'detail' => 'No Storage URL found for the WebID, cannot store data in Solid Pod',
                                    'pointer' => '#no-storage-url',
                                ]];
                                $response['status'] = 422;
                                $response['title'] = 'No Storage URL';
                                $response['type'] = '/errors/';

                                return $response;
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
                            $response['content'] = [[
                                'detail' => 'Could not convert data to Turtle: ' . $e->getMessage(),
                                'pointer' => '#data-conversion-error',
                            ]];
                            $response['status'] = 500;
                            $response['title'] = 'Data conversion error';
                            $response['type'] = '/errors/';

                            return $response;
                        }

                        try {
                            $result = $solidClient->storeResource($webId, $url, $turtle, 'text/turtle');
                        } catch (SolidException $e) {
                            $response['content'] = [[
                                'detail' => 'Could not write resource to Solid Pod: ' . $e->getMessage(),
                                'pointer' => '#solid-write-error',
                            ]];
                            $response['status'] = 502;
                            $response['title'] = 'Solid write error';
                            $response['type'] = '/errors/';

                            return $response;
                        }

                        if (isset($result)) {
                            // Remove local copy, as the raw data has been saved in the Pod
                            try {
                                $this->filesystem->delete($filePath);
                            } catch (\Exception $e) {
                                // We do not care if the delete fails, as the "retry" logic can handle this later
                                // The retry logic should first check if the file hasn't already been written to the remote.
                            }

                            $data = null;
                            // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                            $response['headers']['Location'] = [$url];
                            $statuscode = 201;
                        }
                    }
                } elseif ($version >= 0.3) {
                    $url = $this->getBaseUrl($request) . '/api/data/' . $filePath;
                    $data = null;
                    $statuscode = 201;

                    // For 201 (Created) responses, the Location value refers to the primary resource created by the request. (RFC-9110, Sections 10.2.2 and 15.3.2)
                    $response['headers']['Location'] = [$url];
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
            $response['content'] = $data;
            $response['status'] = $statuscode;
            $response['title'] = $message;
        }

        return $response;
    }

    private function handleRegisterPost(RequestInterface $request, $response)
    {
        $input = $request->getBody()->getContents();
        $version = $this->getRequestedVersion($request);

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

                $isConnected = true;
                if ($version >= 0.4) {
                    $solidClient = $this->solidClientFactory->create(SolidClientFactory::REUSE_STORED_AUTHENTICATION);
                    $isConnected = $solidClient->isWebIdConnected($webId);

                    if (! $isConnected) {
                        $connectionUrl = $request->getUri()->withPath('/api/consent')->withQuery('webid=' . urlencode($webId));
                        $response['content'] = [[
                            'detail' => "The provided WebID '$webId' is not yet connected. To connect this WebID, visit: $connectionUrl",
                            'pointer' => '#webid-not-connected',
                        ]];
                        $response['status'] = 407;
                        $response['title'] = 'WebID Authentication Required';
                        $response['type'] = '/errors/';
                        // @CHECKME: Add Location header?
                        // $response['headers']['Location'] = [$connectionUrl];
                    }
                }

                if ($isConnected === true) {
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
        }

        return $response;
    }

    private function handleRegisterRequest(RequestInterface $request, $response)
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

                        $formContents = file_get_contents(__DIR__ . '/../content/forms/register.html');
                        $form = vsprintf($formContents, [
                            'webId' => $webIdUrl,
                        ]);

                        $content = [
                            'header' => '<p>To connect your P1 dongle to a Solid Pod, please provide the URL of your Solid WebID</p>',
                            'main' => "<section>$form</section><section><output></output></section>",
                            'script' => file_get_contents(__DIR__ . '/../content/forms/form.js'),
                            'title' => 'Provide consent',
                        ];

                        $content = array_merge(self::EMPTY_CONTENT, $content);
                        $template = $this->getContents('template');
                        $response['content'] = vsprintf($template, $content);
                    } else {
                        $response = $this->handleMethodNotAllowed($response, $request, $allowedMethods);
                    }
                break;
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
                    $response = $this->handleMethodNotImplemented($response, $request, $allowedMethods);
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

        $response['content'] = $data;
        $response['title'] = 'MEENT Webhook';

        return $response;
    }
}
