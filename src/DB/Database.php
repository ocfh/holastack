<?php
namespace holastack\DB;

use PDO;

/**
 * 极简 PDO 数据库封装（单例）。
 * 支持 MySQL 与 SQLite（DSN 由 config.php 决定）。
 */
class Database
{
    private static $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO(ELW_DB_DSN, ELW_DB_USER, ELW_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            if (strpos(ELW_DB_DSN, 'sqlite') === 0) {
                $pdo->exec('PRAGMA foreign_keys = ON;');
                // 并发保护：NS 常驻进程与脚本可能同时访问同一 SQLite 文件
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->exec('PRAGMA busy_timeout = 5000;');
            }
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** 若列不存在则补齐（SQLite 不支持 ADD COLUMN IF NOT EXISTS）。 */
    private static function ensureColumn(string $table, string $column, string $def): void
    {
        $cols = self::pdo()->query("PRAGMA table_info($table)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $cols, true)) {
            self::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $def");
        }
    }

    /** MySQL 下判断列是否存在（用于兼容已存在的旧库加列）。 */
    private static function mysqlColumnExists(string $table, string $column): bool
    {
        $pdo = self::pdo();
        $r = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="
            . $pdo->quote($table) . " AND column_name=" . $pdo->quote($column)
        )->fetchColumn();
        return (int) $r > 0;
    }

    /** 初始化数据库结构（按 DSN 类型选择 sqlite/mysql schema）。 */
    public static function migrate(): void
    {
        $dsn = ELW_DB_DSN;
        $type = strpos($dsn, 'sqlite') === 0 ? 'sqlite' : 'mysql';
        $sqlFile = ELW_ROOT . '/schema/' . $type . '.sql';
        if (!file_exists($sqlFile)) {
            throw new \RuntimeException("Schema file not found: $sqlFile");
        }
        $sql = file_get_contents($sqlFile);
        // 去除 SQLite 不支持的 COMMENT 行（MySQL 文件内已用 -- 注释处理，这里仅按语句拆分）
        $pdo = self::pdo();
        if ($type === 'sqlite') {
            $pdo->exec($sql);
            // 兼容已存在的旧库：补齐新增列（SQLite 不支持 IF NOT EXISTS）
            foreach ([
                ['devices', 'last_gw_id', 'TEXT DEFAULT \'\''],
                ['devices', 'ping_period', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'beacon_epoch', 'INTEGER NOT NULL DEFAULT 0'],
            ] as [$tbl, $col, $def]) {
                self::ensureColumn($tbl, $col, $def);
            }
            // 兜底：确保令牌表 / 事件表存在
            $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(16) NOT NULL, level VARCHAR(8) NOT NULL DEFAULT \'info\', gateway_id VARCHAR(32) DEFAULT \'\', dev_id INTEGER DEFAULT 0, app_id INTEGER DEFAULT 0, message TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            self::ensureColumn('uplinks', 'phy_payload', 'TEXT DEFAULT \'\'');
        } else {
            // MySQL：按 ; 拆分执行
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
            // 兜底：确保令牌表 / 事件表存在
            $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (token VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, created_at INT NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(16) NOT NULL, level VARCHAR(8) NOT NULL DEFAULT \'info\', gateway_id VARCHAR(32) DEFAULT \'\', dev_id INT DEFAULT 0, app_id INT DEFAULT 0, message TEXT, created_at INT NOT NULL)');
            if (!self::mysqlColumnExists('uplinks', 'phy_payload')) {
                $pdo->exec('ALTER TABLE uplinks ADD COLUMN phy_payload TEXT');
            }
        }
    }
}
