-- holastack SQLite schema
CREATE TABLE IF NOT EXISTS applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT DEFAULT '',
    app_eui TEXT DEFAULT '',
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS gateways (
    gw_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    region TEXT DEFAULT '',
    created_at INTEGER NOT NULL,
    last_seen INTEGER DEFAULT 0,
    ip TEXT DEFAULT '',
    stats TEXT DEFAULT ''
);

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    dev_eui TEXT NOT NULL,
    join_eui TEXT DEFAULT '',
    dev_addr TEXT DEFAULT '',
    activation TEXT NOT NULL DEFAULT 'OTAA',
    app_key TEXT DEFAULT '',
    nwk_s_key TEXT DEFAULT '',
    app_s_key TEXT DEFAULT '',
    class TEXT NOT NULL DEFAULT 'A',
    region TEXT NOT NULL DEFAULT 'EU868',
    fcnt_up INTEGER NOT NULL DEFAULT 0,
    fcnt_down INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    last_gw_id TEXT DEFAULT '',
    ping_period INTEGER NOT NULL DEFAULT 0,
    beacon_epoch INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (app_id) REFERENCES applications(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
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
    fcnt INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at INTEGER NOT NULL,
    sent_at INTEGER DEFAULT 0
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
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_devices_dev_eui ON devices(dev_eui);
CREATE INDEX IF NOT EXISTS idx_devices_dev_addr ON devices(dev_addr);
CREATE INDEX IF NOT EXISTS idx_uplinks_dev ON uplinks(dev_id);
CREATE INDEX IF NOT EXISTS idx_downlinks_dev ON downlinks(dev_id);
