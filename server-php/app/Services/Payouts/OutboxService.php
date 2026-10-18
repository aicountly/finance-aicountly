<?php
declare(strict_types=1);

namespace App\Services\Payouts;

/**
 * Finance → Engage payout-status callbacks, through the `outbox` table.
 *
 * Events are written in the same transaction as the status change they report
 * (approve, pay, fail, reverse, cancel), so a callback is never lost and never
 * sent for a change that rolled back. bin/outbox-deliver.php (cron, every 5 min)
 * then POSTs each one:
 *
 *   POST {ENGAGE_API_BASE_URL}/v1/inbound/finance/payout-status
 *   X-Finance-Key: {ENGAGE_FINANCE_KEY}         (Engage compares it with ENGAGE_INBOUND_FINANCE_KEY)
 *   Idempotency-Key: {event_key}
 *   {batch_ref, item_ref, status, tds_amount, gst_amount, net_amount, utr, paid_at, reason}
 *
 * 2xx → delivered. Anything else (or no answer) → retried with back-off 2, 4, 8 … minutes
 * (at most 6 h apart); after 12 attempts the event is `dead` until an admin re-queues it.
 * Events for one payout item are delivered strictly in order: a later event waits while an
 * earlier one for the same item is undelivered.
 *
 * Unconfigured (URL or key missing / key under 32 characters): nothing is sent and nothing is
 * marked delivered — events wait in the outbox.
 */
final class OutboxService
{
    public const PATH         = '/v1/inbound/finance/payout-status';
    public const MAX_ATTEMPTS = 12;
    public const MAX_DELAY    = 360;

    /** @var callable(string,array<string,string>,string):array{status:int, error:?string} */
    private $post;

    public function __construct(private \PDO $pdo, ?callable $post = null)
    {
        $this->post = $post ?? [self::class, 'curlPost'];
    }

    public static function isConfigured(): bool
    {
        return finance_env('ENGAGE_API_BASE_URL') !== '' && strlen(finance_env('ENGAGE_FINANCE_KEY')) >= 32;
    }

    /**
     * Queue one payout-status callback for an item.
     *
     * @param array<string,mixed> $item  payout_items row (with batch_ref)
     */
    public function enqueueItemStatus(array $item, string $status, ?string $reason = null): void
    {
        $payload = [
            'batch_ref'  => (string) $item['batch_ref'],
            'item_ref'   => (string) $item['item_ref'],
            'status'     => $status,
            'tds_amount' => (string) $item['tds_amount'],
            'gst_amount' => (string) $item['gst_amount'],
            'net_amount' => (string) $item['net_amount'],
            'utr'        => $status === 'paid' || $status === 'reversed' ? ($item['utr'] ?? null) : null,
            'paid_at'    => $status === 'paid' || $status === 'reversed' ? self::iso($item['paid_at'] ?? null) : null,
            'reason'     => $reason,
        ];
        $aggregate = 'item:' . (int) $item['id'];
        $seq = $this->pdo->prepare('SELECT count(*) AS n FROM outbox WHERE aggregate_key = ?');
        $seq->execute([$aggregate]);
        $n = (int) $seq->fetch()['n'] + 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO outbox (event_key, aggregate_key, target, path, payload) VALUES (?, ?, ?, ?, ?::json)',
        );
        $stmt->execute([
            sprintf('finance-payout-%d-%d-%s', (int) $item['id'], $n, $status),
            $aggregate,
            'engage',
            self::PATH,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Deliver due events.
     *
     * @return array{configured:bool, attempted:int, delivered:int, retrying:int, dead:int}
     */
    public function deliver(int $limit = 100): array
    {
        $summary = ['configured' => self::isConfigured(), 'attempted' => 0, 'delivered' => 0, 'retrying' => 0, 'dead' => 0];
        if (!$summary['configured']) {
            return $summary;
        }
        $base = rtrim(finance_env('ENGAGE_API_BASE_URL'), '/');
        $key  = finance_env('ENGAGE_FINANCE_KEY');

        // Rounds, so a run can deliver "approved" and then "paid" for the same item in order.
        while ($summary['attempted'] < $limit) {
            $batch = $this->claim(min(50, $limit - $summary['attempted']));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $summary['attempted']++;
                $res = ($this->post)($base . $row['path'], [
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                    'X-Finance-Key'   => $key,
                    'Idempotency-Key' => (string) $row['event_key'],
                ], (string) $row['payload']);

                $attempts = (int) $row['attempts'] + 1;
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    $this->pdo->prepare(
                        "UPDATE outbox SET status = 'delivered', attempts = ?, last_http_status = ?, last_error = NULL, delivered_at = now() WHERE id = ?",
                    )->execute([$attempts, $res['status'], $row['id']]);
                    $summary['delivered']++;
                    continue;
                }
                $error = $res['error'] ?? ('HTTP ' . $res['status']);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->pdo->prepare(
                        "UPDATE outbox SET status = 'dead', attempts = ?, last_http_status = ?, last_error = ? WHERE id = ?",
                    )->execute([$attempts, $res['status'] ?: null, mb_substr($error, 0, 500), $row['id']]);
                    $summary['dead']++;
                    continue;
                }
                $delay = min(self::MAX_DELAY, 2 ** $attempts);
                $this->pdo->prepare(
                    "UPDATE outbox SET status = 'pending', attempts = ?, last_http_status = ?, last_error = ?,
                            next_attempt_at = now() + make_interval(mins => ?) WHERE id = ?",
                )->execute([$attempts, $res['status'] ?: null, mb_substr($error, 0, 500), $delay, $row['id']]);
                $summary['retrying']++;
            }
        }

