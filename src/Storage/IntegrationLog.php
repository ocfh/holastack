<?php

namespace holastack\Storage;

use holastack\DB\Database;

class IntegrationLog
{
    const MAX_ROWS = 10000;
    const MAX_BODY = 16000;

    public static function record(array $entry): void
    {
        try {
            Database::execute(
                "INSERT INTO integration_logs
                  (created_at, owner_id, app_id, integration_id, kind, event, `trigger`, dev_eui, dev_addr, fcnt, fport,
                   target, request_body, http_status, ok, latency_ms, message)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    (int) ($entry['created_at'] ?? time()),
                    (int) ($entry['owner_id'] ?? 0),
                    (int) ($entry['app_id'] ?? 0),
                    (int) ($entry['integration_id'] ?? 0),
                    substr((string) ($entry['kind'] ?? 'WEBHOOK'), 0, 24),
                    substr((string) ($entry['event'] ?? 'up'), 0, 12),
                    substr((string) ($entry['trigger'] ?? 'uplink'), 0, 16),
                    substr((string) ($entry['dev_eui'] ?? ''), 0, 32),
                    substr((string) ($entry['dev_addr'] ?? ''), 0, 16),
                    (int) ($entry['fcnt'] ?? 0),
                    (int) ($entry['fport'] ?? 0),
                    substr((string) ($entry['target'] ?? ''), 0, 512),
                    self::shrinkBody((string) ($entry['request_body'] ?? '')),
                    (int) ($entry['http_status'] ?? 0),
                    !empty($entry['ok']) ? 1 : 0,
                    max(0, (int) ($entry['latency_ms'] ?? 0)),
                    substr((string) ($entry['message'] ?? ''), 0, 512),
                ]
            );
        } catch (\Throwable $e) {
            error_log('IntegrationLog::record failed: ' . $e->getMessage());
        }

        try {
            $cnt = (int) Database::fetch("SELECT COUNT(*) AS c FROM integration_logs")['c'];
            if ($cnt > self::MAX_ROWS) {
                $del = $cnt - self::MAX_ROWS;
                Database::execute("DELETE FROM integration_logs WHERE id IN (SELECT id FROM integration_logs ORDER BY id ASC LIMIT $del)");
            }
        } catch (\Throwable $e) {
            error_log('IntegrationLog::trim failed: ' . $e->getMessage());
        }
    }

    private static function shrinkBody(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY) {
            return $body;
        }
        return substr($body, 0, self::MAX_BODY) . "\n...(truncated)";
    }

    public static function list(array $user, array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $role = (string) ($user['role'] ?? '');
        $scope = ($role === 'admin' || $role === 'operator') ? 'all' : 'owner';
        if ($scope === 'owner') {
            $where[] = 'owner_id=?';
            $params[] = (int) ($user['id'] ?? 0);
        }
        if (!empty($filters['owner_id'])) {
            $where[] = 'owner_id=?';
            $params[] = (int) $filters['owner_id'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 'app_id=?';
            $params[] = (int) $filters['app_id'];
        }
        if (!empty($filters['integration_id'])) {
            $where[] = 'integration_id=?';
            $params[] = (int) $filters['integration_id'];
        }
        if (!empty($filters['dev_eui'])) {
            $where[] = 'dev_eui LIKE ?';
            $params[] = '%' . preg_replace('/[^0-9a-fA-F]/', '', (string) $filters['dev_eui']) . '%';
        }
        if (!empty($filters['kind'])) {
            $where[] = 'kind=?';
            $params[] = strtoupper((string) $filters['kind']);
        }
        if (!empty($filters['event'])) {
            $where[] = 'event=?';
            $params[] = strtolower((string) $filters['event']);
        }
        if (isset($filters['ok']) && $filters['ok'] !== '' && $filters['ok'] !== null) {
            $where[] = 'ok=?';
            $params[] = $filters['ok'] ? 1 : 0;
        }
        if (!empty($filters['target_contains'])) {
            $where[] = 'target LIKE ?';
            $params[] = '%' . (string) $filters['target_contains'] . '%';
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at>=?';
            $params[] = (int) $filters['since'];
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = Database::fetchAll(
            "SELECT id, created_at, owner_id, app_id, integration_id, kind, event, `trigger`, dev_eui, dev_addr,
                    fcnt, fport, target, request_body, http_status, ok, latency_ms, message
             FROM integration_logs$whereSql
             ORDER BY id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        $total = (int) Database::fetch("SELECT COUNT(*) AS c FROM integration_logs$whereSql", $params)['c'];
        return ['rows' => $rows, 'total' => $total];
    }

    public static function clearAll(array $user): int
    {
        try {
            $role = (string) ($user['role'] ?? '');
            if ($role === 'admin' || $role === 'operator') {
                return Database::execute("DELETE FROM integration_logs");
            }
            return Database::execute("DELETE FROM integration_logs WHERE owner_id=?", [(int) ($user['id'] ?? 0)]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
