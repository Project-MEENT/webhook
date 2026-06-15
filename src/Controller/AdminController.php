<?php

namespace Meent\WebHook\Controller;

use Meent\WebHook\AdminSession;
use Meent\WebHook\Config;
use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Exception\InvalidArgumentException;
use Meent\WebHook\Session;
use Meent\WebHook\Solid\SolidClient;
use Meent\WebHook\UrlHashTrait;
use Meent\WebHook\WebIdInformation;
use Psr\Http\Message\ServerRequestInterface;

class AdminController extends AbstractController
{
    use UrlHashTrait;

    private const SUBJECT_LOGIN = 'login';
    private const SUBJECT_LOGOUT = 'logout';
    private const SUBJECT_MANAGE = 'manage';

    private AdminSession $adminSession;
    private Config $config;
    private Session $session;
    private SolidClient $solidClient;
    private WebIdInformation $webIdInformation;

    final public function __construct(
        SolidClient $solidClient,
        Session $session,
        AdminSession $adminSession,
        WebIdInformation $webIdInformation,
        ErrorResponse $errorResponse,
        Config $config
    ) {
        $this->adminSession = $adminSession;
        $this->config = $config;
        $this->errorResponse = $errorResponse;
        $this->session = $session;
        $this->solidClient = $solidClient;
        $this->webIdInformation = $webIdInformation;
    }

    final public function handleRequest(ServerRequestInterface $request)
    {
        $parts = $this->splitUriPath($request);
        $queryParams = $request->getQueryParams();

        $subject = '';
        if (($parts[0] ?? null) === 'admin') {
            $subject = $parts[1] ?? self::SUBJECT_ROOT;
        }

        if (isset($queryParams['error'])) {
            $response = $this->errorResponse->badGateway(
                'Solid login error',
                'The Solid provider returned an error: ' . urldecode((string) $queryParams['error'])
            );
        } elseif (isset($queryParams['code'])) {
            $response = $this->handleRedirectRequest($request);
        } else {
            switch ($subject) {
                case self::SUBJECT_ROOT:
                    $response = $this->handleRootRequest($request);
                break;
                case self::SUBJECT_LOGIN:
                    $response = $this->handleLoginRequest($request);
                break;
                case self::SUBJECT_LOGOUT:
                    $response = $this->handleLogoutRequest($request);
                break;
                case self::SUBJECT_MANAGE:
                    $response = $this->handleManageRequest($request);
                break;
                default:
                    $response = $this->handleNotFound($request);
                break;
            }
        }

        if (! isset($response['type'])) {
            $response['type'] = '/admin/';
        }

        return $response;
    }

    private function addCsrfToForm($formContents)
    {
        $csrfToken = htmlentities($this->adminSession->csrfToken());

        return str_replace(
            '</form>',
            '<input name="csrf" type="hidden" value="' . $csrfToken . '" /></form>',
            $formContents,
        );
    }

    private function createInfoTable(array $webIds): string
    {
        $template = $this->getContents('webid-table-row');

        $webIdsInfo = array_map(function ($info) use ($template) {
            $isAdmin = $this->adminSession->isAdmin($info['webid']);
            $isDongleRegistered = $info['api_key'];
            $webIdHas = $this->hashUrl($info['webid'], 'sha1');

            return vsprintf($template, [
                '%1$s' => $info['webid'],
                '%2$s' => $webIdHas,
                '%3$s' => $isAdmin ? 'checked ' : '',
                '%4$s' => $info['has_consent'] ? 'checked ' : '',
                '%5$s' => $isDongleRegistered ? 'checked ' : '',
                '%6$s' => $info['api_key'],
            ]);
        }, $webIds);

        $implode = implode('', $webIdsInfo);

        return <<<"HTML"
            <table class="webid-info">
                <thead>
                <tr>
                    <th>WebID</th>
                    <th>Consent<br/>Given</th>
                    <th>Connected<br>to Pod</th>
                    <th>Dongle<br/>Registered</th>
                    <th>API Key</th>
                    <th>Admin<br/>Account</th>
                </tr>
                </thead>
                <tbody>$implode</tbody>
            </table>
HTML;
    }

    private function handleInvalidCsrf()
    {
        return $this->errorResponse->forbidden('CSRF Error', 'Invalid or missing CSRF token.');
    }

    private function handleLogin(ServerRequestInterface $request)
    {
        $body = $request->getParsedBody();
        $requestedWebId = $body['webid'] ?? null;

        // WebID is required when POSTing to /admin/login
        if (! is_string($requestedWebId) || $requestedWebId === '') {
            // @TODO: Check or change the title/pointer inconsistency.
            return $this->errorResponse->badRequest(
                'Missing WebID',
                'WebID is required to initiate admin authentication.',
                '#webid-required'
            );
        }

        if (! filter_var($requestedWebId, FILTER_VALIDATE_URL)) {
            return $this->errorResponse->unprocessableEntity(
                'Invalid WebID URL',
                'The provided WebID is not a valid URL: ' . htmlentities($requestedWebId)
            );
        }

        // Proceed with Solid authentication
        $redirectUri = $this->solidClient->connectWebId($requestedWebId, $this->session);

        if (empty($redirectUri)) {
            // @TODO: Check or change the title/pointer inconsistency.
            $response = $this->errorResponse->badGateway(
                'Solid login failed',
                'Could not create a Solid authorization redirect for the provided WebID.',
                '#solid-login-redirect-failed'
            );
        } else {
            $response = [
                'headers' => ['Location' => [$redirectUri]],
                'status' => 302,
            ];
        }

        return $response;
    }

