-- holastack SQLite schema
CREATE TABLE IF NOT EXISTS applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    app_eui TEXT DEFAULT '',
    callback_url TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

-- ---- 租户（Tenant，多租户隔离基础） ----
-- private_gateways_unlimited=1 → 关闭上限，无限创建
-- private_gateways_unlimited=0 → 按 private_gateways_limit 限制（默认 0 = 不允许创建网关）
CREATE TABLE IF NOT EXISTS tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    private_gateways_limit INTEGER NOT NULL DEFAULT 0,
    private_gateways_unlimited INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS gateways (
    gw_id TEXT PRIMARY KEY,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    region TEXT DEFAULT '',
    created_at INTEGER NOT NULL,
    last_seen INTEGER DEFAULT 0,
    ip TEXT DEFAULT '',
    stats TEXT DEFAULT '',
    rf_config TEXT DEFAULT ''
);

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    dev_eui TEXT NOT NULL,
    join_eui TEXT DEFAULT '',
    dev_addr TEXT DEFAULT '',
    activation TEXT NOT NULL DEFAULT 'OTAA',
    app_key TEXT DEFAULT '',
    nwk_key TEXT DEFAULT '',
    nwk_s_key TEXT DEFAULT '',
    app_s_key TEXT DEFAULT '',
    f_nwk_s_int_key TEXT DEFAULT '',
    s_nwk_s_int_key TEXT DEFAULT '',
    nwk_s_enc_key TEXT DEFAULT '',
    mac_version TEXT DEFAULT '1.0.3',
    class TEXT NOT NULL DEFAULT 'A',
    region TEXT NOT NULL DEFAULT 'EU868',
    device_profile_id INTEGER DEFAULT 0,
    fcnt_up INTEGER NOT NULL DEFAULT 0,
    fcnt_down INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    last_gw_id TEXT DEFAULT '',
    ping_period INTEGER NOT NULL DEFAULT 0,
    beacon_epoch INTEGER NOT NULL DEFAULT 0,
    -- MAC command / ADR session state
    adr INTEGER NOT NULL DEFAULT 1,
    dr INTEGER NOT NULL DEFAULT 0,
    tx_power_index INTEGER NOT NULL DEFAULT 0,
    nb_trans INTEGER NOT NULL DEFAULT 1,
    rx1_dr_offset INTEGER NOT NULL DEFAULT 0,
    rx2_dr INTEGER NOT NULL DEFAULT 0,
    rx2_frequency INTEGER NOT NULL DEFAULT 0,
    rx_delay INTEGER NOT NULL DEFAULT 1,
    max_supported_tx_power_index INTEGER NOT NULL DEFAULT 0,
    min_supported_tx_power_index INTEGER NOT NULL DEFAULT 0,
    enabled_uplink_channel_indices TEXT DEFAULT '',
    mac_command_error_count TEXT DEFAULT '',
    uplink_adr_history TEXT DEFAULT '',
    pending_mac TEXT DEFAULT '',
    relay_state TEXT DEFAULT '',
    last_seen INTEGER DEFAULT 0,
    battery INTEGER DEFAULT -1,
    margin INTEGER DEFAULT NULL,
    dev_status_req_at INTEGER DEFAULT 0,
    device_time_valid INTEGER NOT NULL DEFAULT 0,
    device_time INTEGER NOT NULL DEFAULT 0,
    latitude REAL DEFAULT 0,
    longitude REAL DEFAULT 0,
    altitude REAL DEFAULT 0,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (app_id) REFERENCES applications(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    tenant_id INTEGER NOT NULL DEFAULT 0,
    email TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS uplinks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dev_id INTEGER NOT NULL,
    app_id INTEGER NOT NULL,
    dev_addr TEXT NOT NULL,
    dev_eui TEXT DEFAULT '',
    fcnt INTEGER NOT NULL,
    port INTEGER NOT NULL,
    confirmed INTEGER NOT NULL DEFAULT 0,
    payload_hex TEXT DEFAULT '',
    decrypted_hex TEXT DEFAULT '',
    phy_payload TEXT DEFAULT '',
    raw_json TEXT DEFAULT '',
    data_rate TEXT DEFAULT '',
    frequency REAL DEFAULT 0,
    rssi INTEGER DEFAULT 0,
    snr REAL DEFAULT 0,
    gateway_id TEXT DEFAULT '',
    received_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS downlinks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dev_id INTEGER NOT NULL,
    app_id INTEGER NOT NULL,
    port INTEGER NOT NULL,
    payload_hex TEXT NOT NULL,
    confirmed INTEGER NOT NULL DEFAULT 0,
    mac INTEGER NOT NULL DEFAULT 0,
    fcnt INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at INTEGER NOT NULL,
    sent_at INTEGER DEFAULT 0,
    transmissions INTEGER NOT NULL DEFAULT 0,
    acknowledged_at INTEGER DEFAULT 0,
    raw_json TEXT
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type VARCHAR(16) NOT NULL,
    level VARCHAR(8) NOT NULL DEFAULT 'info',
    gateway_id VARCHAR(32) DEFAULT '',
    dev_id INTEGER DEFAULT 0,
    app_id INTEGER DEFAULT 0,
    message TEXT DEFAULT '',
    raw_json TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

-- ---- 设备配置模板（Device Profile） ----
CREATE TABLE IF NOT EXISTS device_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    region TEXT NOT NULL DEFAULT 'EU868',
    mac_version TEXT NOT NULL DEFAULT '1.0.4',
    reg_params_revision TEXT NOT NULL DEFAULT 'RP002-1.0.3',
    adr_algorithm TEXT NOT NULL DEFAULT 'default',
    payload_codec_runtime TEXT NOT NULL DEFAULT 'NONE',
    payload_codec_script TEXT DEFAULT '',
    flush_queue_on_activate INTEGER NOT NULL DEFAULT 0,
    uplink_interval INTEGER NOT NULL DEFAULT 0,
    device_status_req_interval INTEGER NOT NULL DEFAULT 0,
    supports_otaa INTEGER NOT NULL DEFAULT 1,
    supports_class_b INTEGER NOT NULL DEFAULT 0,
    supports_class_c INTEGER NOT NULL DEFAULT 0,
    class_b_timeout INTEGER NOT NULL DEFAULT 0,
    class_b_ping_slot_periodicity INTEGER NOT NULL DEFAULT 0,
    class_b_ping_slot_dr INTEGER NOT NULL DEFAULT 0,
    class_b_ping_slot_freq INTEGER NOT NULL DEFAULT 0,
    class_c_timeout INTEGER NOT NULL DEFAULT 0,
    abp_rx1_delay INTEGER NOT NULL DEFAULT 1,
    abp_rx1_dr_offset INTEGER NOT NULL DEFAULT 0,
    abp_rx2_dr INTEGER NOT NULL DEFAULT 0,
    abp_rx2_freq INTEGER NOT NULL DEFAULT 0,
    allow_roaming INTEGER NOT NULL DEFAULT 0,
    relay_params TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

-- ---- 应用级 API Key ----
CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    api_key TEXT NOT NULL UNIQUE,
    application_id INTEGER NOT NULL,
    created_at INTEGER DEFAULT 0
);

