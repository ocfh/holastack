<?php
namespace holastack\Storage;

use holastack\DB\Database;

class Alert
{
    public const OPERATORS = ['gt', 'ge', 'lt', 'le', 'eq', 'neq', 'in'];
    public const SEVERITIES = ['info', 'warn', 'critical'];

    public static function listRules(int $appId, ?int $tenantId = null): array
    {
        $where = '1=1';
        $args = [];
        if ($appId > 0) {
            $where .= ' AND application_id=?';
            $args[] = $appId;
        }
        if ($tenantId !== null) {
            $where .= ' AND tenant_id=?';
            $args[] = $tenantId;
        }
        return Database::fetchAll("SELECT * FROM alert_rules WHERE $where ORDER BY id DESC", $args);
    }

    public static function getRule(int $id): ?array
    {
        return Database::fetch("SELECT * FROM alert_rules WHERE id=?", [$id]);
    }

    public static function createRule(array $p): array
    {
        $norm = self::normalizeRule($p);
        Database::execute(
            "INSERT INTO alert_rules (tenant_id, application_id, device_id, name, field_key, operator, threshold, severity, notify_group_id, enabled, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                $norm['application_id'],
                $norm['device_id'],
                $norm['name'],
                $norm['field_key'],
                $norm['operator'],
                $norm['threshold'],
                $norm['severity'],
                $norm['notify_group_id'],
                $norm['enabled'],
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function updateRule(int $id, array $p): array
    {
        $m = self::getRule($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $merged = array_merge($m, array_intersect_key($p, array_flip([
            'application_id', 'device_id', 'name', 'field_key', 'operator', 'threshold',
            'severity', 'notify_group_id', 'enabled',
        ])));
        $norm = self::normalizeRule($merged);
        Database::execute(
            "UPDATE alert_rules SET name=?, field_key=?, operator=?, threshold=?, severity=?, notify_group_id=?, enabled=? WHERE id=?",
            [$norm['name'], $norm['field_key'], $norm['operator'], $norm['threshold'], $norm['severity'], $norm['notify_group_id'], $norm['enabled'], $id]
        );
        return ['id' => $id];
    }

    public static function deleteRule(int $id): array
    {
        Database::execute("DELETE FROM alert_rules WHERE id=?", [$id]);
        return ['id' => $id];
    }

    private static function normalizeRule(array $p): array
    {
        $op = strtolower((string) ($p['operator'] ?? 'gt'));
        if (!in_array($op, self::OPERATORS, true)) {
            $op = 'gt';
        }
        $sev = strtolower((string) ($p['severity'] ?? 'warn'));
        if (!in_array($sev, self::SEVERITIES, true)) {
            $sev = 'warn';
        }
        return [
            'application_id' => (int) ($p['application_id'] ?? 0),
            'device_id'      => (int) ($p['device_id'] ?? 0),
            'name'           => mb_substr((string) ($p['name'] ?? ''), 0, 128),
            'field_key'      => (string) ($p['field_key'] ?? ''),
            'operator'       => $op,
            'threshold'      => (string) ($p['threshold'] ?? ''),
            'severity'       => $sev,
            'notify_group_id' => (int) ($p['notify_group_id'] ?? 0),
            'enabled'        => empty($p['enabled']) ? 0 : 1,
        ];
    }

    public static function listGroups(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll("SELECT * FROM alert_notification_groups WHERE tenant_id=? ORDER BY id DESC", [$tenantId]);
        }
        return Database::fetchAll("SELECT * FROM alert_notification_groups ORDER BY id DESC");
    }

    public static function getGroup(int $id): ?array
    {
        return Database::fetch("SELECT * FROM alert_notification_groups WHERE id=?", [$id]);
    }

    public static function createGroup(array $p): array
    {
        Database::execute(
            "INSERT INTO alert_notification_groups (tenant_id, name, webhook_url, enabled, created_at) VALUES (?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                mb_substr((string) ($p['name'] ?? ''), 0, 128),
                (string) ($p['webhook_url'] ?? ''),
                empty($p['enabled']) ? 0 : 1,
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function updateGroup(int $id, array $p): array
    {
        $m = self::getGroup($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $name = array_key_exists('name', $p) ? mb_substr((string) $p['name'], 0, 128) : $m['name'];
        $url = array_key_exists('webhook_url', $p) ? (string) $p['webhook_url'] : $m['webhook_url'];
        $enabled = array_key_exists('enabled', $p) ? (empty($p['enabled']) ? 0 : 1) : (int) $m['enabled'];
        Database::execute("UPDATE alert_notification_groups SET name=?, webhook_url=?, enabled=? WHERE id=?", [$name, $url, $enabled, $id]);
        return ['id' => $id];
    }

    public static function deleteGroup(int $id): array
    {
        Database::execute("DELETE FROM alert_notification_groups WHERE id=?", [$id]);
        Database::execute("UPDATE alert_rules SET notify_group_id=0 WHERE notify_group_id=?", [$id]);
        return ['id' => $id];
    }

    public static function listAlerts(?int $tenantId, int $limit, int $offset, ?int $deviceId = null, string $status = ''): array
    {
        $where = '1=1';
        $args = [];
        if ($tenantId !== null) {
            $where .= ' AND tenant_id=?';
            $args[] = $tenantId;
        }
        if ($deviceId !== null && $deviceId > 0) {
            $where .= ' AND device_id=?';
            $args[] = $deviceId;
        }
        if ($status !== '' && in_array($status, ['triggered', 'resolved'], true)) {
            $where .= ' AND status=?';
            $args[] = $status;
        }
        $limit = max(1, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        return Database::fetchAll("SELECT * FROM alerts WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
    }

    public static function activeAlerts(?int $tenantId, int $limit = 100): array
    {
        return self::listAlerts($tenantId, $limit, 0, null, 'triggered');
    }

    public static function counts(?int $tenantId): array
    {
        $where = '';
        if ($tenantId !== null) {
            $where = 'tenant_id=' . (int) $tenantId;
        }
        $triggered = (int) Database::fetchOne(
            'SELECT COUNT(*) FROM alerts' . ($where !== '' ? " WHERE $where AND" : ' WHERE') . " status='triggered'"
        );
        $today = (int) Database::fetchOne(
            'SELECT COUNT(*) FROM alerts' . ($where !== '' ? " WHERE $where AND" : ' WHERE') . ' ts>=' . mktime(0, 0, 0)
        );
        $total = (int) Database::fetchOne('SELECT COUNT(*) FROM alerts' . ($where !== '' ? " WHERE $where" : ''));
        return ['triggered' => $triggered, 'today' => $today, 'total' => $total];
    }

    public static function resolve(int $alertId): array
    {
        Database::execute("UPDATE alerts SET status='resolved' WHERE id=?", [$alertId]);
        return ['id' => $alertId];
    }

    public static function evaluate(array $device, array $decoded): void
    {
        if ($decoded === []) {
            return;
        }
        $devId = (int) ($device['id'] ?? 0);
        $appId = (int) ($device['app_id'] ?? $device['application_id'] ?? 0);
        if ($appId <= 0) {
            return;
        }
        $tenantId = (int) ($device['tenant_id'] ?? 0);
        $rules = Database::fetchAll(
            "SELECT * FROM alert_rules WHERE enabled=1 AND application_id=? AND (device_id=0 OR device_id=?)",
            [$appId, $devId]
        );
        foreach ($rules as $rule) {
            self::evalRule($devId, $tenantId, $device, $rule, $decoded);
        }
    }

    private static function evalRule(int $devId, int $tenantId, array $device, array $rule, array $decoded): void
    {
        $key = (string) ($rule['field_key'] ?? '');
        if ($key === '' || !isset($decoded[$key])) {
            return;
        }
        $v = $decoded[$key];
        $value = $v['value'] ?? null;
        $text = (string) ($v['text'] ?? '');
        $match = self::match((string) ($rule['operator'] ?? 'gt'), $value, (string) ($rule['threshold'] ?? ''));

        $open = Database::fetch("SELECT id FROM alerts WHERE rule_id=? AND device_id=? AND status='triggered' ORDER BY id DESC LIMIT 1", [$rule['id'], $devId]);
        $devName = (string) ($device['name'] ?? ("#{$devId}"));
        $ruleName = (string) ($rule['name'] ?? $key);
        $sev = (string) ($rule['severity'] ?? 'warn');

        if ($match) {
            if ($open) {
                return;
            }
            $opLabel = self::opLabel((string) ($rule['operator'] ?? 'gt'));
            $msg = sprintf('%s 字段「%s」值 %s %s %s，触发告警', $devName, $key, $text, $opLabel, (string) ($rule['threshold'] ?? ''));
            Database::execute(
                "INSERT INTO alerts (tenant_id, device_id, device_name, rule_id, rule_name, field_key, value, text_value, severity, status, message, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$tenantId, $devId, $devName, $rule['id'], $ruleName, $key, is_numeric($value) ? (float) $value : 0, mb_substr($text, 0, 255), $sev, 'triggered', mb_substr($msg, 0, 255), time()]
            );
            self::notify($rule, $devName, $msg, $sev, 'triggered', ['field' => $key, 'value' => $value, 'text' => $text, 'device_id' => $devId]);
        } elseif ($open) {
            $msg = sprintf('%s 字段「%s」已恢复为 %s', $devName, $key, $text);
            Database::execute("UPDATE alerts SET status='resolved', value=?, text_value=? WHERE id=?", [is_numeric($value) ? (float) $value : 0, mb_substr($text, 0, 255), $open['id']]);
            self::notify($rule, $devName, $msg, $sev, 'resolved', ['field' => $key, 'value' => $value, 'text' => $text, 'device_id' => $devId]);
        }
    }

    private static function match(string $op, $value, string $threshold): bool
    {
        $num = is_numeric($value);
        $tnum = is_numeric($threshold);
        switch ($op) {
            case 'gt':  return $num && $tnum && (float) $value > (float) $threshold;
            case 'ge':  return $num && $tnum && (float) $value >= (float) $threshold;
            case 'lt':  return $num && $tnum && (float) $value < (float) $threshold;
            case 'le':  return $num && $tnum && (float) $value <= (float) $threshold;
            case 'eq':
                return $num && $tnum ? (float) $value == (float) $threshold : (string) $value === $threshold;
            case 'neq':
                return $num && $tnum ? (float) $value != (float) $threshold : (string) $value !== $threshold;
            case 'in':
                $needle = strtolower((string) $value);
                foreach (explode(',', $threshold) as $item) {
                    if (trim($item) !== '' && strtolower(trim($item)) === $needle) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    private static function opLabel(string $op): string
    {
        switch ($op) {
            case 'gt': return '>';
            case 'ge': return '≥';
            case 'lt': return '<';
            case 'le': return '≤';
            case 'eq': return '=';
            case 'neq': return '≠';
            case 'in': return '∈';
            default: return $op;
        }
    }

    private static function notify(array $rule, string $devName, string $message, string $severity, string $event, array $extra = []): void
    {
        $groupId = (int) ($rule['notify_group_id'] ?? 0);
        if ($groupId <= 0) {
            return;
        }
        $group = self::getGroup($groupId);
        if (!$group || empty($group['enabled'])) {
            return;
        }
        $url = (string) ($group['webhook_url'] ?? '');
        if ($url === '') {
            return;
        }
        $parts = parse_url($url);
        if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || empty($parts['host'])) {
            return;
        }
        $payload = json_encode([
            'event' => $event,
            'severity' => $severity,
            'alert' => [
                'rule_id' => (int) $rule['id'],
                'rule_name' => (string) ($rule['name'] ?? ''),
                'device' => $devName,
                'message' => $message,
                'time' => date('c'),
            ],
        ] + $extra, JSON_UNESCAPED_UNICODE);
        self::postWebhook($url, $payload);
    }

    public static function notifyForAutomation(int $groupId, string $event, string $message, array $extra = []): void
    {
        if ($groupId <= 0) {
            return;
        }
        $group = self::getGroup($groupId);
        if (!$group || empty($group['enabled'])) {
            return;
        }
        $url = (string) ($group['webhook_url'] ?? '');
        if ($url === '') {
            return;
        }
        $parts = parse_url($url);
        if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || empty($parts['host'])) {
            return;
        }
        self::postWebhook($url, json_encode([
            'event' => $event,
            'severity' => 'info',
            'automation' => [
                'message' => $message,
                'time' => date('c'),
            ],
        ] + $extra, JSON_UNESCAPED_UNICODE));
    }

    private static function postWebhook(string $url, string $payload): void
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            error_log('webhook post failed: ' . $e->getMessage());
        }
    }
}
