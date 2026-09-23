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
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    private const SUBJECT_LOGIN = 'login';
    private const SUBJECT_LOGOUT = 'logout';
    private const SUBJECT_MANAGE = 'manage';

    private AdminSession $adminSession;
    private Session $session;
    private SolidClient $solidClient;
    private WebIdInformation $webIdInformation;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

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

    final public function handleRequest(ServerRequestInterface $request): array
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
            $response = match ($subject) {
                self::SUBJECT_LOGIN => $this->handleLoginRequest($request),
                self::SUBJECT_LOGOUT => $this->handleLogoutRequest($request),
                self::SUBJECT_MANAGE => $this->handleManageRequest($request),
                self::SUBJECT_ROOT => $this->handleRootRequest($request),
                default => $this->handleNotFound($request),
            };
        }

        if (! isset($response['type'])) {
            $response['type'] = '/admin/';
        }

        return $response;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

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
            $webId = $info['webid'] ?? '';

            $hasConsent = $info['has_consent'];
            $isAdmin = $this->adminSession->isAdmin($webId);
            $isDongleRegistered = ! empty($info['api_key']);
            $webIdHash = $this->hashUrl($webId, 'sha1');

            // if $webId is from a Solid server where we are a trusted app, consent is also present.
            $podCreationUrl = $this->config->get(Config::POD_CREATION_URL);
            if ($podCreationUrl) {
                $host = parse_url($podCreationUrl, PHP_URL_HOST);
                $hasConsent = str_contains($webId, $host) || $hasConsent;
            }

            $macs = $info['macs'] ?? [];
            $macsLabel = $macs === [] ? '—' : implode(', ', $macs);

            $storageUrl = $info['storage_url'] ?? '';
            $storageUrlCell = $storageUrl === ''
                ? '—'
                : '<a href="' . $storageUrl . '" target="_blank" rel="noopener">📦</a>';

            return vsprintf($template, [
                '%1$s' => $webId,
                '%2$s' => $webIdHash,
                '%3$s' => $isAdmin ? 'checked ' : '',
                '%4$s' => $hasConsent ? 'checked ' : '',
                '%5$s' => $isDongleRegistered ? 'checked ' : '',
                '%6$s' => $info['api_key'],
                '%7$s' => $macsLabel,
                '%8$s' => (string) ($info['pending_files'] ?? 0),
                '%9$s' => $storageUrlCell,
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
                    <th>MAC</th>
                    <th>Pending<br/>data</th>
                    <th>Storage URL</th>
                    <th>Admin<br/>Account</th>
                    <td></td>
                </tr>
                </thead>
                <tbody>$implode</tbody>
            </table>
HTML;
    }

    private function getRedirectUrl(): string
    {
        return $this->getBaseUrl() . '/admin';
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
        try {
            $redirectUri = $this->solidClient->connectWebId($requestedWebId, $this->session);
        } catch (\Throwable $exception) {
            return $this->errorResponse->badGateway("Could not connect WebID '$requestedWebId'");
        }

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
                $response = [
                    'headers' => ['Location' => [ $this->getRedirectUrl() ]],
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
                    $response = [
                        'headers' => ['Location' => [ $this->getRedirectUrl() ]],
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
        $redirectUri = $this->getRedirectUrl();

        $authenticatedWebId = $this->solidClient->handleRedirect($queryParams, $this->session, $redirectUri);

        if (! $this->adminSession->isAdmin($authenticatedWebId)) {
            $this->adminSession->stop();

            $response = $this->errorResponse->forbidden(
                'Unauthorized admin WebID',
                'The authenticated WebID is not allowed to access the admin area: ' . $authenticatedWebId
            );
        } else {
            $this->adminSession->start($authenticatedWebId);
            $response = [
                'headers' => ['Location' => [ $redirectUri ]],
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

                if ($adminWebId !== '') {
                    $logoutForm = $this->getContents('forms/admin-logout');
                    $logoutForm = $this->addCsrfToForm($logoutForm);
                    $logoutForm = str_replace(['{webid}'], [$adminWebId], $logoutForm);

                    $webIds = $this->webIdInformation->getAll();
                    if ($webIds === []) {
                        $webIdsHtml = '<p><em>No WebIDs found.</em></p>';
                    } else {
                        $webIdsHtml = $this->createInfoTable($webIds);
                    }

                    $pendingFiles = $this->webIdInformation->getTotalPendingFiles();
                    $pendingSummary = $pendingFiles === 0
                        ? '<p>All data has been written to the Solid Pods.</p>'
                        : '<p><strong>' . $pendingFiles . '</strong> data record(s) are still waiting to be written to the Solid Pods (auth failed, network error, etc.).</p>';

                    $orphans = $this->webIdInformation->getOrphanedPendingFiles();
                    if ($orphans === []) {
                        $orphansHtml = '';
                    } else {
                        $orphansTotal = array_sum(array_column($orphans, 'pending_files'));
                        $orphansRows = '';
                        foreach ($orphans as $orphan) {
                            $orphansRows .= vsprintf(
                                '<tr>
                                    <td><code>%1$s</code></td>
                                    <td><code>%2$s</code></td>
                                    <td>%3$s</td>
                                </tr>',
                                [
                                    $orphan['hash'],
                                    $orphan['storage_url'],
                                    $orphan['pending_files'],
                                ],
                            );
                        }

                        $orphansHtml = vsprintf(
                            '
                                <h2>Orphaned data (%1$s record(s) for %2$s pod(s) not linked to a registered WebID)</h2>
                                <p>These data files are stored locally but cannot be written to a Solid Pod because the WebID they belong to is no longer registered. They will be retried once the WebID is registered again.</p>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>WebID hash</th>
                                            <th>Storage URL</th>
                                            <th>Pending data</th>
                                        </tr>
                                    </thead>
                                    <tbody>%3$s</tbody>
                                </table>
                                ',
                            [
                                $orphansTotal,
                                count($orphans),
                                $orphansRows,
                            ],
                        );
                    }

                    $webIdsHtml = $this->addCsrfToForm($webIdsHtml);

                    $content = $this->createContent(
                        'Admin dashboard',
                        $logoutForm,
                        "<section>$pendingSummary</section>$orphansHtml<section>$webIdsHtml</section>",
                        [
                            'forms/admin.js',
                            'forms/check-solid-connection.js',
                            'forms/recover-auth.js',
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
        $webId = $this->normalizeUrl($body['webid']);
        $makeAdmin = isset($body['makeAdmin']) && $body['makeAdmin'] === 'on';

        $webIds = $this->config->get(Config::ADMIN_WEBIDS);
        $config = $this->config->toArray();

        if ($makeAdmin && ! in_array($webId, $webIds, true)) {
            $webIds[] = $webId;
        } elseif (! $makeAdmin && in_array($webId, $webIds, true)) {
            $webIds = array_diff($webIds, [$webId]);

            if ($webIds === []) {
                throw InvalidArgumentException::create('Cannot remove WebID as there would be no admin left');
            }
        }

        if ($webIds !== $this->config->get(Config::ADMIN_WEBIDS)) {
            $config[Config::ADMIN_WEBIDS] = array_values($webIds);
            $this->config->save($config);
        }

        return ['headers' => ['Location' => [ $this->getRedirectUrl() ]], 'status' => 303,];
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
