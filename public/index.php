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

// ChirpStack 兼容格式零值时间戳：必须声明在所有 API 分发块之前——
// const 是运行时语句，/api/、/v1/ 分支处理完即 exit，下方的 cs_* 帮助函数
//（定义于文件后部）被调用时若还没执行到声明处会报 Undefined constant。
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

    // ChirpStack 风格 API（v4 REST 形状：totalCount/result、camelCase、UUID id、gRPC 错误）
    // 由 handleApi() 原生输出，全部 /api/* 资源统一形状
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

/* =====================================================
 * ChirpStack 风格 API 辅助（handleApi 专用）
 * 列表：{totalCount, result}；字段 camelCase；id 为 UUID；
 * 时间：RFC3339 UTC（零值 1970-01-01T00:00:00Z）；
 * 错误：{error, code, message, details}（gRPC code）
 * ===================================================== */

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
        return (int) $uuid;  // 兼容前端直接传数字 id
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

/** 列表响应包装 */
function cs_list(array $result, int $totalCount): array
{
    return ['totalCount' => $totalCount, 'result' => $result];
}

/** 空对象（ChirpStack 对空 map 输出 {}） */
function cs_obj(): \stdClass
{
    return new \stdClass();
}

/** camelCase 行字段公共部分（id/createdAt/updatedAt） */
function cs_rowBase(array $row): array
{
    $t = (int) ($row['created_at'] ?? 0);
    return [
        'id'        => cs_intToUuid((int) $row['id']),
        'createdAt' => cs_ts($t),
        'updatedAt' => cs_ts($t),
    ];
}

/** 把 WebApp 返回的 ['error'=>...] 统一转 gRPC 错误；非错误返回 null */
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

