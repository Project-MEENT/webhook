<?php

namespace Meent\WebHook\Solid;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

class Utility
{
    final public static function base64UrlDecode($encodedData)
    {
        $padding = (4 - strlen($encodedData) % 4) % 4;
        $encodedPayload = strtr($encodedData, '-_', '+/') . str_repeat('=', $padding);
        $data = base64_decode($encodedPayload, true);

        if ($data === false) {
            throw new \InvalidArgumentException('State contains invalid base64url data');
        }

        return $data;
    }

    final public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @throws \JsonException
     */
    final public static function decodeUnsafeJwt(string $jwt): array
    {
        $claims = [];

        $parts = explode('.', $jwt);

        if (count($parts) === 3) {
            $payload = self::base64UrlDecode($parts[1]);

            $encodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($encodedPayload)) {
                $claims = $encodedPayload;
            }
        }

        return $claims;
    }

    /**
     * @throws FilesystemException
     * @throws \JsonException
     */
    final public static function saveOfflineGrant(Session $session, FilesystemOperator $filesystem, $issuerHash)
    {
        $snapshot = [
            DpopProofFactory::SESSION_KEY => $session->get(DpopProofFactory::SESSION_KEY),
            'saved_at' => time(),
            'solid_access_token' => $session->get('solid_access_token'),
            'solid_refresh_token' => $session->get('solid_refresh_token'),
            'solid_resource_url' => $session->get('solid_resource_url'),
            'solid_storage_root' => $session->get('solid_storage_root'),
            'solid_token_expiry' => $session->get('solid_token_expiry'),
            'solid_webid' => $session->get('solid_webid'),
        ];

        $grant = array_filter($snapshot, static function ($value): bool {
            return $value !== null && $value !== '';
        });

        // @FIXME: This grant is issuer AND _webid_ specific. ADD WEBID!
        $path = $issuerHash . '/offline-grant.json';
        $encode = json_encode($grant, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $filesystem->write($path, $encode);
    }
}
