<?php

namespace Meent\WebHook;

class Session
{
    private static self $instance;

    private function __construct() {}

    final public static function current()
    {
        if (! isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    final public function has($key): bool
    {
        return isset($_SESSION[$key]);
    }

    final public function remove($key)
    {
        $value = $_SESSION[$key] ?? null;

        unset($_SESSION[$key]);

        return $value;
    }

    final public function get($key)
    {
        return $_SESSION[$key] ?? null;
    }

    final public function set($key, $value)
    {
        $_SESSION[$key] = $value;

        return $value;
    }
}
