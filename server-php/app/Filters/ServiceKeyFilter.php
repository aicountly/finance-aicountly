<?php
declare(strict_types=1);

require_once __DIR__ . '/../Helpers/ResponseHelper.php';

/**
 * Server-to-server authentication for inbound calls from other Aicountly
 * services (contract: tokens ≥ 32 characters, compared with hash_equals,
 * 503 when unset on this side, 401 when missing or wrong).
 *
 *   Engage → Finance   header X-Engage-Key, env FINANCE_INBOUND_ENGAGE_KEY
 */
class ServiceKeyFilter
{
    public const SERVICES = [
        'engage' => ['env' => 'FINANCE_INBOUND_ENGAGE_KEY', 'header' => 'X-Engage-Key', 'label' => 'Engage'],
    ];

    public static function handle(string $service): void
    {
        $cfg = self::SERVICES[$service] ?? null;
        if ($cfg === null) {
            json_error('Unknown inbound service.', [], 500);
        }
        $expected = finance_env($cfg['env']);
        if (strlen($expected) < 32) {
            json_error("{$cfg['label']} inbound is not configured on Finance ({$cfg['env']}).", [], 503);
        }
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $cfg['header']));
        $provided  = isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) ? trim($_SERVER[$serverKey]) : '';
        if ($provided === '' || !hash_equals($expected, $provided)) {
            json_unauthorized("Missing or invalid {$cfg['header']}.");
        }
    }

    public static function isConfigured(string $service): bool
    {
        $cfg = self::SERVICES[$service] ?? null;

        return $cfg !== null && strlen(finance_env($cfg['env'])) >= 32;
    }
}