        return $summary;
    }

    /**
     * Lease due events (earliest undelivered per item only) for 10 minutes so two
     * overlapping cron runs never send the same event concurrently.
     *
     * @return list<array<string,mixed>>
     */
    private function claim(int $n): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE outbox SET next_attempt_at = now() + interval '10 minutes'
             WHERE id IN (
                SELECT o.id FROM outbox o
                 WHERE o.status = 'pending' AND o.next_attempt_at <= now()
                   AND NOT EXISTS (SELECT 1 FROM outbox p
                                    WHERE p.aggregate_key = o.aggregate_key AND p.id < o.id AND p.status <> 'delivered')
                 ORDER BY o.id
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED)
            RETURNING id, event_key, path, payload::text AS payload, attempts
        SQL);
        $stmt->execute([$n]);
        $rows = $stmt->fetchAll();
        usort($rows, static fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    public function requeue(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE outbox SET status = 'pending', attempts = 0, next_attempt_at = now() WHERE id = ? AND status = 'dead'",
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** @return array{counts:array<string,int>, recent:list<array<string,mixed>>, configured:bool} */
    public function overview(int $limit = 50): array
    {
        $counts = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($this->pdo->query('SELECT status, count(*) AS n FROM outbox GROUP BY status')->fetchAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, event_key, status, attempts, next_attempt_at, last_http_status, last_error, created_at, delivered_at,
                    payload->>'batch_ref' AS batch_ref, payload->>'item_ref' AS item_ref, payload->>'status' AS event_status
               FROM outbox ORDER BY (status = 'delivered'), id DESC LIMIT ?",
        );
        $stmt->execute([$limit]);

        return ['counts' => $counts, 'recent' => $stmt->fetchAll(), 'configured' => self::isConfigured()];
    }

    private static function iso(mixed $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        return (new \DateTimeImmutable((string) $ts))->format(\DateTimeInterface::ATOM);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, error:?string}
     */
    public static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = $answer === false ? curl_error($ch) : null;
        curl_close($ch);
        if ($error === null && ($status < 200 || $status >= 300)) {
            $error = 'HTTP ' . $status . ': ' . mb_substr(is_string($answer) ? $answer : '', 0, 200);
        }

        return ['status' => $status, 'error' => $error];
    }
}