function handleApi(string $method, string $path): array
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

    // ChirpStack 风格 camelCase 分页参数（limit/offset 兼容，tenantId→tenant_id 等）
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
        return ['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'email' => $u['email'] ?? '', 'avatar_url' => WebApp::avatarUrl($u['email'] ?? ''), 'role' => $u['role'], 'role_id' => (int) ($u['role_id'] ?? 0), 'department_id' => (int) ($u['department_id'] ?? 0), 'permissions' => $u['permissions']], 'token' => $token];
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
    $isDownlink = ($resource === 'devices' && ($segs[2] ?? '') === 'downlink');
    $isPwChange = ($resource === 'users' && ($segs[1] ?? '') === 'password');
    $isMulticastEnqueue = ($resource === 'multicast-groups' && ($segs[2] ?? '') === 'enqueue');
    $adminOnlyResource = in_array($resource, ['users', 'tenants', 'settings'], true);
    

    if ($isWrite && $adminOnlyResource && !$isPwChange) {
        Auth::guardApi(Auth::ROLE_ADMIN);
    } elseif ($isWrite && !$isDownlink && !$isPwChange && !$isMulticastEnqueue) {
        Auth::guardWrite();
    } else {
        Auth::guardApi(Auth::ROLE_OPERATOR);
    }
    

    if (!$isWrite && $resource === 'tenants') {
        Auth::guardApi(Auth::ROLE_ADMIN);
    }

    /* ---------- ChirpStack 风格行映射器 ---------- */

    // apiApplication
    $applicationRow = static function (array $a, bool $listItem) use ($camelParam, $get): array {
        $row = array_merge(cs_rowBase($a), [
            'name'        => $a['name'] ?? '',
            'description' => $a['description'] ?? '',
            'tenantId'    => cs_intToUuid((int) ($a['tenant_id'] ?? 0)),
            'tags'        => cs_obj(),
        ]);
        // holastack 扩展字段保留（camelCase）：appEui / callbackUrl 供前端与既有集成使用
        $row['appEui'] = $a['app_eui'] ?? '';
        $row['callbackUrl'] = $a['callback_url'] ?? '';
        // holastack 扩展：numericId（前端按数字 id 过滤/关联，与 devices/apiKeys 一致）
        $row['numericId'] = (int) ($a['id'] ?? 0);
        return $row;
    };

    // apiDeviceListItem / apiDevice（get 用）
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
            // holastack 扩展（camelCase 保留，便于前端继续按 id 过滤/操作）
            $row['id'] = cs_intToUuid((int) $d['id']);
            $row['numericId'] = (int) $d['id'];
            $row['applicationId'] = cs_intToUuid((int) ($d['app_id'] ?? 0));
            $row['devAddr'] = $d['dev_addr'] ?? '';
            $row['activation'] = $d['activation'] ?? '';
            $row['classEnabled'] = strtoupper($d['class'] ?? 'A') === 'B' ? 'CLASS_B' : (strtoupper($d['class'] ?? 'A') === 'C' ? 'CLASS_C' : 'CLASS_A');
            $row['region'] = $d['region'] ?? '';
            $row['isDisabled'] = ($d['status'] ?? '') === 'disabled';
            $row['online'] = ($d['online'] ?? '') === 'online' ? 'ONLINE' : 'OFFLINE';
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
            // holastack 扩展
            'numericId'       => (int) $d['id'],
            'devAddr'         => $d['dev_addr'] ?? '',
            'activation'      => $d['activation'] ?? '',
            'region'          => $d['region'] ?? '',
            'online'          => ($d['online'] ?? '') === 'online' ? 'ONLINE' : 'OFFLINE',
            'codec'           => $d['codec'] ?? '',
            'nwkSKey'         => $d['nwk_s_key'] ?? '',
            'appSKey'         => $d['app_s_key'] ?? '',
            'nwkKey'          => $d['nwk_key'] ?? '',
            'appKey'          => $d['app_key'] ?? '',
        ]);
    };

    // apiGatewayListItem / apiGateway
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
            'location'    => cs_obj(),
            'properties'  => cs_obj(),
            'tags'        => cs_obj(),
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
        // holastack 扩展
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
            if (isset($segs[1]) && $method === 'PUT') {
                $id = cs_uuidToInt((string) $segs[1]);
                // PUT 全量语义：ChirpStack 只传 application 对象，回填旧记录避免误清字段
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
                return ['id' => cs_intToUuid((int) $r['id'])];  // ChirpStack create：200 + {id}
            }
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listApplications($tid !== null ? (int) $tid : null);
            if (($get['applicationId'] ?? $get['app_id'] ?? '') !== '') {
                $rows = array_values(array_filter($rows, fn($a) => (int) $a['id'] === (int) ($get['applicationId'] ?? $get['app_id'])));
            }
            return cs_list(array_map(fn($a) => $applicationRow($a, true), $rows), count($rows));
        case 'devices':
            if (($segs[1] ?? '') === 'import' && $method === 'POST') {
                $r = WebApp::importDevices((int) ($body['app_id'] ?? ($body['applicationId'] ? cs_uuidToInt((string) $body['applicationId']) : 0)), $body['raw'] ?? '', $body['format'] ?? 'csv');
                if ($e = cs_wrapError($r)) { return $e; }
                return $r;
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'downlink' && $method === 'POST') {
                // ChirpStack Enqueue 语义：body.queueItem {fPort, data(Base64), confirmed}；兼容 holastack 原生 {port, payload(hex)}
                $in = $body['queueItem'] ?? $body['deviceQueueItem'] ?? $body;
                $payload = (string) ($in['data'] ?? '');
                if ($payload !== '' && !ctype_xdigit($payload)) {
                    $bin = base64_decode($payload, true);
                    if ($bin === false) {
                        return cs_invalid('data must be Base64 or hex');
                    }
                    $payload = bin2hex($bin);
                }
                // 路径段可能是数字 id（前端用 numericId）或 UUID（ChirpStack 风格）
                $devPathId = ctype_digit((string) $segs[1]) ? (int) $segs[1] : cs_uuidToInt((string) $segs[1]);
                $r = WebApp::enqueueDownlink(
                    $devPathId,
                    (int) ($in['fPort'] ?? $in['port'] ?? 0),
                    $payload !== '' ? $payload : (string) ($in['payload'] ?? ''),
                    !empty($in['confirmed']),
                    !empty($in['mac'])
                );
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => (string) $r['id']];  // ChirpStack Enqueue 返回 {id}
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'fields' && $method === 'GET') {
                return WebApp::deviceFields((int) $segs[1]);
            }
            if (isset($segs[1]) && $method === 'PUT') {
                $r = WebApp::updateDevice(cs_uuidToInt((string) $segs[1]), $body);
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                $r = WebApp::deleteDevice(cs_uuidToInt((string) $segs[1]));
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                $in = $body['device'] ?? $body;
                // ChirpStack camelCase 入参 → holastack snake_case
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
                $r = WebApp::createDevice($in);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['devEui' => $in['dev_eui'] ?? ''];  // ChirpStack DeviceService.Create 返回 {devEui}
            }
            $appId = isset($get['applicationId']) ? cs_uuidToInt((string) $get['applicationId']) : (isset($get['app_id']) ? (int) $get['app_id'] : null);
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listDevices($appId, $tid);
            return cs_list(array_map(fn($d) => $deviceRow($d, true), $rows), count($rows));
        case 'gateways':
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
                return ['gatewayId' => $r['gw_id'] ?? (string) ($in['gw_id'] ?? '')];  // ChirpStack 返回 {gatewayId}
            }
            $tid = isset($get['tenantId']) ? cs_uuidToInt((string) $get['tenantId']) : (isset($get['tenant_id']) ? (int) $get['tenant_id'] : null);
            $rows = WebApp::listGateways($tid !== null ? (int) $tid : null);
            return cs_list(array_map(fn($g) => $gatewayRow($g, true), $rows), count($rows));
        case 'device-profiles':
            if (isset($segs[1]) && $segs[1] !== '') {
                if ($segs[1] === 'adr-algorithms' && $method === 'GET') {
                    $algos = [
                        ['id' => cs_intToUuid(1), 'name' => 'Default ADR algorithm (LoRaWAN MAC)'],
                        ['id' => cs_intToUuid(2), 'name' => 'Disable ADR'],
                    ];
                    return cs_list($algos, count($algos));
                }
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
                    // holastack 扩展
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
                    // holastack 扩展
                    'numericId'         => (int) $p['id'],
                ]);
                $dpList[] = $item;
            }
            return cs_list($dpList, count($dpList));

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
            $out = cs_list($roles, count($roles));
            if (isset($rolesRaw['catalog'])) { $out['catalog'] = $rolesRaw['catalog']; }
            return $out;

        case 'departments':
            if (isset($segs[1]) && $segs[1] !== '') {
                $id = cs_uuidToInt((string) $segs[1]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $r = WebApp::updateDepartment($id, $body);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                if ($method === 'DELETE') {
                    $r = WebApp::deleteDepartment($id);
                    if ($e = cs_wrapError($r)) { return $e; }
                    return [];
                }
                return cs_err(12, 'unimplemented', 'method not allowed');
            }
            if ($method === 'POST') {
                $r = WebApp::createDepartment($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $depts = WebApp::listDepartments();
            $depts = $depts['data'] ?? $depts;
            return cs_list($depts, count($depts));

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
                return ['id' => cs_intToUuid((int) $r['id']), 'token' => $r['token'] ?? ''];  // token 仅 create 时返回一次（与 ChirpStack 一致）
            }
            $keys = WebApp::listApiKeys($appId, $tenantId);
            $keyRows = array_map(fn($k) => array_merge(cs_rowBase($k), [
                'name'            => $k['name'] ?? '',
                'applicationId'   => cs_intToUuid((int) ($k['application_id'] ?? 0)),
                'tokenPreview'    => $k['token_preview'] ?? '',
                'isActive'        => true,
                'tenantId'        => cs_intToUuid(0),
                'displayName'     => $k['name'] ?? '',
                // holastack 扩展
                'numericId'       => (int) $k['id'],
            ]), $keys);
            return cs_list($keyRows, count($keyRows));

        case 'stream':
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) { ob_end_flush(); }
            ignore_user_abort(true);
            $lastId = (int) ($get['after'] ?? 0);
            $deadline = time() + 55;
            while (time() < $deadline) {
                if (connection_status() !== 0) { break; }
                $rows = Database::fetchAll(
                    "SELECT id, type, level, gateway_id, dev_id, message, created_at FROM events WHERE id>? AND app_id=? ORDER BY id ASC LIMIT 50",
                    [$lastId, $appId]
                );
                foreach ($rows as $ev) {
                    $lastId = (int) $ev['id'];
                    $payload = json_encode([
                        'id' => (int) $ev['id'],
                        'type' => $ev['type'],
                        'level' => $ev['level'],
                        'gateway_id' => $ev['gateway_id'],
                        'dev_id' => (int) $ev['dev_id'],
                        'message' => $ev['message'],
                        'created_at' => (int) $ev['created_at'],
                    ], JSON_UNESCAPED_UNICODE);
                    echo "data: " . $payload . "\n\n";
                }
                flush();
                if (empty($rows)) { sleep(1); }
            }
            echo "event: eof\ndata: {}\n\n";
            flush();
            exit;

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
                    // holastack 扩展
                    'devId'       => (int) ($u['dev_id'] ?? 0),
                    'appId'       => (int) ($u['app_id'] ?? 0),
                    'payloadHex'  => $u['payload_hex'] ?? '',
                    'decryptedHex'=> $u['decrypted_hex'] ?? '',
                    // holastack 扩展（帧检视/原始报文需要）：确认位、完整 PHYPayload(hex)、网关协议原文
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
                // 越权校验：非管理员只能取消自己租户可见应用的下行（兼容旧行为里误用的 $appId）
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
                    // holastack 扩展
                    'devId'     => (int) ($d['dev_id'] ?? 0),
                    'appId'     => (int) ($d['app_id'] ?? 0),
                    'payloadHex'=> $d['payload_hex'] ?? '',
                    'acknowledgedAt' => cs_ts($d['acknowledged_at'] ?? 0),
                    // holastack 扩展（下行日志页需要）：小写状态、MAC 帧标记、重传次数、发送时间、网关协议原文
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
                    // holastack 扩展（事件原始报文需要）
                    'rawJson'   => (string) ($e['raw_json'] ?? ''),
                ];
            }, $evs);
            return cs_list($evRows, $total);
        case 'stream':
            Auth::guardApi(Auth::ROLE_OPERATOR);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) { ob_end_flush(); }
            ignore_user_abort(true);
            $lastId = (int) ($get['after'] ?? 0);
            $deadline = time() + 55;
            while (time() < $deadline) {
                if (connection_status() !== 0) { break; }
                $rows = Database::fetchAll(
                    "SELECT id, type, level, gateway_id, dev_id, message, created_at FROM events WHERE id>? ORDER BY id ASC LIMIT 50",
                    [$lastId]
                );
                foreach ($rows as $ev) {
                    $lastId = (int) $ev['id'];
                    $payload = json_encode([
                        'id' => (int) $ev['id'],
                        'type' => $ev['type'],
                        'level' => $ev['level'],
                        'gateway_id' => $ev['gateway_id'],
                        'dev_id' => (int) $ev['dev_id'],
                        'message' => $ev['message'],
                        'created_at' => (int) $ev['created_at'],
                    ], JSON_UNESCAPED_UNICODE);
                    echo "data: " . $payload . "\n\n";
                }
                flush();
                if (empty($rows)) { sleep(1); }
            }
            echo "event: eof\ndata: {}\n\n";
            flush();
            exit;

        case 'users':
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
            if (($segs[1] ?? '') === 'password' && $method === 'POST') {
                $cur = Auth::currentUser();
                $target = (isset($body['user_id']) && $body['user_id'] !== '') ? (int) $body['user_id'] : (int) $cur['id'];
                $r = WebApp::changePassword($target, $body['new_password'] ?? '');
                if ($e = cs_wrapError($r)) { return $e; }
                return [];
            }
            if ($method === 'POST') {
                if (empty($body['username']) || empty($body['password'])) {
                    return cs_invalid('username and password required');
                }
                if (!in_array($body['role'] ?? 'operator', Auth::ROLES, true)) {
                    return cs_invalid('invalid role');
                }
                try {
                    $id = Auth::createUser(
                        $body['username'],
                        $body['password'],
                        $body['role'] ?? Auth::ROLE_OPERATOR,
                        (int) ($body['tenant_id'] ?? 0),
                        $body['new_tenant_name'] ?? null,
                        $body['email'] ?? null,
                        (int) ($body['role_id'] ?? 0),
                        (int) ($body['department_id'] ?? 0)
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
                    'departmentId'   => cs_intToUuid((int) ($u['department_id'] ?? 0)),
                    'departmentName' => $u['department_name'] ?? '',
                    'isAdmin'        => ($u['role'] ?? '') === 'admin',
                    'isActive'       => true,
                    // holastack 扩展
                    'numericId'      => (int) $u['id'],
                ]);
            }, $users);
            return cs_list($userRows, count($userRows));
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
                    // holastack 扩展
                    'numericId'     => (int) $i['id'],
                ]);
            }, $integrations);
            return cs_list($intRows, count($intRows));
        case 'multicast-groups':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'enqueue' && $method === 'POST') {
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
                    cs_uuidToInt((string) $segs[1]),
                    (int) ($in['fPort'] ?? $in['port'] ?? 0),
                    $payload !== '' ? $payload : (string) ($in['payload'] ?? '')
                );
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => (string) ($r['id'] ?? '')];
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
                    $r = WebApp::removeMulticastDevice(cs_uuidToInt((string) $segs[1]), strtolower((string) ($body['devEui'] ?? $body['dev_eui'] ?? '')));
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
                    $r = WebApp::removeMulticastGateway(cs_uuidToInt((string) $segs[1]), strtolower((string) ($body['gatewayId'] ?? $body['gw_id'] ?? '')));
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
                    // holastack 扩展
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
                $r = WebApp::createTenant($body);
                if ($e = cs_wrapError($r)) { return $e; }
                return ['id' => cs_intToUuid((int) $r['id'])];
            }
            $tenants = WebApp::listTenants();
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
                    // holastack 扩展
                    'numericId'           => (int) $t['id'],
                ]);
            }, $tenants);
            return cs_list($tenantRows, count($tenantRows));
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
            $out = ApiLog::list($u, $filters, $limit, $offset);
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
            http_response_code(404);
            return cs_err(12, 'unimplemented', 'unknown endpoint: /api/' . $resource);
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

    // 应用 API 分页辅助（与 handleApi 一致，否则 /v1/devices/{dev_eui}/uplinks 等会 500）
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
                    'GET    /v1/stream  (SSE 实时事件流)',
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