    private function handleLoginRequest(ServerRequestInterface $request): array
    {
        $allowedMethods = ['POST', 'GET'];
        $method = $request->getMethod();

        switch ($method) {
            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;
            case 'GET':
                // GET /admin/login redirects to /admin/ (the form is there)
                $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();
                $response = [
                    'headers' => ['Location' => [$redirectUri]],
                    'status' => 302,
                ];
            break;
            case 'POST':
                if (! $this->hasValidCsrf($request)) {
                    $response = $this->handleInvalidCsrf();
                } else {
                    $response = $this->handleLogin($request);
                }
            break;
            default:
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;
        }

        return $response;
    }

    private function handleLogoutRequest(ServerRequestInterface $request)
    {
        $allowedMethods = ['POST'];
        $method = $request->getMethod();

        switch ($method) {
            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;
            case 'POST':
                if (! $this->hasValidCsrf($request)) {
                    $response = $this->handleInvalidCsrf();
                } else {
                    $this->adminSession->stop();
                    session_regenerate_id(true);

                    $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();
                    $response = [
                        'headers' => ['Location' => [$redirectUri]],
                        'status' => 302,
                    ];
                }
            break;
            default:
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;
        }

        return $response;
    }

    private function handleManageRequest(ServerRequestInterface $request)
    {
        $allowedMethods = ['POST'];
        $method = $request->getMethod();

        switch ($method) {
            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;
            case 'POST':
                if (! $this->hasValidCsrf($request)) {
                    $response = $this->handleInvalidCsrf();
                } else {
                    $response = $this->handleUpdateAdminsRequest($request);
                }
            break;
            default:
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;
        }

        return $response;
    }

    private function handleRedirectRequest(ServerRequestInterface $request)
    {
        $queryParams = $request->getQueryParams();
        $currentUrl = $request->getUri()->withFragment('')->withQuery('')->__toString();
        $authenticatedWebId = $this->solidClient->handleRedirect($queryParams, $this->session, $currentUrl);

        if (! $this->adminSession->isAdmin($authenticatedWebId)) {
            $this->adminSession->stop();

            $response = $this->errorResponse->forbidden(
                'Unauthorized admin WebID',
                'The authenticated WebID is not allowed to access the admin area: ' . $authenticatedWebId
            );
        } else {
            $this->adminSession->start($authenticatedWebId);

            $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();
            $response = [
                'headers' => ['Location' => [$redirectUri]],
                'status' => 302,
            ];
        }

        return $response;
    }

    private function handleRootRequest(ServerRequestInterface $request): array
    {
        $allowedMethods = ['GET'];
        $method = $request->getMethod();

        switch ($method) {
            case 'HEAD':
            case 'OPTIONS':
                $response = $this->handleAllowedHttpMethods($allowedMethods);
            break;
            case 'GET':
                $adminWebId = $this->adminSession->isAuthenticated();

                if ($adminWebId) {
                    $logoutForm = $this->getContents('forms/admin-logout');
                    $logoutForm = $this->addCsrfToForm($logoutForm);
                    $logoutForm = str_replace(['{webid}'], [$adminWebId], $logoutForm);

                    $webIds = $this->webIdInformation->getAll();
                    if ($webIds === []) {
                        $WebIdsHtml = '<p><em>No WebIDs found.</em></p>';
                    } else {
                        $WebIdsHtml = $this->createInfoTable($webIds);
                    }
                    $WebIdsHtml = $this->addCsrfToForm($WebIdsHtml);

                    $content = $this->createContent(
                        'Admin dashboard',
                        $logoutForm,
                        "<section>$WebIdsHtml</section>",
                        [
                            'forms/admin.js',
                            'forms/check-solid-connection.js',
                            'forms/show-password.js',
                        ],
                    );

                    $response = [
                        'content' => $content,
                        'status' => 200,
                        'title' => '',
                    ];
                } else {
                    $queryParams = $request->getQueryParams();

                    $webId = htmlentities($queryParams['webid'] ?? '');
                    $loginForm = $this->getContents('forms/admin-login');
                    $loginForm = $this->addCsrfToForm($loginForm);
                    $loginForm = str_replace(['{webid}'], [$webId], $loginForm);

                    $content = $this->createContent(
                        'Admin login',
                        '<p>Admin access requires logging in with a Solid WebID.</p>',
                        '<section>' . $loginForm . '</section>',
                    );

                    $response = ['content' => $content, 'status' => 200, 'title' => ''];
                }
            break;
            default:
                $response = $this->handleMethodNotAllowed($request, $allowedMethods);
            break;
        }

        return $response;
    }

    private function handleUpdateAdminsRequest(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $webid = $this->normalizeUrl($body['webid']);
        $makeAdmin = isset($body['makeAdmin']) && $body['makeAdmin'] === 'on';

        $webIds = $this->config->get(Config::ADMIN_WEBIDS);
        $config = $this->config->toArray();

        if ($makeAdmin && ! in_array($webid, $webIds, true)) {
            $webIds[] = $webid;
        } elseif (! $makeAdmin && in_array($webid, $webIds, true)) {
            $webIds = array_diff($webIds, [$webid]);

            if ($webIds === []) {
                throw InvalidArgumentException::create('Cannot remove WebID as there would be no admin left');
            }
        }

        if ($webIds !== $this->config->get(Config::ADMIN_WEBIDS)) {
            $config[Config::ADMIN_WEBIDS] = array_values($webIds);
            $this->config->save($config);
        }

        $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();

        $response = ['headers' => ['Location' => [$redirectUri]], 'status' => 303,];

        return $response;
    }

    private function hasValidCsrf(ServerRequestInterface $request): bool
    {
        $isValid = false;

        $body = $request->getParsedBody();

        if (is_array($body)
            && isset($body['csrf'])
            && $this->adminSession->csrfTokenIsValid($body['csrf'])
        ) {
            $isValid = true;
        }

        return $isValid;
    }
}
