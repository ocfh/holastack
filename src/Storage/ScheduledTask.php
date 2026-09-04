<?php
namespace holastack\Storage;

use holastack\DB\Database;
use holastack\Core\Cron;

/**
 * 周期定时任务：按 cron 周期把下行命令入队（复用 downlinks 表）。
 * 调度执行体可被 Web API（手动立即执行）与 NS 常驻循环调用。
 */
class ScheduledTask
{
    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll("SELECT * FROM scheduled_tasks WHERE tenant_id=? ORDER BY id DESC", [$tenantId]);
        }
        return Database::fetchAll("SELECT * FROM scheduled_tasks ORDER BY id DESC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM scheduled_tasks WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        $norm = self::normalize($p);
        if (isset($norm['error'])) {
            return $norm;
        }
        $now = time();
        $next = self::computeNext($norm['cron'], $now);
        Database::execute(
            "INSERT INTO scheduled_tasks (tenant_id, name, application_id, device_id, port, payload_hex, confirmed, cron, enabled, next_run_at, last_run_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                (int) ($norm['tenant_id'] ?? 0),
                $norm['name'],
                $norm['application_id'],
                $norm['device_id'],
                $norm['port'],
                $norm['payload_hex'],
                $norm['confirmed'],
                $norm['cron'],
                $norm['enabled'],
                $next ?? 0,
                0,
                $now,
            ]
        );
        return ['id' => Database::lastInsertId(), 'next_run_at' => $next ?? 0];
    }

    public static function update(int $id, array $p): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $merged = array_merge($m, array_intersect_key($p, array_flip([
            'name', 'device_id', 'port', 'payload_hex', 'confirmed', 'cron', 'enabled',
        ])));
        $norm = self::normalize($merged);
        if (isset($norm['error'])) {
            return $norm;
        }
        $next = self::computeNext($norm['cron'], time());
        Database::execute(
            "UPDATE scheduled_tasks SET name=?, device_id=?, application_id=?, port=?, payload_hex=?, confirmed=?, cron=?, enabled=?, next_run_at=? WHERE id=?",
            [$norm['name'], $norm['device_id'], $norm['application_id'], $norm['port'], $norm['payload_hex'], $norm['confirmed'], $norm['cron'], $norm['enabled'], $next ?? 0, $id]
        );
        return ['id' => $id, 'next_run_at' => $next ?? 0];
    }

    public static function delete(int $id): array
    {
        Database::execute("DELETE FROM scheduled_tasks WHERE id=?", [$id]);
        return ['id' => $id];
    }

    public static function setEnabled(int $id, bool $enabled): array
    {
        self::update($id, ['enabled' => $enabled]);
        return ['id' => $id];
    }

    /**
     * 立即执行一次：将命令入队（pending downlink），并推进 next_run_at。
     */
    public static function run(array $task): array
    {
        $dev = Database::fetch("SELECT id, app_id, tenant_id, name FROM devices WHERE id=?", [(int) $task['device_id']]);
        if (!$dev) {
            self::markResult((int) $task['id'], $task['cron'] ?? '', 'device_not_found');
            return ['error' => 'device_not_found'];
        }
        $hex = strtolower((string) ($task['payload_hex'] ?? ''));
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            self::markResult((int) $task['id'], $task['cron'] ?? '', 'payload_hex_invalid');
            return ['error' => 'payload_hex_invalid'];
        }
        $port = (int) ($task['port'] ?? 1);
        if ($port < 1 || $port > 223) {
            self::markResult((int) $task['id'], $task['cron'] ?? '', 'port_invalid');
            return ['error' => 'port_invalid'];
        }
        Database::execute(
            "INSERT INTO downlinks (dev_id, app_id, port, payload_hex, confirmed, mac, status, created_at) VALUES (?,?,?,?,?,0,'pending',?)",
            [(int) $task['device_id'], (int) $dev['app_id'], $port, $hex, empty($task['confirmed']) ? 0 : 1, time()]
        );
        $dlId = Database::lastInsertId();
        $now = time();
        $next = self::computeNext((string) ($task['cron'] ?? ''), $now) ?? 0;
        Database::execute(
            "UPDATE scheduled_tasks SET last_run_at=?, next_run_at=?, last_result=? WHERE id=?",
            [$now, $next, 'ok dl#' . $dlId, (int) $task['id']]
        );
        return ['id' => (int) $task['id'], 'downlink_id' => $dlId, 'next_run_at' => $next];
    }

    /**
     * 返回到期应执行的任务（enabled=1 且 next_run_at<=now）。
     */
    public static function due(int $now): array
    {
        return Database::fetchAll("SELECT * FROM scheduled_tasks WHERE enabled=1 AND next_run_at>0 AND next_run_at<=?", [$now]);
    }

    private static function markResult(int $id, string $cron, string $result): void
    {
        $next = self::computeNext($cron, time()) ?? 0;
        Database::execute("UPDATE scheduled_tasks SET last_result=?, last_run_at=?, next_run_at=? WHERE id=?", [$result, time(), $next, $id]);
    }

    private static function computeNext(string $cron, int $from): ?int
    {
        if (trim($cron) === '') {
            return null;
        }
        $n = Cron::next($cron, $from);
        if ($n === null) {
            // 60 天内无匹配，仅记录但不置 0，避免下次反复重算
        }
        return $n;
    }

    private static function normalize(array $p): array
    {
        $devId = (int) ($p['device_id'] ?? 0);
        $dev = $devId > 0 ? Database::fetch("SELECT id, app_id, tenant_id, name FROM devices WHERE id=?", [$devId]) : null;
        if (!$dev) {
            return ['error' => 'device_not_found'];
        }
        $hex = strtolower(preg_replace('/\s+/', '', (string) ($p['payload_hex'] ?? '')));
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return ['error' => 'payload_hex_invalid'];
        }
        $port = (int) ($p['port'] ?? 1);
        if ($port < 1 || $port > 223) {
            return ['error' => 'port_invalid'];
        }
        $cron = trim((string) ($p['cron'] ?? ''));
        if ($cron === '' || Cron::parse($cron) === null) {
            return ['error' => 'cron_invalid'];
        }
        return [
            'name'           => mb_substr((string) ($p['name'] ?? ('Scheduled #' . $devId)), 0, 128),
            'tenant_id'      => (int) ($p['tenant_id'] ?? (int) $dev['tenant_id']),
            'application_id' => (int) $dev['app_id'],
            'device_id'      => $devId,
            'port'           => $port,
            'payload_hex'    => $hex,
            'confirmed'      => empty($p['confirmed']) ? 0 : 1,
            'cron'           => $cron,
            'enabled'        => array_key_exists('enabled', $p) ? (empty($p['enabled']) ? 0 : 1) : 1,
        ];
    }
}