<?php

namespace Meent\WebHook\Solid;

use Jose\Component\Core\JWK;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Http\Message\RequestInterface;

class DpopProofFactory
{
    public const SESSION_KEY = 'dpop_private_jwk';

    final public function __construct(
        private JWK $privateJwk,
        private JWSBuilder $jwsBuilder,
        private CompactSerializer $compactSerializer
    ) {}

    final public function getPublicJwkThumbprint(): string
    {
        return $this->privateJwk->toPublic()->thumbprint('sha256');
    }

    /**
     * @throws \Random\RandomException
     * @throws \JsonException
     */
    final public function createProofForRequest(RequestInterface $request, ?string $dpopNonce = null): string
    {
        $claims = [
            // RFC9449 - DPoP - Section 4.2: jti MUST be unique with negligible collision probability.
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($request->getMethod()),
            // RFC9449 - DPoP - Section 4.2: htu excludes query and fragment components.
            'htu' => (string) $request->getUri()->withQuery('')->withFragment(''),
            'iat' => time(),
        ];

        if ($dpopNonce !== null) {
            $claims['nonce'] = $dpopNonce;
        }

        // RFC9449 - DPoP - Section 7.1.  Resource Access Requests
        // When a request presents `Authorization: DPoP <access_token>`, the proof must
        // also carry the `ath` claim from RFC9449 - DPoP - Section 4.2.
        $authorizationHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authorizationHeader, 'DPoP ')) {
            $accessToken = substr($authorizationHeader, 5);

            if ($accessToken !== '') {
                $claims['ath'] = Utility::base64UrlEncode(hash('sha256', $accessToken, true));
            }
        }

        $protectedHeader = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            // RFC9449 - DPoP - Section 4.2: jwk MUST contain the public key, never the private key.
            'jwk' => $this->privateJwk->toPublic()->all(),
        ];

        $payload = json_encode($claims, JSON_THROW_ON_ERROR);

        $jws = $this->jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature($this->privateJwk, $protectedHeader)
            ->build();

        return $this->compactSerializer->serialize($jws, 0);
    }
}
