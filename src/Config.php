<?php

namespace Meent\WebHook;

class Config
{
    public const KEY_ADMIN_WEBIDS = 'admin_webids';
    public const KEY_API_STORAGE_PATH = 'api_storage_path';
    public const KEY_INITIAL_ACCESS_TOKEN = 'client_initial_access_token';
    public const KEY_CLIENT_NAME = 'client_name';
    public const KEY_JWT_TTL = 'jwt_ttl';
    public const KEY_METADATA_CACHE_TTL = 'metadata_cache_ttl';
    public const KEY_SOLID_STORAGE_PATH = 'solid_storage_path';

    private const ERROR_CONFIG_NOT_ARRAY = 'Provided config file must return an array.';
    private const ERROR_FILE_NOT_EXISTS = 'Provided config file "%s" does not exist.';
    private const ERROR_MISSING_REQUIRED_KEYS = 'Missing required config key(s): %s.';
    private const ERROR_UNKNOWN_KEY = 'Unknown config key "%s". Available keys are: %s.';

    private array $config = [];

    private $optionalKeys = [
        self::KEY_INITIAL_ACCESS_TOKEN => 'Initial Access Token for dynamic client registration (optional, only needed if the OP requires it)',
    ];

    private $requiredKeys = [
        self::KEY_ADMIN_WEBIDS => 'List of allowed admin WebID URLs',
        self::KEY_API_STORAGE_PATH => 'Used for persistent storage of data posted to the API',
        self::KEY_CLIENT_NAME => 'MEENT Solid P1 Dongle Webhook',
        self::KEY_JWT_TTL => 'Expiration time of OAuth state JWTs, in seconds.',
        self::KEY_METADATA_CACHE_TTL => 'Expiration time of the OIDC metadata cache, in seconds',
        self::KEY_SOLID_STORAGE_PATH => 'Used for persistent storage of Solid Client and Issuer metadata, DPoP keys, and OAuth state JWTs.',
    ];

    final public static function fromFile(string $filePath): self
    {
        if (! file_exists($filePath)) {
            $message = vsprintf(self::ERROR_FILE_NOT_EXISTS, [$filePath]);
            throw new \RuntimeException($message);
        }

        $config = require $filePath;

        if (! is_array($config)) {
            throw new \RuntimeException(self::ERROR_CONFIG_NOT_ARRAY);
        } else {
            return self::fromArray($config);
        }
    }

    final public static function fromArray(array $config): self
    {
        $instance = new self();

        $instance->validateArray($config);

        $instance->config = $config;

        return $instance;
    }

    public function get(string $key)
    {
        $availableKeys = array_merge(
            array_keys($this->requiredKeys),
            array_keys($this->optionalKeys),
        );

        if (! in_array($key, $availableKeys, true)) {
            $message = vsprintf(self::ERROR_UNKNOWN_KEY, [
                $key,
                implode(', ', $availableKeys),
            ]);

            throw new \RuntimeException($message);
        }

        return $this->config[$key] ?? null;
    }

    private function validateArray(array $config)
    {
        $missingKeys = array_diff(array_keys($this->requiredKeys), array_keys($config));

        if (! empty($missingKeys)) {
            $keys = [];
            foreach ($missingKeys as $missingKey) {
                $keys[] = sprintf("%s (%s)", $missingKey, $this->requiredKeys[$missingKey]);
            }

            $message = vsprintf(self::ERROR_MISSING_REQUIRED_KEYS, [
                implode(', ', $keys),
            ]);

            throw new \RuntimeException($message);
        }

    }
}
