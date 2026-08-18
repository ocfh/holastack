# HolaStack
📡 基于PHP构建的开源轻量化LoRaWAN网络服务器（Network Server），配套交互式Web仪表盘，实现LoRa网关与终端接入、协议解析与跨业务系统协同互联。

## 环境要求
- PHP >= 8.1（推荐 8.3+），需启用扩展：`openssl`、`pdo_sqlite`（默认）或 `pdo_mysql`、`bcmath`。

```bash
# 启动Web后台
php -S domain public/index.php
# 浏览器打开 http://domain/install 完成初始化

# 启动网络服务器
php bin/server.php        # Semtech UDP Packet Forwarder，监听 :1700
php bin/lns.php           # Basic Station / LNS（WebSocket），默认 :3001
```

## 伪静态
```bash
# Apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.php [QSA,L]

# Nginx
if (!-e $request_filename) {
    rewrite ^/(.*)$ /index.php last;
}
```
