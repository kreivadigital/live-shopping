<?php

declare(strict_types=1);

final class Config
{
    private static bool $loaded = false;

    /**
     * Loads environment variables from the project .env file once.
     */
    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = rtrim($basePath, '/') . '/.env';
        if (is_file($envPath) && is_readable($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }

                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    if ($key === '') {
                        continue;
                    }

                    $value = self::stripQuotes($value);
                    if (getenv($key) === false) {
                        putenv($key . '=' . $value);
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Returns a configuration value from the process environment.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Removes matching wrapping quotes from a raw environment value.
     */
    private static function stripQuotes(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
