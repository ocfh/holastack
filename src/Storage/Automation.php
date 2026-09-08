<?php
namespace holastack\Storage;

use holastack\DB\Database;

class Automation
{
    public const OPERATORS = ['gt', 'ge', 'lt', 'le', 'eq', 'neq', 'in'];

    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll("SELECT * FROM automations WHERE tenant_id=? ORDER BY id DESC", [$tenantId]);
        }
        return Database::fetchAll("SELECT * FROM automations ORDER BY id DESC");
    }

    public static function listByApp(int $appId): array
    {
        return Database::fetchAll("SELECT * FROM automations WHERE application_id=? ORDER BY id DESC", [$appId]);
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM automations WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        $norm = self::normalize($p);
        if (isset($norm['error'])) {
            return $norm;
        }
        Database::execute(
            "INSERT INTO automations (tenant_id, application_id, name, trigger_device_id, trigger_field, trigger_operator, trigger_value, cooldown_seconds, enabled, action_type, action_device_id, action_port, action_payload_hex, action_confirmed, notify_group_id, fired_count, last_fired_at, last_result, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,'',?)",
            [
                $norm['tenant_id'], $norm['application_id'], $norm['name'],
                $norm['trigger_device_id'], $norm['trigger_field'], $norm['trigger_operator'], $norm['trigger_value'],
                $norm['cooldown_seconds'], $norm['enabled'],
                $norm['action_type'], $norm['action_device_id'], $norm['action_port'], $norm['action_payload_hex'], $norm['action_confirmed'],
                $norm['notify_group_id'], time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $merged = array_merge($m, array_intersect_key($p, array_flip([
            'name', 'trigger_device_id', 'trigger_field', 'trigger_operator', 'trigger_value',
            'cooldown_seconds', 'enabled', 'action_type', 'action_device_id', 'action_port',
            'action_payload_hex', 'action_confirmed', 'notify_group_id',
        ])));
        $norm = self::normalize($merged);
        if (isset($norm['error'])) {
            return $norm;
        }
        Database::execute(
            "UPDATE automations SET name=?, trigger_device_id=?, trigger_field=?, trigger_operator=?, trigger_value=?, cooldown_seconds=?, enabled=?, action_type=?, action_device_id=?, action_port=?, action_payload_hex=?, action_confirmed=?, notify_group_id=? WHERE id=?",
            [
                $norm['name'], $norm['trigger_device_id'], $norm['trigger_field'], $norm['trigger_operator'], $norm['trigger_value'],
                $norm['cooldown_seconds'], $norm['enabled'],
                $norm['action_type'], $norm['action_device_id'], $norm['action_port'], $norm['action_payload_hex'], $norm['action_confirmed'],
                $norm['notify_group_id'], $id,
            ]
        );
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        Database::execute("DELETE FROM automations WHERE id=?", [$id]);
        return ['id' => $id];
    }

    public static function operatorOptions(): array
    {
        return [
            'gt'  => '> ',
            'ge'  => '≥',
            'lt'  => '< ',
            'le'  => '≤',
            'eq'  => '= ',
            'neq' => '≠',
            'in'  => '∈',
        ];
    }

    public static function evaluateLinkages(array $device, array $decoded): void
    {
        if ($decoded === []) {
            return;
        }
        $devId = (int) ($device['id'] ?? 0);
        $appId = (int) ($device['app_id'] ?? $device['application_id'] ?? 0);
        if ($appId <= 0) {
            return;
        }
        $rows = Database::fetchAll(
            "SELECT * FROM automations WHERE enabled=1 AND application_id=? AND (trigger_device_id=0 OR trigger_device_id=?)",
            [$appId, $devId]
        );
        $now = time();
        foreach ($rows as $row) {
            self::evalOne((int) $devId, $device, $row, $decoded, $now);
        }
    }

    private static function evalOne(int $devId, array $device, array $row, array $decoded, int $now): void
    {
        $key = (string) ($row['trigger_field'] ?? '');
        if ($key === '' || !isset($decoded[$key])) {
            return;
        }
        $v = $decoded[$key];
        $value = $v['value'] ?? $v['text'] ?? null;
        if (!self::match((string) ($row['trigger_operator'] ?? 'gt'), $value, (string) ($row['trigger_value'] ?? ''))) {
            return;
        }
        $cd = (int) ($row['cooldown_seconds'] ?? 60);
        $last = (int) ($row['last_fired_at'] ?? 0);
        if ($cd > 0 && $last > 0 && ($now - $last) < $cd) {
            return;
        }
        $result = self::runAction($row, $device, $key, $v);
        $fired = (int) ($row['fired_count'] ?? 0) + 1;
        Database::execute(
            "UPDATE automations SET fired_count=?, last_fired_at=?, last_result=? WHERE id=?",
            [$fired, $now, (string) $result, (int) $row['id']]
        );
    }

    private static function runAction(array $row, array $sourceDevice, string $fieldKey, array $v): string
    {
        $name = (string) ($row['name'] ?? '#auto');
        $src = (string) ($sourceDevice['name'] ?? '#dev');
        $detail = sprintf('%s 字段「%s」当前 %s', $src, $fieldKey, (string) ($v['text'] ?? $v['value'] ?? ''));
        $type = (string) ($row['action_type'] ?? 'downlink');

        if ($type === 'downlink') {
            $targetId = (int) ($row['action_device_id'] ?? 0);
            if ($targetId <= 0) {
                return 'no target device';
            }
            $hex = strtolower(preg_replace('/\s+/', '', (string) ($row['action_payload_hex'] ?? '')));
            if ($hex === '' || strlen($hex) % 2 !== 0) {
                return 'payload_hex_invalid';
            }
            $port = (int) ($row['action_port'] ?? 1);
            $dev = Database::fetch("SELECT id, app_id FROM devices WHERE id=?", [$targetId]);
            if (!$dev) {
                return 'target device not found';
            }
            Database::execute(
                "INSERT INTO downlinks (dev_id, app_id, port, payload_hex, confirmed, mac, status, raw_json, created_at) VALUES (?,?,?,?,?,0,'pending',?,?)",
                [$targetId, (int) $dev['app_id'], $port, $hex, empty($row['action_confirmed']) ? 0 : 1, json_encode(['reason' => 'automation #' . (int) $row['id'], 'detail' => $detail], JSON_UNESCAPED_UNICODE), time()]
            );
            return 'downlink #' . Database::lastInsertId() . ' ' . $detail;
        }

        Alert::notifyForAutomation((int) ($row['notify_group_id'] ?? 0), 'automation', $name . '：' . $detail, [
            'automation_id' => (int) $row['id'],
            'source_device' => $src,
            'field' => $fieldKey,
            'value' => $v['value'] ?? $v['text'] ?? null,
        ]);
        return 'notified ' . $detail;
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
                return $num && $tnum ? (float) $value == (float) $threshold : ((string) $value) === $threshold;
            case 'neq':
                return $num && $tnum ? (float) $value != (float) $threshold : ((string) $value) !== $threshold;
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

    private static function normalize(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        $app = $appId > 0 ? Database::fetch("SELECT id, tenant_id FROM applications WHERE id=?", [$appId]) : null;
        if (!$app) {
            return ['error' => 'application_not_found'];
        }
        $name = mb_substr((string) ($p['name'] ?? ''), 0, 128);
        if ($name === '') {
            return ['error' => 'name_required'];
        }
        $triggerField = (string) ($p['trigger_field'] ?? '');
        if ($triggerField === '') {
            return ['error' => 'trigger_field_required'];
        }
        $op = (string) ($p['trigger_operator'] ?? 'gt');
        if (!in_array($op, self::OPERATORS, true)) {
            return ['error' => 'operator_invalid'];
        }
        $threshold = (string) ($p['trigger_value'] ?? '');
        if ($threshold === '') {
            return ['error' => 'trigger_value_required'];
        }
        $actionType = (string) ($p['action_type'] ?? 'downlink');
        if (!in_array($actionType, ['downlink', 'notify'], true)) {
            return ['error' => 'action_type_invalid'];
        }
        $actionDeviceId = (int) ($p['action_device_id'] ?? 0);
        if ($actionType === 'downlink' && $actionDeviceId <= 0) {
            return ['error' => 'action_device_required'];
        }
        $hex = strtolower(preg_replace('/\s+/', '', (string) ($p['action_payload_hex'] ?? '')));
        if ($actionType === 'downlink' && ($hex === '' || strlen($hex) % 2 !== 0)) {
            return ['error' => 'action_payload_hex_invalid'];
        }
        return [
            'tenant_id'         => (int) ($p['tenant_id'] ?? (int) $app['tenant_id']),
            'application_id'    => $appId,
            'name'              => $name,
            'trigger_device_id' => (int) ($p['trigger_device_id'] ?? 0),
            'trigger_field'     => $triggerField,
            'trigger_operator'  => $op,
            'trigger_value'     => $threshold,
            'cooldown_seconds'  => max(0, (int) ($p['cooldown_seconds'] ?? 60)),
            'enabled'           => array_key_exists('enabled', $p) ? (empty($p['enabled']) ? 0 : 1) : 1,
            'action_type'       => $actionType,
            'action_device_id'  => $actionDeviceId,
            'action_port'       => max(1, min(223, (int) ($p['action_port'] ?? 1))),
            'action_payload_hex'=> $hex,
            'action_confirmed'  => empty($p['action_confirmed']) ? 0 : 1,
            'notify_group_id'   => max(0, (int) ($p['notify_group_id'] ?? 0)),
        ];
    }
}
