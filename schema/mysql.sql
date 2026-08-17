-- holastack MySQL schema
CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    app_eui VARCHAR(32) DEFAULT '',
    callback_url VARCHAR(512) DEFAULT '',
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS gateways (
    gw_id VARCHAR(32) PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    region VARCHAR(32) DEFAULT '',
    created_at INT NOT NULL,
    last_seen INT DEFAULT 0,
    ip VARCHAR(64) DEFAULT '',
    stats TEXT
);

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    can_have_gateways TINYINT NOT NULL DEFAULT 1,
    private_gateways_limit INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id INT NOT NULL,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    dev_eui VARCHAR(32) NOT NULL,
    join_eui VARCHAR(32) DEFAULT '',
    dev_addr VARCHAR(16) DEFAULT '',
    activation VARCHAR(8) NOT NULL DEFAULT 'OTAA',
    app_key VARCHAR(64) DEFAULT '',
    nwk_key VARCHAR(64) DEFAULT '',
    nwk_s_key VARCHAR(64) DEFAULT '',
    app_s_key VARCHAR(64) DEFAULT '',
    f_nwk_s_int_key VARCHAR(64) DEFAULT '',
    s_nwk_s_int_key VARCHAR(64) DEFAULT '',
    nwk_s_enc_key VARCHAR(64) DEFAULT '',
    mac_version VARCHAR(16) DEFAULT '1.0.3',
    class VARCHAR(4) NOT NULL DEFAULT 'A',
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
    device_profile_id INT DEFAULT 0,
    fcnt_up INT NOT NULL DEFAULT 0,
    fcnt_down INT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    last_gw_id VARCHAR(32) DEFAULT '',
    last_seen INT DEFAULT 0,
    battery INT DEFAULT -1,
    margin INT DEFAULT 0,
    latitude DOUBLE DEFAULT 0,
    longitude DOUBLE DEFAULT 0,
    altitude DOUBLE DEFAULT 0,
    ping_period INT NOT NULL DEFAULT 0,
    beacon_epoch INT NOT NULL DEFAULT 0,
    -- MAC command / ADR session state
    adr TINYINT NOT NULL DEFAULT 1,
    dr INT NOT NULL DEFAULT 0,
    tx_power_index INT NOT NULL DEFAULT 0,
    nb_trans INT NOT NULL DEFAULT 1,
    rx1_dr_offset INT NOT NULL DEFAULT 0,
    rx2_dr INT NOT NULL DEFAULT 0,
    rx_delay INT NOT NULL DEFAULT 1,
    max_supported_tx_power_index INT NOT NULL DEFAULT 0,
    min_supported_tx_power_index INT NOT NULL DEFAULT 0,
    enabled_uplink_channel_indices TEXT,
    mac_command_error_count TEXT,
    uplink_adr_history TEXT,
    pending_mac TEXT,
    relay_state TEXT,
    created_at INT NOT NULL,
    INDEX idx_devices_dev_eui (dev_eui),
    INDEX idx_devices_dev_addr (dev_addr),
    FOREIGN KEY (app_id) REFERENCES applications(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'admin',
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS uplinks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dev_id INT NOT NULL,
    app_id INT NOT NULL,
    dev_addr VARCHAR(16) NOT NULL,
    dev_eui VARCHAR(32) DEFAULT '',
    fcnt INT NOT NULL,
    port INT NOT NULL,
    confirmed TINYINT NOT NULL DEFAULT 0,
    payload_hex TEXT,
    decrypted_hex TEXT,
    phy_payload TEXT,
    raw_json TEXT,
    data_rate VARCHAR(32) DEFAULT '',
    frequency DOUBLE DEFAULT 0,
    rssi INT DEFAULT 0,
    snr DOUBLE DEFAULT 0,
    gateway_id VARCHAR(32) DEFAULT '',
    received_at INT NOT NULL,
    INDEX idx_uplinks_dev (dev_id)
);

