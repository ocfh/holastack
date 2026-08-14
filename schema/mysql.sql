-- holastack MySQL schema
CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    app_eui VARCHAR(32) DEFAULT '',
    callback_url VARCHAR(512) DEFAULT '',
    created_at INT NOT NULL
);

CREATE TABLE IF NOT EXISTS gateways (
    gw_id VARCHAR(32) PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    region VARCHAR(32) DEFAULT '',
    created_at INT NOT NULL,
    last_seen INT DEFAULT 0,
    ip VARCHAR(64) DEFAULT '',
    stats TEXT
);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    dev_eui VARCHAR(32) NOT NULL,
    join_eui VARCHAR(32) DEFAULT '',
    dev_addr VARCHAR(16) DEFAULT '',
    activation VARCHAR(8) NOT NULL DEFAULT 'OTAA',
    app_key VARCHAR(64) DEFAULT '',
    nwk_s_key VARCHAR(64) DEFAULT '',
    app_s_key VARCHAR(64) DEFAULT '',
    class VARCHAR(4) NOT NULL DEFAULT 'A',
    region VARCHAR(16) NOT NULL DEFAULT 'EU868',
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
    created_at INT NOT NULL
);
