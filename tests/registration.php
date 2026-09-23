<?php

// Run with `composer test` or `php tests/registration.php` after composer install.
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Meent\WebHook\Config;
use Meent\WebHook\Controller\Api\DataController;
use Meent\WebHook\Controller\Api\PodCreationController;
use Meent\WebHook\Controller\Api\RegisterController;
use Meent\WebHook\ErrorResponse;
use Meent\WebHook\Solid\OidcClientConfig;
use Meent\WebHook\Solid\SolidClient;
use Meent\WebHook\Solid\SolidClientConfig;
use Meent\WebHook\Solid\SolidClientFactory;
use Psr\Http\Message\RequestInterface;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fixture(): array
{
    $root = sys_get_temp_dir() . '/meent-registration-' . bin2hex(random_bytes(8));
    $fs = new Filesystem(new LocalFilesystemAdapter($root));
    $fs->createDirectory('keys');
    $config = Config::fromArray([
        Config::ADMIN_WEBIDS => [],
        Config::API_STORAGE_PATH => $root,
        Config::BASE_URL => 'https://webhook.example',
        Config::CLIENT_NAME => 'Registration tests',
        Config::JWT_TTL => 300,
        Config::METADATA_CACHE_TTL => 0,
        Config::SOLID_STORAGE_PATH => $root . '/solid',
        Config::STATE_SIGNING_KEY => 'test-state-key',
    ]);
    // Registration v0.5 must not contact Solid; uninitialized dependencies fail if used.
    $solid = (new ReflectionClass(SolidClient::class))->newInstanceWithoutConstructor();
    $controller = new RegisterController($fs, $solid, new ErrorResponse(), $config);
    return compact('root', 'fs', 'config', 'solid', 'controller');
}

function device(array $f, string $mac, string $webId, string $secret = 'shared-test-secret'): void
{
    $f['fs']->write('keys/' . $mac . '.mac', $webId);
    $f['fs']->write('keys/' . $mac . '.secret', hash_hmac('sha256', $secret, '', true));
}

function register(array $f, string $webId, string $secret = 'shared-test-secret', string $version = 'v0.5'): array
{
    return $f['controller']->handleRequest(new ServerRequest(
        'POST', 'https://webhook.example/api/' . $version . '/register/',
        ['X-Client-Secret' => $secret], $webId
    ));
}

function keyFiles(array $f, ?string $webId = null): array
{
    $keys = [];
    foreach ($f['fs']->listContents('keys') as $file) {
        if ($file->isFile() && str_ends_with($file->path(), '.key')) {
            $value = $f['fs']->read($file->path());
            if ($webId === null || $value === $webId) {
                $keys[$file->path()] = $value;
            }
        }
    }
    ksort($keys);
    return $keys;
}

function snapshot(array $f): array
{
    $snapshot = [];
    foreach ($f['fs']->listContents('', true) as $file) {
        $snapshot[$file->path()] = $file->isFile() ? $f['fs']->read($file->path()) : null;
    }
    ksort($snapshot);
    return $snapshot;
}

function cleanup(array $f): void
{
    $f['fs']->deleteDirectory('');
}

/** Real Solid client with all HTTP requests served by a local handler. */
function solidFixture(array $f, string $webId): SolidClient
{
    $handler = function (RequestInterface $request) use ($webId) {
        $url = (string) $request->getUri();
        if ($request->getMethod() === 'PUT') {
            return Create::promiseFor(new Response(201));
        }
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'issuer' => 'https://issuer.example',
                'authorization_endpoint' => 'https://issuer.example/authorize',
                'token_endpoint' => 'https://issuer.example/token',
                'jwks_uri' => 'https://issuer.example/jwks',
                'response_types_supported' => ['code'],
                'subject_types_supported' => ['public'],
                'id_token_signing_alg_values_supported' => ['RS256'],
                'token_endpoint_auth_methods_supported' => ['none'],
            ])));
        }
        if (str_starts_with($url, 'https://pod.example/')) {
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/rdf+json'], json_encode([
                $webId => ['http://www.w3.org/ns/solid/terms#oidcIssuer' => [
                    ['type' => 'uri', 'value' => 'https://issuer.example'],
                ]],
            ])));
        }
        throw new RuntimeException('Unexpected HTTP request in test: ' . $url);
    };
    $clientFs = new Filesystem(new LocalFilesystemAdapter($f['root'] . '/solid'));
    $oidc = OidcClientConfig::fromArray([
        OidcClientConfig::CLIENT_NAME => 'Test client',
        OidcClientConfig::CLIENT_ID => 'https://webhook.example/client.json',
        OidcClientConfig::REDIRECT_URIS => ['https://webhook.example/callback'],
    ]);
    $config = SolidClientConfig::fromArray([
        SolidClientConfig::EXPIRATION_TIME => 300,
        SolidClientConfig::REDIRECT_URI => 'https://webhook.example/callback',
        SolidClientConfig::STATE_SIGNING_KEY => 'test-state-key',
    ]);
    return (new SolidClientFactory($f['config'], $clientFs, $oidc))->create($config, ['handler' => $handler]);
}

