<?php

namespace Meent\WebHook;

use Meent\WebHook\Exception\RuntimeException;
use Meent\WebHook\Solid\OidcClientConfig;

class Config extends AbstractConfig
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public const ADMIN_WEBIDS = 'admin_webids';
    final public const API_STORAGE_PATH = 'api_storage_path';
    final public const CLIENT_NAME = 'client_name';
    final public const JWT_TTL = 'jwt_ttl';
    final public const METADATA_CACHE_TTL = 'metadata_cache_ttl';
    final public const SOLID_STORAGE_PATH = 'solid_storage_path';
    final public const STATE_SIGNING_KEY = 'state_signing_key';

    //////////////////////////// GETTERS AND SETTERS \\\\\\\\\\\\\\\\\\\\\\\\\\\

    final protected function getRequiredKeys(): array
    {
        return [
            self::ADMIN_WEBIDS => 'List of allowed admin WebID URLs',
            self::API_STORAGE_PATH => 'Used for persistent storage of data posted to the API',
            self::CLIENT_NAME => 'Name of the OIDC client to register with the OP',
            self::JWT_TTL => 'Expiration time of OAuth state JWTs, in seconds.',
            self::METADATA_CACHE_TTL => 'Expiration time of the OIDC metadata cache, in seconds',
            self::SOLID_STORAGE_PATH => 'Used for persistent storage of Solid Client and Issuer metadata, DPoP keys, and OAuth state JWTs.',
            self::STATE_SIGNING_KEY => 'Secret key used to sign OAuth state JWTs. Should be a long random string, and kept secret.',
        ];
    }

    final protected function getOptionalKeys(): array
    {
        return [
            OidcClientConfig::INITIAL_ACCESS_TOKEN => 'Initial Access Token for dynamic client registration (optional, only needed if the OP requires it)',
        ];
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\
    // @FIXME: Save should not live here, neither should $this->filepath

    private string $filepath;
    private const ERROR_CONFIG_NOT_ARRAY = 'Provided config file must return an array.';
    private const ERROR_FILE_NOT_EXISTS = 'Provided config file "%s" does not exist.';
    private const ERROR_SAVE_FAILED = 'Failed to save config';

    final public static function fromFile(string $filePath): self
    {
        if (! file_exists($filePath)) {
            $message = vsprintf(self::ERROR_FILE_NOT_EXISTS, [$filePath]);
            throw RuntimeException::create($message);
        }

        $config = require $filePath;

        if (! is_array($config)) {
            throw RuntimeException::create(self::ERROR_CONFIG_NOT_ARRAY);
        } else {
            $configObject = static::fromArray($config);

            $configObject->filepath = $filePath;

            return $configObject;
        }
    }

    // @FIXME: Save should not live here, neither should $this->filepath
    final public function save(array $config)
    {
        if (! empty($this->filepath)) {
            $values = $this->toArray();
            $filePath = $this->filepath;

            $backupPath = vsprintf('%s.bak-%s', [
                $filePath,
                date('YmdHis'),
            ]);
            $copy = copy($filePath, $backupPath);

            if ($copy) {
                ksort($config);

                $content = vsprintf('<?php return %s;', [
                    var_export($config, true),
                ]);

                $success = file_put_contents($filePath, $content) !== false;

                if ($success) {
                    // Force filesystem (and optionally opcode) cache to update
                    clearstatcache(true, $filePath);
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($filePath, true);
                    }

                    $values = array_merge($values, $config);
                    $this->setValues($values);
                } else {
                    throw RuntimeException::create(self::ERROR_SAVE_FAILED .': could not save file to ' . $filePath);
                }
            } else {
                throw RuntimeException::create(self::ERROR_SAVE_FAILED .': could not create backup of existing config');
            }
        } else {
            throw RuntimeException::create(self::ERROR_SAVE_FAILED .': no filepath present');
        }
    }
}
