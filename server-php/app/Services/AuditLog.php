<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Append-only audit trail (table audit_log; a trigger refuses UPDATE/DELETE).
 * Callers pass already-masked before/after snapshots — never a full PAN or
 * account number.
 */
final class AuditLog
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(array $actor, string $action, string $entityType, int|string|null $entityId, ?array $before, ?array $after): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor, actor_name, action, entity_type, entity_id, before, after, ip)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)',
        );
        $stmt->execute([
            $actor['actor'],
            $actor['name'] ?? null,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $actor['ip'] ?? null,
        ]);
    }

    /**
     * @param array{entity_type?:string, entity_id?:string, actor?:string, action?:string} $filters
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function list(array $filters, int $page = 1, int $perPage = 50): array
    {
        $where  = [];
        $params = [];
        foreach (['entity_type', 'entity_id', 'actor'] as $col) {
            if (($filters[$col] ?? '') !== '') {
                $where[]  = "{$col} = ?";
                $params[] = $filters[$col];
            }
        }
        if (($filters['action'] ?? '') !== '') {
            $where[]  = 'action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['action']) . '%';
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $page     = max(1, $page);
        $perPage  = max(1, min(200, $perPage));

        $count = $this->pdo->prepare("SELECT count(*) AS n FROM audit_log {$sqlWhere}");
        $count->execute($params);
        $stmt = $this->pdo->prepare(
            "SELECT id, occurred_at, actor, actor_name, action, entity_type, entity_id, before, after, ip
               FROM audit_log {$sqlWhere} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
        );
        $stmt->execute($params);
        $rows = array_map(static function (array $r): array {
            $r['id']     = (int) $r['id'];
            $r['before'] = $r['before'] === null ? null : json_decode((string) $r['before'], true);
            $r['after']  = $r['after'] === null ? null : json_decode((string) $r['after'], true);

            return $r;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetch()['n'], 'page' => $page, 'per_page' => $perPage];
    }
}
