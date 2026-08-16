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
                ['devices', 'device_profile_id', 'INTEGER DEFAULT 0'],
                ['devices', 'mac_version', 'TEXT DEFAULT \'1.0.3\''],
                ['devices', 'nwk_key', 'TEXT DEFAULT \'\''],
                ['devices', 'f_nwk_s_int_key', 'TEXT DEFAULT \'\''],
                ['devices', 's_nwk_s_int_key', 'TEXT DEFAULT \'\''],
                ['devices', 'nwk_s_enc_key', 'TEXT DEFAULT \'\''],
                ['devices', 'adr', 'INTEGER NOT NULL DEFAULT 1'],
                ['devices', 'dr', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'tx_power_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'nb_trans', 'INTEGER NOT NULL DEFAULT 1'],
                ['devices', 'rx1_dr_offset', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'rx2_dr', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'rx_delay', 'INTEGER NOT NULL DEFAULT 1'],
                ['devices', 'max_supported_tx_power_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'min_supported_tx_power_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'enabled_uplink_channel_indices', 'TEXT DEFAULT \'\''],
                ['devices', 'mac_command_error_count', 'TEXT DEFAULT \'\''],
                ['devices', 'uplink_adr_history', 'TEXT DEFAULT \'\''],
                ['devices', 'pending_mac', 'TEXT DEFAULT \'\''],
                ['devices', 'last_seen', 'INTEGER DEFAULT 0'],
                ['devices', 'battery', 'INTEGER DEFAULT -1'],
                ['devices', 'margin', 'INTEGER DEFAULT 0'],
                ['devices', 'latitude', 'REAL DEFAULT 0'],
                ['devices', 'longitude', 'REAL DEFAULT 0'],
                ['devices', 'altitude', 'REAL DEFAULT 0'],
                ['uplinks', 'phy_payload', 'TEXT DEFAULT \'\''],
                ['uplinks', 'raw_json', 'TEXT DEFAULT \'\''],
                ['downlinks', 'transmissions', 'INTEGER NOT NULL DEFAULT 0'],
                ['downlinks', 'acknowledged_at', 'INTEGER DEFAULT 0'],
                ['applications', 'callback_url', 'TEXT DEFAULT \'\''],
                ['events', 'raw_json', 'TEXT DEFAULT \'\''],
                ['applications', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['device_profiles', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['gateways', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['api_keys', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['integrations', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['multicast_groups', 'tenant_id', 'INTEGER DEFAULT 0'],
            ] as [$tbl, $col, $def]) {
                self::ensureColumn($tbl, $col, $def);
            }
            // 兜底：确保令牌表 / 事件表 / 租户表存在
            $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(16) NOT NULL, level VARCHAR(8) NOT NULL DEFAULT \'info\', gateway_id VARCHAR(32) DEFAULT \'\', dev_id INTEGER DEFAULT 0, app_id INTEGER DEFAULT 0, message TEXT DEFAULT \'\', raw_json TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT DEFAULT \'\', can_have_gateways INTEGER NOT NULL DEFAULT 1, private_gateways_limit INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            // ---- ChirpStack-port 路线图模块表（BasicStation/Relay/FUOTA/Roaming） ----
            $pdo->exec('CREATE TABLE IF NOT EXISTS stations (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, gateway_id TEXT NOT NULL, name TEXT NOT NULL, region TEXT NOT NULL DEFAULT \'EU868\', lns_secret TEXT DEFAULT \'\', ca_cert TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, relay_dev_eui TEXT NOT NULL, region TEXT NOT NULL DEFAULT \'EU868\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, relay_gateway_id INTEGER NOT NULL, dev_eui TEXT NOT NULL, dev_addr TEXT DEFAULT \'\', nwk_s_key TEXT DEFAULT \'\', app_s_key TEXT DEFAULT \'\', f_nwk_s_int_key TEXT DEFAULT \'\', s_nwk_s_int_key TEXT DEFAULT \'\', nwk_s_enc_key TEXT DEFAULT \'\', mac_version TEXT DEFAULT \'1.1\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, application_id INTEGER NOT NULL, multicast_group_id INTEGER NOT NULL, fragment_size INTEGER NOT NULL DEFAULT 200, redundancy INTEGER NOT NULL DEFAULT 1, descriptor_version INTEGER NOT NULL DEFAULT 0, fw_version TEXT DEFAULT \'\', fw_length INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_deployments (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, dev_id INTEGER NOT NULL, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', fragments_received INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_fragments (id INTEGER PRIMARY KEY AUTOINCREMENT, deployment_id INTEGER NOT NULL, frag_index INTEGER NOT NULL, data TEXT NOT NULL, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT \'PASSIVE\', protocol VARCHAR(16) NOT NULL DEFAULT \'BI_1_0\', server TEXT DEFAULT \'\', async_timeout INTEGER NOT NULL DEFAULT 250, enabled INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL)');
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
            $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(16) NOT NULL, level VARCHAR(8) NOT NULL DEFAULT \'info\', gateway_id VARCHAR(32) DEFAULT \'\', dev_id INT DEFAULT 0, app_id INT DEFAULT 0, message TEXT, raw_json TEXT, created_at INT NOT NULL)');
            if (!self::mysqlColumnExists('uplinks', 'phy_payload')) {
                $pdo->exec('ALTER TABLE uplinks ADD COLUMN phy_payload TEXT');
            }
            if (!self::mysqlColumnExists('uplinks', 'raw_json')) {
                $pdo->exec('ALTER TABLE uplinks ADD COLUMN raw_json TEXT');
            }
            if (!self::mysqlColumnExists('events', 'raw_json')) {
                $pdo->exec('ALTER TABLE events ADD COLUMN raw_json TEXT');
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, description VARCHAR(255) DEFAULT \'\', can_have_gateways TINYINT NOT NULL DEFAULT 1, private_gateways_limit INT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            // ---- ChirpStack-port 路线图模块表（BasicStation/Relay/FUOTA/Roaming） ----
            $pdo->exec('CREATE TABLE IF NOT EXISTS stations (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, gateway_id VARCHAR(32) NOT NULL, name VARCHAR(128) NOT NULL, region VARCHAR(16) NOT NULL DEFAULT \'EU868\', lns_secret VARCHAR(128) DEFAULT \'\', ca_cert TEXT, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_gateways (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, relay_dev_eui VARCHAR(32) NOT NULL, region VARCHAR(16) NOT NULL DEFAULT \'EU868\', created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_devices (id INT AUTO_INCREMENT PRIMARY KEY, relay_gateway_id INT NOT NULL, dev_eui VARCHAR(32) NOT NULL, dev_addr VARCHAR(16) DEFAULT \'\', nwk_s_key VARCHAR(64) DEFAULT \'\', app_s_key VARCHAR(64) DEFAULT \'\', f_nwk_s_int_key VARCHAR(64) DEFAULT \'\', s_nwk_s_int_key VARCHAR(64) DEFAULT \'\', nwk_s_enc_key VARCHAR(64) DEFAULT \'\', mac_version VARCHAR(16) DEFAULT \'1.1\', created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_campaigns (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, application_id INT NOT NULL, multicast_group_id INT NOT NULL, fragment_size INT NOT NULL DEFAULT 200, redundancy INT NOT NULL DEFAULT 1, descriptor_version INT NOT NULL DEFAULT 0, fw_version VARCHAR(32) DEFAULT \'\', fw_length INT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_deployments (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, dev_id INT NOT NULL, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', fragments_received INT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_fragments (id INT AUTO_INCREMENT PRIMARY KEY, deployment_id INT NOT NULL, frag_index INT NOT NULL, data TEXT NOT NULL, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_servers (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT \'PASSIVE\', protocol VARCHAR(16) NOT NULL DEFAULT \'BI_1_0\', server VARCHAR(255) DEFAULT \'\', async_timeout INT NOT NULL DEFAULT 250, enabled TINYINT NOT NULL DEFAULT 1, created_at INT NOT NULL)');
            foreach ([
                ['devices', 'last_seen', 'INT DEFAULT 0'],
                ['devices', 'device_profile_id', 'INT DEFAULT 0'],
                ['devices', 'mac_version', 'VARCHAR(16) DEFAULT \'1.0.3\''],
                ['devices', 'nwk_key', 'VARCHAR(64) DEFAULT \'\''],
                ['devices', 'f_nwk_s_int_key', 'VARCHAR(64) DEFAULT \'\''],
                ['devices', 's_nwk_s_int_key', 'VARCHAR(64) DEFAULT \'\''],
                ['devices', 'nwk_s_enc_key', 'VARCHAR(64) DEFAULT \'\''],
                ['devices', 'adr', 'TINYINT DEFAULT 1'],
                ['devices', 'dr', 'INT DEFAULT 0'],
                ['devices', 'tx_power_index', 'INT DEFAULT 0'],
                ['devices', 'nb_trans', 'INT DEFAULT 1'],
                ['devices', 'rx1_dr_offset', 'INT DEFAULT 0'],
                ['devices', 'rx2_dr', 'INT DEFAULT 0'],
                ['devices', 'rx_delay', 'INT DEFAULT 1'],
                ['devices', 'max_supported_tx_power_index', 'INT DEFAULT 0'],
                ['devices', 'min_supported_tx_power_index', 'INT DEFAULT 0'],
                ['devices', 'enabled_uplink_channel_indices', 'TEXT'],
                ['devices', 'mac_command_error_count', 'TEXT'],
                ['devices', 'uplink_adr_history', 'TEXT'],
                ['devices', 'pending_mac', 'TEXT'],
                ['devices', 'battery', 'INT DEFAULT -1'],
                ['devices', 'margin', 'INT DEFAULT 0'],
                ['devices', 'latitude', 'DOUBLE DEFAULT 0'],
                ['devices', 'longitude', 'DOUBLE DEFAULT 0'],
                ['devices', 'altitude', 'DOUBLE DEFAULT 0'],
                ['applications', 'callback_url', 'VARCHAR(512) DEFAULT \'\''],
                ['events', 'raw_json', 'TEXT'],
                ['applications', 'tenant_id', 'INT DEFAULT 0'],
                ['device_profiles', 'tenant_id', 'INT DEFAULT 0'],
                ['gateways', 'tenant_id', 'INT DEFAULT 0'],
                ['api_keys', 'tenant_id', 'INT DEFAULT 0'],
                ['integrations', 'tenant_id', 'INT DEFAULT 0'],
                ['multicast_groups', 'tenant_id', 'INT DEFAULT 0'],
                ['downlinks', 'transmissions', 'INT DEFAULT 0'],
                ['downlinks', 'acknowledged_at', 'INT DEFAULT 0'],
            ] as [$tbl, $col, $def]) {
                if (!self::mysqlColumnExists($tbl, $col)) {
                    $pdo->exec("ALTER TABLE $tbl ADD COLUMN $col $def");
                }
            }
        }
    }
}
