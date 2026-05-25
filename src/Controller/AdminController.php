<?php

namespace Meent\WebHook\Controller;

use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Solid\Session;
use Meent\WebHook\Solid\SolidClient;
use Meent\WebHook\UrlHashTrait;
use Meent\WebHook\WebIdInformation;
use Psr\Http\Message\RequestInterface;

class AdminController extends AbstractController
{
    use UrlHashTrait;

    private const SESSION_KEY_AUTHENTICATED_AT = 'admin_authenticated_at';
    private const SESSION_KEY_AUTHENTICATED_WEBID = 'admin_webid';
    private const SESSION_KEY_CSRF_TOKEN = 'admin_csrf_token';
    private const SESSION_MAX_AGE = 3600;

    private const SUBJECT_LOGIN = 'login';
    private const SUBJECT_LOGOUT = 'logout';

    private array $adminWebIds;
    private Session $session;
    private SolidClient $solidClient;
    private WebIdInformation $webIdInformation;

    final public function __construct(
        SolidClient $solidClient,
        Session $session,
        WebIdInformation $webIdInformation,
        array $adminWebIds,
        ErrorResponse $errorResponse,
    ) {
        $this->adminWebIds = array_map([$this, 'normalizeUrl'], $adminWebIds);
        $this->errorResponse = $errorResponse;
        $this->session = $session;
        $this->solidClient = $solidClient;
        $this->webIdInformation = $webIdInformation;
    }

