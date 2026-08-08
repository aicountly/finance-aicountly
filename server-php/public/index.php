<?php
declare(strict_types=1);

/**
 * Front Controller — FINANCE.AICOUNTLY.ORG PHP API
 *
 * All HTTP requests are rewritten to this file via .htaccess. It loads the
 * environment, sets up CORS, resolves the route, applies the auth filter,
 * then dispatches to the controller.
 *
 * Finance keeps no local user database — console.aicountly.org is the sole
 * identity provider (see AUTH_INTEGRATION.md in console-react-app), so this
 * API has no session table and no DB config to bootstrap.
 */

/* ------------------------------------------------------------------
 * 1. Bootstrap – Load .env
 * ------------------------------------------------------------------ */

(function (): void {
    $envFile = dirname(__DIR__) . '/.env';
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
})();

/* ------------------------------------------------------------------
 * 2. Error reporting based on environment
 * ------------------------------------------------------------------ */

$appEnv = $_ENV['APP_ENV'] ?? 'development';

if ($appEnv === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* ------------------------------------------------------------------
 * 3. Load helpers & filters
 * ------------------------------------------------------------------ */

require_once dirname(__DIR__) . '/app/Helpers/ResponseHelper.php';
require_once dirname(__DIR__) . '/app/Helpers/JwtHelper.php';
require_once dirname(__DIR__) . '/app/Filters/CorsFilter.php';
require_once dirname(__DIR__) . '/app/Filters/AuthFilter.php';

/* ------------------------------------------------------------------
 * 4. CORS
 * ------------------------------------------------------------------ */

CorsFilter::handle();

/* ------------------------------------------------------------------
 * 5. Parse request
 * ------------------------------------------------------------------ */

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri    = '/' . trim((string) $requestUri, '/');

// Strip /api/ prefix (the API is deployed inside the document root at /api).
if (str_starts_with($requestUri, '/api/')) {
    $requestUri = '/' . substr($requestUri, 5);
} elseif ($requestUri === '/api') {
    $requestUri = '/';
}

$path = trim($requestUri, '/');

/* ------------------------------------------------------------------
 * 6. Load routes & match
 * ------------------------------------------------------------------ */

$routes = require dirname(__DIR__) . '/app/Config/Routes.php';

$matchedRoute = null;
$routeParams  = [];

if (isset($routes[$requestMethod])) {
    foreach ($routes[$requestMethod] as $pattern => $config) {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            $matchedRoute = $config;

            if (preg_match_all('#\{([a-zA-Z_]+)\}#', $pattern, $paramNames)) {
                foreach ($paramNames[1] as $i => $name) {
                    $routeParams[$name] = $matches[$i + 1];
                }
            }
            break;
        }
    }
}

if ($matchedRoute === null) {
    json_not_found('Route not found.');
}

/* ------------------------------------------------------------------
 * 7. Dispatch with middleware
 * ------------------------------------------------------------------ */

try {
    global $currentUser;
    $currentUser = null;

    if (!empty($matchedRoute['auth'])) {
        AuthFilter::handle();
    }

    $controllerRel  = str_replace('\\', '/', $matchedRoute['controller']);
    $controllerFile = dirname(__DIR__) . "/app/Controllers/{$controllerRel}.php";

    if (!is_file($controllerFile)) {
        json_error("Controller not found: {$matchedRoute['controller']}", [], 500);
    }

    require_once $controllerFile;

    $classParts = explode('\\', $matchedRoute['controller']);
    $className  = end($classParts);

    if (!class_exists($className)) {
        json_error("Controller class not found: {$className}", [], 500);
    }

    $controller = new $className();
    $method     = $matchedRoute['method'];

    if (!method_exists($controller, $method)) {
        json_error("Method not found: {$className}::{$method}", [], 500);
    }

    $controller->setParams($routeParams);
    if (is_array($currentUser)) {
        $controller->setCurrentUser($currentUser);
    }

    $body = [];
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $controller->$method($routeParams, $body);
} catch (\Throwable $e) {
    $code    = exception_http_status($e);
    $message = exception_public_message($e, $appEnv);

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data'    => null,
        'errors'  => $appEnv !== 'production' ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'type' => $e::class,
        ] : [],
    ], JSON_UNESCAPED_SLASHES);
}