-- ---- 站点设置（键值存储，仅 admin 可写） ----
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    skey TEXT NOT NULL UNIQUE,
    svalue TEXT,
    updated_at INTEGER DEFAULT 0
);

-- ---- 集成配置 ----
CREATE TABLE IF NOT EXISTS integrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id INTEGER NOT NULL,
    tenant_id INTEGER DEFAULT 0,
    kind TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    config_json TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

-- ---- 组播组 ----
CREATE TABLE IF NOT EXISTS multicast_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    application_id INTEGER NOT NULL,
    region TEXT NOT NULL DEFAULT 'EU868',
    group_type TEXT NOT NULL DEFAULT 'C',
    mc_addr TEXT DEFAULT '',
    mc_nwk_s_key TEXT DEFAULT '',
    mc_app_s_key TEXT DEFAULT '',
    f_cnt INTEGER NOT NULL DEFAULT 0,
    dr INTEGER NOT NULL DEFAULT 0,
    frequency INTEGER NOT NULL DEFAULT 0,
    class_b_ping_slot_periodicity INTEGER NOT NULL DEFAULT 0,
    class_c_scheduling_type TEXT NOT NULL DEFAULT 'DELAY',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS multicast_group_devices (
    multicast_group_id INTEGER NOT NULL,
    dev_eui TEXT NOT NULL,
    PRIMARY KEY (multicast_group_id, dev_eui)
);

CREATE TABLE IF NOT EXISTS multicast_group_gateways (
    multicast_group_id INTEGER NOT NULL,
    gw_id TEXT NOT NULL,
    PRIMARY KEY (multicast_group_id, gw_id)
);

CREATE TABLE IF NOT EXISTS multicast_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    multicast_group_id INTEGER NOT NULL,
    f_port INTEGER NOT NULL,
    payload_hex TEXT NOT NULL,
    f_cnt INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    expires_at INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_devices_dev_eui ON devices(dev_eui);
CREATE INDEX IF NOT EXISTS idx_devices_dev_addr ON devices(dev_addr);
CREATE INDEX IF NOT EXISTS idx_uplinks_dev ON uplinks(dev_id);
CREATE INDEX IF NOT EXISTS idx_downlinks_dev ON downlinks(dev_id);
CREATE INDEX IF NOT EXISTS idx_api_keys_app ON api_keys(application_id);
CREATE INDEX IF NOT EXISTS idx_integrations_app ON integrations(application_id);
CREATE INDEX IF NOT EXISTS idx_mg_app ON multicast_groups(application_id);
CREATE INDEX IF NOT EXISTS idx_mq_mg ON multicast_queue(multicast_group_id);

-- ---- Basic Station / LNS（WebSocket 后端） ----
CREATE TABLE IF NOT EXISTS stations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    gateway_id TEXT NOT NULL,
    name TEXT NOT NULL,
    region TEXT NOT NULL DEFAULT 'EU868',
    lns_secret TEXT DEFAULT '',
    ca_cert TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