CREATE TABLE IF NOT EXISTS downlinks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dev_id INT NOT NULL,
    app_id INT NOT NULL,
    port INT NOT NULL,
    payload_hex TEXT NOT NULL,
    confirmed TINYINT NOT NULL DEFAULT 0,
    fcnt INT NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at INT NOT NULL,
    sent_at INT DEFAULT 0,
    transmissions INT NOT NULL DEFAULT 0,
    acknowledged_at INT DEFAULT 0,
    INDEX idx_downlinks_dev (dev_id)
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    token VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    created_at INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(16) NOT NULL,
    level VARCHAR(8) NOT NULL DEFAULT 'info',
    gateway_id VARCHAR(32) DEFAULT '',
    dev_id INT DEFAULT 0,
    app_id INT DEFAULT 0,
    message TEXT,
    raw_json TEXT,
    created_at INT NOT NULL
);

-- ---- ChirpStack-port: 设备配置模板（Device Profile） ----
CREATE TABLE IF NOT EXISTS device_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
    mac_version VARCHAR(16) NOT NULL DEFAULT '1.0.4',
    reg_params_revision VARCHAR(16) NOT NULL DEFAULT 'RP002-1.0.3',
    adr_algorithm VARCHAR(32) NOT NULL DEFAULT 'default',
    payload_codec_runtime VARCHAR(16) NOT NULL DEFAULT 'NONE',
    payload_codec_script TEXT,
    flush_queue_on_activate TINYINT NOT NULL DEFAULT 0,
    uplink_interval INT NOT NULL DEFAULT 0,
    device_status_req_interval INT NOT NULL DEFAULT 0,
    supports_otaa TINYINT NOT NULL DEFAULT 1,
    supports_class_b TINYINT NOT NULL DEFAULT 0,
    supports_class_c TINYINT NOT NULL DEFAULT 0,
    class_b_timeout INT NOT NULL DEFAULT 0,
    class_b_ping_slot_periodicity INT NOT NULL DEFAULT 0,
    class_b_ping_slot_dr INT NOT NULL DEFAULT 0,
    class_b_ping_slot_freq INT NOT NULL DEFAULT 0,
    class_c_timeout INT NOT NULL DEFAULT 0,
    abp_rx1_delay INT NOT NULL DEFAULT 1,
    abp_rx1_dr_offset INT NOT NULL DEFAULT 0,
    abp_rx2_dr INT NOT NULL DEFAULT 0,
    abp_rx2_freq INT NOT NULL DEFAULT 0,
    allow_roaming TINYINT NOT NULL DEFAULT 0,
    relay_params TEXT,
    created_at INT NOT NULL
);

-- ---- ChirpStack-port: 应用级 API Key ----
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    application_id INT NOT NULL,
    created_at INT NOT NULL DEFAULT 0,
    INDEX idx_api_keys_app (application_id)
);

-- ---- 站点设置（键值存储，仅 admin 可写） ----
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skey VARCHAR(64) NOT NULL UNIQUE,
    svalue TEXT,
    updated_at INT NOT NULL DEFAULT 0
);

-- ---- ChirpStack-port: 集成配置（HTTP/MQTT/InfluxDB/...） ----
CREATE TABLE IF NOT EXISTS integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    tenant_id INT DEFAULT 0,
    kind VARCHAR(32) NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 1,
    config_json TEXT,
    created_at INT NOT NULL,
    INDEX idx_integrations_app (application_id)
);

-- ---- ChirpStack-port: 组播组（Multicast Group, Class B/C） ----
CREATE TABLE IF NOT EXISTS multicast_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    application_id INT NOT NULL,
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
    group_type VARCHAR(8) NOT NULL DEFAULT 'C',
    mc_addr VARCHAR(16) DEFAULT '',
    mc_nwk_s_key VARCHAR(64) DEFAULT '',
    mc_app_s_key VARCHAR(64) DEFAULT '',
    f_cnt INT NOT NULL DEFAULT 0,
    dr INT NOT NULL DEFAULT 0,
    frequency INT NOT NULL DEFAULT 0,
    class_b_ping_slot_periodicity INT NOT NULL DEFAULT 0,
    class_c_scheduling_type VARCHAR(8) NOT NULL DEFAULT 'DELAY',
    created_at INT NOT NULL,
    INDEX idx_mg_app (application_id)
);

