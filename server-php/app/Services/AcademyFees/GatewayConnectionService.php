<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\AuditLog;
use App\Services\DataVault;
use App\Support\ApiException;

/**
 * Payment-gateway connections (table fin_gateway_connections): Aicountly's own Razorpay / Cashfree merchant accounts
 * that Academy fee links are created on. finance_admin only (route gate `settings`).
 *
 *  - The API secret and the webhook secret are encrypted with DataVault (FINANCE_DATA_KEY), like company bank account
 *    numbers. They are write-only: no API answer, audit row or log line ever carries them — only "set" flags and the
 *    last four characters of the API secret.
 *  - One connection per provider and mode (test / live). A connection must be enabled to be used; one enabled
 *    connection is the default (used when the caller does not name a provider).
 *  - "Test connection" makes a read-only authenticated call to the provider and stores the verdict.
 */
final class GatewayConnectionService
{
    public const PROVIDERS = ['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree'];
    public const MODES     = ['test' => 'Test (sandbox)', 'live' => 'Live'];

    private const COLUMNS = 'id, provider, mode, label, key_id, key_secret_last4, webhook_secret_enc IS NOT NULL AS webhook_secret_set, enabled, is_default,
                             last_test_at, last_test_ok, last_test_message, created_by, created_at, updated_by, updated_at';

    private AuditLog $audit;

    /** @var (\Closure(array<string,mixed>):GatewayAdapter)|null tests inject adapters */
    private ?\Closure $adapterFactory;

    public function __construct(private \PDO $pdo, private ?DataVault $vault = null, ?\Closure $adapterFactory = null, private ?GatewayHttp $http = null)
    {
        $this->audit          = new AuditLog($pdo);
        $this->adapterFactory = $adapterFactory;
    }

