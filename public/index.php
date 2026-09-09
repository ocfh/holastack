<?php
require __DIR__ . '/../bootstrap.php';
use holastack\Web\WebApp;
use holastack\Web\Setting;
use holastack\Auth\Auth;
use holastack\Auth\ApiKey;
use holastack\DB\Database;
use holastack\Install\Installer;
use holastack\Storage\ApiLog;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . $path;
    if ($path !== '/' && is_file($staticFile)) {
        return false;
    }
}

$logApi = ($path === '/v1' || strpos($path, '/v1/') === 0);
if ($logApi) {
    $__apiLogStart = microtime(true);
    $__apiLogCtx = [
        'method' => $method,
        'path' => $path,
        'query' => $_SERVER['QUERY_STRING'] ?? '',
        'ip' => \holastack\Storage\ApiLog::clientIp(),
        'body_size' => strlen((string) file_get_contents('php://input')),
        'application_id' => 0,
    ];

    register_shutdown_function(static function () use (&$__apiLogStart, &$__apiLogCtx) {
        $lat = (int) ((microtime(true) - $__apiLogStart) * 1000);
        $status = http_response_code() ?: 200;
        $u = \holastack\Auth\Auth::currentUser();
        \holastack\Storage\ApiLog::record([
            'created_at' => time(),
            'method' => $__apiLogCtx['method'],
            'path' => $__apiLogCtx['path'],
            'status' => $status,
            'latency_ms' => $lat,
            'ip' => $__apiLogCtx['ip'],
            'user_id' => $u['id'] ?? 0,
            'username' => $u['username'] ?? '',
            'role' => $u['role'] ?? '',
            'tenant_id' => (int) ($u['tenant_id'] ?? 0),
            'application_id' => (int) ($__apiLogCtx['application_id'] ?? 0),
            'query' => $__apiLogCtx['query'],
            'body_size' => $__apiLogCtx['body_size'],
        ]);
    });
}

if (!ELW_INSTALLED) {
    if ($path === '/install') {
        Installer::handle();
        exit;
    }
    if (strpos($path, '/api/') === 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(['error' => 'not_installed']);
        exit;
    }
    header('Location: /install');
    exit;
}
if ($path === '/install') {
    header('Location: /login');
    exit;
}

Database::migrate();

const CS_ZERO_TS = '1970-01-01T00:00:00Z';

if ($path === '/v1' || strpos($path, '/v1/') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(handleAppApi($method, $path), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error: ' . $e->getMessage()]);
    }
    exit;
}

if (strpos($path, '/api/view/') === 0) {
    $viewName = substr($path, strlen('/api/view/'));
    header('Content-Type: text/html; charset=utf-8');
    try {
        Auth::guardApi(Auth::ROLE_OPERATOR);
        if ($viewName === 'loracalc') {
            echo holastack\Web\ViewRenderer::renderLoraCalc();
        } elseif ($viewName === 'apidocs') {
            echo holastack\Web\ViewRenderer::renderApiDocs();
        } else {
            http_response_code(404);
            echo 'not found';
        }
    } catch (\Throwable $e) {
        http_response_code(403);
        echo 'unauthorized';
    }
    exit;
}

if ($path === '/api' || strpos($path, '/api/') === 0) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        echo json_encode(handleApi($method, $path), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(cs_err(13, 'internal', $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo renderPage();

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (is_array($dec)) {
        return $dec;
    }
    parse_str($raw, $out);
    return $out;
}

function cs_intToUuid(int $id): string
{
    $hex = str_pad(dechex(max(0, $id)), 12, '0', STR_PAD_LEFT);
    return '00000000-0000-0000-0000-' . $hex;
}

function cs_uuidToInt(string $uuid): int
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return 0;
    }
    if (ctype_digit($uuid)) {
        return (int) $uuid;
    }
    $hex = preg_replace('/[^0-9a-fA-F]/', '', $uuid);
    if ($hex === '' || strlen($hex) < 12) {
        return 0;
    }
    return (int) hexdec(substr($hex, -12));
}

function cs_ts($unix): string
{
    $unix = (int) $unix;
    return $unix > 0 ? gmdate('Y-m-d\TH:i:s\Z', $unix) : CS_ZERO_TS;
}

function cs_err(int $code, string $error, string $message): array
{

    return ['error' => $error, 'code' => $code, 'message' => $message, 'details' => []];
}

function cs_notFound(string $what = 'object does not exist'): array
{
    http_response_code(404);
    return cs_err(5, 'not_found', $what);
}

function cs_invalid(string $msg): array
{
    http_response_code(400);
    return cs_err(3, 'invalid_argument', $msg);
}

function cs_forbidden(string $msg = 'permission denied'): array
{
    http_response_code(403);
    return cs_err(7, 'permission_denied', $msg);
}

function cs_unimplemented(string $what = 'Method is not implemented.'): array
{
    http_response_code(501);
    return cs_err(12, 'unimplemented', $what);
}

function cs_list(array $result, int $totalCount): array
{
    return ['totalCount' => $totalCount, 'result' => $result];
}

function cs_obj(): \stdClass
{
    return new \stdClass();
}

function cs_rowBase(array $row): array
{
    $t = (int) ($row['created_at'] ?? 0);
    return [
        'id'        => cs_intToUuid((int) $row['id']),
        'createdAt' => cs_ts($t),
        'updatedAt' => cs_ts($t),
    ];
}

function cs_wrapError(array $r): ?array
{
    if (!isset($r['error'])) {
        return null;
    }
    $msg = (string) $r['error'];
    if (stripos($msg, 'not found') !== false || stripos($msg, '不存在') !== false) {
        return cs_notFound($msg);
    }
    if (stripos($msg, 'forbidden') !== false || stripos($msg, '越权') !== false) {
        return cs_forbidden($msg);
    }
    if (stripos($msg, '已存在') !== false || stripos($msg, 'already exists') !== false) {
        http_response_code(409);
        return cs_err(6, 'already_exists', $msg);
    }
    return cs_invalid($msg);
}

function cs_resolveDeviceSeg(string $seg): array
{
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $seg));

    if (strlen($hex) === 16 && strpos($seg, '-') === false) {
        $dev = Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [$hex]);
        if ($dev) {
            return ['devEui' => $hex, 'id' => (int) $dev['id']];
        }

        $id = cs_uuidToInt($seg);
        if ($id > 0 && Database::fetch("SELECT id FROM devices WHERE id=?", [$id])) {
            return ['devEui' => '', 'id' => $id];
        }
        return ['devEui' => $hex, 'id' => 0];
    }
    if (strpos($seg, '-') !== false) {
        return ['devEui' => '', 'id' => cs_uuidToInt($seg)];
    }
    return ['devEui' => '', 'id' => (int) $seg];
}

function cs_devEuiToId(string $devEui): int
{
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $devEui));
    if ($hex === '') {
        return 0;
    }
    $dev = Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [$hex]);
    return $dev ? (int) $dev['id'] : 0;
}