    final public function handleRequest(RequestInterface $request)
    {
        try {
            $method = $request->getMethod();
            $parts = $this->splitUriPath($request);
            $queryParams = $request->getQueryParams();

            $subject = '';
            if (($parts[0] ?? null) === 'admin') {
                $subject = $parts[1] ?? self::SUBJECT_ROOT;
            }

            if (isset($queryParams['error'])) {
                $response = $this->errorResponse->badGateway('Solid login error','The Solid provider returned an error: ' . urldecode((string) $queryParams['error']));
            } elseif (isset($queryParams['code'])) {
                $authenticatedWebId = $this->solidClient->handleRedirect($queryParams, $this->session);

                if (! in_array($this->normalizeUrl($authenticatedWebId), $this->adminWebIds, true)) {
                    $this->session->remove(self::SESSION_KEY_AUTHENTICATED_WEBID);
                    $this->session->remove(self::SESSION_KEY_AUTHENTICATED_AT);

                    $response = $this->errorResponse->forbidden('Unauthorized admin WebID','The authenticated WebID is not allowed to access the admin area: ' . $authenticatedWebId);
                } else {
                    session_regenerate_id(true);
                    $this->session->set(self::SESSION_KEY_AUTHENTICATED_WEBID, $authenticatedWebId);
                    $this->session->set(self::SESSION_KEY_AUTHENTICATED_AT, time());

                    $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();
                    $response = [
                        'headers' => ['Location' => [$redirectUri]],
                        'status' => 302,
                    ];
                }
            } else {
                switch ($subject) {
                    case self::SUBJECT_ROOT:
                        switch ($method) {
                            case 'HEAD':
                            case 'OPTIONS':
                                $response = $this->handleAllowedHttpMethods(['GET']);
                            break;
                            case 'GET':
                                $webId = $this->session->get(self::SESSION_KEY_AUTHENTICATED_WEBID);
                                $authenticatedAt = $this->session->get(self::SESSION_KEY_AUTHENTICATED_AT);

                                if (is_string($webId)
                                    && $webId !== ''
                                    && in_array($this->normalizeUrl($webId), $this->adminWebIds, true)
                                    && is_int($authenticatedAt)
                                    && (time() - $authenticatedAt) < self::SESSION_MAX_AGE
                                ) {
                                    $webId = (string) $this->session->get(self::SESSION_KEY_AUTHENTICATED_WEBID);

                                    $logoutForm = file_get_contents(__DIR__ . '/../content/forms/admin-logout.html');
                                    $logoutForm = $this->addCsrfToForm($logoutForm);
                                    $logoutForm = str_replace(['{webid}'], [$webId], $logoutForm);

                                    $webIds = $this->webIdInformation->getAll();
                                    if ($webIds === []) {
                                        $WebIdsHtml = '<p><em>No WebIDs found.</em></p>';
                                    } else {
                                        $WebIdsHtml = $this->createInfoTable($webIds);
                                    }

                                    $content = $this->createContent(
                                        'Admin dashboard',
                                        $logoutForm,
                                        "<section>$WebIdsHtml</section>",
                                    );

                                    $response = [
                                        'content' => $content,
                                        'status' => 200,
                                        'title' => '',
                                    ];
                                } else {
                                    $webId = htmlentities($queryParams['webid'] ?? '');

                                    $loginForm = file_get_contents(__DIR__ . '/../content/forms/admin-login.html');
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
                                $response = $this->handleMethodNotAllowed($request, ['GET']);
                            break;
                        }
                    break;
                    case self::SUBJECT_LOGIN:
                        switch ($method) {
                            case 'HEAD':
                            case 'OPTIONS':
                                $response = $this->handleAllowedHttpMethods(['POST', 'GET']);
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
                                $response = $this->handleLogin($request);
                            break;
                            default:
                                $response = $this->handleMethodNotAllowed($request, ['POST', 'GET']);
                            break;
                        }
                    break;
                    case self::SUBJECT_LOGOUT:
                        switch ($method) {
                            case 'HEAD':
                            case 'OPTIONS':
                                $response = $this->handleAllowedHttpMethods(['POST']);
                            break;
                            case 'POST':
                                if (! $this->isValidCsrfToken($request)) {
                                    $response = $this->handleInvalidCsrf();
                                } else {
                                    $this->session->remove(self::SESSION_KEY_AUTHENTICATED_WEBID);
                                    $this->session->remove(self::SESSION_KEY_AUTHENTICATED_AT);
                                    session_regenerate_id(true);

                                    $redirectUri = $request->getUri()->withPath('/admin')->withFragment('')->withQuery('')->__toString();
                                    $response = [
                                        'headers' => ['Location' => [$redirectUri]],
                                        'status' =>  302,
                                    ];
                                }
                            break;
                            default:
                                $response = $this->handleMethodNotAllowed($request, ['POST']);
                            break;
                        }
                    break;
                    default:
                        $response = $this->handleNotFound($request);
                    break;
                }
            }
        } catch (\Throwable $exception) {
            $response = $this->errorResponse->badGateway('Admin Solid login failed','Failed to complete admin Solid login: ' . $exception->getMessage());
        }

        if (! isset($response['type'])) {
            $response['type'] = '/admin/';
        }

        return $response;
    }

    private function addCsrfToForm($formContents)
    {
        $csrfToken = htmlentities($this->getOrCreateCsrfToken());

        return str_replace(
            '</form>',
            '<input name="csrf" type="hidden" value="' . $csrfToken .'" /></form>',
            $formContents,
        );
    }

    private function createInfoTable(array $webIds): string
    {
        $template = file_get_contents(__DIR__ . '/../content/webid-table-row.html');

        $webIdsInfo = array_map(function ($info) use ($template) {
            return vsprintf($template, [
                $info['webid'],
                $this->hashUrl($info['webid'], 'sha1'),
                in_array($info['webid'], $this->adminWebIds, true)
                    ? 'checked '
                    : '',
                $info['has_consent']
                    ? 'checked '
                    : '',
                $info['api_key']
                    ? 'checked '
                    : '',
                $info['api_key'],
            ]);
        }, $webIds);

        $implode = implode('', $webIdsInfo);

        $WebIdsHtml = <<<"HTML"
            <table class="webid-info">
                <thead>
                <tr>
                    <th>WebID</th>
                    <th>Admin<br/>Account</th>
                    <th>Consent<br/>Given</th>
                    <th>Dongle<br/>Registered</th>
                    <th>API Key</th>
                </tr>
                </thead>
                <tbody>$implode</tbody>
            </table>
HTML;

        return $WebIdsHtml;
    }

    private function isValidCsrfToken(RequestInterface $request): bool
    {
        $isValid = false;
        $body = $request->getParsedBody();

        if (is_array($body)) {
            $provided = $body['csrf'] ?? null;
            $stored = $this->session->get(self::SESSION_KEY_CSRF_TOKEN);

            if (is_string($provided) && is_string($stored) && $provided !== '' && $stored !== '') {
                $isValid = hash_equals($stored, $provided);
            }
        }

        if ($isValid) {
            $this->session->remove(self::SESSION_KEY_CSRF_TOKEN);
        }

        return $isValid;
    }

    private function handleInvalidCsrf()
    {
        return $this->errorResponse->forbidden('Invalid CSRF token','Invalid or missing CSRF token for admin action.');
    }

    private function getOrCreateCsrfToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY_CSRF_TOKEN);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY_CSRF_TOKEN, $token);
        }

        return $token;
    }

    private function handleLogin(RequestInterface $request)
    {
        $body = $request->getParsedBody();
        $requestedWebId = $body['webid'] ?? null;

        // WebID is required when POSTing to /admin/login
        if (! is_string($requestedWebId) || $requestedWebId === '') {
            // @TODO: Check or change the title/pointer inconsistency.
            return $this->errorResponse->badRequest('Missing WebID','WebID is required to initiate admin authentication.','#webid-required');
        }

        // CSRF validation required before authentication attempt
        if (! $this->isValidCsrfToken($request)) {
            return $this->errorResponse->forbidden('Invalid CSRF token','Invalid or missing CSRF token. Please try again from the admin page.');
        }

        if (! filter_var($requestedWebId, FILTER_VALIDATE_URL)) {
            return $this->errorResponse->unprocessableEntity('Invalid WebID URL','The provided WebID is not a valid URL: ' . htmlentities($requestedWebId));
        }

        // Proceed with Solid authentication
        $redirectUri = $this->solidClient->connectWebId($requestedWebId, $this->session);

        if (empty($redirectUri)) {
            // @TODO: Check or change the title/pointer inconsistency.
            $response = $this->errorResponse->badGateway('Solid login failed','Could not create a Solid authorization redirect for the provided WebID.','#solid-login-redirect-failed');
        } else {
            $response = [
                'headers' => ['Location' => [$redirectUri]],
                'status' =>  302,
            ];
        }

        return $response;
    }
}
