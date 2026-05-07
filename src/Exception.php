<?php

namespace Meent\WebHook;

class Exception extends \Exception
{
    final public static function create(string $message = '', $previous = null): static
    {
        return new static($message, 0, $previous);
    }

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