    private function vault(): DataVault
    {
        return $this->vault ??= DataVault::fromEnv();
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string,mixed>> masked, default first */
    public function list(): array
    {
        return array_map([self::class, 'publicRow'],
            $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM fin_gateway_connections ORDER BY is_default DESC, enabled DESC, provider, mode')->fetchAll());
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fin_gateway_connections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Gateway connection not found.');
        }

        return self::publicRow($row);
    }

    /** Enabled connections, without key ids (what the staff "create link" form and Engage may choose from). */
    public function enabledOptions(): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'provider' => (string) $r['provider'], 'mode' => (string) $r['mode'], 'label' => (string) $r['label'],
            'is_default' => (bool) $r['is_default'],
        ], $this->pdo->query('SELECT id, provider, mode, label, is_default FROM fin_gateway_connections WHERE enabled ORDER BY is_default DESC, provider')->fetchAll());
    }

    /**
     * The connection a new link uses: the enabled one for $provider (live preferred over test), else the default.
     *
     * @return array<string,mixed> the raw row (with ciphertext; never returned to a caller)
     */
    public function resolve(?string $provider): array
    {
        if ($provider !== null && $provider !== '') {
            if (!isset(self::PROVIDERS[$provider])) {
                throw ApiException::coded(422, 'validation_failed', 'Unknown provider.', ['provider' => 'razorpay or cashfree.']);
            }
            $stmt = $this->pdo->prepare("SELECT * FROM fin_gateway_connections WHERE enabled AND provider = ? ORDER BY is_default DESC, (mode = 'live') DESC LIMIT 1");
            $stmt->execute([$provider]);
            $row = $stmt->fetch();
            if (!$row) {
                throw ApiException::coded(503, 'gateway_not_configured', self::PROVIDERS[$provider] . ' is not connected in Finance (Settings → Payment gateways).');
            }

            return $row;
        }
        $row = $this->pdo->query('SELECT * FROM fin_gateway_connections WHERE enabled AND is_default LIMIT 1')->fetch();
        if (!$row) {
            throw ApiException::coded(503, 'gateway_not_configured', 'No payment gateway is connected in Finance yet (Settings → Payment gateways).');
        }

        return $row;
    }

    /** @return array<string,mixed> raw row */
    public function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_gateway_connections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Gateway connection not found.');
        }

        return $row;
    }

    /** @param array<string,mixed> $row raw row */
    public function adapter(array $row, ?int $timeout = null): GatewayAdapter
    {
        if ($this->adapterFactory !== null) {
            return ($this->adapterFactory)($row);
        }
        $secret = $this->vault()->decrypt((string) $row['key_secret_enc']);
        $http   = $this->http ?? new GatewayHttp();
        if ($timeout !== null) {
            $http = $http->withTimeout($timeout);
        }

        return $row['provider'] === 'cashfree'
            ? new CashfreeAdapter((string) $row['key_id'], $secret, (string) $row['mode'], $http)
            : new RazorpayAdapter((string) $row['key_id'], $secret, $http);
    }

    /**
     * Secrets a webhook for $provider may be signed with: each enabled (or recently disabled) connection's webhook secret,
     * and for Cashfree its client secret when no separate webhook secret is saved.
     *
     * @return list<array{connection_id:int, secret:string}>
     */
    public function webhookSecrets(string $provider): array
    {
        $stmt = $this->pdo->prepare('SELECT id, key_secret_enc, webhook_secret_enc FROM fin_gateway_connections WHERE provider = ? ORDER BY enabled DESC, id');
        $stmt->execute([$provider]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $enc = $r['webhook_secret_enc'] ?? null;
            if ($enc === null && $provider === 'cashfree') {
                $enc = $r['key_secret_enc'];
            }
            if ($enc === null) {
                continue;
            }
            try {
                $out[] = ['connection_id' => (int) $r['id'], 'secret' => $this->vault()->decrypt((string) $enc)];
            } catch (\Throwable $e) {
                error_log('[finance] gateway connection ' . (int) $r['id'] . ' secret does not decrypt with FINANCE_DATA_KEY.');
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ writes (finance_admin)

    /**
     * @param array<string,mixed> $input {provider, mode, label, key_id, key_secret, webhook_secret?}
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     */
    public function create(array $input, array $actor): array
    {
        $errors   = [];
        $provider = is_string($input['provider'] ?? null) ? strtolower(trim($input['provider'])) : '';
        $mode     = is_string($input['mode'] ?? null) ? strtolower(trim($input['mode'])) : '';
        if (!isset(self::PROVIDERS[$provider])) {
            $errors['provider'] = 'razorpay or cashfree.';
        }
        if (!isset(self::MODES[$mode])) {
            $errors['mode'] = 'test or live.';
        }
        $label = self::text($input['label'] ?? null, 80) ?: ((self::PROVIDERS[$provider] ?? 'Gateway') . ' ' . ($mode ?: ''));
        $keyId = self::keyId($input['key_id'] ?? null, $provider, $mode, $errors);
        $secret = self::secret($input['key_secret'] ?? null, 'key_secret', true, $errors);
        $hook   = self::secret($input['webhook_secret'] ?? null, 'webhook_secret', false, $errors);
        if ($errors !== []) {
            throw ApiException::coded(422, 'validation_failed', 'The connection is invalid.', $errors);
        }
        $vault = $this->vault();
        $exists = $this->pdo->prepare('SELECT 1 FROM fin_gateway_connections WHERE provider = ? AND mode = ?');
        $exists->execute([$provider, $mode]);
        if ($exists->fetch()) {
            throw ApiException::conflict(self::PROVIDERS[$provider] . " ({$mode}) is already connected: edit that connection instead.");
        }

        return $this->tx(function () use ($provider, $mode, $label, $keyId, $secret, $hook, $vault, $actor): array {
            $ins = $this->pdo->prepare(
                'INSERT INTO fin_gateway_connections (provider, mode, label, key_id, key_secret_enc, key_secret_last4, webhook_secret_enc, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            );
            $ins->execute([$provider, $mode, $label, $keyId, $vault->encrypt((string) $secret), DataVault::last4((string) $secret),
                $hook === null ? null : $vault->encrypt($hook), $actor['actor'], $actor['actor']]);
            $id  = (int) $ins->fetchColumn();
            $row = $this->get($id);
            $this->audit->record($actor, 'gateway_connection.created', 'gateway_connection', $id, null, self::snapshot($row));

            return $row;
        });
    }

    /**
     * Edit label / key id; replace a secret only when a new one is sent (blank keeps the stored one).
     *
     * @param array<string,mixed> $input {label?, key_id?, key_secret?, webhook_secret?, clear_webhook_secret?}
     */
    public function update(int $id, array $input, array $actor): array
    {
        $raw    = $this->row($id);
        $before = $this->get($id);
        $errors = [];
        $label  = array_key_exists('label', $input) ? self::text($input['label'], 80) : (string) $raw['label'];
        if ($label === '') {
            $errors['label'] = 'Required.';
        }
        $keyId  = array_key_exists('key_id', $input) ? self::keyId($input['key_id'], (string) $raw['provider'], (string) $raw['mode'], $errors) : (string) $raw['key_id'];
        $secret = self::secret($input['key_secret'] ?? null, 'key_secret', false, $errors);
        $hook   = self::secret($input['webhook_secret'] ?? null, 'webhook_secret', false, $errors);
        if ($errors !== []) {
            throw ApiException::coded(422, 'validation_failed', 'The connection is invalid.', $errors);
        }
        $changedKeys = $keyId !== $raw['key_id'] || $secret !== null;

        return $this->tx(function () use ($id, $raw, $label, $keyId, $secret, $hook, $input, $actor, $before, $changedKeys): array {
            $vault = ($secret !== null || $hook !== null) ? $this->vault() : null;
            $this->pdo->prepare(
                'UPDATE fin_gateway_connections SET label = ?, key_id = ?, key_secret_enc = ?, key_secret_last4 = ?, webhook_secret_enc = ?,
                        last_test_at = CASE WHEN ? THEN NULL ELSE last_test_at END, last_test_ok = CASE WHEN ? THEN NULL ELSE last_test_ok END,
                        last_test_message = CASE WHEN ? THEN NULL ELSE last_test_message END, updated_by = ?, updated_at = now() WHERE id = ?',
            )->execute([
                $label, $keyId,
                $secret !== null ? $vault->encrypt($secret) : $raw['key_secret_enc'],
                $secret !== null ? DataVault::last4($secret) : $raw['key_secret_last4'],
                !empty($input['clear_webhook_secret']) ? null : ($hook !== null ? $vault->encrypt($hook) : $raw['webhook_secret_enc']),
                $changedKeys ? 'true' : 'false', $changedKeys ? 'true' : 'false', $changedKeys ? 'true' : 'false', $actor['actor'], $id,
            ]);
            $after = $this->get($id);
            $this->audit->record($actor, 'gateway_connection.updated', 'gateway_connection', $id, self::snapshot($before),
                self::snapshot($after) + ['key_secret_replaced' => $secret !== null, 'webhook_secret_replaced' => $hook !== null,
                    'webhook_secret_cleared' => !empty($input['clear_webhook_secret'])]);

            return $after;
        });
    }

    public function setEnabled(int $id, bool $enabled, array $actor): array
    {
        $before = $this->get($id);

        return $this->tx(function () use ($id, $enabled, $actor, $before): array {
            $this->pdo->query('SELECT id FROM fin_gateway_connections FOR UPDATE');
            if ($enabled) {
                $hasDefault = (bool) $this->pdo->query('SELECT 1 FROM fin_gateway_connections WHERE is_default')->fetch();
                $this->pdo->prepare('UPDATE fin_gateway_connections SET enabled = true, is_default = ?, updated_by = ?, updated_at = now() WHERE id = ?')
                    ->execute([$hasDefault && !$before['is_default'] ? 'false' : 'true', $actor['actor'], $id]);
            } else {
                $this->pdo->prepare('UPDATE fin_gateway_connections SET enabled = false, is_default = false, updated_by = ?, updated_at = now() WHERE id = ?')
                    ->execute([$actor['actor'], $id]);
                if ($before['is_default']) {
                    // Hand the default to another enabled connection (live first), if any.
                    $this->pdo->exec("UPDATE fin_gateway_connections SET is_default = true WHERE id = (
                        SELECT id FROM fin_gateway_connections WHERE enabled ORDER BY (mode = 'live') DESC, id LIMIT 1)");
                }
            }
            $after = $this->get($id);
            $this->audit->record($actor, $enabled ? 'gateway_connection.enabled' : 'gateway_connection.disabled', 'gateway_connection', $id,
                self::snapshot($before), self::snapshot($after));

            return $after;
        });
    }

    public function makeDefault(int $id, array $actor): array
    {
        $before = $this->get($id);
        if (!$before['enabled']) {
            throw ApiException::conflict('Enable the connection before making it the default.');
        }

        return $this->tx(function () use ($id, $actor, $before): array {
            $this->pdo->exec('UPDATE fin_gateway_connections SET is_default = false WHERE is_default');
            $this->pdo->prepare('UPDATE fin_gateway_connections SET is_default = true, updated_by = ?, updated_at = now() WHERE id = ?')->execute([$actor['actor'], $id]);
            $after = $this->get($id);
            $this->audit->record($actor, 'gateway_connection.made_default', 'gateway_connection', $id, self::snapshot($before), self::snapshot($after));

            return $after;
        });
    }

    /** Provider auth check; the verdict is stored and audited (never the secret). */
    public function test(int $id, array $actor): array
    {
        $raw = $this->row($id);
        $res = $this->adapter($raw, 10)->testConnection();
        $this->pdo->prepare('UPDATE fin_gateway_connections SET last_test_at = now(), last_test_ok = ?, last_test_message = ? WHERE id = ?')
            ->execute([$res['ok'] ? 'true' : 'false', mb_substr((string) $res['message'], 0, 300), $id]);
        $this->audit->record($actor, 'gateway_connection.tested', 'gateway_connection', $id, null, ['ok' => $res['ok'], 'message' => $res['message']]);

        return ['ok' => (bool) $res['ok'], 'message' => (string) $res['message'], 'connection' => $this->get($id)];
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $r */
    public static function publicRow(array $r): array
    {
        return [
            'id'                 => (int) $r['id'],
            'provider'           => (string) $r['provider'],
            'provider_label'     => self::PROVIDERS[$r['provider']] ?? (string) $r['provider'],
            'mode'               => (string) $r['mode'],
            'label'              => (string) $r['label'],
            'key_id'             => (string) $r['key_id'],
            'key_secret'         => 'set (…' . $r['key_secret_last4'] . ')',
            'webhook_secret_set' => (bool) $r['webhook_secret_set'],
            'webhook_secret_note' => $r['provider'] === 'cashfree' && !$r['webhook_secret_set'] ? 'Cashfree signs webhooks with the client secret.' : null,
            'enabled'            => (bool) $r['enabled'],
            'is_default'         => (bool) $r['is_default'],
            'last_test_at'       => $r['last_test_at'],
            'last_test_ok'       => $r['last_test_ok'] === null ? null : (bool) $r['last_test_ok'],
            'last_test_message'  => $r['last_test_message'],
            'created_by'         => (string) $r['created_by'],
            'created_at'         => (string) $r['created_at'],
            'updated_by'         => $r['updated_by'],
            'updated_at'         => (string) $r['updated_at'],
        ];
    }

    /** What the audit log keeps of a connection (no secret, not even its last four). */
    private static function snapshot(array $p): array
    {
        return array_intersect_key($p, array_flip(['provider', 'mode', 'label', 'key_id', 'webhook_secret_set', 'enabled', 'is_default']));
    }

    /** @param array<string,string> $errors */
    private static function keyId(mixed $v, string $provider, string $mode, array &$errors): string
    {
        $key = is_string($v) ? trim($v) : '';
        if (!preg_match('/^[A-Za-z0-9_.:-]{6,100}$/', $key)) {
            $errors['key_id'] = 'The key id / app id from the provider dashboard (letters, digits, _ . : -).';

            return $key;
        }
        if ($provider === 'razorpay' && $mode !== '') {
            $want = $mode === 'live' ? 'rzp_live_' : 'rzp_test_';
            if (!str_starts_with($key, $want)) {
                $errors['key_id'] = "A Razorpay {$mode} key id starts with {$want}.";
            }
        }

        return $key;
    }

    /** @param array<string,string> $errors */
    private static function secret(mixed $v, string $field, bool $required, array &$errors): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        if ($s === '') {
            if ($required) {
                $errors[$field] = 'Required.';
            }

            return null;
        }
        if (strlen($s) < 8 || strlen($s) > 200 || preg_match('/\s/', $s)) {
            $errors[$field] = 'Paste the value from the provider dashboard (8–200 characters, no spaces).';

            return null;
        }

        return $s;
    }

    private static function text(mixed $v, int $max): string
    {
        return is_string($v) ? mb_substr(trim((string) preg_replace('/\s+/', ' ', $v)), 0, $max) : '';
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $out = $fn();
            $this->pdo->commit();

            return $out;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
