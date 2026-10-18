<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the HTTP front controller (public/index.php) and the
 * CLI scripts under bin/ (migrate.php, outbox-deliver.php).
 *
 *  - finance_load_env(): reads server-php/.env into $_ENV / putenv (same rules
 *    the front controller has always used: KEY=value, optional quotes, # comments).
 *  - finance_env(): one place to read a setting — $_ENV first, then getenv(), so
 *    values passed by the process environment (tests, cron) work as well.
 *  - A tiny PSR-4-style autoloader: App\Foo\Bar → app/Foo/Bar.php. No Composer.
 */

if (!function_exists('finance_load_env')) {
    function finance_load_env(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    function finance_env(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $_ENV) && is_scalar($_ENV[$key])) {
            return trim((string) $_ENV[$key]);
        }
        $value = getenv($key);

        return $value === false ? $default : trim((string) $value);
    }

    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