CREATE TABLE IF NOT EXISTS multicast_group_devices (
    multicast_group_id INT NOT NULL,
    dev_eui VARCHAR(32) NOT NULL,
    PRIMARY KEY (multicast_group_id, dev_eui)
);

CREATE TABLE IF NOT EXISTS multicast_group_gateways (
    multicast_group_id INT NOT NULL,
    gw_id VARCHAR(32) NOT NULL,
    PRIMARY KEY (multicast_group_id, gw_id)
);

CREATE TABLE IF NOT EXISTS multicast_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    multicast_group_id INT NOT NULL,
    f_port INT NOT NULL,
    payload_hex TEXT NOT NULL,
    f_cnt INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    expires_at INT DEFAULT 0,
    INDEX idx_mq_mg (multicast_group_id)
);

-- ---- ChirpStack-port: Basic Station / LNS（WebSocket 后端） ----
CREATE TABLE IF NOT EXISTS stations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    gateway_id VARCHAR(32) NOT NULL,
    name VARCHAR(128) NOT NULL,
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
    lns_secret VARCHAR(128) DEFAULT '',
    ca_cert TEXT,
    created_at INT NOT NULL
);

-- ---- ChirpStack-port: 中继（Relay, TS011 / LoRaWAN 1.1 Relay） ----
CREATE TABLE IF NOT EXISTS relay_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    relay_dev_eui VARCHAR(32) NOT NULL,
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS relay_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    relay_gateway_id INT NOT NULL,
    dev_eui VARCHAR(32) NOT NULL,
    -- ---- 会话字段（对齐 ChirpStack internal.proto RelayDevice） ----
    slot_index INT NOT NULL DEFAULT 0,              -- proto index：中继过滤列表槽位（0-15）
    join_eui VARCHAR(32) DEFAULT '',
    dev_addr VARCHAR(16) DEFAULT '',
    root_wor_s_key VARCHAR(64) DEFAULT '',
    provisioned TINYINT NOT NULL DEFAULT 0,
    uplink_limit_bucket_size INT NOT NULL DEFAULT 0,
    uplink_limit_reload_rate INT NOT NULL DEFAULT 0,
    w_f_cnt_last_request INT NOT NULL DEFAULT 0,
    -- ---- 会话密钥（1.0 用 nwk_s_key/app_s_key；1.1 用 f/s_nwk_s_int_key + nwk_s_enc_key） ----
    nwk_s_key VARCHAR(64) DEFAULT '',
    app_s_key VARCHAR(64) DEFAULT '',
    f_nwk_s_int_key VARCHAR(64) DEFAULT '',
    s_nwk_s_int_key VARCHAR(64) DEFAULT '',
    nwk_s_enc_key VARCHAR(64) DEFAULT '',
    mac_version VARCHAR(16) DEFAULT '1.1',
    created_at INT NOT NULL
);

-- ---- ChirpStack-port: FUOTA（固件分片 + 组播 + 时钟同步） ----
CREATE TABLE IF NOT EXISTS fuota_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    application_id INT NOT NULL,
    multicast_group_id INT NOT NULL,
    fragment_size INT NOT NULL DEFAULT 200,
    redundancy INT NOT NULL DEFAULT 1,
    descriptor_version INT NOT NULL DEFAULT 0,
    fw_version VARCHAR(32) DEFAULT '',
    fw_length INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS fuota_deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    dev_id INT NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    fragments_received INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS fuota_fragments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    frag_index INT NOT NULL,
    data TEXT NOT NULL,
    created_at INT NOT NULL
);

-- ---- ChirpStack-port: 漫游（Roaming, Backend Interface） ----
CREATE TABLE IF NOT EXISTS roaming_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT DEFAULT 0,
    name VARCHAR(128) NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'PASSIVE',
    protocol VARCHAR(16) NOT NULL DEFAULT 'BI_1_0',
    server VARCHAR(255) DEFAULT '',
    async_timeout INT NOT NULL DEFAULT 250,
    enabled TINYINT NOT NULL DEFAULT 1,
    created_at INT NOT NULL
);
