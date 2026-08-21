<?php
namespace holastack\Integration;

use holastack\DB\Database;








class Integration
{
    public const KIND_HTTP = 'HTTP';
    public const KIND_INFLUX_DB = 'INFLUX_DB';
    public const KIND_MQTT = 'MQTT_GLOBAL';
    

    public const KIND_AWS_SNS = 'AWS_SNS';
    public const KIND_AZURE_SB = 'AZURE_SERVICE_BUS';
    public const KIND_GCP_PUBSUB = 'GCP_PUBSUB';
    public const KIND_AMQP = 'AMQP';
    public const KIND_KAFKA = 'KAFKA';

    public static function listKinds(): array
    {
        return [
            self::KIND_HTTP,
            self::KIND_INFLUX_DB,
            self::KIND_MQTT,
            self::KIND_AWS_SNS,
            self::KIND_AZURE_SB,
            self::KIND_GCP_PUBSUB,
            self::KIND_AMQP,
            self::KIND_KAFKA,
        ];
    }

    




    public static function create(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        $kind = strtoupper($p['kind'] ?? '');
        if ($appId <= 0) {
            return ['error' => 'application_id required'];
        }
        if (!in_array($kind, self::listKinds(), true)) {
            return ['error' => 'unsupported integration kind: ' . $kind];
        }
        $config = $p['config'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }
        Database::execute(
            "INSERT INTO integrations (application_id, tenant_id, kind, enabled, config_json, created_at) VALUES (?,?,?,?,?,?)",
            [$appId, (int) ($p['tenant_id'] ?? 0), $kind, !empty($p['enabled']) ? 1 : 0, json_encode($config, JSON_UNESCAPED_UNICODE), time()]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function list(int $applicationId): array
    {
        return Database::fetchAll("SELECT * FROM integrations WHERE application_id=? ORDER BY id DESC", [$applicationId]);
    }

    public static function update(int $id, array $p): array
    {
        $set = [];
        $params = [];
        if (isset($p['enabled'])) {
            $set[] = 'enabled=?';
            $params[] = $p['enabled'] ? 1 : 0;
        }
        if (array_key_exists('config', $p)) {
            $set[] = 'config_json=?';
            $params[] = json_encode($p['config'] ?? [], JSON_UNESCAPED_UNICODE);
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE integrations SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        Database::execute("DELETE FROM integrations WHERE id=?", [$id]);
        return ['ok' => true];
    }

    









    public static function dispatch(int $appId, array $device, array $uplinkData, array $telemetry, callable $log, string $eventType = 'up'): void
    {
        $rows = Database::fetchAll("SELECT * FROM integrations WHERE application_id=? AND enabled=1", [$appId]);
        if (empty($rows)) {
            return;
        }
        

        $data = self::buildPayload($device, $uplinkData, $telemetry, $eventType);
        foreach ($rows as $it) {
            $cfg = json_decode($it['config_json'] ?? '{}', true) ?: [];
            try {
                switch ($it['kind']) {
                    case self::KIND_HTTP:
                        self::handleHttp($cfg, $data, $log);
                        break;
                    case self::KIND_INFLUX_DB:
                        self::handleInfluxDb($cfg, $data, $log);
                        break;
                    case self::KIND_MQTT:
                        self::handleMqtt($cfg, $data, $log, $eventType);
                        break;
                    case self::KIND_AWS_SNS:
                        self::handleAwsSns($cfg, $data, $log);
                        break;
                    case self::KIND_AZURE_SB:
                        self::handleAzureServiceBus($cfg, $data, $log);
                        break;
                    case self::KIND_GCP_PUBSUB:
                        self::handleGcpPubsub($cfg, $data, $log);
                        break;
                    case self::KIND_AMQP:
                        self::handleAmqp($cfg, $data, $log, $eventType);
                        break;
                    case self::KIND_KAFKA:
                        self::handleKafka($cfg, $data, $log, $eventType);
                        break;
                    default:
                        $log("INTEGRATION: unsupported kind {$it['kind']}");
                }
            } catch (\Throwable $e) {
                $log("INTEGRATION: {$it['kind']} failed: " . $e->getMessage());
            }
        }
    }

    public static function buildPayload(array $device, array $uplinkData, array $telemetry, string $eventType = 'up'): array
    {
        $appId = (int) ($device['app_id'] ?? $uplinkData['app_id'] ?? 0);
        $app = Database::fetch("SELECT name FROM applications WHERE id=?", [$appId]);
        $appName = $app['name'] ?? ('app-' . $appId);

        $decoded = null;
        $dpId = (int) ($device['device_profile_id'] ?? 0);
        if ($dpId > 0 && !empty($uplinkData['payload_hex'])) {
            $dp = Database::fetch("SELECT payload_codec_runtime FROM device_profiles WHERE id=?", [$dpId]);
            if ($dp && ($dp['payload_codec_runtime'] ?? '') === Codec::RUNTIME_CAYENNE_LPP) {
                $decoded = Codec::decodeUplink(Codec::RUNTIME_CAYENNE_LPP, $uplinkData['payload_hex'] ?? '');
            }
        }

        $tele = [];
        foreach (['battery', 'margin', 'latitude', 'longitude', 'altitude'] as $k) {
            if (array_key_exists($k, $telemetry) && $telemetry[$k] !== null && $telemetry[$k] !== '') {
                $tele[$k] = $telemetry[$k];
            }
        }

        return [
            'event' => $eventType,
            'end_device_ids' => [
                'device_id'       => $uplinkData['name'] ?? $uplinkData['dev_eui'] ?? '',
                'dev_eui'         => $uplinkData['dev_eui'] ?? '',
                'dev_addr'        => $uplinkData['dev_addr'] ?? '',
                'application_ids' => ['application_id' => $appName],
            ],
            'received_at' => gmdate('Y-m-d H:i:s', (int) ($uplinkData['received_at'] ?? time())),
            'uplink_message' => [
                'f_port'          => (int) ($uplinkData['port'] ?? 0),
                'f_cnt'           => (int) ($uplinkData['fcnt'] ?? 0),
                'frm_payload'     => $uplinkData['frm_payload'] ?? '',
                'decoded_payload' => $decoded, 

                'confirmed'       => !empty($uplinkData['confirmed']),
                'rx_metadata'     => [[
                    'gateway_ids'  => ['gateway_id' => $uplinkData['gateway_id'] ?? ''],
                    'rssi'         => (int) ($uplinkData['rssi'] ?? 0),
                    'channel_rssi' => (int) ($uplinkData['rssi'] ?? 0),
                    'snr'          => (float) ($uplinkData['snr'] ?? 0),
                ]],
                'settings' => [
                    'data_rate' => ['lora' => ['bandwidth' => 0, 'spreading_factor' => 0]],
                    'frequency' => (string) ($uplinkData['frequency'] ?? ''),
                    'timestamp' => (int) ($uplinkData['tmst'] ?? 0),
                ],
            ],
        ];
    }

    


    private static function handleHttp(array $cfg, array $data, callable $log): void
    {
        $url = $cfg['url'] ?? '';
        if ($url === '') {
            $log("INTEGRATION HTTP: missing url");
            return;
        }
        $headers = $cfg['headers'] ?? [];
        self::httpPost($url, $data, $headers, $log);
    }

    private static function handleInfluxDb(array $cfg, array $data, callable $log): void
    {
        $endpoint = $cfg['endpoint'] ?? '';
        if ($endpoint === '') {
            $log("INTEGRATION INFLUX: missing endpoint");
            return;
        }
        $measurement = $cfg['measurement'] ?? 'device_uplink';
        $um = $data['uplink_message'] ?? [];
        $rm = $um['rx_metadata'][0] ?? [];
        $fields = [];
        if (isset($um['f_cnt'])) $fields['f_cnt'] = (int) $um['f_cnt'];
        if (isset($um['f_port'])) $fields['f_port'] = (int) $um['f_port'];
        if (isset($rm['rssi'])) $fields['rssi'] = (int) $rm['rssi'];
        if (isset($rm['snr'])) $fields['snr'] = (float) $rm['snr'];
        $decoded = $um['decoded_payload'] ?? null;
        if (is_array($decoded)) {
            foreach ($decoded as $d) {
                if (isset($d['value'])) {
                    $fields['ch_' . $d['channel'] . '_' . $d['type']] = is_numeric($d['value']) ? $d['value'] : 0;
                }
            }
        }
        if (empty($fields)) {
            return;
        }
        $tags = 'device=' . ($data['end_device_ids']['dev_eui'] ?? 'unknown');
        $fparts = [];
        foreach ($fields as $k => $v) {
            $fparts[] = "$k=$v";
        }
        $line = $measurement . ',' . $tags . ' ' . implode(',', $fparts) . ' ' . ((int) (microtime(true) * 1e9));
        $headers = ['Content-Type' => 'text/plain'];
        if (!empty($cfg['token'])) {
            $headers['Authorization'] = 'Token ' . $cfg['token'];
        }
        self::httpPostRaw($endpoint, $line, $headers, $log);
    }

    private static function handleMqtt(array $cfg, array $data, callable $log, string $eventType = 'up'): void
    {
        $server = $cfg['server'] ?? 'tcp://127.0.0.1:1883';
        $topic = $cfg['topic'] ?? 'application/{app_id}/device/{dev_eui}/up';
        $topic = str_replace(
            ['{app_id}', '{dev_eui}', '{dev_addr}', '{event}'],
            [
                $data['end_device_ids']['application_ids']['application_id'] ?? '',
                $data['end_device_ids']['dev_eui'] ?? '',
                $data['end_device_ids']['dev_addr'] ?? '',
                $eventType,
            ],
            $topic
        );
        $qos = (int) ($cfg['qos'] ?? 0);
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);

        $client = new MqttClient(
            $server,
            $cfg['username'] ?? '',
            $cfg['password'] ?? '',
            '',
            !empty($cfg['tls']),
            !empty($cfg['tls_verify'])
        );
        if (!$client->connect()) {
            $log("INTEGRATION MQTT: connect failed $server");
            return;
        }
        $client->publish($topic, $body, $qos);
        $client->disconnect();
        $log("INTEGRATION MQTT: published to $topic");
    }

    private static function httpPost(string $url, array $body, array $headers, callable $log): void
    {
        self::httpPostRaw($url, json_encode($body, JSON_UNESCAPED_UNICODE), $headers, $log);
    }

    






    private static function httpsRequest(string $url, string $body, array $headers, callable $log, string $method = 'POST'): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            $log("INTEGRATION HTTP: bad scheme $url");
            return null;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (!empty($parts['query']) ? '?' . $parts['query'] : '');
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $ctx = $scheme === 'https'
            ? stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
            : null;
        $fp = @stream_socket_client("$transport://$host:$port", $errno, $errstr, 3.0, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $log("INTEGRATION HTTP: connect failed $url ($errstr #$errno)");
            return null;
        }
        $hdrs = "$method $path HTTP/1.1\r\nHost: $host\r\nContent-Length: " . strlen($body) . "\r\n";
        foreach ($headers as $k => $v) {
            $hdrs .= "$k: $v\r\n";
        }
        $hdrs .= "Connection: close\r\n\r\n";
        $req = $hdrs . $body;
        $len = strlen($req);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($fp, substr($req, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
        stream_set_timeout($fp, 3);
        $resp = '';
        while (!@feof($fp)) {
            $chunk = @fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $resp .= $chunk;
        }
        @fclose($fp);
        $pos = strpos($resp, "\r\n\r\n");
        return $pos === false ? $resp : substr($resp, $pos + 4);
    }

    private static function httpPostRaw(string $url, string $body, array $headers, callable $log): void
    {
        self::httpsRequest($url, $body, $headers, $log);
        $log("INTEGRATION HTTP: POST $url (" . strlen($body) . "B)");
    }

    


    





    private static function handleAwsSns(array $cfg, array $data, callable $log): void
    {
        $region = $cfg['aws_region'] ?? '';
        $keyId = $cfg['aws_access_key_id'] ?? '';
        $secret = $cfg['aws_secret_access_key'] ?? '';
        $topicArn = $cfg['topic_arn'] ?? '';
        if ($region === '' || $keyId === '' || $secret === '' || $topicArn === '') {
            $log("INTEGRATION AWS_SNS: missing aws_region/aws_access_key_id/aws_secret_access_key/topic_arn");
            return;
        }
        $host = "sns.$region.amazonaws.com";
        $body = http_build_query([
            'Action' => 'Publish',
            'Message' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'TopicArn' => $topicArn,
            'Version' => '2010-03-31',
        ]);
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);
        $canonicalHeaders = "content-type:application/x-www-form-urlencoded\nhost:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "POST\n/\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $scope = "$datestamp/$region/sns/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $kSecret = 'AWS4' . $secret;
        $kDate = hash_hmac('sha256', $datestamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'sns', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "AWS4-HMAC-SHA256 Credential=$keyId/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization' => $authorization,
        ];
        $ok = self::httpsRequest("https://$host/", $body, $headers, $log) !== null;
        $log("INTEGRATION AWS_SNS: publish " . ($ok ? 'ok' : 'failed') . " -> $topicArn");
    }

    






    private static function handleAzureServiceBus(array $cfg, array $data, callable $log): void
    {
        $conn = $cfg['connection_string'] ?? '';
        $publishName = $cfg['publish_name'] ?? '';
        if ($conn === '' || $publishName === '') {
            $log("INTEGRATION AZURE_SB: missing connection_string/publish_name");
            return;
        }
        $parts = [];
        foreach (explode(';', $conn) as $kv) {
            if (strpos($kv, '=') !== false) {
                [$k, $v] = explode('=', $kv, 2);
                $parts[trim($k)] = trim($v);
            }
        }
        $endpoint = rtrim($parts['Endpoint'] ?? '', '/'); 

        $keyName = $parts['SharedAccessKeyName'] ?? '';
        $key = $parts['SharedAccessKey'] ?? '';
        if ($endpoint === '' || $keyName === '' || $key === '') {
            $log("INTEGRATION AZURE_SB: connection_string missing Endpoint/SharedAccessKeyName/SharedAccessKey");
            return;
        }
        

        $host = parse_url($endpoint, PHP_URL_HOST); 

        $resourceUri = "https://$host/$publishName";
        $expiry = time() + 3600;
        $toSign = urlencode($resourceUri) . "\n" . $expiry;
        $sig = base64_encode(hash_hmac('sha256', $toSign, $key, true));
        $sas = "SharedAccessSignature sr=" . urlencode($resourceUri)
            . "&sig=" . urlencode($sig) . "&se=$expiry&skn=" . urlencode($keyName);
        $url = "https://$host/$publishName/messages?api-version=2021-05";
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => $sas,
        ];
        $ok = self::httpsRequest($url, $body, $headers, $log) !== null;
        $log("INTEGRATION AZURE_SB: publish " . ($ok ? 'ok' : 'failed') . " -> $publishName");
    }

    





    private static function handleGcpPubsub(array $cfg, array $data, callable $log): void
    {
        $projectId = $cfg['project_id'] ?? '';
        $topicName = $cfg['topic_name'] ?? '';
        $credPath = $cfg['credentials_file'] ?? '';
        $credJson = $cfg['credentials_json'] ?? '';
        if ($projectId === '' || $topicName === '') {
            $log("INTEGRATION GCP_PUBSUB: missing project_id/topic_name");
            return;
        }
        if ($credJson === '' && $credPath !== '') {
            $credJson = @file_get_contents($credPath);
        }
        if ($credJson === '') {
            $log("INTEGRATION GCP_PUBSUB: missing credentials (credentials_json or credentials_file)");
            return;
        }
        $sa = json_decode($credJson, true);
        if (!isset($sa['client_email'], $sa['private_key'])) {
            $log("INTEGRATION GCP_PUBSUB: service-account JSON missing client_email/private_key");
            return;
        }
        $token = self::gcpAccessToken($sa['client_email'], $sa['private_key'], $log);
        if ($token === '') {
            return;
        }
        $url = "https://pubsub.googleapis.com/v1/projects/$projectId/topics/$topicName:publish";
        $body = json_encode(['messages' => [['data' => base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE))]]]);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer $token",
        ];
        $ok = self::httpsRequest($url, $body, $headers, $log) !== null;
        $log("INTEGRATION GCP_PUBSUB: publish " . ($ok ? 'ok' : 'failed') . " -> $topicName");
    }

    private static function gcpAccessToken(string $clientEmail, string $privateKey, callable $log): string
    {
        $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = self::base64url(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/pubsub',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600,
        ]));
        $signingInput = "$header.$claim";
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
            $log("INTEGRATION GCP_PUBSUB: openssl_sign failed (check private_key / openssl ext)");
            return '';
        }
        $assertion = "$signingInput." . self::base64url($sig);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        $resp = self::httpsRequest(
            'https://oauth2.googleapis.com/token',
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $log
        );
        if ($resp === null) {
            return '';
        }
        $j = json_decode($resp, true);
        return $j['access_token'] ?? '';
    }

    private static function base64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    






    private static function handleAmqp(array $cfg, array $data, callable $log, string $eventType = 'up'): void
    {
        $url = $cfg['url'] ?? '';
        $exchange = $cfg['exchange'] ?? 'amq.topic';
        $routingTpl = $cfg['routing_key_template'] ?? 'application.{app_id}.device.{dev_eui}.event.{event}';
        if ($url === '') {
            $log("INTEGRATION AMQP: missing url");
            return;
        }
        $routingKey = str_replace(
            ['{app_id}', '{dev_eui}', '{dev_addr}', '{event}'],
            [
                $data['end_device_ids']['application_ids']['application_id'] ?? '',
                $data['end_device_ids']['dev_eui'] ?? '',
                $data['end_device_ids']['dev_addr'] ?? '',
                $eventType,
            ],
            $routingTpl
        );
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $client = new AmqpClient($url);
        if (!$client->connect()) {
            $log("INTEGRATION AMQP: connect failed $url");
            return;
        }
        $client->publish($exchange, $routingKey, $body);
        $client->disconnect();
        $log("INTEGRATION AMQP: published to exchange=$exchange key=$routingKey");
    }

    





    private static function handleKafka(array $cfg, array $data, callable $log, string $eventType = 'up'): void
    {
        $brokers = $cfg['brokers'] ?? '';
        $topic = $cfg['topic'] ?? '';
        if ($brokers === '' || $topic === '') {
            $log("INTEGRATION KAFKA: missing brokers/topic");
            return;
        }
        if (!extension_loaded('rdkafka')) {
            $log("INTEGRATION KAFKA: php-rdkafka extension not loaded; publish skipped (install rdkafka)");
            return;
        }
        try {
            $conf = new \RdKafka\Conf();
            if (!empty($cfg['username'])) {
                $conf->set('sasl.username', $cfg['username']);
                $conf->set('sasl.password', $cfg['password'] ?? '');
                $conf->set('sasl.mechanism', 'PLAIN');
                $conf->set('security.protocol', !empty($cfg['tls']) ? 'sasl_ssl' : 'sasl_plaintext');
            } elseif (!empty($cfg['tls'])) {
                $conf->set('security.protocol', 'ssl');
            }
            $producer = new \RdKafka\Producer($conf);
            $producer->addBrokers($brokers);
            $topicH = $producer->newTopic($topic);
            $key = $data['end_device_ids']['dev_eui'] ?? '';
            $topicH->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($data, JSON_UNESCAPED_UNICODE), $key ?: null);
            $producer->poll(0);
            $producer->flush(3000);
            $log("INTEGRATION KAFKA: published -> $topic");
        } catch (\Throwable $e) {
            $log("INTEGRATION KAFKA: " . $e->getMessage());
        }
    }
}
