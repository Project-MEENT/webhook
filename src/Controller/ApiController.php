<?php

namespace Meent\WebHook\Controller;

use League\Flysystem\FilesystemOperator;
use Meent\WebHook\AdminSession;
use Meent\WebHook\Controller\Api\ConsentController;
use Meent\WebHook\Controller\Api\DataController;
use Meent\WebHook\Controller\Api\PodCreationController;
use Meent\WebHook\Controller\Api\RegisterController;
use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Exception;
use Meent\WebHook\Exception\RuntimeException;
use Meent\WebHook\Session;
use Meent\WebHook\Solid\SolidClient;
use Meent\WebHook\UrlHashTrait;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\ServerRequestInterface;

class ApiController extends AbstractController
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    private const AVAILABLE_SUBJECTS = [
        self::SUBJECT_CONSENT,
        self::SUBJECT_DATA,
        self::SUBJECT_POD_CREATION,
        self::SUBJECT_REGISTER,
    ];

    private const AVAILABLE_VERSIONS = [
        'v0.1', // No-op
        'v0.2', // Store without API key
        'v0.3', // Store with registered API key
        'v0.4', // Provide consent
        'v0.5', // Solid Pod Creation
    ];

    private const SUBJECT_CONSENT = 'consent';
    private const SUBJECT_DATA = 'data';
    private const SUBJECT_POD_CREATION = 'pod';
    private const SUBJECT_REGISTER = 'register';

    protected AdminSession $adminSession;
    protected HttpClientInterface $httpClient;
    protected Session $session;

    ///////////////////////////// GETTERS & SETTERS \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function setAdminSession(AdminSession $adminSession): void
    {
        $this->adminSession = $adminSession;
    }

    final public function setHttpClient(HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    final public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(
        protected FilesystemOperator $filesystem,
        protected SolidClient $solidClient,
        protected ErrorResponse $errorResponse,
    ) {}

    public function handleRequest(ServerRequestInterface $request): array
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
                    $controller = new ConsentController($this->filesystem, $this->solidClient, $this->errorResponse);
                    $controller->setSession($this->session);
                } else {
                    $response = $this->handleNotFound($request);
                }
            break;

            case self::SUBJECT_DATA:
                $controller = new DataController($this->filesystem, $this->solidClient, $this->errorResponse);
                $controller->setAdminSession($this->adminSession);
            break;

            case self::SUBJECT_POD_CREATION:
                if ($version >= 0.5) {
                    $controller = new PodCreationController($this->filesystem, $this->solidClient, $this->errorResponse);
                    $controller->setHttpClient($this->httpClient);
                } else {
                    $response = $this->handleNotFound($request);
                }
            break;

            case self::SUBJECT_REGISTER:
                $controller = new RegisterController($this->filesystem, $this->solidClient, $this->errorResponse);
            break;

            case self::SUBJECT_ROOT:
                $response = $this->handleRootRequest($request);
            break;

            default:
                $response = $this->handleNotFound($request);
            break;
        }

        if (isset($controller)) {
            $response = $controller->handleRequest($request);
        }

        $response['headers']['API-Version'] = ["v$version"];

        if (! isset($response['type'])) {
            $response['type'] = '/api/';
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final protected function createContainer($storageUrl, $webIdUrl)
    {
        $containerUrl = vsprintf('%s/%s/', [
            'root' => rtrim($storageUrl, '/'),
            'path' => 'MEENT/p1',
        ]);

        $solidResponse = $this->solidClient->storeResource(
            $webIdUrl,
            rtrim($containerUrl, '/') . '/README.md',
            'This Container is where all P1 dongle data is written.'
        );

        $storageFilePath = vsprintf('/storage-urls/%s.url', [
            'webIdHash' => $this->hashUrl($webIdUrl, 'sha1'),
        ]);

        $this->filesystem->write($storageFilePath, $storageUrl);
    }

    final protected function getBaseUrl(ServerRequestInterface $request): string
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

    final protected function getRequestedObject(ServerRequestInterface $request): string
    {
        $subject = $this->getRequestedSubject($request);
        $path = $request->getUri()->getPath();

        $pathOffset = strpos($path, $subject) + strlen($subject);
        $object = substr($path, $pathOffset);

        return ltrim($object, '/');
    }

    final protected function getRequestedSubject(ServerRequestInterface $request)
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw RuntimeException::create('Invalid path');
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

    final protected function getRequestedVersion(ServerRequestInterface $request): float
    {
        $parts = $this->splitUriPath($request);

        if ($parts[0] !== 'api') {
            throw RuntimeException::create('Invalid path');
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
            throw RuntimeException::create('Invalid version');
        }

        return (float) ltrim($version, 'v');
    }

    private function handleRootRequest(ServerRequestInterface $request)
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
