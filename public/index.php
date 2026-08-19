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
        echo json_encode(['error' => 'server_error: ' . $e->getMessage()]);
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
        return ['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']], 'token' => $token];
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

    switch ($resource) {
        case 'stats':
            return WebApp::getStats();
        case 'regions':
            return ['regions' => WebApp::regions()];
        case 'settings':
            Auth::guardApi(Auth::ROLE_ADMIN);
            if ($method === 'POST') {
                Setting::setMany($body);
                return ['ok' => true, 'data' => Setting::getAll()];
            }
            return ['data' => Setting::getAll()];
        case 'applications':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateApplication((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteApplication((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createApplication($body);
            }
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listApplications($tid)];
        case 'devices':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'downlink' && $method === 'POST') {
                return WebApp::enqueueDownlink((int) $segs[1], (int) ($body['port'] ?? 0), $body['payload'] ?? '', !empty($body['confirmed']), !empty($body['mac']));
            }
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateDevice((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteDevice((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createDevice($body);
            }
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : null;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listDevices($appId, $tid)];
        case 'gateways':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateGateway($segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteGateway($segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createGateway($body);
            }
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listGateways($tid)];
        case 'uplinks':
            $devId = isset($get['dev_id']) ? (int) $get['dev_id'] : null;
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : null;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            return ['data' => WebApp::listUplinks($devId, $appId, $lim, $tid, $off), 'total' => WebApp::countUplinks($devId, $appId, $tid), 'limit' => $lim, 'offset' => $off];
        case 'downlinks':
            $devId = isset($get['dev_id']) ? (int) $get['dev_id'] : null;
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : null;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            return ['data' => WebApp::listDownlinks($devId, $appId, $lim, $tid, $off), 'total' => WebApp::countDownlinks($devId, $appId, $tid), 'limit' => $lim, 'offset' => $off];
        case 'events':
            $devId = isset($get['dev_id']) ? (int) $get['dev_id'] : null;
            $gwId = isset($get['gw_id']) ? trim($get['gw_id']) : null;
            $type = isset($get['type']) ? trim($get['type']) : null;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            $lim = $limitOf('limit'); $off = $offsetOf('offset');
            return ['data' => WebApp::listEvents($devId, $gwId, $type, $lim, $tid, $off), 'total' => WebApp::countEvents($devId, $gwId, $type, $tid), 'limit' => $lim, 'offset' => $off];
        case 'users':
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteUser((int) $segs[1]);
            }
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateUser((int) $segs[1], $body);
            }
            if (($segs[1] ?? '') === 'password' && $method === 'POST') {
                $cur = Auth::currentUser();
                $target = (isset($body['user_id']) && $body['user_id'] !== '') ? (int) $body['user_id'] : (int) $cur['id'];
                return WebApp::changePassword($target, $body['new_password'] ?? '');
            }
            if ($method === 'POST') {
                if (empty($body['username']) || empty($body['password'])) {
                    return ['error' => 'username and password required'];
                }
                if (!in_array($body['role'] ?? 'operator', Auth::ROLES, true)) {
                    return ['error' => 'invalid role'];
                }
                $id = Auth::createUser(
                    $body['username'],
                    $body['password'],
                    $body['role'] ?? Auth::ROLE_OPERATOR,
                    (int) ($body['tenant_id'] ?? 0),
                    $body['new_tenant_name'] ?? null
                );
                return ['id' => $id];
            }
            return ['data' => WebApp::listUsers()];
        case 'device-profiles':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateDeviceProfile((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteDeviceProfile((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createDeviceProfile($body);
            }
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listDeviceProfiles($tid)];
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
                return WebApp::updateIntegration((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteIntegration((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createIntegration($body);
            }
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : 0;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listIntegrations($appId, $tid)];
        case 'multicast-groups':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'enqueue' && $method === 'POST') {
                return WebApp::enqueueMulticast((int) $segs[1], (int) ($body['port'] ?? 0), $body['payload'] ?? '');
            }
            if (isset($segs[1]) && $method === 'GET' && !isset($segs[2])) {
                return WebApp::getMulticastGroup((int) $segs[1]);
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'devices') {
                if ($method === 'GET') {
                    return WebApp::multicastDevices((int) $segs[1]);
                }
                if ($method === 'POST') {
                    return WebApp::addMulticastDevice((int) $segs[1], $body['dev_eui'] ?? '');
                }
                if ($method === 'DELETE') {
                    return WebApp::removeMulticastDevice((int) $segs[1], $body['dev_eui'] ?? '');
                }
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'gateways') {
                if ($method === 'GET') {
                    return WebApp::multicastGateways((int) $segs[1]);
                }
                if ($method === 'POST') {
                    return WebApp::addMulticastGateway((int) $segs[1], $body['gw_id'] ?? '');
                }
                if ($method === 'DELETE') {
                    return WebApp::removeMulticastGateway((int) $segs[1], $body['gw_id'] ?? '');
                }
            }
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateMulticastGroup((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteMulticastGroup((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createMulticastGroup($body);
            }
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : null;
            $tid = isset($get['tenant_id']) ? (int) $get['tenant_id'] : null;
            return ['data' => WebApp::listMulticastGroups($appId, $tid)];
        case 'fuota':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'start' && $method === 'POST') {
                return WebApp::startFuotaCampaign((int) $segs[1], $body);
            }
            if (isset($segs[1]) && ($segs[2] ?? '') === 'devices' && $method === 'POST') {
                return WebApp::addFuotaDeployment((int) $segs[1], (int) ($body['dev_id'] ?? 0));
            }
            if (isset($segs[1]) && $method === 'GET' && !isset($segs[2])) {
                return WebApp::getFuotaCampaign((int) $segs[1]);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteFuotaCampaign((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createFuotaCampaign($body);
            }
            return ['data' => WebApp::listFuotaCampaigns()];
        case 'tenants':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateTenant((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteTenant((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createTenant($body);
            }
            return ['data' => WebApp::listTenants()];
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
            return ['data' => $out['rows'], 'total' => $out['total'], 'limit' => $limit, 'offset' => $offset];
        default:
            return ['error' => 'unknown endpoint'];
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
    


    switch ($sub) {
        case '':
            return [
                'service' => 'HolaStack application API',
                'version' => 'v1',
                'auth' => 'Authorization: Bearer <API_KEY> 或 ?api_key=<API_KEY>',
                'endpoints' => [
                    'GET    /v1/info',
                    'GET    /v1/devices',
                    'GET    /v1/devices/{dev_eui}',
                    'GET    /v1/devices/{dev_eui}/uplinks',
                    'GET    /v1/uplinks',
                    'GET    /v1/downlinks',
                    'POST   /v1/devices/{dev_eui}/downlink',
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
                

                $up = Database::fetch("SELECT COUNT(*) c FROM uplinks WHERE dev_id=?", [$dev['id']])['c'];
                $dl = Database::fetch("SELECT COUNT(*) c FROM downlinks WHERE dev_id=?", [$dev['id']])['c'];
                return ['device' => $deviceView($dev), 'counts' => ['uplinks' => (int) $up, 'downlinks' => (int) $dl]];
            }
            return ['data' => array_map($deviceView, WebApp::listDevices($appId))];

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
  <h1 id="brand"><a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit">HolaStack</a></h1>
  <span id="pageTitle" class="brand-sub"></span>
  <nav id="deskNav" class="desk-nav"></nav>
  <div class="spacer"></div>
  <span class="who" id="who"></span>
  <button class="ghost" id="themeToggle" onclick="toggleTheme()" title="切换主题" style="padding:7px 9px;line-height:1;display:inline-flex;align-items:center;justify-content:center"></button>
  <button class="ghost tb-account" onclick="changePw()">修改密码</button>
  <button class="ghost tb-account" onclick="logout()">退出</button>
  <button class="hamburger" id="navToggle" aria-label="菜单" onclick="toggleNav()">☰</button>
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

<div class="modal" id="modal"><div class="box" id="modalBox"></div></div>
<div id="loader"><div class="spinner"></div></div>

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
