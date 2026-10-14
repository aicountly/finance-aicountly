<?php
declare(strict_types=1);

/**
 * Route table for the Finance API.
 *
 * Every entry: 'method' (controller method), 'controller' (relative to
 * app/Controllers, backslashes map to subfolders), 'auth' (require a valid
 * Finance JWT via AuthFilter).
 */
return [
    'GET' => [
        // Public readiness flags (booleans only) for post-deploy and live verification.
        'health' => [
            'controller' => 'Ops\\HealthController',
            'method'     => 'index',
            'auth'       => false,
        ],
        'v1/auth/sso-callback' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'ssoCallback',
            'auth'       => false,
        ],
        'v1/auth/me' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'me',
            'auth'       => true,
        ],
    ],
    'POST' => [
        'v1/auth/console-session' => [
            'controller' => 'Auth\\AuthController',
            'method'     => 'consoleSession',
            'auth'       => false,
        ],
    ],
];
