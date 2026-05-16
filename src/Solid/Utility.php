<?php

namespace Meent\WebHook\Solid;

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

    final public static function createSignature($data, $key): string
    {
        return static::base64UrlEncode(hash_hmac(
            'sha256',
            $data,
            $key,
            true
        ));
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
}
