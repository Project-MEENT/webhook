<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection ReturnTypeCanBeDeclaredInspection
 */

namespace Meent\WebHook\Solid;

use Facile\OpenIDClient\AuthMethod\AuthMethodInterface;
use Facile\OpenIDClient\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

class DpopAuthMethod implements AuthMethodInterface
{
    final public function __construct(
        private AuthMethodInterface $authMethod,
        private DpopProofFactory $dpopProofFactory,
        private Session $sessionStore
    ) {}

    final public function getSupportedMethod(): string
    {
        return $this->authMethod->getSupportedMethod();
    }

    /**
     * @throws \Random\RandomException
     * @throws \JsonException
     */
    final public function createRequest(
        RequestInterface $request,
        ClientInterface $client,
        array $claims
    ): RequestInterface {
        $request = $this->authMethod->createRequest($request, $client, $claims);

        $dpopProof = $this->dpopProofFactory->createProofForRequest($request);
        $this->sessionStore->set('last_dpop_proof', $dpopProof);

        return $request->withHeader('DPoP', $dpopProof);
    }
}

