<?php

namespace Meent\WebHook;

use Meent\WebHook\Exception\RuntimeException;

abstract class AbstractConfig
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private const ERROR_MISSING_REQUIRED_KEYS = 'Missing required config key(s): %s.';
    private const ERROR_UNKNOWN_KEY = 'Unknown config key "%s". Available keys are: %s.';

    private array $values = [];

    //////////////////////////// GETTERS AND SETTERS \\\\\\\\\\\\\\\\\\\\\\\\\\\

    abstract protected function getOptionalKeys(): array;

    abstract protected function getRequiredKeys(): array;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public static function fromArray(array $values): static
    {
        if (method_exists(static::class, '__construct')) {
            $config = new static($values);
        } else {
            $config = new static();
            $config->setValues($values);
        }

        return $config;
    }

    final public function get(string $key)
    {
        $availableKeys = array_merge(
            array_keys($this->getRequiredKeys()),
            array_keys($this->getOptionalKeys()),
        );

        if (! in_array($key, $availableKeys, true)) {
            $message = vsprintf(self::ERROR_UNKNOWN_KEY, [
                $key,
                implode(', ', $availableKeys),
            ]);

            throw RuntimeException::create($message);
        }

        return $this->values[$key] ?? null;
    }

    final public function toArray(): array
    {
        return $this->values;
    }

    final public function with(string $key, $value): static
    {
        $values = $this->values;

        $values[$key] = $value;

        $this->setValues($values);

        return $this;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    protected function setValues(array $values): void
    {
        $this->validate($values);

        $this->values = $values;
    }

    protected function validate(array $config): void
    {
        $requiredKeys = $this->getRequiredKeys();
        $missingKeys = array_diff(array_keys($requiredKeys), array_keys($config));

        if (! empty($missingKeys)) {
            $keys = [];
            foreach ($missingKeys as $missingKey) {
                $keys[] = sprintf("%s (%s)", $missingKey, $requiredKeys[$missingKey]);
            }

            $message = vsprintf(self::ERROR_MISSING_REQUIRED_KEYS, [
                implode(', ', $keys),
            ]);

            throw RuntimeException::create($message);
        }
    }
}
