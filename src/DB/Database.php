<?php
namespace holastack\DB;

use PDO;
use holastack\Storage\DeviceProfile;






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

    private static function ensureColumn(string $table, string $column, string $def): void
    {
        $cols = self::pdo()->query("PRAGMA table_info($table)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $cols, true)) {
            self::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $def");
        }
    }

    private static function mysqlColumnExists(string $table, string $column): bool
    {
        $pdo = self::pdo();
        $r = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="
            . $pdo->quote($table) . " AND column_name=" . $pdo->quote($column)
        )->fetchColumn();
        return (int) $r > 0;
    }

    public static function migrate(): void
    {
        $dsn = ELW_DB_DSN;
        $type = strpos($dsn, 'sqlite') === 0 ? 'sqlite' : 'mysql';
        $sqlFile = ELW_ROOT . '/schema/' . $type . '.sql';
        if (!file_exists($sqlFile)) {
            throw new \RuntimeException("Schema file not found: $sqlFile");
        }
        $sql = file_get_contents($sqlFile);
        

        $pdo = self::pdo();
        if ($type === 'sqlite') {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                error_log('migrate sqlite failed: ' . $e->getMessage());
            }
            

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
                ['devices', 'rx2_frequency', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'rx_delay', 'INTEGER NOT NULL DEFAULT 1'],
                ['devices', 'device_time', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'device_time_valid', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'max_supported_tx_power_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'min_supported_tx_power_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['devices', 'enabled_uplink_channel_indices', 'TEXT DEFAULT \'\''],
                ['devices', 'mac_command_error_count', 'TEXT DEFAULT \'\''],
                ['devices', 'uplink_adr_history', 'TEXT DEFAULT \'\''],
                ['devices', 'pending_mac', 'TEXT DEFAULT \'\''],
                ['devices', 'last_seen', 'INTEGER DEFAULT 0'],
                ['devices', 'battery', 'INTEGER DEFAULT -1'],
                ['devices', 'margin', 'INTEGER DEFAULT NULL'],
                ['devices', 'dev_status_req_at', 'INTEGER DEFAULT 0'],
                ['devices', 'latitude', 'REAL DEFAULT 0'],
                ['devices', 'longitude', 'REAL DEFAULT 0'],
                ['devices', 'altitude', 'REAL DEFAULT 0'],
                ['uplinks', 'phy_payload', 'TEXT DEFAULT \'\''],
                ['uplinks', 'raw_json', 'TEXT DEFAULT \'\''],
                ['downlinks', 'transmissions', 'INTEGER NOT NULL DEFAULT 0'],
                ['downlinks', 'acknowledged_at', 'INTEGER DEFAULT 0'],
                ['downlinks', 'raw_json', 'TEXT DEFAULT \'\''],
                ['downlinks', 'mac', 'INTEGER NOT NULL DEFAULT 0'],
                ['applications', 'callback_url', 'TEXT DEFAULT \'\''],
                ['events', 'raw_json', 'TEXT DEFAULT \'\''],
                ['users', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['applications', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['devices', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['device_profiles', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['gateways', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['api_keys', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['integrations', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['multicast_groups', 'tenant_id', 'INTEGER DEFAULT 0'],
                ['api_keys', 'created_at', 'INTEGER DEFAULT 0'],
                ['roaming_servers', 'net_id', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'kek_label', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'ca_cert', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'tls_cert', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'tls_key', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'authorization', 'TEXT DEFAULT \'\''],
                ['roaming_servers', 'passive_roaming_lifetime', 'INTEGER NOT NULL DEFAULT 0'],
                ['roaming_servers', 'validate_mic', 'INTEGER NOT NULL DEFAULT 1'],
                ['devices', 'relay_state', 'TEXT DEFAULT \'\''],
                ['device_profiles', 'relay_params', 'TEXT DEFAULT \'\''],
                

                ['relay_devices', 'slot_index', 'INTEGER NOT NULL DEFAULT 0'],
                ['relay_devices', 'join_eui', 'TEXT DEFAULT \'\''],
                ['relay_devices', 'root_wor_s_key', 'TEXT DEFAULT \'\''],
                ['relay_devices', 'provisioned', 'INTEGER NOT NULL DEFAULT 0'],
                ['relay_devices', 'uplink_limit_bucket_size', 'INTEGER NOT NULL DEFAULT 0'],
                ['relay_devices', 'uplink_limit_reload_rate', 'INTEGER NOT NULL DEFAULT 0'],
                ['relay_devices', 'w_f_cnt_last_request', 'INTEGER NOT NULL DEFAULT 0'],
                

                ['fuota_campaigns', 'state', 'VARCHAR(16) NOT NULL DEFAULT \'PENDING\''],
                ['fuota_campaigns', 'mc_ke_key', 'TEXT DEFAULT \'\''],
                ['fuota_campaigns', 'min_delay', 'INTEGER NOT NULL DEFAULT 200'],
                ['fuota_campaigns', 'max_delay', 'INTEGER NOT NULL DEFAULT 1000'],
                ['fuota_campaigns', 'timeout', 'INTEGER NOT NULL DEFAULT 3600'],
                ['fuota_campaigns', 'frames_sent', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'total_frames', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'next_frame_at', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'started_at', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'updated_at', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'firmware_sha256', 'TEXT DEFAULT \'\''],
                ['fuota_campaigns', 'firmware_crc', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'status_req_sent', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'frag_nb_missing', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'mc_group_ans', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'status_ans', 'INTEGER NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'updated_at', 'INTEGER NOT NULL DEFAULT 0'],
                

                ['tenants', 'private_gateways_unlimited', 'INTEGER NOT NULL DEFAULT 0'],
            ] as [$tbl, $col, $def]) {
                self::ensureColumn($tbl, $col, $def);
            }
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_keks (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL UNIQUE, kek TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_pending (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(16) NOT NULL, dev_eui TEXT DEFAULT \'\', dev_addr TEXT DEFAULT \'\', gw_id TEXT NOT NULL DEFAULT \'\', peer TEXT DEFAULT \'\', ul_tmst INTEGER NOT NULL DEFAULT 0, region TEXT NOT NULL DEFAULT \'\', freq REAL NOT NULL DEFAULT 0, datr TEXT DEFAULT \'\', dl_delay INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL DEFAULT 0)');
            self::ensureColumn('roaming_pending', 'dl_delay', 'INTEGER NOT NULL DEFAULT 0');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS auth_tokens (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(16) NOT NULL, level VARCHAR(8) NOT NULL DEFAULT \'info\', gateway_id VARCHAR(32) DEFAULT \'\', dev_id INTEGER DEFAULT 0, app_id INTEGER DEFAULT 0, message TEXT DEFAULT \'\', raw_json TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT DEFAULT \'\', private_gateways_limit INTEGER NOT NULL DEFAULT 0, private_gateways_unlimited INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS stations (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, gateway_id TEXT NOT NULL, name TEXT NOT NULL, region TEXT NOT NULL DEFAULT \'EU868\', lns_secret TEXT DEFAULT \'\', ca_cert TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, relay_dev_eui TEXT NOT NULL, region TEXT NOT NULL DEFAULT \'EU868\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, relay_gateway_id INTEGER NOT NULL, dev_eui TEXT NOT NULL, slot_index INTEGER NOT NULL DEFAULT 0, join_eui TEXT DEFAULT \'\', dev_addr TEXT DEFAULT \'\', root_wor_s_key TEXT DEFAULT \'\', provisioned INTEGER NOT NULL DEFAULT 0, uplink_limit_bucket_size INTEGER NOT NULL DEFAULT 0, uplink_limit_reload_rate INTEGER NOT NULL DEFAULT 0, w_f_cnt_last_request INTEGER NOT NULL DEFAULT 0, nwk_s_key TEXT DEFAULT \'\', app_s_key TEXT DEFAULT \'\', f_nwk_s_int_key TEXT DEFAULT \'\', s_nwk_s_int_key TEXT DEFAULT \'\', nwk_s_enc_key TEXT DEFAULT \'\', mac_version TEXT DEFAULT \'1.1\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, application_id INTEGER NOT NULL, multicast_group_id INTEGER NOT NULL, fragment_size INTEGER NOT NULL DEFAULT 200, redundancy INTEGER NOT NULL DEFAULT 1, descriptor_version INTEGER NOT NULL DEFAULT 0, fw_version TEXT DEFAULT \'\', fw_length INTEGER NOT NULL DEFAULT 0, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', mc_ke_key TEXT DEFAULT \'\', min_delay INTEGER NOT NULL DEFAULT 200, max_delay INTEGER NOT NULL DEFAULT 1000, timeout INTEGER NOT NULL DEFAULT 3600, frames_sent INTEGER NOT NULL DEFAULT 0, total_frames INTEGER NOT NULL DEFAULT 0, next_frame_at INTEGER NOT NULL DEFAULT 0, started_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, firmware_sha256 TEXT DEFAULT \'\', firmware_crc INTEGER NOT NULL DEFAULT 0, status_req_sent INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_deployments (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, dev_id INTEGER NOT NULL, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', fragments_received INTEGER NOT NULL DEFAULT 0, frag_nb_missing INTEGER NOT NULL DEFAULT 0, mc_group_ans INTEGER NOT NULL DEFAULT 0, status_ans INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_fragments (id INTEGER PRIMARY KEY AUTOINCREMENT, deployment_id INTEGER NOT NULL, frag_index INTEGER NOT NULL, data TEXT NOT NULL, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_frames (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, seq INTEGER NOT NULL, fopts_hex TEXT NOT NULL, created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT \'PASSIVE\', protocol VARCHAR(16) NOT NULL DEFAULT \'BI_1_0\', server TEXT DEFAULT \'\', async_timeout INTEGER NOT NULL DEFAULT 250, enabled INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL)');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS integrations (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL, tenant_id INTEGER DEFAULT 0, kind TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, config_json TEXT DEFAULT \'\', created_at INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS multicast_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, name TEXT NOT NULL, application_id INTEGER NOT NULL, region TEXT NOT NULL DEFAULT \'EU868\', group_type TEXT NOT NULL DEFAULT \'C\', mc_addr TEXT DEFAULT \'\', mc_nwk_s_key TEXT DEFAULT \'\', mc_app_s_key TEXT DEFAULT \'\', f_cnt INTEGER NOT NULL DEFAULT 0, dr INTEGER NOT NULL DEFAULT 0, frequency INTEGER NOT NULL DEFAULT 0, class_b_ping_slot_periodicity INTEGER NOT NULL DEFAULT 0, class_c_scheduling_type TEXT NOT NULL DEFAULT \'DELAY\', created_at INTEGER NOT NULL)');
            self::ensureColumn('uplinks', 'phy_payload', 'TEXT DEFAULT \'\'');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS api_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL, method VARCHAR(8) NOT NULL DEFAULT \'\', path TEXT DEFAULT \'\', status INTEGER NOT NULL DEFAULT 0, latency_ms INTEGER NOT NULL DEFAULT 0, ip TEXT DEFAULT \'\', user_id INTEGER NOT NULL DEFAULT 0, username TEXT DEFAULT \'\', role VARCHAR(16) DEFAULT \'\', tenant_id INTEGER NOT NULL DEFAULT 0, application_id INTEGER NOT NULL DEFAULT 0, query TEXT DEFAULT \'\', body_size INTEGER NOT NULL DEFAULT 0)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_logs_tenant ON api_logs(tenant_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_logs_app ON api_logs(application_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_logs_created ON api_logs(created_at)');
            

            try {
                $cols = $pdo->query("PRAGMA table_info(tenants)")->fetchAll(\PDO::FETCH_COLUMN, 1);
                if (in_array('can_have_gateways', $cols, true) && in_array('private_gateways_unlimited', $cols, true)) {
                    $pdo->exec("UPDATE tenants SET private_gateways_unlimited=1 WHERE can_have_gateways=1");
                }
            } catch (\Throwable $e) {
                error_log('migrate tenants (sqlite) data copy: ' . $e->getMessage());
            }
        } else {
            

            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt === '') {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                } catch (\Throwable $e) {
                    error_log('migrate mysql stmt failed: ' . $e->getMessage() . ' | ' . substr($stmt, 0, 160));
                }
            }
            

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
            $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL, description VARCHAR(255) DEFAULT \'\', private_gateways_limit INT NOT NULL DEFAULT 0, private_gateways_unlimited TINYINT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS stations (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, gateway_id VARCHAR(32) NOT NULL, name VARCHAR(128) NOT NULL, region VARCHAR(16) NOT NULL DEFAULT \'EU868\', lns_secret VARCHAR(128) DEFAULT \'\', ca_cert TEXT, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_gateways (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, relay_dev_eui VARCHAR(32) NOT NULL, region VARCHAR(16) NOT NULL DEFAULT \'EU868\', created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS relay_devices (id INT AUTO_INCREMENT PRIMARY KEY, relay_gateway_id INT NOT NULL, dev_eui VARCHAR(32) NOT NULL, slot_index INT NOT NULL DEFAULT 0, join_eui VARCHAR(32) DEFAULT \'\', dev_addr VARCHAR(16) DEFAULT \'\', root_wor_s_key VARCHAR(64) DEFAULT \'\', provisioned TINYINT NOT NULL DEFAULT 0, uplink_limit_bucket_size INT NOT NULL DEFAULT 0, uplink_limit_reload_rate INT NOT NULL DEFAULT 0, w_f_cnt_last_request INT NOT NULL DEFAULT 0, nwk_s_key VARCHAR(64) DEFAULT \'\', app_s_key VARCHAR(64) DEFAULT \'\', f_nwk_s_int_key VARCHAR(64) DEFAULT \'\', s_nwk_s_int_key VARCHAR(64) DEFAULT \'\', nwk_s_enc_key VARCHAR(64) DEFAULT \'\', mac_version VARCHAR(16) DEFAULT \'1.1\', created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_campaigns (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, application_id INT NOT NULL, multicast_group_id INT NOT NULL, fragment_size INT NOT NULL DEFAULT 200, redundancy INT NOT NULL DEFAULT 1, descriptor_version INT NOT NULL DEFAULT 0, fw_version VARCHAR(32) DEFAULT \'\', fw_length INT NOT NULL DEFAULT 0, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', mc_ke_key VARCHAR(64) DEFAULT \'\', min_delay INT NOT NULL DEFAULT 200, max_delay INT NOT NULL DEFAULT 1000, timeout INT NOT NULL DEFAULT 3600, frames_sent INT NOT NULL DEFAULT 0, total_frames INT NOT NULL DEFAULT 0, next_frame_at INT NOT NULL DEFAULT 0, started_at INT NOT NULL DEFAULT 0, updated_at INT NOT NULL DEFAULT 0, firmware_sha256 VARCHAR(64) DEFAULT \'\', firmware_crc INT NOT NULL DEFAULT 0, status_req_sent TINYINT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_deployments (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, dev_id INT NOT NULL, state VARCHAR(16) NOT NULL DEFAULT \'PENDING\', fragments_received INT NOT NULL DEFAULT 0, frag_nb_missing INT NOT NULL DEFAULT 0, mc_group_ans TINYINT NOT NULL DEFAULT 0, status_ans TINYINT NOT NULL DEFAULT 0, updated_at INT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_fragments (id INT AUTO_INCREMENT PRIMARY KEY, deployment_id INT NOT NULL, frag_index INT NOT NULL, data TEXT NOT NULL, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS fuota_frames (id INT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NOT NULL, seq INT NOT NULL, fopts_hex TEXT NOT NULL, created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_servers (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT \'PASSIVE\', protocol VARCHAR(16) NOT NULL DEFAULT \'BI_1_0\', server VARCHAR(255) DEFAULT \'\', async_timeout INT NOT NULL DEFAULT 250, enabled TINYINT NOT NULL DEFAULT 1, created_at INT NOT NULL)');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS integrations (id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, tenant_id INT DEFAULT 0, kind VARCHAR(32) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, config_json TEXT, created_at INT NOT NULL, INDEX idx_integrations_app (application_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS multicast_groups (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT DEFAULT 0, name VARCHAR(128) NOT NULL, application_id INT NOT NULL, region VARCHAR(16) NOT NULL DEFAULT \'EU868\', group_type VARCHAR(8) NOT NULL DEFAULT \'C\', mc_addr VARCHAR(16) DEFAULT \'\', mc_nwk_s_key VARCHAR(64) DEFAULT \'\', mc_app_s_key VARCHAR(64) DEFAULT \'\', f_cnt INT NOT NULL DEFAULT 0, dr INT NOT NULL DEFAULT 0, frequency INT NOT NULL DEFAULT 0, class_b_ping_slot_periodicity INT NOT NULL DEFAULT 0, class_c_scheduling_type VARCHAR(8) NOT NULL DEFAULT \'DELAY\', created_at INT NOT NULL, INDEX idx_mg_app (application_id))');
            

            $pdo->exec('CREATE TABLE IF NOT EXISTS api_logs (id INT AUTO_INCREMENT PRIMARY KEY, created_at INT NOT NULL, method VARCHAR(8) NOT NULL DEFAULT \'\', path VARCHAR(255) DEFAULT \'\', status INT NOT NULL DEFAULT 0, latency_ms INT NOT NULL DEFAULT 0, ip VARCHAR(64) DEFAULT \'\', user_id INT NOT NULL DEFAULT 0, username VARCHAR(64) DEFAULT \'\', role VARCHAR(16) DEFAULT \'\', tenant_id INT NOT NULL DEFAULT 0, application_id INT NOT NULL DEFAULT 0, query VARCHAR(512) DEFAULT \'\', body_size INT NOT NULL DEFAULT 0, INDEX idx_api_logs_tenant (tenant_id), INDEX idx_api_logs_app (application_id), INDEX idx_api_logs_created (created_at))');
            foreach ([
                ['devices', 'last_seen', 'INT DEFAULT 0'],
                ['devices', 'last_gw_id', 'VARCHAR(32) DEFAULT \'\''],
                ['devices', 'ping_period', 'INT DEFAULT 0'],
                ['devices', 'beacon_epoch', 'INT NOT NULL DEFAULT 0'],
                ['devices', 'device_time', 'INT NOT NULL DEFAULT 0'],
                ['devices', 'device_time_valid', 'TINYINT NOT NULL DEFAULT 0'],
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
                ['devices', 'rx2_frequency', 'INT DEFAULT 0'],
                ['devices', 'rx_delay', 'INT DEFAULT 1'],
                ['devices', 'max_supported_tx_power_index', 'INT DEFAULT 0'],
                ['devices', 'min_supported_tx_power_index', 'INT DEFAULT 0'],
                ['devices', 'enabled_uplink_channel_indices', 'TEXT'],
                ['devices', 'mac_command_error_count', 'TEXT'],
                ['devices', 'uplink_adr_history', 'TEXT'],
                ['devices', 'pending_mac', 'TEXT'],
                ['devices', 'battery', 'INT DEFAULT -1'],
                ['devices', 'margin', 'INT DEFAULT NULL'],
                ['devices', 'dev_status_req_at', 'INT DEFAULT 0'],
                ['devices', 'latitude', 'DOUBLE DEFAULT 0'],
                ['devices', 'longitude', 'DOUBLE DEFAULT 0'],
                ['devices', 'altitude', 'DOUBLE DEFAULT 0'],
                ['applications', 'callback_url', 'VARCHAR(512) DEFAULT \'\''],
                ['events', 'raw_json', 'TEXT'],
                ['users', 'tenant_id', 'INT DEFAULT 0'],
                ['applications', 'tenant_id', 'INT DEFAULT 0'],
                ['devices', 'tenant_id', 'INT DEFAULT 0'],
                ['device_profiles', 'tenant_id', 'INT DEFAULT 0'],
                ['gateways', 'tenant_id', 'INT DEFAULT 0'],
                ['api_keys', 'tenant_id', 'INT DEFAULT 0'],
                ['integrations', 'tenant_id', 'INT DEFAULT 0'],
                ['multicast_groups', 'tenant_id', 'INT DEFAULT 0'],
                ['downlinks', 'transmissions', 'INT DEFAULT 0'],
                ['downlinks', 'acknowledged_at', 'INT DEFAULT 0'],
                ['downlinks', 'raw_json', 'TEXT'],
                ['downlinks', 'mac', 'TINYINT NOT NULL DEFAULT 0'],
                ['roaming_servers', 'net_id', 'VARCHAR(6) DEFAULT \'\''],
                ['roaming_servers', 'kek_label', 'VARCHAR(32) DEFAULT \'\''],
                ['roaming_servers', 'ca_cert', 'TEXT'],
                ['roaming_servers', 'tls_cert', 'TEXT'],
                ['roaming_servers', 'tls_key', 'TEXT'],
                ['roaming_servers', 'authorization', 'VARCHAR(255) DEFAULT \'\''],
                ['roaming_servers', 'passive_roaming_lifetime', 'INT DEFAULT 0'],
                ['roaming_servers', 'validate_mic', 'TINYINT DEFAULT 1'],
                ['devices', 'relay_state', 'TEXT'],
                ['device_profiles', 'relay_params', 'TEXT'],
                

                ['relay_devices', 'slot_index', 'INT NOT NULL DEFAULT 0'],
                ['relay_devices', 'join_eui', 'VARCHAR(32) DEFAULT \'\''],
                ['relay_devices', 'root_wor_s_key', 'VARCHAR(64) DEFAULT \'\''],
                ['relay_devices', 'provisioned', 'TINYINT NOT NULL DEFAULT 0'],
                ['relay_devices', 'uplink_limit_bucket_size', 'INT NOT NULL DEFAULT 0'],
                ['relay_devices', 'uplink_limit_reload_rate', 'INT NOT NULL DEFAULT 0'],
                ['relay_devices', 'w_f_cnt_last_request', 'INT NOT NULL DEFAULT 0'],
                

                ['fuota_campaigns', 'state', 'VARCHAR(16) NOT NULL DEFAULT \'PENDING\''],
                ['fuota_campaigns', 'mc_ke_key', 'VARCHAR(64) DEFAULT \'\''],
                ['fuota_campaigns', 'min_delay', 'INT NOT NULL DEFAULT 200'],
                ['fuota_campaigns', 'max_delay', 'INT NOT NULL DEFAULT 1000'],
                ['fuota_campaigns', 'timeout', 'INT NOT NULL DEFAULT 3600'],
                ['fuota_campaigns', 'frames_sent', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'total_frames', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'next_frame_at', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'started_at', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'updated_at', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'firmware_sha256', 'VARCHAR(64) DEFAULT \'\''],
                ['fuota_campaigns', 'firmware_crc', 'INT NOT NULL DEFAULT 0'],
                ['fuota_campaigns', 'status_req_sent', 'TINYINT NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'frag_nb_missing', 'INT NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'mc_group_ans', 'TINYINT NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'status_ans', 'TINYINT NOT NULL DEFAULT 0'],
                ['fuota_deployments', 'updated_at', 'INT NOT NULL DEFAULT 0'],
                

                ['tenants', 'private_gateways_unlimited', 'TINYINT NOT NULL DEFAULT 0'],
            ] as [$tbl, $col, $def]) {
                if (!self::mysqlColumnExists($tbl, $col)) {
                    $pdo->exec("ALTER TABLE $tbl ADD COLUMN $col $def");
                }
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_keks (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(32) NOT NULL UNIQUE, kek VARCHAR(64) DEFAULT \'\', created_at INT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS roaming_pending (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, dev_eui VARCHAR(32) DEFAULT \'\', dev_addr VARCHAR(16) DEFAULT \'\', gw_id VARCHAR(32) NOT NULL DEFAULT \'\', peer TEXT, ul_tmst INT NOT NULL DEFAULT 0, region VARCHAR(16) NOT NULL DEFAULT \'\', freq DOUBLE NOT NULL DEFAULT 0, datr VARCHAR(16) DEFAULT \'\', dl_delay INT NOT NULL DEFAULT 0, created_at INT NOT NULL, expires_at INT NOT NULL DEFAULT 0, INDEX idx_rp_dev (dev_eui), INDEX idx_rp_addr (dev_addr))');
            

            if (self::mysqlColumnExists('tenants', 'can_have_gateways') && self::mysqlColumnExists('tenants', 'private_gateways_unlimited')) {
                try {
                    $pdo->exec("UPDATE tenants SET private_gateways_unlimited=1 WHERE can_have_gateways=1");
                } catch (\Throwable $e) {
                    error_log('migrate tenants (mysql) data copy: ' . $e->getMessage());
                }
            }
        }
        

        DeviceProfile::ensureDefault();
    }
}
