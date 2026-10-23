<?php
declare(strict_types=1);

/**
 * Front Controller — finance.aicountly.org PHP API
 *
 * All HTTP requests are rewritten to this file via .htaccess. It loads the
 * environment, sets up CORS, resolves the route, applies the auth filter,
 * then dispatches to the controller.
 *
 * Finance keeps no local user database — console.aicountly.org is the sole
 * identity provider (see AUTH_INTEGRATION.md in console-react-app). The
 * PostgreSQL database (FINANCE_DB_*) holds the payout ledger only; routes that
 * need it open it lazily (App\Database\Database), so health and SSO keep
 * working when it is not configured.
 */

/* ------------------------------------------------------------------
 * 1. Bootstrap – Load .env
 * ------------------------------------------------------------------ */

require_once dirname(__DIR__) . '/app/bootstrap.php';
finance_load_env(dirname(__DIR__) . '/.env');

/* ------------------------------------------------------------------
 * 2. Error reporting based on environment
 * ------------------------------------------------------------------ */

// Errors are shown only when APP_ENV says development / local explicitly: a missing or mistyped APP_ENV on the
// server must not print stack traces, file paths or SQL to clients (security audit, Oct 2026).
$appEnv = $_ENV['APP_ENV'] ?? 'production';

if (!in_array($appEnv, ['development', 'local', 'testing'], true)) {
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
require_once dirname(__DIR__) . '/app/Filters/ServiceKeyFilter.php';
require_once dirname(__DIR__) . '/app/Filters/S2sFilter.php';

/* ------------------------------------------------------------------
 * 4. CORS — never for the signed server-to-server routes (v1/inbound/manage/…):
 *    no browser may call them, with or without a staff session.
 * ------------------------------------------------------------------ */

if (!S2sFilter::isS2sPath((string) ($_SERVER['REQUEST_URI'] ?? '/'))) {
    CorsFilter::handle();
}

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

    if (!empty($matchedRoute['inbound'])) {
        ServiceKeyFilter::handle((string) $matchedRoute['inbound']);
    }

    // AIC-S2S v1 (Manage → Finance): signature, nonce, rate limit. Answers with the contract envelope.
    if (!empty($matchedRoute['s2s'])) {
        S2sFilter::handle((array) $matchedRoute['s2s']);
    }

    if (!empty($matchedRoute['auth'])) {
        AuthFilter::handle();

        // Role gate (FinanceAccessPolicy::GATES). An authenticated route
        // without a gate is only v1/auth/me — tests/run.php enforces that.
        if (isset($matchedRoute['gate'])
            && !\App\Services\FinanceAccessPolicy::allows((array) ($currentUser['roles'] ?? []), (string) $matchedRoute['gate'])) {
            json_forbidden(\App\Services\FinanceAccessPolicy::MSG_ROLE_FORBIDDEN);
        }
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
} catch (\App\Support\ApiException $e) {
    // Written for the caller (validation, conflict, four-eyes, not configured): shown in every environment.
    $errors = $e->errors();
    if ($e->errorCode() !== null && !array_key_exists('code', $errors)) {
        $errors['code'] = $e->errorCode();
    }
    json_error($e->getMessage(), $errors, $e->status());
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