-- ---- 中继（Relay, TS011 / LoRaWAN 1.1 Relay） ----
CREATE TABLE IF NOT EXISTS relay_gateways (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    relay_dev_eui TEXT NOT NULL,
    region TEXT NOT NULL DEFAULT 'EU868',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS relay_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    relay_gateway_id INTEGER NOT NULL,
    dev_eui TEXT NOT NULL,
    -- ---- 会话字段（对齐 ChirpStack internal.proto RelayDevice） ----
    slot_index INTEGER NOT NULL DEFAULT 0,          -- proto index：中继过滤列表槽位（0-15）
    join_eui TEXT DEFAULT '',
    dev_addr TEXT DEFAULT '',
    root_wor_s_key TEXT DEFAULT '',
    provisioned INTEGER NOT NULL DEFAULT 0,
    uplink_limit_bucket_size INTEGER NOT NULL DEFAULT 0,
    uplink_limit_reload_rate INTEGER NOT NULL DEFAULT 0,
    w_f_cnt_last_request INTEGER NOT NULL DEFAULT 0,
    -- ---- 会话密钥（1.0 用 nwk_s_key/app_s_key；1.1 用 f/s_nwk_s_int_key + nwk_s_enc_key） ----
    nwk_s_key TEXT DEFAULT '',
    app_s_key TEXT DEFAULT '',
    f_nwk_s_int_key TEXT DEFAULT '',
    s_nwk_s_int_key TEXT DEFAULT '',
    nwk_s_enc_key TEXT DEFAULT '',
    mac_version TEXT DEFAULT '1.1',
    created_at INTEGER NOT NULL
);

-- ---- FUOTA（固件分片 + 组播 + 时钟同步） ----
CREATE TABLE IF NOT EXISTS fuota_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    application_id INTEGER NOT NULL,
    multicast_group_id INTEGER NOT NULL,
    fragment_size INTEGER NOT NULL DEFAULT 200,
    redundancy INTEGER NOT NULL DEFAULT 1,
    descriptor_version INTEGER NOT NULL DEFAULT 0,
    fw_version TEXT DEFAULT '',
    fw_length INTEGER NOT NULL DEFAULT 0,
    -- 状态机（对齐 ChirpStack fuota_campaign：PENDING → SETUP → FRAGMENTATION → STATUS → DONE/FAILED）
    state VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    mc_ke_key TEXT DEFAULT '',
    min_delay INTEGER NOT NULL DEFAULT 200,
    max_delay INTEGER NOT NULL DEFAULT 1000,
    timeout INTEGER NOT NULL DEFAULT 3600,
    frames_sent INTEGER NOT NULL DEFAULT 0,
    total_frames INTEGER NOT NULL DEFAULT 0,
    next_frame_at INTEGER NOT NULL DEFAULT 0,
    started_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    firmware_sha256 TEXT DEFAULT '',
    firmware_crc INTEGER NOT NULL DEFAULT 0,
    status_req_sent INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fuota_deployments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    dev_id INTEGER NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    fragments_received INTEGER NOT NULL DEFAULT 0,
    frag_nb_missing INTEGER NOT NULL DEFAULT 0,
    mc_group_ans INTEGER NOT NULL DEFAULT 0,
    status_ans INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS fuota_fragments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    deployment_id INTEGER NOT NULL,
    frag_index INTEGER NOT NULL,
    data TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

-- 预组装的组播下行帧（按 campaign 存，调度器按 min/max_delay 节流取出）
CREATE TABLE IF NOT EXISTS fuota_frames (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    seq INTEGER NOT NULL,
    fopts_hex TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

-- ---- 漫游（Roaming, Backend Interface） ----
CREATE TABLE IF NOT EXISTS roaming_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER DEFAULT 0,
    name TEXT NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'PASSIVE',
    protocol VARCHAR(16) NOT NULL DEFAULT 'BI_1_0',
    server TEXT DEFAULT '',
    async_timeout INTEGER NOT NULL DEFAULT 250,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL
);

-- ---- API 调用日志（admin 全局 / tenant 仅本租户 可读） ----
CREATE TABLE IF NOT EXISTS api_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at INTEGER NOT NULL,
    method VARCHAR(8) NOT NULL DEFAULT '',
    path TEXT DEFAULT '',
    status INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    ip TEXT DEFAULT '',
    user_id INTEGER NOT NULL DEFAULT 0,
    username TEXT DEFAULT '',
    role VARCHAR(16) DEFAULT '',
    tenant_id INTEGER NOT NULL DEFAULT 0,
    application_id INTEGER NOT NULL DEFAULT 0,
    query TEXT DEFAULT '',
    body_size INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_api_logs_tenant ON api_logs(tenant_id);
CREATE INDEX IF NOT EXISTS idx_api_logs_app ON api_logs(application_id);
CREATE INDEX IF NOT EXISTS idx_api_logs_created ON api_logs(created_at);
