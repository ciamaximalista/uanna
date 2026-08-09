<?php

namespace Oannes;

final class Autoload
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Oannes\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }
}