function cs_appIntegrationEndpoint(string $method, int $appId, array $segs, array $body): array
{

    $officialKinds = ['HTTP', 'INFLUX_DB', 'THINGS_BOARD', 'MY_DEVICES', 'GCP_PUB_SUB', 'AWS_SNS', 'AZURE_SERVICE_BUS', 'PILOT_THINGS', 'MQTT_GLOBAL', 'IFTTT', 'BLYNK'];
    $pathKind = strtoupper(str_replace('-', '_', (string) ($segs[0] ?? '')));

    if ($pathKind === '' && $method === 'GET') {
        $rows = Database::fetchAll("SELECT * FROM integrations WHERE application_id=? ORDER BY id", [$appId]);
        $result = array_map(static fn($i) => ['kind' => strtoupper((string) $i['kind'])], $rows);
        return cs_list($result, count($result));
    }

    if ($pathKind === 'MQTT' && ($segs[1] ?? '') === 'certificate' && $method === 'POST') {
        return ['caCert' => '', 'tlsCert' => '', 'tlsKey' => '', 'expiresAt' => CS_ZERO_TS];
    }

    if (!in_array($pathKind, $officialKinds, true)) {
        return cs_unimplemented('unknown integration kind: ' . $pathKind);
    }

    $existing = Database::fetch("SELECT * FROM integrations WHERE application_id=? AND kind=?", [$appId, $pathKind]);

    if ($method === 'GET') {
        if (!$existing) {
            return cs_notFound('integration does not exist');
        }
        $cfg = json_decode((string) $existing['config_json'], true) ?: [];
        $int = array_merge(['applicationId' => cs_intToUuid($appId)], $cfg);
        return ['integration' => $int];
    }
    if ($method === 'POST' || $method === 'PUT') {
        $in = $body['integration'] ?? $body;
        $cfg = $in;
        unset($cfg['applicationId']);
        if ($existing) {
            Database::execute(
                "UPDATE integrations SET config_json=?, enabled=1 WHERE id=?",
                [json_encode($cfg, JSON_UNESCAPED_UNICODE), (int) $existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO integrations (application_id, tenant_id, kind, enabled, config_json, created_at) VALUES (?,?,?,?,?,?)",
                [$appId, 0, $pathKind, 1, json_encode($cfg, JSON_UNESCAPED_UNICODE), time()]
            );
        }
        return [];
    }
    if ($method === 'DELETE') {
        if ($existing) {
            Database::execute("DELETE FROM integrations WHERE id=?", [(int) $existing['id']]);
            return [];
        }
        return cs_notFound('integration does not exist');
    }
    return cs_invalid('method not allowed');
}

function handleApi(string $method, string $path): array|\stdClass
{
    $segs = explode('/', trim($path, '/'));
    array_shift($segs);

    $resource = $segs[0] ?? '';
    $body = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) ? getJsonBody() : [];
    $get = $_GET;

    $limitOf  = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 50);
        return max(1, min($n, 500));
    };
    $offsetOf = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 0);
        return max(0, $n);
    };

    $camelParam = static function (array $get, string $camel, string $snake) {
        return $get[$camel] ?? $get[$snake] ?? null;
    };
    $uuidOf = static function ($v): int {
        return $v !== null ? cs_uuidToInt((string) $v) : 0;
    };

    if ($resource === 'login') {
        if ($method !== 'POST') {
            return ['error' => 'method not allowed'];
        }
        $u = Auth::authenticate($body['username'] ?? '', $body['password'] ?? '');
        if (!$u) {
            http_response_code(401);
            return ['error' => 'invalid credentials'];
        }
        $token = Auth::issueToken($u);
        $u['permissions'] = Auth::permissionsFor($u);
        return ['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'email' => $u['email'] ?? '', 'avatar_url' => WebApp::avatarUrl($u['email'] ?? ''), 'role' => $u['role'], 'role_id' => (int) ($u['role_id'] ?? 0), 'permissions' => $u['permissions']], 'token' => $token];
    }
    if ($resource === 'logout') {
        Auth::logout(Auth::tokenFromRequest());
        return ['ok' => true];
    }
    if ($resource === 'me') {
        $u = Auth::currentUser();
        if (!$u) {
            http_response_code(401);
            return ['error' => 'unauthorized'];
        }
        $u['avatar_url'] = WebApp::avatarUrl($u['email'] ?? '');
        $u['permissions'] = Auth::permissionsFor($u);
        return ['user' => $u];
    }

    if ($resource === 'public-settings') {
        return ['data' => Setting::getPublic()];
    }

    if ($resource === 'i18n') {
        $lang = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($get['lang'] ?? ''));
        $lang = $lang !== '' ? $lang : 'zh';
        $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
        $dict = is_file($file) ? require $file : [];
        if (!is_array($dict)) {
            $dict = [];
        }
        return ['lang' => $lang, 'dict' => $dict, 'langs' => ELW_langOptions()];
    }
    if ($resource === 'regions') {
        return ['regions' => WebApp::regions()];
    }

    $isWrite = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $isDownlink = ($resource === 'devices' && in_array($segs[2] ?? '', ['downlink', 'queue'], true));
    $isPwChange = ($resource === 'users' && in_array($segs[1] ?? '', ['password'], true) || ($resource === 'users' && ($segs[2] ?? '') === 'password'));
    $isMulticastEnqueue = ($resource === 'multicast-groups' && in_array($segs[2] ?? '', ['enqueue', 'queue'], true));
    $isDemoClearLogs = ($resource === 'settings' && !empty($body['clear_logs']) && (WebApp::scopePublic())['demo']);
    $adminOnlyResource = in_array($resource, ['users', 'tenants', 'settings'], true);

    if ($isDemoClearLogs) {
        return [];
    } elseif ($isWrite && $adminOnlyResource && !$isPwChange) {
        Auth::guardApi(Auth::ROLE_ADMIN);
    } elseif ($isWrite && !$isDownlink && !$isPwChange && !$isMulticastEnqueue) {
        Auth::guardWrite();
    } else {
        Auth::guardApi(Auth::ROLE_OPERATOR);
    }

    if (!$isWrite && $resource === 'tenants') {
        Auth::guardApi(Auth::ROLE_ADMIN);
    }

    $applicationRow = static function (array $a, bool $listItem) use ($camelParam, $get): array {
        $row = array_merge(cs_rowBase($a), [
            'name'        => $a['name'] ?? '',
            'description' => $a['description'] ?? '',
            'tenantId'    => cs_intToUuid((int) ($a['tenant_id'] ?? 0)),
            'tags'        => cs_obj(),
        ]);

        $row['appEui'] = $a['app_eui'] ?? '';
        $row['callbackUrl'] = $a['callback_url'] ?? '';

        $row['numericId'] = (int) ($a['id'] ?? 0);
        return $row;
    };

    $deviceProfileName = static function (int $pid): string {
        if ($pid <= 0) return '';
        $r = \holastack\DB\Database::fetch("SELECT name FROM device_profiles WHERE id=?", [$pid]);
        return $r ? $r['name'] : '';
    };
    $deviceStatusOf = static function (array $d) {
        $lastSeen = (int) ($d['last_seen'] ?? 0);
        if ($lastSeen <= 0) {
            return cs_obj();
        }
        return [
            'batteryLevel'        => 255,
            'externalPowerSource' => false,
            'margin'              => 0,
        ];
    };
    $deviceRow = static function (array $d, bool $listItem) use ($deviceStatusOf, $deviceProfileName): array {
        if ($listItem) {
            $row = [
                'devEui'            => $d['dev_eui'] ?? '',
                'name'              => $d['name'] ?? '',
                'description'       => '',
                'deviceProfileId'   => cs_intToUuid((int) ($d['device_profile_id'] ?? 0)),
                'deviceProfileName' => $deviceProfileName((int) ($d['device_profile_id'] ?? 0)),
                'deviceStatus'      => $deviceStatusOf($d),
                'lastSeenAt'        => cs_ts($d['last_seen'] ?? 0),
                'tags'              => cs_obj(),
            ];

            $row['id'] = cs_intToUuid((int) $d['id']);
            $row['numericId'] = (int) $d['id'];
            $row['applicationId'] = cs_intToUuid((int) ($d['app_id'] ?? 0));
            $row['devAddr'] = $d['dev_addr'] ?? '';
            $row['activation'] = $d['activation'] ?? '';
            $row['classEnabled'] = strtoupper($d['class'] ?? 'A') === 'B' ? 'CLASS_B' : (strtoupper($d['class'] ?? 'A') === 'C' ? 'CLASS_C' : 'CLASS_A');
            $row['region'] = $d['region'] ?? '';
            $row['isDisabled'] = ($d['status'] ?? '') === 'disabled';
            $row['online'] = ($d['online'] ?? '') === 'online' ? 'ONLINE' : 'OFFLINE';
            $row['latitude'] = (float) ($d['latitude'] ?? 0);
            $row['longitude'] = (float) ($d['longitude'] ?? 0);
            return $row;
        }
        $cls = strtoupper($d['class'] ?? 'A');
        return array_merge(cs_rowBase($d), [
            'devEui'          => $d['dev_eui'] ?? '',
            'name'            => $d['name'] ?? '',
            'applicationId'   => cs_intToUuid((int) ($d['app_id'] ?? 0)),
            'description'     => '',
            'deviceProfileId' => cs_intToUuid((int) ($d['device_profile_id'] ?? 0)),
            'isDisabled'      => ($d['status'] ?? '') === 'disabled',
            'skipFcntCheck'   => false,
            'joinEui'         => $d['join_eui'] ?? '',
            'tags'            => cs_obj(),
            'variables'       => cs_obj(),
            'lastSeenAt'      => cs_ts($d['last_seen'] ?? 0),
            'classEnabled'    => $cls === 'B' ? 'CLASS_B' : ($cls === 'C' ? 'CLASS_C' : 'CLASS_A'),
            'deviceStatus'    => $deviceStatusOf($d),

            'numericId'       => (int) $d['id'],
            'devAddr'         => $d['dev_addr'] ?? '',
            'activation'      => $d['activation'] ?? '',
            'region'          => $d['region'] ?? '',
            'online'          => ($d['online'] ?? '') === 'online' ? 'ONLINE' : 'OFFLINE',
            'latitude'        => (float) ($d['latitude'] ?? 0),
            'longitude'       => (float) ($d['longitude'] ?? 0),
            'codec'           => $d['codec'] ?? '',
            'nwkSKey'         => $d['nwk_s_key'] ?? '',
            'appSKey'         => $d['app_s_key'] ?? '',
            'nwkKey'          => $d['nwk_key'] ?? '',
            'appKey'          => $d['app_key'] ?? '',
        ]);
    };

    $gatewayStateOf = static function (int $lastSeen): string {
        if ($lastSeen <= 0) return 'NEVER_SEEN';
        return $lastSeen >= time() - \holastack\Web\WebApp::GW_OFFLINE_TIMEOUT ? 'ONLINE' : 'OFFLINE';
    };
    $gatewayRow = static function (array $g, bool $listItem) use ($gatewayStateOf): array {
        $lastSeen = (int) ($g['last_seen'] ?? 0);
        $base = [
            'gatewayId'   => $g['gw_id'] ?? '',
            'name'        => $g['name'] ?? '',
            'description' => '',
            'tenantId'    => cs_intToUuid((int) ($g['tenant_id'] ?? 0)),
            'state'       => $gatewayStateOf($lastSeen),
            'lastSeenAt'  => cs_ts($lastSeen),
            'location'    => ((float) ($g['latitude'] ?? 0) !== 0.0 || (float) ($g['longitude'] ?? 0) !== 0.0)
                ? (object) ['latitude' => (float) ($g['latitude'] ?? 0), 'longitude' => (float) ($g['longitude'] ?? 0)]
                : cs_obj(),
            'properties'  => cs_obj(),
            'tags'        => cs_obj(),
            'latitude'    => (float) ($g['latitude'] ?? 0),
            'longitude'   => (float) ($g['longitude'] ?? 0),
        ];
        if ($listItem) {
            $base['createdAt'] = cs_ts($g['created_at'] ?? 0);
            $base['updatedAt'] = cs_ts($g['created_at'] ?? 0);
            $base['downlinkPriority'] = 0;
        } else {
            $base['metadata'] = cs_obj();
            $base['statsInterval'] = 30;
            $base['downlinkPriority'] = 0;
            $base['createdAt'] = cs_ts($g['created_at'] ?? 0);
            $base['updatedAt'] = cs_ts($g['created_at'] ?? 0);
        }

        $base['region'] = $g['region'] ?? '';
        $base['status'] = ($gatewayStateOf($lastSeen) === 'ONLINE') ? 'online' : 'offline';
        $base['uplinks'] = (int) ($g['uplinks'] ?? 0);
        $base['lastSeenFmt'] = $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : '-';
        $base['rfConfig'] = (isset($g['rf_config']) && $g['rf_config'] !== '') ? json_decode($g['rf_config'], true) : null;
        return $base;
    };

    switch ($resource) {
        case 'stats':
            return WebApp::getStats();
        case 'regions':
            return ['regions' => WebApp::regions()];
        case 'settings':
            if ($method === 'POST' && !empty($body['clear_logs'])) {
                Auth::guardApi(Auth::ROLE_TENANT);
                $tid = isset($body['clear_logs_tenant']) ? (int) $body['clear_logs_tenant'] : null;
                $r = WebApp::clearLogs((string) $body['clear_logs'], $tid);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            Auth::guardApi(Auth::ROLE_ADMIN);
            if ($method === 'POST') {
                Setting::setMany($body);
                return ['data' => Setting::getAll()];
            }
            return ['data' => Setting::getAll()];
        case 'applications':
            if (isset($segs[1]) && isset($segs[2]) && $segs[2] !== '') {

                $appId2 = cs_uuidToInt((string) $segs[1]);
                $sub2 = (string) $segs[2];
                if ($sub2 === 'device-tags' && $method === 'GET') {
                    if (!WebApp::appInScope($appId2)) {
                        return cs_notFound('application not found');
                    }
                    $tagRows = Database::fetchAll(
                        "SELECT latest_fields FROM devices WHERE app_id=? AND latest_fields<>''",
                        [$appId2]
                    );
                    $tags = [];
                    foreach ($tagRows as $d) {
                        $fields = json_decode((string) $d['latest_fields'], true) ?: [];
                        foreach (array_keys($fields) as $k) {
                            $tags[$k] = true;
                        }
                    }
                    $result = [];
                    foreach (array_keys($tags) as $k) {
                        $result[] = ['key' => $k, 'values' => []];
                    }
                    return ['result' => $result];
                }
                if ($sub2 === 'device-profiles' && $method === 'GET') {

                    $dpRows2 = WebApp::listDeviceProfiles(null);
                    $profRows = [];
                    foreach ($dpRows2 as $p) {
                        $rev2 = preg_replace('/^RP00[12][-._]/', '', $p['reg_params_revision'] ?? 'RP002-1.0.3');
                        $macFl2 = ['1.0.0' => 'LORAWAN_1_0_0', '1.0.1' => 'LORAWAN_1_0_1', '1.0.2' => 'LORAWAN_1_0_2', '1.0.3' => 'LORAWAN_1_0_3', '1.0.4' => 'LORAWAN_1_0_4', '1.1.0' => 'LORAWAN_1_1_0'];
                        $profRows[] = array_merge(cs_rowBase($p), [
                            'name'              => $p['name'] ?? '',
                            'description'       => $p['description'] ?? '',
                            'region'            => strtoupper($p['region'] ?? 'EU868'),
                            'macVersion'        => $macFl2[$p['mac_version'] ?? '1.0.4'] ?? 'LORAWAN_1_0_4',
                            'regParamsRevision' => 'RP002_' . str_replace(['.', '-'], '_', $rev2),
                            'supportsOtaa'      => (bool) ($p['supports_otaa'] ?? 1),
                            'supportsClassB'    => (bool) ($p['supports_class_b'] ?? 0),
                            'supportsClassC'    => (bool) ($p['supports_class_c'] ?? 0),
                            'tags'              => cs_obj(),
                            'numericId'         => (int) $p['id'],
                        ]);
                    }
                    $off2 = $offsetOf('offset');
                    $lim2 = $limitOf('limit');
                    return cs_list(array_slice($profRows, $off2, $lim2), count($profRows));
                }
                if ($sub2 === 'integrations') {
                    return cs_appIntegrationEndpoint($method, $appId2, array_values(array_slice($segs, 3)), $body);
                }
            }
            if (isset($segs[1]) && $method === 'GET') {

                $a = WebApp::getApplication(cs_uuidToInt((string) $segs[1]));
                if (!$a) {
                    return cs_notFound('application not found');
                }
                return ['application' => $applicationRow($a, false)];
            }
            if (isset($segs[1]) && $method === 'PUT') {
                $id = cs_uuidToInt((string) $segs[1]);

                $old = \holastack\DB\Database::fetch("SELECT * FROM applications WHERE id=?", [$id]);
                if (!$old) { return cs_notFound('application not found'); }
                $in = $body['application'] ?? $body;
                $in['name'] = $in['name'] ?? $old['name'];
                $in['description'] = array_key_exists('description', $in) ? $in['description'] : ($old['description'] ?? '');
                $in['app_eui'] = $in['app_eui'] ?? $old['app_eui'];
                $in['callback_url'] = array_key_exists('callbackUrl', $in) ? $in['callbackUrl'] : ($in['callback_url'] ?? $old['callback_url']);
                $r = WebApp::updateApplication($id, $in);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteApplication(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                $in = $body['application'] ?? $body;
                if (isset($in['tenantId']) && !isset($in['tenant_id'])) { $in['tenant_id'] = cs_uuidToInt((string) $in['tenantId']); }
                $r = WebApp::createApplication($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listApplications($tid !== null ? (int) $tid : null);
            if (($get['applicationId'] ?? $get['app_id'] ?? '') !== '') {
                $rows = array_values(array_filter($rows, fn($a) => (int) $a['id'] === (int) ($get['applicationId'] ?? $get['app_id'])));
            }

            $search = trim((string) ($get['search'] ?? ''));
            if ($search !== '') {
                $rows = array_values(array_filter($rows, fn($a) => stripos((string) ($a['name'] ?? ''), $search) !== false));
            }
            $total = count($rows);
            if ((int) ($get['limit'] ?? 50) === 0) {
                return cs_list([], $total);
            }
            $result = array_slice(array_values($rows), $offsetOf('offset'), $limitOf('limit'));
            return cs_list(array_map(fn($a) => $applicationRow($a, true), $result), $total);
        case 'devices':
            if (($segs[1] ?? '') === 'import' && $method === 'POST') {
                $r = WebApp::importDevices((int) ($body['app_id'] ?? ($body['applicationId'] ? cs_uuidToInt((string) $body['applicationId']) : 0)), $body['raw'] ?? '', $body['format'] ?? 'csv');
                if ($e = cs_wrapError($r)) { return $e; }
                return $r;
            }
            if (isset($segs[1]) && $segs[1] !== '' && ($segs[2] ?? '') !== '') {

                $res = cs_resolveDeviceSeg((string) $segs[1]);
                $devId = (int) $res['id'];
                $sub = (string) $segs[2];

                if ($devId <= 0 || !WebApp::getDevice($devId)) {
                    return cs_notFound('device not found');
                }

                if ($sub === 'downlink' && $method === 'POST') {

                    $in = $body['queueItem'] ?? $body['deviceQueueItem'] ?? $body;
                    $payload = (string) ($in['data'] ?? '');
                    if ($payload !== '' && !ctype_xdigit($payload)) {
                        $bin = base64_decode($payload, true);
                        if ($bin === false) {
                            return cs_invalid('data must be Base64 or hex');
                        }
                        $payload = bin2hex($bin);
                    }
                    $r = WebApp::enqueueDownlink(
                        $devId,
                        (int) ($in['fPort'] ?? $in['port'] ?? 0),
                        $payload !== '' ? $payload : (string) ($in['payload'] ?? ''),
                        !empty($in['confirmed']),
                        !empty($in['mac'])
                    );
                    if ($e = cs_wrapError($r)) { return $e; }
                    return ['id' => (string) $r['id']];
                }
                if ($sub === 'fields' && $method === 'GET') {
                    return WebApp::deviceFields($devId);
                }
                if ($sub === 'queue') {
                    $qId = $segs[3] ?? null;
                    if ($qId !== null && $method === 'DELETE') {

                        $qid = cs_uuidToInt((string) $qId);
                        $q = Database::fetch("SELECT id, dev_id, status FROM downlinks WHERE id=?", [$qid]);
                        if (!$q || (int) $q['dev_id'] !== $devId) {
                            return cs_notFound('queue item not found');
                        }
                        if ($q['status'] !== 'pending') {
                            http_response_code(409);
                            return cs_err(9, 'failed_precondition', 'queue item is not pending');
                        }
                        Database::execute("UPDATE downlinks SET status='canceled' WHERE id=?", [$qid]);
                        return [];
                    }
                    if ($devId <= 0) {
                        return cs_notFound('device not found');
                    }
                    if ($method === 'POST') {

                        if (!empty($body['flushQueue'])) {
                            Database::execute("UPDATE downlinks SET status='canceled' WHERE dev_id=? AND status='pending'", [$devId]);
                        }
                        $in = $body['queueItem'] ?? $body['deviceQueueItem'] ?? [];
                        $payload = (string) ($in['data'] ?? '');
                        if ($payload !== '' && !ctype_xdigit($payload)) {
                            $bin = base64_decode($payload, true);
                            if ($bin === false) {
                                return cs_invalid('data must be Base64 encoded');
                            }
                            $payload = bin2hex($bin);
                        }
                        $r = WebApp::enqueueDownlink($devId, (int) ($in['fPort'] ?? 0), $payload, !empty($in['confirmed']));
                        if ($e = cs_wrapError($r)) { return $e; }
                        return ['id' => cs_intToUuid((int) $r['id'])];
                    }
                    if ($method === 'GET') {

                        $rows = Database::fetchAll(
                            "SELECT d.*, v.dev_eui FROM downlinks d JOIN devices v ON v.id=d.dev_id WHERE d.dev_id=? AND d.status='pending' ORDER BY d.id ASC",
                            [$devId]
                        );
                        $items = array_map(static function ($q) {
                            return [
                                'id'          => cs_intToUuid((int) $q['id']),
                                'devEui'      => $q['dev_eui'] ?? '',
                                'fPort'       => (int) $q['port'],
                                'confirmed'   => (bool) $q['confirmed'],
                                'isPending'   => true,
                                'isEncrypted' => false,
                                'fCntDown'    => (int) ($q['fcnt'] ?? 0),
                                'data'        => base64_encode(hex2bin((string) $q['payload_hex']) ?: ''),
                                'expiresAt'   => CS_ZERO_TS,
                            ];
                        }, $rows);
                        return cs_list($items, count($items));
                    }
                    if ($method === 'DELETE') {

                        Database::execute("UPDATE downlinks SET status='canceled' WHERE dev_id=? AND status='pending'", [$devId]);
                        return [];
                    }
                }
                if ($sub === 'activate' && $method === 'POST') {

                    $dev = Database::fetch("SELECT activation FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    $act = $body['deviceActivation'] ?? $body;
                    $hex = static fn($v) => strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $v));
                    $addr = $hex($act['devAddr'] ?? '');
                    $nwk = $hex($act['fNwkSIntKey'] ?? $act['nwkSEncKey'] ?? '');
                    $app = $hex($act['appSKey'] ?? '');
                    if (strlen($addr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                        return cs_invalid('deviceActivation requires devAddr(8 hex), fNwkSIntKey(32 hex), appSKey(32 hex)');
                    }
                    Database::execute(
                        "UPDATE devices SET activation='ABP', dev_addr=?, nwk_s_key=?, app_s_key=?, fcnt_up=? WHERE id=?",
                        [$addr, $nwk, $app, (int) ($act['fCntUp'] ?? 0), $devId]
                    );
                    return [];
                }
                if ($sub === 'activation') {
                    $dev = Database::fetch("SELECT * FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    if ($method === 'GET') {

                        return ['deviceActivation' => [
                            'devEui'      => $dev['dev_eui'],
                            'devAddr'     => $dev['dev_addr'] ?? '',
                            'appSKey'     => $dev['app_s_key'] ?? '',
                            'fNwkSIntKey' => ($dev['f_nwk_s_int_key'] ?? '') ?: ($dev['nwk_s_key'] ?? ''),
                            'sNwkSIntKey' => $dev['s_nwk_s_int_key'] ?? '',
                            'nwkSEncKey'  => ($dev['nwk_s_enc_key'] ?? '') ?: ($dev['nwk_s_key'] ?? ''),
                            'fCntUp'      => (int) $dev['fcnt_up'],
                            'nFCntDown'   => (int) $dev['fcnt_down'],
                            'aFCntDown'   => (int) $dev['fcnt_down'],
                        ]];
                    }
                    if ($method === 'DELETE') {

                        Database::execute(
                            "UPDATE devices SET dev_addr='', nwk_s_key='', app_s_key='', f_nwk_s_int_key='', s_nwk_s_int_key='', nwk_s_enc_key='', fcnt_up=0, fcnt_down=0, last_seen=0, status='pending' WHERE id=?",
                            [$devId]
                        );
                        return [];
                    }
                }
                if ($sub === 'keys') {
                    $dev = Database::fetch("SELECT * FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    if ($method === 'GET') {

                        return ['deviceKeys' => [
                            'devEui'    => $dev['dev_eui'],
                            'appKey'    => $dev['app_key'] ?? '',
                            'nwkKey'    => $dev['nwk_key'] ?? '',
                            'genAppKey' => '',
                        ]];
                    }
                    if ($method === 'POST' || $method === 'PUT') {
                        $k = $body['deviceKeys'] ?? $body;
                        $hex = static fn($v) => strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $v));
                        $sets = [];
                        $params = [];
                        foreach ([['appKey', 'app_key'], ['nwkKey', 'nwk_key']] as [$csK, $col]) {
                            if (isset($k[$csK]) && $k[$csK] !== '') {
                                $v = $hex($k[$csK]);
                                if (strlen($v) !== 32) {
                                    return cs_invalid($csK . ' must be 128-bit HEX encoded');
                                }
                                $sets[] = "$col=?";
                                $params[] = $v;
                            }
                        }
                        if ($sets) {
                            $params[] = $devId;
                            Database::execute("UPDATE devices SET " . implode(', ', $sets) . " WHERE id=?", $params);
                        }
                        return [];
                    }
                    if ($method === 'DELETE') {
                        Database::execute("UPDATE devices SET app_key='', nwk_key='' WHERE id=?", [$devId]);
                        return [];
                    }
                }
                if ($sub === 'get-next-f-cnt-down' && $method === 'POST') {

                    $dev = Database::fetch("SELECT fcnt_down FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    $next = (int) $dev['fcnt_down'] + 1;
                    Database::execute("UPDATE devices SET fcnt_down=? WHERE id=?", [$next, $devId]);
                    return ['fCntDown' => $next];
                }
                if ($sub === 'get-random-dev-addr' && $method === 'POST') {

                    $addr = str_pad(dechex(random_int(0, 0x7fffffff)), 8, '0', STR_PAD_LEFT);
                    return ['devAddr' => $addr];
                }

                if (in_array($sub, ['get-next-f-cnt-down', 'get-random-dev-addr'], true)) {
                    return cs_invalid('method not allowed');
                }
                if ($sub === 'dev-nonces' && $method === 'DELETE') {

                    return [];
                }
                if ($sub === 'metrics' && $method === 'GET') {

                    $dev = Database::fetch("SELECT id FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    $rows = Database::fetchAll(
                        "SELECT received_at FROM uplinks WHERE dev_id=? AND received_at>=? ORDER BY received_at ASC",
                        [$devId, time() - 86400]
                    );
                    $buckets = [];
                    foreach ($rows as $u) {
                        $b = gmdate('Y-m-d\TH:00:00\Z', (int) $u['received_at']);
                        $buckets[$b] = ($buckets[$b] ?? 0) + 1;
                    }
                    return [
                        'rxPackets' => [
                            'name'       => 'RX packets',
                            'kind'       => 'COUNTER',
                            'timestamps' => array_keys($buckets),
                            'datasets'   => [['label' => 'RX packets', 'data' => array_values($buckets)]],
                        ],
                        'states'  => cs_obj(),
                        'metrics' => cs_obj(),
                    ];
                }
                if ($sub === 'link-metrics' && $method === 'GET') {

                    $dev = Database::fetch("SELECT id FROM devices WHERE id=?", [$devId]);
                    if (!$dev) {
                        return cs_notFound('device not found');
                    }
                    $rows = Database::fetchAll(
                        "SELECT received_at, rssi, snr FROM uplinks WHERE dev_id=? AND received_at>=? ORDER BY received_at ASC",
                        [$devId, time() - 86400]
                    );
                    $bRssi = [];
                    $bSnr = [];
                    foreach ($rows as $u) {
                        $b = gmdate('Y-m-d\TH:00:00\Z', (int) $u['received_at']);
                        $bRssi[$b][] = (int) $u['rssi'];
                        $bSnr[$b][] = (float) $u['snr'];
                    }
                    $avg = static function (array $b) {
                        return array_map(static fn($arr) => round(array_sum($arr) / max(1, count($arr)), 1), array_values($b));
                    };
                    return [
                        'rxPackets' => ['name' => 'RX packets', 'kind' => 'COUNTER', 'timestamps' => array_keys($bRssi), 'datasets' => [['label' => 'RX packets', 'data' => array_map('count', array_values($bRssi))]]],
                        'gwRssi'    => ['name' => 'RSSI', 'kind' => 'GAUGE', 'timestamps' => array_keys($bRssi), 'datasets' => [['label' => 'RSSI', 'data' => $avg($bRssi)]]],
                        'gwSnr'     => ['name' => 'SNR', 'kind' => 'GAUGE', 'timestamps' => array_keys($bSnr), 'datasets' => [['label' => 'SNR', 'data' => $avg($bSnr)]]],
                        'errors'    => ['name' => 'Errors', 'kind' => 'COUNTER', 'timestamps' => [], 'datasets' => []],
                    ];
                }
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'downlink' && $method === 'POST') {

                $in = $body['queueItem'] ?? $body['deviceQueueItem'] ?? $body;
                $payload = (string) ($in['data'] ?? '');
                if ($payload !== '' && !ctype_xdigit($payload)) {
                    $bin = base64_decode($payload, true);
                    if ($bin === false) {
                        return cs_invalid('data must be Base64 or hex');
                    }
                    $payload = bin2hex($bin);
                }

                $devPathId = ctype_digit((string) $segs[1]) ? (int) $segs[1] : cs_uuidToInt((string) $segs[1]);
                $r = WebApp::enqueueDownlink(
                    $devPathId,
                    (int) ($in['fPort'] ?? $in['port'] ?? 0),
                    $payload !== '' ? $payload : (string) ($in['payload'] ?? ''),
                    !empty($in['confirmed']),
                    !empty($in['mac'])
                );
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => (string) $r['id']];
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'fields' && $method === 'GET') {
                return WebApp::deviceFields((int) $segs[1]);
            }
            if (isset($segs[1]) && $method === 'PUT') {

                $res = cs_resolveDeviceSeg((string) $segs[1]);
                if ($res['id'] <= 0) {
                    return cs_notFound('device not found');
                }
                $r = WebApp::updateDevice((int) $res['id'], $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $res = cs_resolveDeviceSeg((string) $segs[1]);
                if ($res['id'] <= 0) {
                    return cs_notFound('device not found');
                }
                $r = WebApp::deleteDevice((int) $res['id']);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'GET') {

                $res = cs_resolveDeviceSeg((string) $segs[1]);
                if ($res['id'] <= 0) {
                    return cs_notFound('device not found');
                }
                $d = WebApp::getDevice((int) $res['id']);
                if (!$d) {
                    return cs_notFound('device not found');
                }
                return ['device' => $deviceRow($d, false)];
            }
            if ($method === 'POST') {
                $in = $body['device'] ?? $body;

                foreach ([
                    'applicationId' => 'app_id',
                    'deviceProfileId' => 'device_profile_id',
                    'devEui' => 'dev_eui',
                    'joinEui' => 'join_eui',
                ] as $cs => $hs) {
                    if (isset($in[$cs]) && !isset($in[$hs])) {
                        $in[$hs] = ($cs === 'applicationId' || $cs === 'deviceProfileId') ? cs_uuidToInt((string) $in[$cs]) : $in[$cs];
                    }
                }

                if (isset($in['keys']) && is_array($in['keys'])) {
                    if (isset($in['keys']['appKey']) && !isset($in['app_key'])) { $in['app_key'] = $in['keys']['appKey']; }
                    if (isset($in['keys']['nwkKey']) && !isset($in['nwk_key'])) { $in['nwk_key'] = $in['keys']['nwkKey']; }
                }

                if (!isset($in['join_eui']) && empty($in['dev_addr'])) {
                    $in['join_eui'] = $in['joinEui'] ?? '0101010101010101';
                }
                $r = WebApp::createDevice($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return cs_obj();
            }
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : null);
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listDevices($appId, $tid);

            $search = trim((string) ($get['search'] ?? ''));
            if ($search !== '') {
                $rows = array_values(array_filter($rows, fn($d) => stripos((string) ($d['name'] ?? ''), $search) !== false || stripos((string) ($d['dev_eui'] ?? ''), $search) !== false));
            }
            if (isset($get['deviceProfileId'])) {
                $dpId = cs_uuidToInt((string) $get['deviceProfileId']);
                $rows = array_values(array_filter($rows, fn($d) => (int) ($d['device_profile_id'] ?? 0) === $dpId));
            }
            $total = count($rows);
            $lim = $limitOf('limit');
            $off = $offsetOf('offset');
            if ((int) ($get['limit'] ?? 50) === 0) {

                return cs_list([], $total);
            }
            $result = array_slice(array_values($rows), $off, $lim);
            return cs_list(array_map(fn($d) => $deviceRow($d, true), $result), $total);
        case 'gateways':
            if (isset($segs[1]) && isset($segs[2]) && $segs[2] !== '') {

                $gwSeg = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $segs[1]));
                $subGw = (string) $segs[2];
                if ($subGw === 'metrics' && $method === 'GET') {

                    if (!WebApp::getGateway($gwSeg)) {
                        return cs_notFound('gateway not found');
                    }
                    $rows = Database::fetchAll(
                        "SELECT received_at FROM uplinks WHERE gateway_id=? AND received_at>=? ORDER BY received_at ASC",
                        [$gwSeg, time() - 86400]
                    );
                    $rx = [];
                    foreach ($rows as $u) {
                        $b = gmdate('Y-m-d\TH:00:00\Z', (int) $u['received_at']);
                        $rx[$b] = ($rx[$b] ?? 0) + 1;
                    }
                    $mkMetric = static fn(array $b, string $n) => ['name' => $n, 'kind' => 'COUNTER', 'timestamps' => array_keys($b), 'datasets' => [['label' => $n, 'data' => array_values($b)]]];
                    return [
                        'rxPackets' => $mkMetric($rx, 'RX packets'),
                        'rxPacketsPerDr' => $mkMetric([], 'RX packets / DR'),
                        'rxPacketsPerFreq' => $mkMetric([], 'RX packets / frequency'),
                        'txPackets' => $mkMetric([], 'TX packets'),
                        'txPacketsPerDr' => $mkMetric([], 'TX packets / DR'),
                        'txPacketsPerFreq' => $mkMetric([], 'TX packets / frequency'),
                        'txPacketsPerStatus' => $mkMetric([], 'TX packets / status'),
                    ];
                }
                if ($subGw === 'duty-cycle-metrics' && $method === 'GET') {

                    return [
                        'maxLoadPercentage' => ['name' => 'Max load', 'kind' => 'GAUGE', 'timestamps' => [], 'datasets' => []],
                        'windowPercentage' => ['name' => 'Window', 'kind' => 'GAUGE', 'timestamps' => [], 'datasets' => []],
                    ];
                }
                if ($subGw === 'generate-certificate' && $method === 'POST') {

                    return ['caCert' => '', 'tlsCert' => '', 'tlsKey' => '', 'expiresAt' => CS_ZERO_TS];
                }
            }
            if (isset($segs[1]) && $segs[1] === 'relay-gateways') {

                if ($method === 'GET' && !isset($segs[2])) {
                    $sc = WebApp::scopePublic();
                    $rgRows = Database::fetchAll(
                        "SELECT * FROM relay_gateways" . ($sc['is_admin'] || $sc['demo'] ? '' : ' WHERE tenant_id=' . (int) $sc['tenant_id']) . " ORDER BY id DESC"
                    );
                    $result = array_map(static function ($rg) {
                        return [
                            'relayId'       => substr(md5((string) $rg['relay_dev_eui']), 0, 8),
                            'name'          => $rg['name'] ?? '',
                            'description'   => '',
                            'tenantId'      => cs_intToUuid((int) ($rg['tenant_id'] ?? 0)),
                            'regionConfigId' => strtoupper($rg['region'] ?? ''),
                            'state'         => 'NEVER_SEEN',
                            'lastSeenAt'    => CS_ZERO_TS,
                            'createdAt'     => cs_ts($rg['created_at'] ?? 0),
                            'updatedAt'     => cs_ts($rg['created_at'] ?? 0),
                        ];
                    }, $rgRows);
                    $off3 = $offsetOf('offset');
                    $lim3 = $limitOf('limit');
                    return cs_list(array_slice($result, $off3, $lim3), count($result));
                }
                if (isset($segs[3]) && $method === 'PUT') {
                    return cs_unimplemented('relay gateway update is not supported');
                }
                if (isset($segs[2]) && $method === 'DELETE') {
                    $relayId = strtolower((string) $segs[2]);
                    $sc = WebApp::scopePublic();
                    $rg = Database::fetch("SELECT * FROM relay_gateways WHERE substr(md5(relay_dev_eui),1,8)=? OR relay_dev_eui=?", [$relayId, $relayId]);
                    if (!$rg || !($sc['is_admin'] || $sc['demo'] || (int) ($rg['tenant_id'] ?? 0) === (int) $sc['tenant_id'])) {
                        return cs_notFound('relay gateway not found');
                    }
                    Database::execute("DELETE FROM relay_gateways WHERE substr(md5(relay_dev_eui),1,8)=? OR relay_dev_eui=?", [$relayId, $relayId]);
                    return [];
                }
            }
            if (isset($segs[1]) && $method === 'GET' && !isset($segs[2])) {

                $g = WebApp::getGateway(strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $segs[1])));
                if (!$g) {
                    return cs_notFound('gateway not found');
                }
                return ['gateway' => $gatewayRow($g, false)];
            }
            if (isset($segs[1]) && $method === 'PUT') {
                $r = WebApp::updateGateway(strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $segs[1])), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteGateway(strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $segs[1])));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                $in = $body['gateway'] ?? $body;
                if (isset($in['gatewayId']) && !isset($in['gw_id'])) { $in['gw_id'] = $in['gatewayId']; }
                if (isset($in['tenantId']) && !isset($in['tenant_id'])) { $in['tenant_id'] = cs_uuidToInt((string) $in['tenantId']); }
                $r = WebApp::createGateway($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return cs_obj();
            }
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listGateways($tid !== null ? (int) $tid : null);

            $search = trim((string) ($get['search'] ?? ''));
            if ($search !== '') {
                $rows = array_values(array_filter($rows, fn($g) => stripos((string) ($g['name'] ?? ''), $search) !== false || stripos((string) ($g['gw_id'] ?? ''), $search) !== false));
            }
            $total = count($rows);
            if ((int) ($get['limit'] ?? 50) === 0) {
                return cs_list([], $total);
            }
            $result = array_slice(array_values($rows), $offsetOf('offset'), $limitOf('limit'));
            return cs_list(array_map(fn($g) => $gatewayRow($g, true), $result), $total);
        case 'device-profiles':
            if (isset($segs[1]) && $segs[1] === 'adr-algorithms' && $method === 'GET') {
                $algos = [
                    ['id' => cs_intToUuid(1), 'name' => 'Default ADR algorithm (LoRaWAN MAC)'],
                    ['id' => cs_intToUuid(2), 'name' => 'Disable ADR'],
                ];
                return cs_list($algos, count($algos));
            }
            if (isset($segs[1]) && $segs[1] === 'vendors' && $method === 'GET') {

                return cs_list([], 0);
            }
            if (isset($segs[1]) && $segs[1] === 'devices' && $method === 'GET' && !isset($segs[2])) {

                return cs_list([], 0);
            }
            if (isset($segs[1]) && $segs[1] === 'devices' && isset($segs[2]) && $method === 'GET') {

                $dId = cs_uuidToInt((string) $segs[2]);
                $d = WebApp::getDevice($dId);
                if (!$d || (int) ($d['device_profile_id'] ?? 0) <= 0) {
                    return cs_notFound('device profile not found');
                }
                $dp2 = WebApp::getDeviceProfile((int) $d['device_profile_id']);
                if (!$dp2) {
                    return cs_notFound('device profile not found');
                }

                $segs[1] = (string) $dp2['id'];
                $segs[2] = null;
            }
            if (isset($segs[2]) && isset($segs[1]) && $method === 'GET' && !in_array($segs[1], ['adr-algorithms', 'vendors', 'devices'], true)) {

                return cs_notFound('device profile not found');
            }
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $in = $body['deviceProfile'] ?? $body;
                    foreach (['macVersion' => 'mac_version', 'regParamsRevision' => 'reg_params_revision', 'supportsOtaa' => 'supports_otaa', 'supportsClassB' => 'supports_class_b', 'supportsClassC' => 'supports_class_c', 'uplinkInterval' => 'uplink_interval', 'payloadCodecRuntime' => 'payload_codec_runtime', 'payloadCodecScript' => 'payload_codec_script'] as $cs => $hs) {
                        if (isset($in[$cs]) && !isset($in[$hs])) { $in[$hs] = $in[$cs]; }
                    }
                    if (isset($in['mac_version'])) {
                        $macMap = ['LORAWAN_1_0_0' => '1.0.0', 'LORAWAN_1_0_1' => '1.0.1', 'LORAWAN_1_0_2' => '1.0.2', 'LORAWAN_1_0_3' => '1.0.3', 'LORAWAN_1_0_4' => '1.0.4', 'LORAWAN_1_1_0' => '1.1.0'];
                        if (isset($macMap[$in['mac_version']])) { $in['mac_version'] = $macMap[$in['mac_version']]; }
                    }
                    if (isset($in['reg_params_revision'])) {
                        $in['reg_params_revision'] = preg_replace('/^RP00[12]_/', '', str_replace('_', '.', $in['reg_params_revision']));
                    }
                    $r = WebApp::updateDeviceProfile($id, $in);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteDeviceProfile($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                $dp = WebApp::getDeviceProfile($id);
                if (!$dp) {
                    return cs_notFound('device profile not found');
                }
                $rev = preg_replace('/^RP00[12][-._]/', '', $dp['reg_params_revision'] ?? 'RP002-1.0.3');
                $macMapFl = ['1.0.0' => 'LORAWAN_1_0_0', '1.0.1' => 'LORAWAN_1_0_1', '1.0.2' => 'LORAWAN_1_0_2', '1.0.3' => 'LORAWAN_1_0_3', '1.0.4' => 'LORAWAN_1_0_4', '1.1.0' => 'LORAWAN_1_1_0'];
                $row = array_merge(cs_rowBase($dp), [
                    'name'                   => $dp['name'] ?? '',
                    'description'            => $dp['description'] ?? '',
                    'region'                 => strtoupper($dp['region'] ?? 'EU868'),
                    'macVersion'             => $macMapFl[$dp['mac_version'] ?? '1.0.4'] ?? 'LORAWAN_1_0_4',
                    'regParamsRevision'      => 'RP002_' . str_replace(['.', '-'], '_', $rev),
                    'adrAlgorithmId'         => cs_intToUuid(1),
                    'payloadCodecRuntime'    => strtoupper($dp['payload_codec_runtime'] ?? 'NONE') === 'NONE' ? 'NONE' : 'JS',
                    'payloadCodecScript'     => $dp['payload_codec_script'] ?? '',
                    'flushQueueOnActivate'   => (bool) ($dp['flush_queue_on_activate'] ?? 0),
                    'uplinkInterval'         => (int) ($dp['uplink_interval'] ?? 0),
                    'deviceStatusReqInterval'=> (int) ($dp['device_status_req_interval'] ?? 0),
                    'supportsOtaa'           => (bool) ($dp['supports_otaa'] ?? 1),
                    'supportsClassB'         => (bool) ($dp['supports_class_b'] ?? 0),
                    'supportsClassC'         => (bool) ($dp['supports_class_c'] ?? 0),
                    'classBTimeout'          => (int) ($dp['class_b_timeout'] ?? 0),
                    'classBPingSlotPeriodicity' => (int) ($dp['class_b_ping_slot_periodicity'] ?? 0),
                    'classBPingSlotDr'       => (int) ($dp['class_b_ping_slot_dr'] ?? 0),
                    'classBPingSlotFreq'     => (int) ($dp['class_b_ping_slot_freq'] ?? 0),
                    'classCTimeout'          => (int) ($dp['class_c_timeout'] ?? 0),
                    'abpRx1Delay'            => (int) ($dp['abp_rx1_delay'] ?? 1),
                    'abpRx1DrOffset'         => (int) ($dp['abp_rx1_dr_offset'] ?? 0),
                    'abpRx2Dr'               => (int) ($dp['abp_rx2_dr'] ?? 0),
                    'abpRx2Freq'             => (int) ($dp['abp_rx2_freq'] ?? 0),
                    'allowRoaming'           => (bool) ($dp['allow_roaming'] ?? 0),
                    'tags'                   => cs_obj(),
                    'tenantId'               => cs_intToUuid((int) ($dp['tenant_id'] ?? 0)),

                    'numericId'              => (int) $dp['id'],
                ]);
                return ['deviceProfile' => $row];
            }
            if ($method === 'POST') {
                $in = $body['deviceProfile'] ?? $body;
                foreach (['macVersion' => 'mac_version', 'regParamsRevision' => 'reg_params_revision', 'supportsOtaa' => 'supports_otaa', 'supportsClassB' => 'supports_class_b', 'supportsClassC' => 'supports_class_c', 'uplinkInterval' => 'uplink_interval', 'payloadCodecRuntime' => 'payload_codec_runtime', 'payloadCodecScript' => 'payload_codec_script', 'tenantId' => 'tenant_id'] as $cs => $hs) {
                    if (isset($in[$cs]) && !isset($in[$hs])) { $in[$hs] = $in[$cs]; }
                }
                if (isset($in['mac_version'])) {
                    $macMap = ['LORAWAN_1_0_0' => '1.0.0', 'LORAWAN_1_0_1' => '1.0.1', 'LORAWAN_1_0_2' => '1.0.2', 'LORAWAN_1_0_3' => '1.0.3', 'LORAWAN_1_0_4' => '1.0.4', 'LORAWAN_1_1_0' => '1.1.0'];
                    if (isset($macMap[$in['mac_version']])) { $in['mac_version'] = $macMap[$in['mac_version']]; }
                }
                if (isset($in['reg_params_revision'])) {
                    $in['reg_params_revision'] = preg_replace('/^RP00[12]_/', '', str_replace('_', '.', $in['reg_params_revision']));
                }
                if (isset($in['tenant_id']) && !is_numeric($in['tenant_id'])) { $in['tenant_id'] = cs_uuidToInt((string) $in['tenant_id']); }
                $r = WebApp::createDeviceProfile($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $dpRows = WebApp::listDeviceProfiles(null);
            $dpList = [];
            foreach ($dpRows as $p) {
                $rev = preg_replace('/^RP00[12][-._]/', '', $p['reg_params_revision'] ?? 'RP002-1.0.3');
                $macMapFl = ['1.0.0' => 'LORAWAN_1_0_0', '1.0.1' => 'LORAWAN_1_0_1', '1.0.2' => 'LORAWAN_1_0_2', '1.0.3' => 'LORAWAN_1_0_3', '1.0.4' => 'LORAWAN_1_0_4', '1.1.0' => 'LORAWAN_1_1_0'];
                $item = array_merge(cs_rowBase($p), [
                    'name'              => $p['name'] ?? '',
                    'description'       => $p['description'] ?? '',
                    'region'            => strtoupper($p['region'] ?? 'EU868'),
                    'macVersion'        => $macMapFl[$p['mac_version'] ?? '1.0.4'] ?? 'LORAWAN_1_0_4',
                    'regParamsRevision' => 'RP002_' . str_replace(['.', '-'], '_', $rev),
                    'adrAlgorithmId'    => cs_intToUuid(1),
                    'payloadCodecRuntime' => strtoupper($p['payload_codec_runtime'] ?? 'NONE') === 'NONE' ? 'NONE' : 'JS',
                    'flushQueueOnActivate' => (bool) ($p['flush_queue_on_activate'] ?? 0),
                    'uplinkInterval'    => (int) ($p['uplink_interval'] ?? 0),
                    'deviceStatusReqInterval' => (int) ($p['device_status_req_interval'] ?? 0),
                    'supportsOtaa'      => (bool) ($p['supports_otaa'] ?? 1),
                    'supportsClassB'    => (bool) ($p['supports_class_b'] ?? 0),
                    'supportsClassC'    => (bool) ($p['supports_class_c'] ?? 0),
                    'classBTimeout'     => (int) ($p['class_b_timeout'] ?? 0),
                    'classBPingSlotPeriodicity' => (int) ($p['class_b_ping_slot_periodicity'] ?? 0),
                    'classBPingSlotDr'  => (int) ($p['class_b_ping_slot_dr'] ?? 0),
                    'classBPingSlotFreq' => (int) ($p['class_b_ping_slot_freq'] ?? 0),
                    'classCTimeout'     => (int) ($p['class_c_timeout'] ?? 0),
                    'abpRx1Delay'       => (int) ($p['abp_rx1_delay'] ?? 1),
                    'abpRx1DrOffset'    => (int) ($p['abp_rx1_dr_offset'] ?? 0),
                    'abpRx2Dr'          => (int) ($p['abp_rx2_dr'] ?? 0),
                    'abpRx2Freq'        => (int) ($p['abp_rx2_freq'] ?? 0),
                    'allowRoaming'      => (bool) ($p['allow_roaming'] ?? 0),
                    'tags'              => cs_obj(),
                    'tenantId'          => cs_intToUuid((int) ($p['tenant_id'] ?? 0)),

                    'numericId'         => (int) $p['id'],
                ]);
                $dpList[] = $item;
            }

            $search = trim((string) ($get['search'] ?? ''));
            if ($search !== '') {
                $dpList = array_values(array_filter($dpList, fn($x) => stripos((string) ($x['name'] ?? ''), $search) !== false));
            }
            $total = count($dpList);
            if ((int) ($get['limit'] ?? 50) === 0) {
                return cs_list([], $total);
            }
            $dpList = array_slice(array_values($dpList), $offsetOf('offset'), $limitOf('limit'));
            return cs_list($dpList, $total);

        case 'thing-models':
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : 0);
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateThingModel($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteThingModel($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                $m = WebApp::getThingModel($id);
                if (!$m) {
                    return cs_notFound('thing model not found');
                }
                return ['thingModel' => $m];
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = cs_uuidToInt((string) $body['applicationId']);
                }
                $r = WebApp::createThingModel($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            return cs_list(WebApp::listThingModels($appId), count(WebApp::listThingModels($appId)));

        case 'device-readings':
            Auth::guardApi(Auth::ROLE_OPERATOR);
            $devId = isset($get['dev_id']) ? (int) $get['dev_id'] : 0;
            $field = (string) ($get['field'] ?? '');
            $from = isset($get['from']) ? (int) $get['from'] : 0;
            $to = isset($get['to']) ? (int) $get['to'] : time();
            return WebApp::queryDeviceReadings($devId, $field, $from, $to);

        case 'alert-rules':
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : 0);
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    if (isset($body['applicationId']) && !isset($body['application_id'])) {
                        $body['application_id'] = cs_uuidToInt((string) $body['applicationId']);
                    }
                    $r = WebApp::updateAlertRule($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteAlertRule($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = cs_uuidToInt((string) $body['applicationId']);
                }
                $r = WebApp::createAlertRule($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $rules = WebApp::listAlertRules($appId);
            return cs_list($rules, count($rules));

        case 'alerts':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'POST' && (($get['action'] ?? '') === 'resolve' || ($body['action'] ?? '') === 'resolve')) {
                    $r = WebApp::resolveAlert($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if (($get['scope'] ?? '') === 'counts') {
                return WebApp::alertCounts();
            }
            if (($get['scope'] ?? '') === 'active') {
                $r = WebApp::activeAlerts(isset($get['limit']) ? (int) $get['limit'] : 100);
                $data = $r['data'] ?? $r;
                return cs_list($data, is_array($data) ? count($data) : 0);
            }
            $limit = min(500, isset($get['limit']) ? (int) $get['limit'] : 50);
            $offset = isset($get['offset']) ? (int) $get['offset'] : 0;
            $did = isset($get['devId']) ? cs_uuidToInt((string) $get['devId']) : (isset($get['dev_id']) ? (int) $get['dev_id'] : null);
            $status = (string) ($get['status'] ?? '');
            $r = WebApp::listAlerts($limit, $offset, $did, $status);
            $rows = $r['data'] ?? [];
            $out = ['totalCount' => count($rows), 'result' => $rows];
            if (isset($r['counts'])) { $out['counts'] = $r['counts']; }
            return $out;

        case 'notification-groups':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateNotificationGroup($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteNotificationGroup($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                $r = WebApp::createNotificationGroup($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $groups = WebApp::listNotificationGroups();
            $groups = $groups['data'] ?? $groups;
            return cs_list($groups, count($groups));

        case 'scheduled-tasks':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'POST' && (($get['action'] ?? '') === 'run' || ($body['action'] ?? '') === 'run')) {
                    $r = WebApp::runScheduledTask($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return ['id' => cs_intToUuid((int) $r['id']), 'downlinkId' => cs_intToUuid((int) ($r['downlink_id'] ?? 0)), 'nextRunAt' => cs_ts($r['next_run_at'] ?? 0)];
                }
                if ($method === 'POST' && (($get['action'] ?? '') === 'toggle' || ($body['action'] ?? '') === 'toggle')) {
                    $r = WebApp::toggleScheduledTask($id, !empty($get['enabled']) || !empty($body['enabled']));
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    if (isset($body['deviceId']) && !isset($body['device_id'])) { $body['device_id'] = cs_uuidToInt((string) $body['deviceId']); }
                    $r = WebApp::updateScheduledTask($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteScheduledTask($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                if (isset($body['deviceId']) && !isset($body['device_id'])) { $body['device_id'] = cs_uuidToInt((string) $body['deviceId']); }
                $r = WebApp::createScheduledTask($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $tasks = WebApp::listScheduledTasks();
            $tasks = $tasks['data'] ?? $tasks;
            return cs_list($tasks, count($tasks));

        case 'automations':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    if (isset($body['applicationId']) && !isset($body['application_id'])) { $body['application_id'] = cs_uuidToInt((string) $body['applicationId']); }
                    if (isset($body['triggerDeviceId']) && !isset($body['trigger_device_id'])) { $body['trigger_device_id'] = cs_uuidToInt((string) $body['triggerDeviceId']); }
                    $r = WebApp::updateAutomation($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteAutomation($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) { $body['application_id'] = cs_uuidToInt((string) $body['applicationId']); }
                if (isset($body['triggerDeviceId']) && !isset($body['trigger_device_id'])) { $body['trigger_device_id'] = cs_uuidToInt((string) $body['triggerDeviceId']); }
                $r = WebApp::createAutomation($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $autos = WebApp::listAutomations();
            $autos = $autos['data'] ?? $autos;
            return cs_list($autos, count($autos));

        case 'roles':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateRole($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteRole($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                if (isset($body['tenantId']) && !isset($body['tenant_id'])) { $body['tenant_id'] = cs_uuidToInt((string) $body['tenantId']); }
                $r = WebApp::createRole($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $rolesRaw = WebApp::listRoles();
            $roles = $rolesRaw['data'] ?? $rolesRaw;
            foreach ($roles as &$rr) {
                $rr['devicesLimit'] = (int) ($rr['devices_limit'] ?? 0);
                $rr['gatewaysLimit'] = (int) ($rr['gateways_limit'] ?? 0);
                $rr['gatewaysUnlimited'] = (int) ($rr['gateways_unlimited'] ?? 0) === 1 ? true : false;
                $rr['isSystem'] = (int) ($rr['is_system'] ?? 0) === 1 ? true : false;
                $rr['userCount'] = (int) ($rr['user_count'] ?? 0);
            }
            unset($rr);
            $out = cs_list($roles, count($roles));
            if (isset($rolesRaw['catalog'])) { $out['catalog'] = $rolesRaw['catalog']; }
            return $out;

        case 'departments':

            if ($method === 'POST') { return cs_err(7, 'permission denied', 'departments feature removed'); }
            if (isset($segs[1]) && $segs[1] !== '' && ($method === 'PUT' || $method === 'PATCH' || $method === 'DELETE')) {
                return cs_err(5, 'not found', 'departments feature removed');
            }
            return cs_list([], 0);

        case 'api-keys':
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : 0);
            $tenantId = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            if (isset($segs[1]) && $segs[1] !== '') {
                if ($method === 'DELETE') {
                    $r = WebApp::deleteApiKey(cs_uuidToInt((string) $segs[1]));
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = is_numeric($body['applicationId']) ? (int) $body['applicationId'] : cs_uuidToInt((string) $body['applicationId']);
                }
                $r = WebApp::createApiKey((int) ($body['application_id'] ?? $appId), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id']), 'token' => $r['token'] ?? ''];
            }
            $keys = WebApp::listApiKeys($appId, $tenantId);
            $keyRows = array_map(fn($k) => array_merge(cs_rowBase($k), [
                'name'            => $k['name'] ?? '',
                'applicationId'   => cs_intToUuid((int) ($k['application_id'] ?? 0)),
                'tokenPreview'    => $k['token_preview'] ?? '',
                'isActive'        => true,
                'tenantId'        => cs_intToUuid(0),
                'displayName'     => $k['name'] ?? '',

                'numericId'       => (int) $k['id'],
            ]), $keys);
            return cs_list($keyRows, count($keyRows));

        case 'uplinks':
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            if (isset($segs[1]) && $segs[1] !== '' && $method === 'GET' && ctype_digit((string) $segs[1])) {
                $row = WebApp::getUplink((int) $segs[1], $tid);
                if (!$row) { return cs_notFound('uplink not found'); }
                return ['uplink' => $row];
            }
            $devId = isset($get['devId']) ? cs_uuidToInt((string) $get['devId']) : (isset($get['dev_id']) ? (int) $get['dev_id'] : null);
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : null);
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            $ups = WebApp::listUplinks($devId, $appId, $lim, $tid, $off);
            $total = WebApp::countUplinks($devId, $appId, $tid);
            $upRows = array_map(static function ($u) {
                return [
                    'id'          => (string) $u['id'],
                    'devEui'      => $u['dev_eui'] ?? '',
                    'devAddr'     => $u['dev_addr'] ?? '',
                    'fCntUp'      => (int) ($u['fcnt'] ?? 0),
                    'fPort'       => (int) ($u['port'] ?? 0),
                    'data'        => base64_encode(hex2bin((string) ($u['payload_hex'] ?? '')) ?: ''),
                    'decrypted'   => (string) ($u['decrypted_hex'] ?? '') !== '' ? base64_encode(hex2bin($u['decrypted_hex'])) : null,
                    'rssi'        => (int) ($u['rssi'] ?? 0),
                    'snr'         => (float) ($u['snr'] ?? 0),
                    'gatewayId'   => $u['gateway_id'] ?? '',
                    'time'        => cs_ts($u['received_at'] ?? 0),

                    'devId'       => (int) ($u['dev_id'] ?? 0),
                    'appId'       => (int) ($u['app_id'] ?? 0),
                    'payloadHex'  => $u['payload_hex'] ?? '',
                    'decryptedHex'=> $u['decrypted_hex'] ?? '',

                    'confirmed'   => (bool) ($u['confirmed'] ?? 0),
                    'phyPayload'  => (string) ($u['phy_payload'] ?? ''),
                    'rawJson'     => (string) ($u['raw_json'] ?? ''),
                ];
            }, $ups);
            return cs_list($upRows, $total);
        case 'downlinks':
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            if (isset($segs[1]) && $segs[1] !== '' && $method === 'GET' && ctype_digit((string) $segs[1])) {
                $row = WebApp::getDownlink((int) $segs[1], $tid);
                if (!$row) { return cs_notFound('downlink not found'); }
                return ['downlink' => $row];
            }
            if (isset($segs[1]) && $segs[1] !== '' && $method === 'DELETE') {
                $id = cs_uuidToInt((string) $segs[1]);
                $dl = Database::fetch("SELECT id, dev_id, app_id, status FROM downlinks WHERE id=?", [$id]);
                if (!$dl) {
                    return cs_notFound('downlink not found');
                }

                $cur = Auth::currentUser();
                if ($cur && ($cur['role'] ?? '') !== Auth::ROLE_ADMIN) {
                    $visible = WebApp::visibleAppIds(isset($tid) && $tid ? (int) $tid : null) ?? [];
                    if (!in_array((int) $dl['app_id'], $visible, true)) {
                        return cs_forbidden('downlink not in your application');
                    }
                }
                if ($dl['status'] !== 'pending') {
                    http_response_code(409);
                    return cs_err(9, 'failed_precondition', 'downlink is not pending (status=' . $dl['status'] . ')');
                }
                Database::execute("UPDATE downlinks SET status='canceled' WHERE id=?", [$id]);
                return [];
            }
            $devId = isset($get['devId']) ? cs_uuidToInt((string) $get['devId']) : (isset($get['dev_id']) ? (int) $get['dev_id'] : null);
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : null);
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            $dls = WebApp::listDownlinks($devId, $appId, $lim, $tid, $off);
            $total = WebApp::countDownlinks($devId, $appId, $tid);
            $dlRows = array_map(static function ($d) {
                return [
                    'id'        => (string) $d['id'],
                    'devEui'    => $d['dev_eui'] ?? '',
                    'fPort'     => (int) ($d['port'] ?? 0),
                    'fCntDown'  => (int) ($d['fcnt'] ?? 0),
                    'data'      => base64_encode(hex2bin((string) ($d['payload_hex'] ?? '')) ?: ''),
                    'confirmed' => (bool) ($d['confirmed'] ?? 0),
                    'isPending' => ($d['status'] ?? '') === 'pending',
                    'state'     => strtoupper($d['status'] ?? ''),
                    'time'      => cs_ts($d['created_at'] ?? 0),

                    'devId'     => (int) ($d['dev_id'] ?? 0),
                    'appId'     => (int) ($d['app_id'] ?? 0),
                    'payloadHex'=> $d['payload_hex'] ?? '',
                    'acknowledgedAt' => cs_ts($d['acknowledged_at'] ?? 0),

                    'status'        => (string) ($d['status'] ?? ''),
                    'mac'           => (int) ($d['mac'] ?? 0),
                    'transmissions' => (int) ($d['transmissions'] ?? 0),
                    'sentAt'        => cs_ts($d['sent_at'] ?? 0),
                    'rawJson'       => (string) ($d['raw_json'] ?? ''),
                ];
            }, $dls);
            return cs_list($dlRows, $total);
        case 'events':
            $devId = isset($get['devId']) ? cs_uuidToInt((string) $get['devId']) : (isset($get['dev_id']) ? (int) $get['dev_id'] : null);
            $gwId = isset($get['gatewayId']) ? trim((string) $get['gatewayId']) : (isset($get['gw_id']) ? trim($get['gw_id']) : null);
            $type = isset($get['type']) ? trim($get['type']) : null;
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            $evs = WebApp::listEvents($devId, $gwId, $type, $lim, $tid, $off);
            $total = WebApp::countEvents($devId, $gwId, $type, $tid);
            $evRows = array_map(static function ($e) {
                return [
                    'id'        => (string) $e['id'],
                    'type'      => $e['type'] ?? '',
                    'level'     => $e['level'] ?? '',
                    'gatewayId' => $e['gateway_id'] ?? '',
                    'devId'     => (int) ($e['dev_id'] ?? 0),
                    'message'   => $e['message'] ?? '',
                    'time'      => cs_ts($e['created_at'] ?? 0),

                    'rawJson'   => (string) ($e['raw_json'] ?? ''),
                ];
            }, $evs);
            return cs_list($evRows, $total);
        case 'users':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'password' && $method === 'POST') {

                $target = cs_uuidToInt((string) $segs[1]);
                $r = WebApp::changePassword($target, $body['password'] ?? $body['new_password'] ?? '');
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (($segs[1] ?? '') === 'password' && $method === 'POST') {

                $cur = Auth::currentUser();
                $target = (isset($body['user_id']) && $body['user_id'] !== '') ? (int) $body['user_id'] : (int) $cur['id'];
                $r = WebApp::changePassword($target, $body['new_password'] ?? '');
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteUser(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'PUT') {
                $r = WebApp::updateUser(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                if (empty($body['username']) || empty($body['password'])) {
                    return cs_invalid('username and password required');
                }
                $roleId = (int) ($body['role_id'] ?? 0);
                try {
                    $role = $roleId > 0 ? Auth::roleFromRoleId($roleId, Auth::ROLE_OPERATOR) : Auth::ROLE_OPERATOR;
                    $id = Auth::createUser(
                        $body['username'],
                        $body['password'],
                        $role,
                        0,
                        null,
                        $body['email'] ?? null,
                        $roleId
                    );
                } catch (\InvalidArgumentException $e) {
                    return cs_invalid('invalid email');
                }
                return ['id' => cs_intToUuid((int) $id)];
            }
            $users = WebApp::listUsers();
            $userRows = array_map(static function ($u) {
                return array_merge(cs_rowBase($u), [
                    'username'       => $u['username'] ?? '',
                    'email'          => $u['email'] ?? '',
                    'role'           => $u['role'] ?? '',
                    'tenantId'       => cs_intToUuid((int) ($u['tenant_id'] ?? 0)),
                    'tenantName'     => $u['tenant_name'] ?? '',
                    'roleId'         => cs_intToUuid((int) ($u['role_id'] ?? 0)),
                    'roleName'       => $u['role_name'] ?? '',
                    'isAdmin'        => ($u['role'] ?? '') === 'admin',
                    'isActive'       => true,

                    'numericId'      => (int) $u['id'],
                ]);
            }, $users);
            $totalU = count($userRows);
            if ((int) ($get['limit'] ?? 50) === 0) {
                return cs_list([], $totalU);
            }
            return cs_list(array_slice($userRows, $offsetOf('offset'), $limitOf('limit')), $totalU);
        case 'api-keys':
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteApiKey((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createApiKey((int) ($body['application_id'] ?? 0), $body);
            }
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : 0;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listApiKeys($appId, $tid)];
        case 'integrations':
            if (isset($segs[1]) && $method === 'PUT') {
                $r = WebApp::updateIntegration(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteIntegration(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = is_numeric($body['applicationId']) ? (int) $body['applicationId'] : cs_uuidToInt((string) $body['applicationId']);
                }
                $r = WebApp::createIntegration($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : 0);
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $integrations = WebApp::listIntegrations($appId, $tid);
            $intRows = array_map(static function ($i) {
                return array_merge(cs_rowBase($i), [
                    'applicationId' => cs_intToUuid((int) ($i['application_id'] ?? $i['app_id'] ?? 0)),
                    'kind'          => strtoupper($i['kind'] ?? ''),
                    'enabled'       => (bool) ($i['enabled'] ?? 0),
                    'configuration' => json_decode((string) ($i['config_json'] ?? '{}'), true) ?: cs_obj(),

                    'numericId'     => (int) $i['id'],
                ]);
            }, $integrations);
            return cs_list($intRows, count($intRows));
        case 'multicast-groups':
            if (isset($segs[1]) && in_array($segs[2] ?? '', ['enqueue', 'queue'], true)) {

                $mgId = cs_uuidToInt((string) $segs[1]);
                $isEnqueueAlias = ($segs[2] === 'enqueue');
                if ($isEnqueueAlias || $method === 'POST') {
                    $in = $body['queueItem'] ?? $body['deviceQueueItem'] ?? $body;
                    $payload = (string) ($in['data'] ?? '');
                    if ($payload !== '' && !ctype_xdigit($payload)) {
                        $bin = base64_decode($payload, true);
                        if ($bin === false) {
                            return cs_invalid('data must be Base64 or hex');
                        }
                        $payload = bin2hex($bin);
                    }
                    $r = WebApp::enqueueMulticast(
                        $mgId,
                        (int) ($in['fPort'] ?? $in['port'] ?? 0),
                        $payload !== '' ? $payload : (string) ($in['payload'] ?? '')
                    );
                    if ($e = cs_wrapError($r)) { return $e; }

                    return ['fCnt' => (int) ($r['f_cnt'] ?? 0)];
                }
                if ($method === 'GET') {

                    $mqRows = Database::fetchAll(
                        "SELECT * FROM multicast_queue WHERE multicast_group_id=? ORDER BY id ASC",
                        [$mgId]
                    );
                    $mqItems = array_map(static fn($m) => [
                        'multicastGroupId' => cs_intToUuid((int) $m['multicast_group_id']),
                        'fPort'            => (int) $m['f_port'],
                        'fCnt'             => (int) ($m['f_cnt'] ?? 0),
                        'data'             => base64_encode(hex2bin((string) $m['payload_hex']) ?: ''),
                        'expiresAt'        => !empty($m['expires_at']) ? cs_ts($m['expires_at']) : CS_ZERO_TS,
                    ], $mqRows);
                    return ['items' => $mqItems];
                }
                if ($method === 'DELETE') {

                    Database::execute("DELETE FROM multicast_queue WHERE multicast_group_id=?", [$mgId]);
                    return [];
                }
            }
            if (isset($segs[1]) && $method === 'GET' && !isset($segs[2])) {
                $g = WebApp::getMulticastGroup(cs_uuidToInt((string) $segs[1]));
                if (!$g) {
                    return cs_notFound('multicast group not found');
                }
                return ['multicastGroup' => $g];
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'devices') {
                if ($method === 'GET') {
                    $r = WebApp::multicastDevices(cs_uuidToInt((string) $segs[1]));
                    $rows = $r['data'] ?? (is_array($r) ? $r : []);
                    return cs_list($rows, count($rows));
                }
                if ($method === 'POST') {

                    $r = WebApp::addMulticastDevice(cs_uuidToInt((string) $segs[1]), strtolower((string) ($body['devEui'] ?? $body['dev_eui'] ?? '')));
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {

                    $devEuiDel = strtolower((string) ($segs[3] ?? $body['devEui'] ?? $body['dev_eui'] ?? ''));
                    $r = WebApp::removeMulticastDevice(cs_uuidToInt((string) $segs[1]), $devEuiDel);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'gateways') {
                if ($method === 'GET') {
                    $r = WebApp::multicastGateways(cs_uuidToInt((string) $segs[1]));
                    $rows = $r['data'] ?? (is_array($r) ? $r : []);
                    return cs_list($rows, count($rows));
                }
                if ($method === 'POST') {
                    $r = WebApp::addMulticastGateway(cs_uuidToInt((string) $segs[1]), strtolower((string) ($body['gatewayId'] ?? $body['gw_id'] ?? '')));
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {

                    $gwIdDel = strtolower((string) ($segs[3] ?? $body['gatewayId'] ?? $body['gw_id'] ?? ''));
                    $r = WebApp::removeMulticastGateway(cs_uuidToInt((string) $segs[1]), $gwIdDel);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
            }
            if (isset($segs[1]) && $method === 'PUT') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) { $body['application_id'] = cs_uuidToInt((string) $body['applicationId']); }
                $r = WebApp::updateMulticastGroup(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteMulticastGroup(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = is_numeric($body['applicationId']) ? (int) $body['applicationId'] : cs_uuidToInt((string) $body['applicationId']);
                }
                $r = WebApp::createMulticastGroup($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : null);
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $mgs = WebApp::listMulticastGroups($appId, $tid);
            $mgRows = array_map(static function ($m) {
                return array_merge(cs_rowBase($m), [
                    'name'          => $m['name'] ?? '',
                    'applicationId' => cs_intToUuid((int) ($m['application_id'] ?? 0)),
                    'region'        => strtoupper($m['region'] ?? ''),
                    'groupType'     => strtoupper($m['group_type'] ?? 'C') === 'B' ? 'CLASS_B' : (strtoupper($m['group_type'] ?? 'C') === 'A' ? 'CLASS_A' : 'CLASS_C'),
                    'mcAddr'        => $m['mc_addr'] ?? '',
                    'mcNwkSKey'     => $m['mc_nwk_s_key'] ?? '',
                    'mcAppSKey'     => $m['mc_app_s_key'] ?? '',
                    'fCnt'          => (int) ($m['f_cnt'] ?? 0),
                    'dr'            => (int) ($m['dr'] ?? 0),
                    'frequency'     => (int) ($m['frequency'] ?? 0),
                    'classBPingSlotPeriodicity' => (int) ($m['class_b_ping_slot_periodicity'] ?? 0),
                    'classCSchedulingType' => strtoupper($m['class_c_scheduling_type'] ?? 'DELAY') === 'GPS' ? 'GPS' : 'DELAY',

                    'numericId'     => (int) $m['id'],
                    'appId'         => (int) ($m['application_id'] ?? 0),
                ]);
            }, $mgs);
            return cs_list($mgRows, count($mgRows));
        case 'fuota':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'start' && $method === 'POST') {
                $r = WebApp::startFuotaCampaign(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return $r;
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'devices' && $method === 'POST') {
                $r = WebApp::addFuotaDeployment(cs_uuidToInt((string) $segs[1]), isset($body['devId']) ? cs_uuidToInt((string) $body['devId']) : (int) ($body['dev_id'] ?? 0));
                if ($e = cs_wrapError($r)) { return $e; }
                return $r;
            }
            if (isset($segs[1]) && $method === 'GET' && !isset($segs[2])) {
                $r = WebApp::getFuotaCampaign(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return $r;
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteFuotaCampaign(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                if (isset($body['applicationId']) && !isset($body['application_id'])) {
                    $body['application_id'] = is_numeric($body['applicationId']) ? (int) $body['applicationId'] : cs_uuidToInt((string) $body['applicationId']);
                }
                if (isset($body['multicastGroupId']) && !isset($body['multicast_group_id'])) {
                    $body['multicast_group_id'] = is_numeric($body['multicastGroupId']) ? (int) $body['multicastGroupId'] : cs_uuidToInt((string) $body['multicastGroupId']);
                }
                $r = WebApp::createFuotaCampaign($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $camps = WebApp::listFuotaCampaigns();
            return cs_list($camps, count($camps));
        case 'tenants':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'users') {

                $tid2 = cs_uuidToInt((string) $segs[1]);
                if ($method === 'GET') {
                    $tUsers = Database::fetchAll("SELECT * FROM users WHERE tenant_id=? ORDER BY id", [$tid2]);
                    $tuRows = array_map(static fn($tu) => [
                        'tenantId'       => cs_intToUuid($tid2),
                        'userId'         => cs_intToUuid((int) $tu['id']),
                        'email'          => $tu['email'] ?? '',
                        'isAdmin'        => ($tu['role'] ?? '') === 'admin',
                        'isDeviceAdmin'  => in_array($tu['role'] ?? '', ['admin', 'tenant'], true),
                        'isGatewayAdmin' => in_array($tu['role'] ?? '', ['admin', 'tenant'], true),
                        'createdAt'      => cs_ts($tu['created_at'] ?? 0),
                        'updatedAt'      => cs_ts($tu['created_at'] ?? 0),
                        'numericId'      => (int) $tu['id'],
                    ], $tUsers);
                    $offT = $offsetOf('offset');
                    $limT = $limitOf('limit');
                    return cs_list(array_slice($tuRows, $offT, $limT), count($tuRows));
                }
                if ($method === 'POST') {
                    $tu = $body['tenantUser'] ?? $body;
                    $email = (string) ($tu['email'] ?? '');
                    if ($email === '') {
                        return cs_invalid('email required');
                    }
                    $existing = Database::fetch("SELECT id FROM users WHERE email=?", [$email]);
                    if ($existing) {
                        Database::execute("UPDATE users SET tenant_id=? WHERE id=?", [$tid2, (int) $existing['id']]);
                        return [];
                    }
                    return cs_notFound('user with given email does not exist');
                }
                if (isset($segs[3]) && ($method === 'PUT' || $method === 'DELETE')) {
                    $uid2 = cs_uuidToInt((string) $segs[3]);
                    if ($method === 'DELETE') {
                        Database::execute("UPDATE users SET tenant_id=0 WHERE id=? AND tenant_id=?", [$uid2, $tid2]);
                        return [];
                    }
                    $tu = $body['tenantUser'] ?? $body;
                    $role = !empty($tu['isAdmin']) ? 'admin' : (!empty($tu['isDeviceAdmin']) ? 'tenant' : 'operator');
                    Database::execute("UPDATE users SET role=?, tenant_id=? WHERE id=?", [$role, $tid2, $uid2]);
                    return [];
                }
            }
            if (isset($segs[1]) && $segs[1] === 'by-devaddr-prefix-overlap' && $method === 'GET') {

                return cs_list([], 0);
            }
            if (isset($segs[1]) && $method === 'GET') {

                $t = Database::fetch("SELECT * FROM tenants WHERE id=?", [cs_uuidToInt((string) $segs[1])]);
                if (!$t) {
                    return cs_notFound('tenant not found');
                }
                return ['tenant' => array_merge(cs_rowBase($t), [
                    'name'                => $t['name'] ?? '',
                    'description'         => $t['description'] ?? '',
                    'canHaveGateways'     => (int) ($t['private_gateways_unlimited'] ?? 0) > 0,
                    'privateGatewaysUp'   => false,
                    'privateGatewaysDown' => false,
                    'maxDeviceCount'      => 0,
                    'maxGatewayCount'     => (int) ($t['private_gateways_limit'] ?? 0),
                    'tags'                => cs_obj(),
                    'numericId'           => (int) $t['id'],
                ])];
            }
            if (isset($segs[1]) && $method === 'PUT') {
                $r = WebApp::updateTenant(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteTenant(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                $in = $body['tenant'] ?? $body;
                if (isset($in['name'])) { $in['name'] = $in['name']; }
                $r = WebApp::createTenant($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $tenants = WebApp::listTenants();

            $search = trim((string) ($get['search'] ?? ''));
            if ($search !== '') {
                $tenants = array_values(array_filter($tenants, fn($t) => stripos((string) ($t['name'] ?? ''), $search) !== false));
            }
            $tenantRows = array_map(static function ($t) {
                return array_merge(cs_rowBase($t), [
                    'name'                => $t['name'] ?? '',
                    'description'         => $t['description'] ?? '',
                    'canHaveGateways'     => (int) ($t['private_gateways_unlimited'] ?? 0) > 0,
                    'privateGatewaysUp'   => false,
                    'privateGatewaysDown' => false,
                    'maxDeviceCount'      => 0,
                    'maxGatewayCount'     => (int) ($t['private_gateways_limit'] ?? 0),
                    'tags'                => cs_obj(),

                    'numericId'           => (int) $t['id'],
                ]);
            }, $tenants);
            $totalT = count($tenantRows);
            if ((int) ($get['limit'] ?? 50) === 0) {
                return cs_list([], $totalT);
            }
            return cs_list(array_slice($tenantRows, $offsetOf('offset'), $limitOf('limit')), $totalT);
        case 'device-profile-templates':

            if ($method === 'GET' && !isset($segs[1])) {
                return cs_list([], 0);
            }
            if ($method === 'POST') {
                return cs_unimplemented('device-profile templates are not persisted in this server');
            }
            if (isset($segs[1])) {
                if ($method === 'GET') {
                    return cs_notFound('device-profile template not found');
                }
                if ($method === 'PUT') {
                    return cs_unimplemented('device-profile templates are not persisted in this server');
                }
                if ($method === 'DELETE') {
                    return cs_notFound('device-profile template not found');
                }
            }
            return cs_invalid('method not allowed');
        case 'relays':

            $sc = WebApp::scopePublic();
            $relayTid = (int) $sc['tenant_id'];
            $relayAll = $sc['is_admin'] || $sc['demo'];
            if (isset($segs[1]) && ($segs[2] ?? '') === 'devices') {
                $relayEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $segs[1]));
                $relayGw = Database::fetch("SELECT * FROM relay_gateways WHERE relay_dev_eui=?", [$relayEui]);
                if (!$relayGw || (!$relayAll && (int) ($relayGw['tenant_id'] ?? 0) !== $relayTid)) {
                    return cs_notFound('relay gateway not found');
                }
                if ($method === 'GET') {
                    $rdRows = Database::fetchAll(
                        "SELECT rd.*, d.name AS dev_name FROM relay_devices rd LEFT JOIN devices d ON d.dev_eui=rd.dev_eui WHERE rd.relay_gateway_id IN (SELECT id FROM relay_gateways WHERE relay_dev_eui=?) ORDER BY rd.id",
                        [$relayEui]
                    );
                    $rdList = array_map(static fn($rd) => [
                        'devEui'    => $rd['dev_eui'] ?? '',
                        'name'      => $rd['dev_name'] ?? ($rd['dev_eui'] ?? ''),
                        'createdAt' => cs_ts($rd['created_at'] ?? 0),
                    ], $rdRows);
                    $offR = $offsetOf('offset');
                    $limR = $limitOf('limit');
                    return cs_list(array_slice($rdList, $offR, $limR), count($rdList));
                }
                if ($method === 'POST') {
                    $in = $body['deviceDevEui'] ?? $body['devEui'] ?? '';
                    $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $in));
                    if (strlen($devEui) !== 16) {
                        return cs_invalid('deviceDevEui must be a 16-hex EUI64');
                    }
                    $exists = Database::fetch(
                        "SELECT id FROM relay_devices WHERE relay_gateway_id=? AND dev_eui=?",
                        [(int) $relayGw['id'], $devEui]
                    );
                    if ($exists) {
                        http_response_code(409);
                        return cs_err(6, 'already_exists', 'device already added to relay');
                    }
                    Database::execute(
                        "INSERT INTO relay_devices (relay_gateway_id, dev_eui, created_at) VALUES (?,?,?)",
                        [(int) $relayGw['id'], $devEui, time()]
                    );
                    return [];
                }
            }
            if ($method === 'GET') {

                $relRows = Database::fetchAll(
                    "SELECT d.dev_eui, d.name FROM devices d WHERE (d.relay_state='relay' OR d.dev_eui IN (SELECT relay_dev_eui FROM relay_gateways))" . ($relayAll ? '' : ' AND d.tenant_id=' . $relayTid) . " ORDER BY d.id DESC"
                );
                $relList = array_map(static fn($r) => ['devEui' => $r['dev_eui'] ?? '', 'name' => $r['name'] ?? ''], $relRows);
                $offR2 = $offsetOf('offset');
                $limR2 = $limitOf('limit');
                return cs_list(array_slice($relList, $offR2, $limR2), count($relList));
            }
            return cs_invalid('method not allowed');
        case 'api-logs':

            $u = Auth::currentUser();
            $role = $u['role'] ?? '';
            if (!in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_TENANT, Auth::ROLE_OPERATOR], true)) {
                http_response_code(403);
                return ['error' => 'forbidden'];
            }
            $filters = [
                'tenant_id' => isset($get['tenant_id']) ? (int) $get['tenant_id'] : null,
                'application_id' => isset($get['application_id']) ? (int) $get['application_id'] : null,
                'ip' => isset($get['ip']) ? trim((string) $get['ip']) : null,
                'status' => isset($get['status']) ? $get['status'] : null,
                'method' => isset($get['method']) ? trim((string) $get['method']) : null,
                'path_contains' => isset($get['path_contains']) ? trim((string) $get['path_contains']) : null,
                'since' => isset($get['since']) ? (int) $get['since'] : null,
            ];
            $limit = $limitOf('limit');
            $offset = $offsetOf('offset');
            $out = WebApp::listApiLogs($limit, $offset, $filters);
            $logRows = array_map(static function ($l) {
                return [
                    'id'        => (string) ($l['id'] ?? ''),
                    'method'    => $l['method'] ?? '',
                    'path'      => $l['path'] ?? '',
                    'status'    => (int) ($l['status'] ?? 0),
                    'latencyMs' => (int) ($l['latency_ms'] ?? 0),
                    'ip'        => $l['ip'] ?? '',
                    'username'  => $l['username'] ?? '',
                    'role'      => $l['role'] ?? '',
                    'tenantId'  => (int) ($l['tenant_id'] ?? 0),
                    'applicationId' => (int) ($l['application_id'] ?? 0),
                    'query'     => $l['query'] ?? '',
                    'bodySize'  => (int) ($l['body_size'] ?? 0),
                    'time'      => cs_ts($l['created_at'] ?? 0),
                ];
            }, $out['rows'] ?? []);
            return cs_list($logRows, (int) ($out['total'] ?? count($logRows)));
        default:
            http_response_code(501);
            return cs_unimplemented('unknown endpoint: /api/' . $resource);
    }
}

function handleAppApi(string $method, string $path): array
{
    $segs = explode('/', trim($path, '/'));
    array_shift($segs);

    $sub = $segs[0] ?? '';
    $body = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) ? getJsonBody() : [];
    $get = $_GET;

    $token = ApiKey::tokenFromRequest();
    $appId = $token ? ApiKey::validate($token) : 0;
    if (!$appId) {
        http_response_code(401);
        return ['error' => 'invalid_api_key', 'message' => '请在请求头携带 Authorization: Bearer <API_KEY> 或使用 ?api_key=<API_KEY>'];
    }

    if (isset($GLOBALS['__apiLogCtx']) && is_array($GLOBALS['__apiLogCtx'])) {
        $GLOBALS['__apiLogCtx']['application_id'] = (int) $appId;
    }
    $app = WebApp::getApplication($appId);
    if (!$app) {
        http_response_code(401);
        return ['error' => 'application_not_found'];
    }

    $deviceView = static function (array $d): array {
        $lastSeen = max((int) ($d['last_seen'] ?? 0), (int) ($d['created_at'] ?? 0));
        $online = ($d['status'] === 'active' && $lastSeen >= time() - WebApp::DEV_OFFLINE_TIMEOUT) ? 'online' : 'offline';
        return [
            'id' => (int) $d['id'],
            'name' => $d['name'],
            'dev_eui' => $d['dev_eui'] ?? '',
            'dev_addr' => $d['dev_addr'] ?? '',
            'activation' => $d['activation'] ?? '',
            'class' => $d['class'] ?? 'A',
            'region' => $d['region'] ?? '',
            'status' => $d['status'] ?? '',
            'codec' => $d['codec'] ?? '',
            'online' => $online,
            'last_seen' => $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : '-',
            'created_at' => (int) ($d['created_at'] ?? 0),
        ];
    };
    $resolveDevice = static function (string $devEui) use ($appId): ?array {
        $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $devEui));
        if ($devEui === '') {
            return null;
        }
        return Database::fetch("SELECT * FROM devices WHERE dev_eui=? AND app_id=?", [$devEui, $appId]);
    };

    $resolveGateway = static function (string $gwId): ?array {
        $gwId = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $gwId));
        if ($gwId === '') {
            return null;
        }
        return WebApp::getGateway($gwId);
    };

    $limitOf  = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 50);
        return max(1, min($n, 500));
    };
    $offsetOf = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 0);
        return max(0, $n);
    };

    $gatewayView = static function (array $g): array {
        $timeout = time() - WebApp::GW_OFFLINE_TIMEOUT;
        return [
            'gw_id'     => $g['gw_id'] ?? '',
            'name'      => $g['name'] ?? '',
            'region'    => $g['region'] ?? '',
            'status'    => ((int) ($g['last_seen'] ?? 0) >= $timeout) ? 'online' : 'offline',
            'last_seen' => ($g['last_seen'] ?? 0) ? date('Y-m-d H:i:s', (int) $g['last_seen']) : '-',
            'rf_config' => (isset($g['rf_config']) && $g['rf_config'] !== '') ? json_decode($g['rf_config'], true) : null,
        ];
    };

    switch ($sub) {
        case '':
            return [
                'service' => 'HolaStack application API',
                'version' => 'v1',
                'auth' => 'Authorization: Bearer <API_KEY> 或 ?api_key=<API_KEY>',
                'endpoints' => [
                    'GET    /v1/info',
                    'GET    /v1/devices',
                    'POST   /v1/devices',
                    'GET    /v1/devices/{dev_eui}',
                    'PUT    /v1/devices/{dev_eui}',
                    'DELETE /v1/devices/{dev_eui}',
                    'GET    /v1/devices/{dev_eui}/uplinks',
                    'GET    /v1/gateways',
                    'POST   /v1/gateways',
                    'GET    /v1/gateways/{gw_id}',
                    'PUT    /v1/gateways/{gw_id}',
                    'DELETE /v1/gateways/{gw_id}',
                    'GET    /v1/uplinks',
                    'GET    /v1/downlinks',
                    'POST   /v1/devices/{dev_eui}/downlink',
                    'GET    /v1/devices/{dev_eui}/downlinks',
                    'GET    /v1/devices/{dev_eui}/metrics',
                    'GET    /v1/device-profiles',
                    'POST   /v1/device-profiles',
                    'GET    /v1/device-profiles/{id}',
                    'PUT    /v1/device-profiles/{id}',
                    'DELETE /v1/device-profiles/{id}',
                    'GET    /v1/api-keys',
                    'POST   /v1/api-keys',
                    'DELETE /v1/api-keys/{id}',
                    'DELETE /v1/downlinks/{id}',
                ],
            ];

        case 'info':
            $devCount = Database::fetch("SELECT COUNT(*) c FROM devices WHERE app_id=?", [$appId])['c'];
            $upCount = Database::fetch("SELECT COUNT(*) c FROM uplinks WHERE app_id=?", [$appId])['c'];
            $dlCount = Database::fetch("SELECT COUNT(*) c FROM downlinks WHERE app_id=?", [$appId])['c'];
            return [
                'application' => [
                    'id' => (int) $app['id'],
                    'name' => $app['name'],
                    'app_eui' => $app['app_eui'] ?? '',
                    'description' => $app['description'] ?? '',
                ],
                'counts' => [
                    'devices' => (int) $devCount,
                    'uplinks' => (int) $upCount,
                    'downlinks' => (int) $dlCount,
                ],
            ];

        case 'devices':

            if (isset($segs[1]) && $segs[1] !== '') {
                $dev = $resolveDevice($segs[1]);
                if (!$dev) {
                    http_response_code(404);
                    return ['error' => 'device_not_found'];
                }
                $sub2 = $segs[2] ?? '';
                if ($sub2 === 'uplinks' && $method === 'GET') {
                    return ['data' => WebApp::listUplinks($dev['id'], null, $limitOf('limit'))];
                }
                if ($sub2 === 'downlink' && $method === 'POST') {
                    $port = (int) ($body['port'] ?? 0);
                    $payload = (string) ($body['payload'] ?? '');
                    $confirmed = !empty($body['confirmed']);
                    $mac = !empty($body['mac']);
                    $r = WebApp::enqueueDownlink($dev['id'], $port, $payload, $confirmed, $mac);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    http_response_code(201);
                    return $r;
                }
                if ($sub2 === 'downlinks' && $method === 'GET') {
                    $status = $get['status'] ?? '';
                    $sql = "SELECT id, dev_id, port, payload_hex, confirmed, mac, fcnt, status, created_at, sent_at FROM downlinks WHERE dev_id=?";
                    $params = [$dev['id']];
                    if ($status !== '') {
                        $sql .= " AND status=?";
                        $params[] = $status;
                    }
                    $sql .= " ORDER BY id DESC LIMIT 200";
                    return ['data' => Database::fetchAll($sql, $params)];
                }
                if ($sub2 === 'metrics' && $method === 'GET') {
                    $hours = (int) ($get['range'] ?? 24);
                    if ($hours <= 0 || $hours > 720) { $hours = 24; }
                    $since = time() - $hours * 3600;
                    $rows = Database::fetchAll(
                        "SELECT received_at, rssi, snr, fcnt, port FROM uplinks WHERE dev_id=? AND received_at>=? ORDER BY received_at ASC LIMIT 1000",
                        [$dev['id'], $since]
                    );
                    $points = array_map(static function ($r) {
                        return [
                            't' => (int) $r['received_at'],
                            'rssi' => (int) ($r['rssi'] ?? 0),
                            'snr' => (float) ($r['snr'] ?? 0),
                            'fcnt' => (int) ($r['fcnt'] ?? 0),
                            'port' => (int) ($r['port'] ?? 0),
                        ];
                    }, $rows);
                    return ['range_hours' => $hours, 'points' => $points, 'count' => count($points)];
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateDevice($dev['id'], $body);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    return ['id' => $dev['id'], 'updated' => true];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteDevice($dev['id']);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    return ['id' => $dev['id'], 'deleted' => true];
                }

                $up = Database::fetch("SELECT COUNT(*) c FROM uplinks WHERE dev_id=?", [$dev['id']])['c'];
                $dl = Database::fetch("SELECT COUNT(*) c FROM downlinks WHERE dev_id=?", [$dev['id']])['c'];
                return ['device' => $deviceView($dev), 'counts' => ['uplinks' => (int) $up, 'downlinks' => (int) $dl]];
            }
            if ($method === 'POST') {
                $body['app_id'] = $appId;
                $r = WebApp::createDevice($body);
                if (isset($r['error'])) {
                    http_response_code(400);
                    return $r;
                }
                http_response_code(201);
                return $r;
            }
            return ['data' => array_map($deviceView, WebApp::listDevices($appId))];

        case 'gateways':
            if (isset($segs[1]) && $segs[1] !== '') {
                $gwId = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $segs[1]));
                $gw = $resolveGateway($gwId);
                if (!$gw) {
                    http_response_code(404);
                    return ['error' => 'gateway not found or forbidden'];
                }
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateGateway($gwId, $body);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    return ['gw_id' => $gwId, 'updated' => true];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteGateway($gwId);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    return ['gw_id' => $gwId, 'deleted' => true];
                }
                return ['gateway' => $gatewayView($gw)];
            }
            if ($method === 'POST') {
                $r = WebApp::createGateway($body);
                if (isset($r['error'])) {
                    http_response_code(400);
                    return $r;
                }
                http_response_code(201);
                return $r;
            }
            return ['data' => array_map($gatewayView, WebApp::listGateways())];

        case 'uplinks':
            $devId = 0;
            if (!empty($get['dev_eui'])) {
                $d = $resolveDevice((string) $get['dev_eui']);
                if ($d) {
                    $devId = $d['id'];
                }
            }
            return ['data' => WebApp::listUplinks($devId ?: null, $appId, $limitOf('limit'))];

        case 'downlinks':
            $devId = 0;
            if (!empty($get['dev_eui'])) {
                $d = $resolveDevice((string) $get['dev_eui']);
                if ($d) {
                    $devId = $d['id'];
                }
            }
            return ['data' => WebApp::listDownlinks($devId ?: null, $appId, $limitOf('limit'))];

        default:
            http_response_code(404);
            return ['error' => 'not_found', 'message' => "未知端点：/$sub"];
    }
}

function renderPage(): string
{

    $lang = Setting::get('ui_lang', 'zh');

    $lang = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$lang);
    if ($lang === '') {
        $lang = 'zh';
    }
    $dict = [];
    $dictFile = dirname(__DIR__) . '/lang/' . $lang . '.php';
    if (is_file($dictFile)) {
        $loaded = require $dictFile;
        if (is_array($loaded)) {
            $dict = $loaded;
        }
    }
    $dictJson = json_encode($dict, JSON_UNESCAPED_UNICODE);
    if ($dictJson === false) {
        $dictJson = '[]';
    }
    $i18nHead = '<script>window.UI_LANG=' . json_encode($lang) . ';window.I18N=' . $dictJson . ';</script>';
    return $i18nHead . <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HolaStack</title>
<link id="faviconLink" rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2064%2064%22%3E%3Cimage%20href%3D%22data%3Aimage%2Fpng%3Bbase64%2CiVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAOiUlEQVR4AdxaCXgURRb%2BqzqQRARiQA5ZAdGIICL3oYmg4oGI5wp4oCIerLh4gecnsOq6Kiq4q%2BuC56e4Joh4oMKyHisg4geCq%2BCtoHhwe6AgJN21%2Fz9h4mSYnplMgpLtb2q6u%2BrVu%2BrVq1ev2qKK1yHTXKfCYv%2BKouKyZwqLSxexfFhYXLa6sKRsc1FJmfs1imhFaBaXknbpIvFSVOJfLt6qKA7SUkDRNNeeBB8g4Q2e85ca4%2B6EwYnGmG4s%2BxuDpgbIrSrxTOFFK0LTGNI23cQL4O4Sb%2BRxrXgVz%2BngT6qAwpLSfkQ4C85fToLDSTg%2FHaS%2FJQx53FO8imcq4sVDS0oPT8ZPYgU4ZwqL%2FesNzGwDHJsMQbptIrR7HaB5PaBnM%2BD4fYCT9jM4cm%2BgQyOgUQ6Q7aWLLT04KqK%2Fhfk3p8e1oEyJetn4yu7TXLPCEv8FmvnNbKsRlnpQ4NuKDB462uLRYy1uL7K4qruHK7pYjOvt4Z4jLB4nq5P7WYzqbNAom5Rr7kcZ3C2Sqfc0lx%2BPtpICuk52dXJc2XPSXDxgVd9pOejYGJjYx2ACBe7Z3KJZPcNRNjDGVEJn%2Bb5bHYM2DQ1%2BX2DxxACL0woM6tFiKgFW44Uk%2Bme5slntp7m6sWhs7Etuw7IJgOmOal4S7%2BKDDSb2tejSxOwgcCr0OVkGIzsZTBtg0Kp%2BKuiqtJse%2BUHZ7bE9KhTQe7rrTE5HxTZm8pxP8%2F1Tb4PT9jeoY6sufJSm5ZDVr2txZx%2BLXpxC0fpq340ZVVTsukTxVCggq8wfZwD%2BkPFl2fuyLgZ997awFCBjRDEdm%2BxmcE0Pi2a7xVRW45Es8uePjaKIKECj7wxOiFZmcidW%2FJFme9jv9JQJhvA%2B%2BTkGdxxmoFUkHCr9Fsl62HRXoB4RBXD0rybb%2FKkqs9KO%2FnXAPqbGRh5xV8sGFsMPrBaLFRiJxTjfv14Vtu%2FDLocPJ7Jk%2FCNCjDzYQs4rYyRpdDyRcUONWQEwiMtiri3NRSfOfCkBmV6dmgAdGksNmWJIr1%2BWNbiUcUJ60MmhyG2uDcBhQ9ArOWjyViLCgNYGNeTzkhNja1ELgwZ1EYkRFD3ukQ3slgVOPTZW%2BRf0shauygrIp70U7gUM45w8vS3QNt9UmXSmHXIorCJHRZWx5e9HMKxuiSopQrJbB9c6HWZyPGBgG%2BBuBjczBlrcUuhRARYjDvbQqoFJB0WNwFiaWmvSa86ocg%2BuDo1yDfba3aB9I4uxPS3u72fQu3k4qdgWyW5hkIcUV10K%2F5dCgzHdPHRmZCcmUnT5TZqNMSjYw%2BK2Ig9nHQCKhuQXZacVmKQKaM4A5IGjLLo2tcmR7WKt53WwOHk%2Fk5wrZ%2FI0mKEKqF8HuOlQC5kcatmlFWNUZxvZkIWxToPJ07BmhwEont9%2FjxRaDOu8C9Rbsj60nUQMZSY7tDWLLUe1IobQvrWjoXMToAWTMGHcUszETT2bsiO9a%2BLW2lNb1zM4%2FYDwgQxVgNJVtUfM5Jz2a2nAnXVCIJuolvkI7MPsTKK22linbFNYdimhAjzWhnWojQoQz3UZy%2BgeXyhqfBXgO%2BCnUv7t2FQra5xzlCcx6wkVUBYAH25M3KE21q7apAFNzHlCBQh0xieMlPXwf1BKPgyXJVQBS9YCK76v%2FdNg%2FWaHWZ%2BHyxGqAHWZ%2FhHnQi23gNkrHTSlw8QIVYA6zF4JzFkZQE5E77WpaACXrAkw9QM9hXOeVAFl7DvhLYf%2FrnMI6EnD0exaLRqwlZy%2B4xY6bC5LzltSBajrVh%2B4cq7DxCW1ZzpMfT%2FAiJcDfL9VEiQvKRWg7qWU%2FdlPgX7TfQj53C8DfPqdw8af0y8btjhsILxKbD%2FVr6OjWsf2jSqEiW1P9az%2B4mXB1wGe%2FjjAqc%2F5uH8ZsCXFyEsulbQUIECVbVTElHcdbljgcNFLAU5%2FMf1yxqwAZxBeJbaf6s9k25lsO5332LZ0noVPvFz3Oq10qcO6n8Vp%2BiVUATrjO7%2BDiZzbx6Oja4CUIS3XRPmZ00wlI1zsK14CMRXHaENmj89pb9CyflxDzGuoAhrlAme3t5g%2B0MONPOxs0wDQJimm7y75qI1vi90ROUV6isnb4UyNtU2S1LGppOB2Gjrs1McLkw63aWdcU%2BHdGe1tmdzTxxdT%2BlmcfaBFXTFPQltoJbwl%2FIUqIH7Vy%2Bbwd%2BTpz61Mhz9yjIXOAfeklSTE%2BitW6qisX0vgr30NJh%2FloWdzg%2Fp1TaWMcEDfFcZSqALCOjCRCH3JcXV3i5IBHnQcHpZsCMNRE%2FViXIcyMzhFx%2Fby0KmJrdKhSJQH4Yk%2BV7p%2F9j0PQZb4WP2TYyRYqaniRXnDk%2Fe1eOYEixt6msjHThWNO%2Blhb87vMV0NZpDmiI4eD2TDCf24zeGBZT4WrUngIbd3C1WArOapTwAtU%2Fe87WPVJpcwGpRF7E6TO6qVxb1HepjEk6N9GwK5Wdsp1MBNFta6ATC%2Bl8Fj%2FT0MpNLzeSok2vHoFQV%2B9aPD4wyGTn0%2BwKPvAYpj4uGi76EKiAJoI%2FHkx%2FSqcwJc%2FEqApWulmmhr5bvhq74J0kGKHNGZTEaqjtUZ%2F45hZvo%2BOrUHeThzeMvkZv7BxgCX%2FifAcPI6mfGKltVUhEMVUJ9r6AltDPKyy1FonX5vA3D5aw5%2FeNnHs58G%2BJZRW3lr5X%2BPCXmdF154UPn0GMa1uCCvMkyytya5PLwvMHjiOItre1gU5BnUoUdPpMzvtzrM%2FCzAJa%2F4DH8d3l4HROP%2F%2FBxEnHVTnm6F0QtVgHLpo7sxDjje4jKeyUshYkABx3Iq4k5ukoa8EOCR5T42ca7J9OKJyER1gDmMa%2FH99NB3HGYh4aifeNDIu%2FKQF3eU4B4u4alOC6blE8GK1mam7BSWDyIPExY7vLMenKIRNJFPaS48yKCYCpSz3jdJglcKSLhlkOkLndbSU%2FTtXn%2BL63oYdOVBg%2BpVtL4%2BtBw4iyHshMUBPuMOTPWJigTp0cxgKvHcfIiBjtejcO3ygWu6lzM85ADL0Ubo9Q3n9yRuzIbODqCwPNbMC%2FKA0XSQ%2BujyzHaWDlJDFopKDVst1%2Fvv9JSqNMg2OKa1xV19PB5BW%2BhzV50dqt%2B3VOHzK4Bh%2Fwpw1VyfoxEwCZnY8%2BYwnihsYfHnQz1Mp3VNPdbiPjrP4%2FaxaEgawhdftjJL%2B%2F4Gh5vf9CP7j6e5MVu3pRxKzrZjY0Q%2BxpzSz8MJdJCyupSis7tktzAuLQUQPvKTWe%2Bfb3BrocWDR1sMbQfUsZEmON4WrgZGveoiylBGKSyPIDz6BK5lA5N0%2FX6NO88L6NRGvhpgzuc0c5RfIjmwDSCHO4krj6zLs0B5a%2BV%2F8VW5JvrmVltynVABK38AHnsvwOc%2FuB3iAGnXo003q2dwfgePAZHFeQeaik2H%2FMTqzcDf3nbQbu2JDwJoadLcjZJOdtcWV052%2BBwf495wWMmsbnRKNs4FTuGx98OMRkd39bB3fYMs8iKFxuLk6ELb7Ke4RX4rJA4wxlABxrwd2zH6rGzQ%2FcsczqVZ30TTE1O%2BsEYBtt%2BNARrnGpzL2FtrtDZOeVw56LSpW%2BDrn4D73nHQlvfGhQHWc8%2BfCI%2BUo7OIye%2F4kGOTk%2F2YQyNlkkTkm6CRBxs8ebyiTwudXIn2djYqbrI4rQyTlpRPl7u5RdZusQIg5oGWsdISycKYuh0eOf3w0hflAdFVcwO8%2BkVQ4W3jgcWoNk5Pcm7f1cfimFaoiMklyMuriIf7fuGZ%2FxXJo%2Fx6lym38W8EGEyP%2FvgHqBS4dG8K3HyoxZMDLAa1tZBiy3vt%2BL90rcPYBeV5B%2FmJMMGjPSW7LbNeUgVEgeVtF60Bxi10OGe2jxe49ipMjrbH3rPJZecmhquGR69vcCLjieinroonhOe61wOcMtPHaSyXcH6%2F%2BiXww7ZyLNrH92kB6MOn24s8FLUwULQpBZdD%2FPL%2FHWORuV8FkTjgMgZBc78CNpX%2B0p7sSbLbN5bhI9rq%2BmSA8W2fc07ezrV3BPNuk94KoDggHkbv1DDnqMXlXJqmMJob081AwqlNZT09%2BRqWqC1wgcBQRo9yruN6W3RozBGXtxNwXCmlaZbQt1xIHsYyQ6U4IIonDjTsdYNktxhvAmfwFKp4idhGpp9mfOqggEhxwFKGyYnmt%2BZZHmP3gW0simnK13LN7xITT%2BzH9fsiBi7%2FZOByQUcLrQ5ybPEsyU%2FosGbyOwHOYOxxL33LavqYQMzEA6d4Z5fpGG%2BCiH6t8%2B5mBX%2FI6JLJzfzMMQ53XAIDfPRtwKRkYnT16hj055o%2Fqa8HhboPcyl9gFGiAhetKokY2MbRXrUpwFj6iXPolB9nrn8NV5lEsOnUkTPnjDdRsBEFzB1i3uf8mqOK6pZ314MJU4cRTJpqR1aaZHgU6u7LON%2BSeBjdF1cEVGzAuMLhNfqJMLiq1JPcnNcHmQ%2FVJ6IAPfjOG01fQIPSW%2FUKBwwrGEdoRybPrph9JeMJLVGpMMvM1zBNLic7dJaPWxc5LOfeI5VHT4U32s7R3wzjXRF9r1DAgiFmGYwZHW2oqbscnWL28xnNXTc%2FwFoKF4ZbCYw7FgdcZQLIycrZhsFmWm8MRs4bZN6L9q9QgCrmDfb%2BwXuVHSL7pPxpBBd8g0hAdM18H6%2BsCqAY3%2BcUefObAOMW%2BJEAaCb3FNrOcqRS4qwygEPJvEFZj8T2q6QANZQZbyinQomed0bRUduCr4HxDHFPejbAyTMDjJnnoDjgxzTX74z4ovBc94fF991BAW8MMlvmDckaAhjNkyQJZVT7%2BonHV99xJ1ltRMkR%2BAHMGMkk2eJBd1BAFIDTYWJgvAOdw2Os26mKIP6d8RPPUyXD64O9O8IIhCpAHbRUzB%2BSdbYxXgEVMYXzcpf%2Fckg8ilfxPG9w1lDJIFnCSlIFRDvNHWRWUBEXzR%2Bc1cg3XmeA08PhWS5Zi1k%2BArCahBnU8ulX%2BIkWhVwj2iyL6bPIi7lSvIlH8Sqe02HlfwAAAP%2F%2Ffv%2BGxAAAAAZJREFUAwCEfganlN6elQAAAABJRU5ErkJggg%3D%3D%22%20width%3D%2264%22%20height%3D%2264%22%2F%3E%3C%2Fsvg%3E">
<link rel="stylesheet" href="/assets/app.css">
<script>

(function(){
  var saved = localStorage.getItem('elw_theme');
  if(!saved){
    saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  document.documentElement.setAttribute('data-theme', saved);

  var _ul = window.UI_LANG || 'zh';
  document.documentElement.setAttribute('lang', _ul === 'en' ? 'en' : (_ul === 'zh' ? 'zh-CN' : _ul));
})();
</script>
</head>
<body>
<header class="hidden" id="topbar">
  <div class="brand-block">
    <h1 id="brand"><a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit">HolaStack</a></h1>
    <span id="pageTitle" class="brand-sub"></span>
  </div>
  <nav id="deskNav" class="desk-nav"></nav>
  <div class="spacer"></div>
  <img id="avatar" class="avatar" alt="avatar" src="https://gravatar.webp.se/avatar/00000000000000000000000000000000?s=40&d=mp">
  <span class="who" id="who"></span>
  <button class="ghost" id="themeToggle" onclick="toggleTheme()" title="切换主题" style="padding:7px 9px;line-height:1;display:inline-flex;align-items:center;justify-content:center"></button>
  <button class="ghost tb-account" onclick="changePw()"><svg class="hi" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"> <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/> </svg>修改密码</button>
  <button class="ghost tb-account" onclick="logout()"><svg class="hi" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"> <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/> </svg>退出</button>
  <button class="hamburger" id="navToggle" aria-label="菜单" onclick="toggleNav()"><svg class="hi" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button>
  <div id="mobilePanel" class="mobile-panel"></div>
</header>

<div id="login" class="hidden">
  <div class="box">
    <div id="loginLogo" class="login-logo"></div>
    <h3>登录</h3>
    <label>用户名</label><input id="l_user">
    <label>密码</label><input id="l_pass" type="password">
    <div id="l_err" class="muted" style="color:var(--err)"></div>
    <button style="margin-top:16px;width:100%" onclick="doLogin()">登录</button>
    <div id="loginNotice" class="login-notice hidden"></div>
    <footer id="loginFooter" class="site-footer login-footer"></footer>
  </div>
</div>

<main id="view" class="hidden"></main>
<footer id="siteFooter" class="site-footer hidden"></footer>

<nav id="floatDock" class="float-dock hidden">
  <div id="logRefreshBox" class="logrefresh-float"></div>
  <div id="floatPrimary"></div>
  <button class="float-btn" onclick="pageTop()" title="回到顶部"><svg class="hi" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg></button>
  <button class="float-btn" onclick="pageBottom()" title="回到底部"><svg class="hi" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg></button>
</nav>

<div class="modal" id="modal"><div class="box" id="modalBox"></div></div>
<div id="loader"><div class="spinner"></div></div>

<script src="/assets/js/icons.js"></script>
<script src="/assets/js/core.js"></script>
<script src="/assets/js/table.js"></script>
<script src="/assets/js/views.js"></script>
<script src="/assets/js/forms.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/loracalc.js"></script>
<script src="/assets/apidocs.js"></script>
</body>
</html>
HTML;
}
