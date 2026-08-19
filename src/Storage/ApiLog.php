<?php







namespace holastack\Storage;

use holastack\DB\Database;

class ApiLog
{
    const MAX_ROWS = 10000;

    



    public static function record(array $entry): void
    {
        try {
            Database::execute(
                "INSERT INTO api_logs
                  (created_at, method, path, status, latency_ms, ip, user_id, username, role, tenant_id, application_id, query, body_size)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    (int) ($entry['created_at'] ?? time()),
                    substr((string) ($entry['method'] ?? ''), 0, 8),
                    substr((string) ($entry['path'] ?? ''), 0, 255),
                    (int) ($entry['status'] ?? 0),
                    max(0, (int) ($entry['latency_ms'] ?? 0)),
                    substr((string) ($entry['ip'] ?? ''), 0, 64),
                    (int) ($entry['user_id'] ?? 0),
                    substr((string) ($entry['username'] ?? ''), 0, 64),
                    substr((string) ($entry['role'] ?? ''), 0, 16),
                    (int) ($entry['tenant_id'] ?? 0),
                    (int) ($entry['application_id'] ?? 0),
                    substr((string) ($entry['query'] ?? ''), 0, 512),
                    max(0, (int) ($entry['body_size'] ?? 0)),
                ]
            );
        } catch (\Throwable $e) {
            error_log('ApiLog::record failed: ' . $e->getMessage());
        }
        

        try {
            $cnt = (int) Database::fetch("SELECT COUNT(*) AS c FROM api_logs")['c'];
            if ($cnt > self::MAX_ROWS) {
                $del = $cnt - self::MAX_ROWS;
                Database::execute("DELETE FROM api_logs WHERE id IN (SELECT id FROM api_logs ORDER BY id ASC LIMIT $del)");
            }
        } catch (\Throwable $e) {
            error_log('ApiLog::trim failed: ' . $e->getMessage());
        }
    }

    



    public static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if (is_string($v) && $v !== '') {
                

                $ip = trim(explode(',', $v)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    






    public static function list(array $user, array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $role = (string) ($user['role'] ?? '');
        $scope = ($role === 'admin' || $role === 'operator') ? 'all' : 'tenant';
        if ($scope === 'tenant') {
            $where[] = 'tenant_id=?';
            $params[] = (int) ($user['tenant_id'] ?? 0);
        }
        if (!empty($filters['tenant_id'])) {
            $where[] = 'tenant_id=?';
            $params[] = (int) $filters['tenant_id'];
        }
        if (!empty($filters['application_id'])) {
            $where[] = 'application_id=?';
            $params[] = (int) $filters['application_id'];
        }
        if (!empty($filters['ip'])) {
            $where[] = 'ip=?';
            $params[] = (string) $filters['ip'];
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $where[] = 'status=?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'method=?';
            $params[] = strtoupper((string) $filters['method']);
        }
        if (!empty($filters['path_contains'])) {
            $where[] = 'path LIKE ?';
            $params[] = '%' . (string) $filters['path_contains'] . '%';
        }
        if (!empty($filters['kind'])) {
            $kind = (string) $filters['kind'];
            if ($kind === 'api') {
                $where[] = "(path = '/api' OR path LIKE '/api/%')";
            } elseif ($kind === 'v1') {
                $where[] = "(path = '/v1' OR path LIKE '/v1/%')";
            }
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at>=?';
            $params[] = (int) $filters['since'];
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = Database::fetchAll(
            "SELECT id, created_at, method, path, status, latency_ms, ip, user_id, username, role, tenant_id, application_id, query, body_size
             FROM api_logs$whereSql
             ORDER BY id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        $total = (int) Database::fetch("SELECT COUNT(*) AS c FROM api_logs$whereSql", $params)['c'];
        return ['rows' => $rows, 'total' => $total];
    }

    public static function clearOlderThan(int $cutoff): int
    {
        try {
            $cnt = (int) Database::fetch("SELECT COUNT(*) AS c FROM api_logs WHERE created_at<?", [$cutoff])['c'];
            Database::execute("DELETE FROM api_logs WHERE created_at<?", [$cutoff]);
            return $cnt;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