$tests = [];
$tests['shared secrets retain the requested WebID and reuse the key'] = function (array $f) {
    foreach (['000000000001', '000000000002', '000000000003'] as $mac) {
        device($f, $mac, 'https://pod.example/' . $mac . '#me');
    }
    foreach (['000000000003', '000000000001', '000000000002'] as $mac) {
        $webId = 'https://pod.example/' . $mac . '#me';
        $first = register($f, $webId);
        check($first['status'] === 201, 'First registration must return 201');
        check($first['content']['webid'] === $webId, 'Registration changed the WebID');
        $again = register($f, $webId);
        check($again['status'] === 200, 'Repeat registration must return 200');
        check($again['content'] === $first['content'], 'Retry must return the same key and WebID');
        check(count(keyFiles($f, $webId)) === 1, 'Expected exactly one key for this device');

        $data = new DataController($f['fs'], $f['solid'], new ErrorResponse(), $f['config']);
        // Exercise data authorization without attempting a Solid write.
        $post = new ReflectionMethod($data, 'handlePostRequest');
        $request = new ServerRequest('POST', 'https://webhook.example/api/data', [
            'Authorization' => 'Bearer ' . $first['content']['api_key'],
            'X-MAC-Address' => $mac,
        ]);
        check($post->invoke($data, $request, '')['status'] === 422, 'Correct key/MAC must reach payload validation');
        $otherMac = $mac === '000000000001' ? '000000000002' : '000000000001';
        check($post->invoke($data, $request->withHeader('X-MAC-Address', $otherMac), '')['status'] === 403, 'Wrong MAC must be rejected');
    }
    check(count(keyFiles($f)) === 3, 'Devices sharing a secret need distinct keys');
};
$tests['failed authentication never creates or removes registration data'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    device($f, '000000000001', $webId);
    $f['fs']->write('keys/legacy-a.key', $webId);
    $f['fs']->write('keys/legacy-b.key', $webId);
    foreach ([['', 'shared-test-secret', 422], ['not-a-url', 'shared-test-secret', 422],
        [$webId, '', 401], [$webId, 'wrong-secret', 403],
        ['https://pod.example/unknown#me', 'shared-test-secret', 404],
        ['https://pod.example/device#someone-else', 'shared-test-secret', 404]] as [$input, $secret, $status]) {
        $before = snapshot($f);
        check(register($f, $input, $secret)['status'] === $status, 'Unexpected failure status');
        check(snapshot($f) === $before, 'Failed request changed stored registration data');
    }
    $f['fs']->delete('keys/000000000001.secret');
    $before = snapshot($f);
    check(register($f, $webId)['status'] === 404, 'Missing secret file must fail');
    check(snapshot($f) === $before, 'Missing secret file caused writes');
    device($f, '000000000001', $webId);
    device($f, '000000000002', $webId);
    $before = snapshot($f);
    check(register($f, $webId)['status'] === 409, 'Ambiguous MAC mapping must fail');
    check(snapshot($f) === $before, 'Ambiguous mapping caused writes');
};
$tests['legacy duplicates converge to one stable key without affecting other devices'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    $other = 'https://pod.example/other#me';
    device($f, '000000000001', $webId);
    device($f, '000000000002', $other);
    foreach (['z-key', 'a-key', 'm-key'] as $key) {
        $f['fs']->write('keys/' . $key . '.key', $webId);
    }
    $f['fs']->write('keys/other.key', $other);
    $result = register($f, $webId);
    check($result['status'] === 200 && $result['content']['api_key'] === 'a-key', 'Must keep the lexically first existing key');
    check(keyFiles($f) === ['keys/a-key.key' => $webId, 'keys/other.key' => $other], 'Duplicate cleanup affected the wrong files');
    check(register($f, $webId)['content'] === $result['content'], 'Consolidated key must remain stable');
};
$tests['a registration directory left by the old bug receives one correct key'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    device($f, '000000000001', $webId);
    $f['fs']->createDirectory($f['controller']->hashUrl($webId, 'sha1'));
    $result = register($f, $webId);
    check($result['status'] === 200, 'Existing directory must return 200');
    check(count(keyFiles($f, $webId)) === 1, 'Existing directory still needs a usable key');
    check(register($f, $webId)['content'] === $result['content'], 'Recovered registration changed on retry');
};
$tests['v0.3 retains conflict behavior'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    check(register($f, $webId, '', 'v0.3')['status'] === 201, 'v0.3 initial registration changed');
    check(register($f, $webId, '', 'v0.3')['status'] === 409, 'v0.3 retry must conflict');
    check(count(keyFiles($f)) === 1, 'v0.3 retry created another key');
};
$tests['v0.4 retains consent and conflict behavior'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    $solid = solidFixture($f, $webId);
    $f['controller'] = new RegisterController($f['fs'], $solid, new ErrorResponse(), $f['config']);
    check(register($f, $webId, '', 'v0.4')['status'] === 407, 'v0.4 must require consent');
    check(keyFiles($f) === [], 'Missing consent created a key');
    $solid->persistGrantForWebId($webId, ['solid_access_token' => 'test-token']);
    check(register($f, $webId, '', 'v0.4')['status'] === 201, 'Connected v0.4 WebID must register');
    check(register($f, $webId, '', 'v0.4')['status'] === 409, 'v0.4 retry must conflict');
};
$tests['pod retries return the existing WebID without calling the provider'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    device($f, '000000000001', $webId);
    $controller = new PodCreationController($f['fs'], $f['solid'], new ErrorResponse(), $f['config']);
    $controller->setHttpClient(new Client(['handler' => function () {
        throw new RuntimeException('Existing MAC must not create a pod');
    }]));
    $request = new ServerRequest('POST', 'https://webhook.example/api/pod', ['X-Client-Secret' => 'shared-test-secret'], '000000000001');
    for ($i = 0; $i < 3; ++$i) {
        $response = $controller->handleRequest($request);
        check($response['status'] === 200 && $response['content']['webid'] === $webId, 'Pod retry must preserve the WebID');
        $request->getBody()->rewind();
    }
    check($controller->handleRequest($request->withHeader('X-Client-Secret', 'wrong'))['status'] === 403, 'Wrong pod secret must fail');
};
$tests['pod creation followed by retries calls the provider once'] = function (array $f) {
    $webId = 'https://pod.example/device#me';
    $solid = solidFixture($f, $webId);
    $controller = new PodCreationController($f['fs'], $solid, new ErrorResponse(), $f['config']);
    $providerCalls = 0;
    $controller->setHttpClient(new Client(['handler' => function () use (&$providerCalls, $webId) {
        ++$providerCalls;
        return Create::promiseFor(new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'webId' => $webId, 'storageUrl' => 'https://pod.example/storage/',
            'access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token',
        ])));
    }]));
    foreach ([201, 200, 200] as $expectedStatus) {
        $response = $controller->handleRequest(new ServerRequest(
            'POST', 'https://webhook.example/api/pod', ['X-Client-Secret' => 'shared-test-secret'], '000000000001'
        ));
        check($response['status'] === $expectedStatus, 'Unexpected pod status: ' . json_encode($response));
        check($response['content']['webid'] === $webId, 'Pod retry changed the WebID');
    }
    check($providerCalls === 1, 'Provider was called more than once');
    check($f['fs']->read('keys/000000000001.mac') === $webId, 'Incorrect stored pod mapping');
};

$failures = 0;
foreach ($tests as $name => $test) {
    $f = fixture();
    try {
        $test($f);
        echo "PASS $name\n";
    } catch (Throwable $e) {
        ++$failures;
        fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    } finally {
        cleanup($f);
    }
}
echo count($tests) . " tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
