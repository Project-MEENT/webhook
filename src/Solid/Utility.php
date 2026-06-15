<?php

namespace Meent\WebHook\Solid;

use Meent\WebHook\Exception\SolidException;

class Utility
{
    final public static function base64UrlDecode($encodedData): string
    {
        $padding = (4 - strlen($encodedData) % 4) % 4;
        $encodedPayload = strtr($encodedData, '-_', '+/') . str_repeat('=', $padding);
        $data = base64_decode($encodedPayload, true);

        if ($data === false) {
            throw SolidException::create('State contains invalid base64url data');
        }

        return $data;
    }

    final public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    final public static function base64UrlJsonDecode(string $encodedData): mixed
    {
        return Utility::jsonDecode(Utility::base64UrlDecode($encodedData));
    }

    final public static function base64UrlJsonEncode(array $json): string
    {
        return Utility::base64UrlEncode(Utility::jsonEncode($json));
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

    final public static function jsonDecode($json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw SolidException::create('Could not decode JSON: ' . $e->getMessage(), $e);
        }
    }

    final public static function jsonEncode($json, $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($json, $flags);
        } catch (\JsonException $e) {
            throw SolidException::create('Could not encode JSON: ' . $e->getMessage(), $e);
        }
    }
}
