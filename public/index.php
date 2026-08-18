<?php
/**
 * holastack Web 管理后台入口（同时作为 PHP 内置服务器 router 脚本）。
 * 启动：php -S localhost:8080 public/index.php
 *   - /install            安装向导（未安装时）
 *   - /login              通过 /api/login 接口完成登录（SPA 内）
 *   - /api/*              返回 JSON（除 login/logout/me 外均需登录；写操作需 admin）
 *   - 其它路径            单页管理界面（SPA）
 */
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
// PHP 内置开发服务器（php -S）：当请求静态资源（public/assets/*、favicon.ico、robots.txt 等）
// 时，路由脚本必须返回 false 才能让 server 直接发送文件，否则会被当作 PHP 处理并落到
// renderPage() 输出 HTML，让 <script src="/assets/*.js"> 拿到 SPA 整页、脚本解析失败。
// 生产环境（Nginx/Apache）由 web server 自身处理静态资源，本分支不执行。
if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . $path;
    if ($path !== '/' && is_file($staticFile)) {
        return false;
    }
}
// 标记：是否需要为本次请求写 API 日志（仅 /v1/* 记；管理界面 /api/* 不记，避免日志被自身调用淹没）
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
    // /v1 应用 API 鉴权后可回填 application_id；本钩子在响应发出后执行
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

// ---- 安装守卫 ----
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

// 已安装：确保表结构存在（含 auth_tokens 等新增表，对已存在库兜底）
Database::migrate();

// ---- 应用级开放 API（/v1/，使用 API 密钥鉴权，作用域限定到该 Key 所属应用）----
// 注意：$path 已 rtrim('/')，根路径为 '/v1'（对应请求 '/v1/'）
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

// ---- 视图渲染端点：返回后台视图的 HTML 片段（服务端翻译/样式统一） ----
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

// ---- API 路由 ----
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

// ============================================================
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
    array_shift($segs); // 去掉 'api'
    $resource = $segs[0] ?? '';
    $body = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) ? getJsonBody() : [];
    $get = $_GET;
    // 分页工具：admin/management 路由（/api/...）与 v1 应用路由（/v1/...）共用
    $limitOf  = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 50);
        return max(1, min($n, 500));
    };
    $offsetOf = static function (string $key) use ($get): int {
        $n = (int) ($get[$key] ?? 0);
        return max(0, $n);
    };

    // 公开端点
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
    // 公开接口：无需登录即可访问（静态配置，无敏感信息）
    if ($resource === 'public-settings') {
        return ['data' => Setting::getPublic()];
    }
    // 公开接口：语言包（无需登录即可访问，仅含界面译文）
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

    // 其余需登录。权限模型：
    //  - users/tenants/settings 为系统管理资源 → 仅 admin
    //  - 其他写操作（应用/设备/网关/模板/Key/外部集成/组播）→ admin 或 tenant（guardWrite），WebApp 层再按租户细粒度拦截
    //  - 只读 + 下行/组播入队 + 改自己密码 → operator 及以上
    $isWrite = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $isDownlink = ($resource === 'devices' && ($segs[2] ?? '') === 'downlink');
    $isPwChange = ($resource === 'users' && ($segs[1] ?? '') === 'password');
    $isMulticastEnqueue = ($resource === 'multicast-groups' && ($segs[2] ?? '') === 'enqueue');
    $adminOnlyResource = in_array($resource, ['users', 'tenants', 'settings'], true);
    // 改自己密码（users/password）不属于系统管理写操作，operator 及以上均可
    if ($isWrite && $adminOnlyResource && !$isPwChange) {
        Auth::guardApi(Auth::ROLE_ADMIN);
    } elseif ($isWrite && !$isDownlink && !$isPwChange && !$isMulticastEnqueue) {
        Auth::guardWrite();
    } else {
        Auth::guardApi(Auth::ROLE_OPERATOR);
    }
    // 租户列表属于系统管理数据：读取也仅限 admin（避免租户间信息泄露）
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
                return WebApp::enqueueDownlink((int) $segs[1], (int) ($body['port'] ?? 0), $body['payload'] ?? '', !empty($body['confirmed']));
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
            // 仅 admin / tenant / operator 可读；admin 可见全部，tenant 只能看自己 tenant_id 的日志
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

/**
 * 应用级开放 API（/v1/）：使用「API 密钥」鉴权，所有数据作用域限定到该 Key 所属应用。
 * 鉴权头：Authorization: Bearer <API_KEY>  或  ?api_key=<API_KEY>
 * 设备响应已剥离 app_key / nwk_s_key / app_s_key 等敏感密钥。
 */
function handleAppApi(string $method, string $path): array
{
    $segs = explode('/', trim($path, '/'));
    array_shift($segs); // 去掉 'v1'
    $sub = $segs[0] ?? '';
    $body = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) ? getJsonBody() : [];
    $get = $_GET;

    // ---- 鉴权：API 密钥 -> application_id ----
    $token = ApiKey::tokenFromRequest();
    $appId = $token ? ApiKey::validate($token) : 0;
    if (!$appId) {
        http_response_code(401);
        return ['error' => 'invalid_api_key', 'message' => '请在请求头携带 Authorization: Bearer <API_KEY> 或使用 ?api_key=<API_KEY>'];
    }
    // 回填到 API 日志上下文（用于审计：记录哪个应用发起的调用）
    if (isset($GLOBALS['__apiLogCtx']) && is_array($GLOBALS['__apiLogCtx'])) {
        $GLOBALS['__apiLogCtx']['application_id'] = (int) $appId;
    }
    $app = WebApp::getApplication($appId);
    if (!$app) {
        http_response_code(401);
        return ['error' => 'application_not_found'];
    }

    // 仅保留应用公开字段（不泄露密钥）
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
    // $limitOf / $offsetOf 由 handleApi() 顶部统一提供

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
            // /v1/devices/{dev_eui}[/uplinks|/downlink]
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
                    $r = WebApp::enqueueDownlink($dev['id'], $port, $payload, $confirmed);
                    if (isset($r['error'])) {
                        http_response_code(400);
                        return $r;
                    }
                    http_response_code(201);
                    return $r;
                }
                // 单设备详情
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
    // 注入当前语言包：window.UI_LANG（当前语言）/ window.I18N（中文→译文 字典）。
    // 放在 <!DOCTYPE> 之前，确保 <head> 内的 FOUC 脚本能据此设置 <html lang>。
    $lang = Setting::get('ui_lang', 'zh');
    // 语言白名单放开为「任意合法语言标识」，以支持 lang/ 下新增的多语言文件；含非法字符回退 zh
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
<style>
  /* ===== 主题变量：深色（默认） ===== */
  :root, [data-theme="dark"]{
    --bg:#0f1420; --panel:#1a2233; --line:#2b3650; --txt:#e6ecf5; --mut:#8b97ad;
    --acc:#3da9fc; --ok:#36d399; --warn:#fbbd23; --err:#f87272;
    /* 派生色（硬编码色替换） */
    --bg-deep:#0d1320;    /* input / pre 背景 */
    --bg-hover:#1f2740;   /* tr:hover */
    --bg-subtle:#161d2c;  /* th / hl-row */
    --bg-chip:#243049;    /* ghost btn / chip */
    --shadow-rgba:0,0,0;  /* 用于 rgba(N,N,N,.4) 的 RGB 分量 */
    --txt-on-acc:#04121f; /* 按钮文字色 */
    --tag-ok-bg:#103a2a; --tag-pending-bg:#3a3410; --tag-err-bg:#3a1620; --tag-off-bg:#2a2f3d;
    --tag-a-bg:#10304a; --tag-b-bg:#2a2a10; --tag-c-bg:#103a2a;
    --err-box-bg:#3a1620; --err-box-border:#5a2230; --err-box-txt:#ffd7d7;
  }
  /* ===== 主题变量：浅色 ===== */
  [data-theme="light"]{
    --bg:#f4f6fa; --panel:#ffffff; --line:#d0d6e0; --txt:#1a2332; --mut:#6a7588;
    --acc:#1a73e8; --ok:#1a9e5c; --warn:#c4810c; --err:#d63636;
    --bg-deep:#eef1f6;
    --bg-hover:#f0f3f8;
    --bg-subtle:#f7f9fc;
    --bg-chip:#e8edf4;
    --shadow-rgba:0,0,0;
    --txt-on-acc:#ffffff;
    --tag-ok-bg:#d4f0dd; --tag-pending-bg:#fcf0d0; --tag-err-bg:#f9dada; --tag-off-bg:#e8ebef;
    --tag-a-bg:#d8e8fa; --tag-b-bg:#f5f0d0; --tag-c-bg:#d4f0dd;
    --err-box-bg:#fce4e4; --err-box-border:#f0b0b0; --err-box-txt:#8b2020;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{display:flex;align-items:center;gap:14px;padding:12px 18px;background:var(--panel);border-bottom:1px solid var(--line);position:relative}
  header h1{font-size:15px;margin:0;color:var(--acc);flex:0 0 auto}
  #brand img{height:22px;vertical-align:middle}
  /* 桌面端：分类二级菜单（缩小尺寸） */
  .desk-nav{display:flex;gap:2px;align-items:center}
  .navgrp{position:relative}
  .navgrp-btn{background:none;border:none;color:var(--mut);padding:7px 11px;border-radius:6px;cursor:pointer;font-size:13px;font-family:inherit;display:flex;align-items:center;gap:4px}
  .navgrp-btn:hover,.navgrp.open .navgrp-btn{color:var(--txt);background:var(--bg-chip)}
  .navgrp .caret{font-size:10px;opacity:.7}
  .navsub{display:none;position:absolute;top:100%;left:0;min-width:150px;background:var(--panel);border:1px solid var(--line);border-radius:8px;box-shadow:0 8px 20px rgba(var(--shadow-rgba),.12);padding:6px;z-index:60;flex-direction:column}
  .navgrp.open .navsub{display:flex}
  .navsub a{padding:8px 10px;border-radius:6px;color:var(--mut);text-decoration:none;white-space:nowrap;font-size:13px}
  .navsub a:hover,.navsub a.active{color:var(--txt);background:var(--bg-chip)}
  /* 移动端面板容器（双列分类）初始隐藏 */
  .mobile-panel{display:none}
  .login-logo{text-align:center;margin-bottom:14px}
  .login-logo img{height:56px}
  .hamburger{display:none;border:1px solid var(--line);background:var(--bg-deep);color:var(--txt);font-size:18px;line-height:1;padding:6px 10px;border-radius:8px;cursor:pointer;flex:0 0 auto}
  .spacer{flex:1}
  .who{color:var(--mut);font-size:12px}
  main{padding:22px;max-width:1100px;margin:0 auto}
  .cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px 20px;min-width:140px}
  .card .n{font-size:26px;font-weight:700;color:var(--acc)} .card .l{color:var(--mut);font-size:12px}
  table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:10px;overflow:hidden}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);font-size:13px}
  th{color:var(--mut);font-weight:600;background:var(--bg-subtle)} tr:hover td{background:var(--bg-hover)}
  table.sortable th{cursor:default;user-select:none}
  table.sortable th[onclick]{cursor:pointer}
  table.sortable th[onclick]:hover{background:var(--bg-chip);color:var(--txt)}
  table.sortable th .sort-arrow{font-size:11px;display:inline-block;width:10px}
  table.sortable th select{font-size:12px;padding:2px 6px}
  table.sortable th select:hover{background:var(--bg-chip)}
  /* 右上角 toast 提示条：渐变卡片（与登录页公告框同风格），4 种状态色 + 复制成功专用色 */
  /*  之前引用 --bg-card/--ok-bg 等未定义变量 → background 失效 → 透明。改用 --bg-subtle/--panel 渐变 + 类型色边框/左侧色条。 */
  .toast{background:linear-gradient(135deg,var(--bg-subtle),var(--panel));color:var(--txt);border:1px solid var(--line);border-left-width:3px;border-radius:10px;box-shadow:0 4px 18px rgba(var(--shadow-rgba),.14)}
  .toast.ok{border-left-color:var(--ok)}
  .toast.info{border-left-color:var(--acc)}
  .toast.warn{border-left-color:var(--warn)}
  .toast.err{border-left-color:var(--err)}
  .toast.copy{border-left-color:var(--ok)}
  button,.btn{background:var(--acc);color:var(--txt-on-acc);border:0;padding:8px 14px;border-radius:7px;cursor:pointer;font-weight:600}
  button.ghost{background:var(--bg-chip);color:var(--txt)} button.danger{background:var(--tag-err-bg);color:var(--err)}
  input,select,textarea{background:var(--bg-deep);color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:8px 10px;width:100%;font-family:inherit}
  /* 自定义单选框 / 复选框（与主题一致；覆盖全局 width:100% 防止被拉伸成宽块） */
  input[type="checkbox"], input[type="radio"]{
    -webkit-appearance:none;appearance:none;-moz-appearance:none;
    width:18px;height:18px;min-width:18px;max-width:18px;padding:0;margin:0;
    background:var(--bg-deep);border:1.5px solid var(--line);border-radius:6px;
    display:inline-block;vertical-align:-2px;cursor:pointer;position:relative;flex:0 0 auto;
    transition:background .15s ease,border-color .15s ease,box-shadow .15s ease;
  }
  input[type="radio"]{border-radius:50%}
  input[type="checkbox"]:checked, input[type="radio"]:checked{background:var(--acc);border-color:var(--acc)}
  input[type="checkbox"]:checked::after{content:"";position:absolute;left:5px;top:1px;width:5px;height:10px;border:solid var(--txt-on-acc);border-width:0 2px 2px 0;transform:rotate(45deg)}
  input[type="radio"]:checked::after{content:"";position:absolute;left:5px;top:5px;width:6px;height:6px;border-radius:50%;background:var(--txt-on-acc)}
  input[type="checkbox"]:focus-visible, input[type="radio"]:focus-visible{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--acc) 25%,transparent)}
  label:has(input[type="checkbox"]), label:has(input[type="radio"]), .check{display:inline-flex;align-items:center;gap:8px;margin:10px 0 4px;color:var(--txt);font-size:13px;cursor:pointer}
  .mp-grid a.mp-danger{color:var(--err)}
  label{display:block;color:var(--mut);margin:10px 0 4px;font-size:12px}
  .row{display:flex;gap:12px;flex-wrap:wrap} .row>div{flex:1;min-width:200px}
  .modal{position:fixed;inset:0;background:rgba(var(--shadow-rgba),.55);display:none;align-items:center;justify-content:center}
  .modal.show{display:flex} .box{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:22px;width:min(560px,92vw);max-height:88vh;overflow:auto}
  .box h3{margin:0 0 8px}
  .tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;background:var(--bg-chip);color:var(--mut)}
  .tag.ok{background:var(--tag-ok-bg);color:var(--ok)} .tag.pending{background:var(--tag-pending-bg);color:var(--warn)} .tag.err{background:var(--tag-err-bg);color:var(--err)} .tag.off{background:var(--tag-off-bg);color:var(--mut)}
  .tag.A{background:var(--tag-a-bg);color:var(--acc)} .tag.B{background:var(--tag-b-bg);color:var(--warn)} .tag.C{background:var(--tag-c-bg);color:var(--ok)}
  .muted{color:var(--mut)} pre{background:var(--bg-deep);padding:10px;border-radius:8px;overflow:auto;font-size:12px} .hidden{display:none !important}
  #login{display:flex;align-items:center;justify-content:center;min-height:100vh}
  #login.hidden{display:none}
  #login .box{width:min(380px,92vw)}
  .login-notice{margin-top:14px;display:flex;gap:10px;align-items:flex-start;background:linear-gradient(135deg,var(--bg-subtle),var(--panel));border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--txt);white-space:pre-wrap;line-height:1.6;word-break:break-word}
  .login-notice.single{justify-content:center;align-items:center}
  .login-notice .ln-ico{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:color-mix(in srgb,var(--acc) 16%,transparent);color:var(--acc);margin-top:1px}
  /* ===== 概览页增强：三环形图 / 消息总览 / 日志两栏 ===== */
  .rings{display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px}
  .ring-card{flex:1 1 200px;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:18px}
  .ring{width:140px;height:140px;flex:0 0 auto}
  .ring-legend{flex:1;display:flex;flex-direction:column;gap:8px;min-width:0}
  .hl-row{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;border-radius:9px;background:var(--bg-subtle)}
  .hl-row b{font-size:17px} .hl-row .pct{color:var(--mut);font-size:12px;font-weight:400}
  .dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;vertical-align:middle}
  .hl-online .dot{background:var(--ok)} .hl-offline .dot{background:var(--err)}
  .msg-bar{display:flex;align-items:center;gap:26px;background:var(--bg-subtle);border:1px solid var(--line);border-radius:12px;padding:18px 26px;margin-bottom:16px}
  .msg-num{font-size:36px;font-weight:700;color:var(--acc);line-height:1} .msg-lbl{color:var(--mut);margin-left:10px;font-size:13px}
  .msg-split{display:flex;gap:26px;margin-left:auto;color:var(--mut);font-size:13px}
  .msg-split b{color:var(--txt)} .msg-split .up{color:var(--ok)} .msg-split .down{color:var(--acc)}
  .log-cols{display:flex;gap:16px}
  .log-col{flex:1;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
  .log-col h3{margin:0 0 10px;font-size:15px;color:var(--txt)}
  .log-row{padding:10px 2px;border-bottom:1px solid var(--line)} .log-row:last-child{border-bottom:0}
  .log-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .log-who{color:var(--mut);font-size:11px} .log-time{color:var(--mut);font-size:11px;margin-left:auto}
  .log-msg{margin-top:6px;font-size:13px;color:var(--txt);word-break:break-word;white-space:pre-wrap}
  .log-empty{padding:20px;text-align:center;color:var(--mut);font-size:13px}
  @media (max-width:860px){
    /* 窄屏：桌面分类菜单隐藏，改用汉堡 + 双列分类面板 */
    .desk-nav{display:none}
    .hamburger{display:block}
    header{gap:10px;position:relative}
    .who{display:none}
    .tb-account{display:none}
    .mobile-panel{display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:var(--panel);border-bottom:1px solid var(--line);box-shadow:0 8px 20px rgba(var(--shadow-rgba),.12);padding:14px;max-height:82vh;overflow:auto}
    .mobile-panel.open{display:block}
    .mp-group{margin-bottom:14px}
    .mp-glabel{font-size:12px;color:var(--acc);font-weight:600;margin-bottom:6px;padding-left:2px}
    .mp-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .mp-grid a{display:block;padding:12px 10px;border-radius:8px;background:var(--bg-subtle);color:var(--txt);text-decoration:none;font-size:14px;border:1px solid var(--line)}
    .mp-grid a.active{background:var(--bg-chip);border-color:var(--acc)}
  }
  @media (max-width:760px){
    .ring-card{flex-direction:column;text-align:center}.ring-legend{width:100%}.msg-bar{flex-direction:column;align-items:flex-start;gap:12px}.msg-split{margin-left:0}.log-cols{flex-direction:column}
    header{gap:12px;position:relative}
    .who{display:none}
    /* 控件与小屏堆叠 */
    .row{align-items:stretch}
    .row>div, .row>div[style*="flex:0 0"]{flex:1 1 100%!important;max-width:100%}
    .row select, .row input{width:100%}
    /* 表格横向滚动，避免溢出 */
    table{display:block;overflow-x:auto;white-space:nowrap;width:100%}
    main{padding:14px}
    .loracalc .grid{grid-template-columns:1fr}
  }
  /* ===== 页面切换加载遮罩 ===== */
  #loader{position:fixed;inset:0;background:rgba(var(--shadow-rgba),.3);display:none;align-items:center;justify-content:center;z-index:200}
  #loader.show{display:flex}
  #loader .spinner{width:44px;height:44px;border:4px solid rgba(var(--shadow-rgba),.12);border-top-color:var(--acc);border-radius:50%;animation:elw-spin .8s linear infinite}
  #loader .lbl{position:absolute;margin-top:74px;color:var(--mut);font-size:13px}
  @keyframes elw-spin{to{transform:rotate(360deg)}}
  .err-box{background:var(--err-box-bg);border:1px solid var(--err-box-border);color:var(--err-box-txt);padding:14px 16px;border-radius:10px;margin:8px 0}
  /* 页面底部 footer：支持 HTML（由站点设置提供），暗色低对比度不抢戏 */
  footer.site-footer{margin:24px auto 18px;padding:14px 18px;max-width:1100px;text-align:center;color:var(--mut);font-size:12px;border-top:1px solid var(--line)}
  footer.site-footer a{color:var(--mut);text-decoration:underline;text-decoration-color:var(--line)}
  footer.site-footer a:hover{color:var(--txt)}
  footer.site-footer.login-footer{margin:14px auto 0;max-width:380px;border:0;padding:0 18px}
</style>
<script>
// 防止主题闪烁（FOUC）：在首次渲染前从 localStorage 或系统偏好读取并设置 data-theme
(function(){
  var saved = localStorage.getItem('elw_theme');
  if(!saved){
    saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  document.documentElement.setAttribute('data-theme', saved);
  // i18n：跟随站点语言设置 <html lang>，与当前界面语言保持一致（可为任意语言）
  var _ul = window.UI_LANG || 'zh';
  document.documentElement.setAttribute('lang', _ul === 'en' ? 'en' : (_ul === 'zh' ? 'zh-CN' : _ul));
})();
</script>
</head>
<body>
<header class="hidden" id="topbar">
  <h1 id="brand"><a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit">HolaStack</a></h1>
  <nav id="deskNav" class="desk-nav"></nav>
  <div class="spacer"></div>
  <button class="ghost" id="themeToggle" onclick="toggleTheme()" title="切换主题" style="padding:7px 9px;line-height:1;display:inline-flex;align-items:center;justify-content:center"></button>
  <span class="who" id="who"></span>
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
<div id="loader"><div class="spinner"></div><div class="lbl">加载中…</div></div>

<script>
// ===== 主题切换 =====
function getTheme(){ return document.documentElement.getAttribute('data-theme') || 'dark'; }
function toggleTheme(){
  var next = getTheme() === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('elw_theme', next);
  updateThemeIcon(next);
  // 重新渲染当前页：环形图(SVG 硬编码色需重建)、loracalc(注入的 style 需刷新)、apidocs
  rerenderForTheme();
}
function rerenderForTheme(){
  var v = state.view;
  if (v === 'dashboard' && typeof viewDashboard === 'function') return viewDashboard();
  if (v === 'loracalc' && typeof viewLoraCalc === 'function') return viewLoraCalc();
  if (v === 'apidocs' && typeof viewApiDocs === 'function') return viewApiDocs();
  // 其他页面纯 CSS 驱动，无需重建 DOM
}
// 主题切换图标：dark 显示月亮（点切到亮），light 显示太阳（点切到暗）。
// 16x16 stroke icon，跟随 currentColor 自适应主题色。
const ICON_MOON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
const ICON_SUN  = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
function updateThemeIcon(t){
  var btn = document.getElementById('themeToggle');
  if(!btn) return;
  btn.innerHTML = (t === 'dark' ? ICON_MOON : ICON_SUN);
  btn.setAttribute('aria-label', t === 'dark' ? '切换到浅色主题' : '切换到深色主题');
}
// 页面加载时同步按钮图标
updateThemeIcon(getTheme());

// ===== 国际化（i18n）=====
// t(s)：取当前语言译文；未命中则回退原文（中文）。DOM walker 与 alert/confirm 共用。
function t(s){
  if (typeof s !== 'string' || s === '') return s;
  return (window.I18N && window.I18N[s] !== undefined) ? window.I18N[s] : s;
}
// 遍历 root 下的文本节点 / placeholder / title / aria-label / 按钮 value，按中文原文替换为译文。
// 不在字典中的字符串保持原样（中文），安全回退。
function applyI18n(root){
  root = root || document;
  if (!window.I18N) return;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);
  nodes.forEach(node => {
    const raw = node.nodeValue;
    if (!raw || !raw.trim()) return;
    const tr = t(raw);
    if (tr !== raw) node.nodeValue = tr;
  });
  if (root.querySelectorAll){
    root.querySelectorAll('[placeholder],[title],[aria-label],button,option,input[type=button],input[type=submit]').forEach(el => {
      if (el.placeholder){ const tr = t(el.placeholder); if (tr !== el.placeholder) el.placeholder = tr; }
      if (el.title){ const tr = t(el.title); if (tr !== el.title) el.title = tr; }
      const al = el.getAttribute && el.getAttribute('aria-label');
      if (al){ const tr = t(al); if (tr !== al) el.setAttribute('aria-label', tr); }
      if ((el.tagName === 'BUTTON' || el.type === 'button' || el.type === 'submit') && el.value){ const tr = t(el.value); if (tr !== el.value) el.value = tr; }
    });
  }
}
// 动态内容（列表刷新、模态框、导航）自动翻译：监听 body 子树新增节点。
// 仅监听 childList（不监听 characterData/attributes），翻译文本节点不会触发回环。
const _i18nObserver = new MutationObserver(muts => {
  muts.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) applyI18n(n); }));
});
function startI18nObserver(){ _i18nObserver.observe(document.body, { childList: true, subtree: true }); }
// 切换语言：拉取对应字典 → 设置全局变量与 <html lang> → 静默重渲染当前页
// （模板源语言为中文，en 字典翻译、zh 字典保留中文，因此双向切换都正确）。
async function loadDict(lang){
  try {
    const r = await fetch('/api/i18n?lang=' + encodeURIComponent(lang));
    const j = await r.json();
    window.UI_LANG = (j.lang || lang);
    window.I18N = (j.dict && typeof j.dict === 'object') ? j.dict : {};
    window.LANGS = (j.langs && typeof j.langs === 'object') ? j.langs : {zh:'中文'};
  } catch(e){ window.UI_LANG = lang; window.I18N = {}; window.LANGS = {zh:'中文'}; }
}
function langAttr(lang){ return (lang === 'en') ? 'en' : (lang === 'zh' ? 'zh-CN' : lang); }
async function applyLanguage(lang){
  // 后台接口 /api/settings 已经把 ui_lang 写库，前端只需刷新整页让所有 DOM（含顶栏、nav、模态框占位等）
  // 重新按新语言渲染；硬刷新最稳妥，避免遗漏的 DOM 片段还停留在旧语言。
  try {
    await fetch('/api/i18n?lang=' + encodeURIComponent(lang));
  } catch(e){}
  location.reload();
}
// 让原生 alert/confirm 也走 i18n：浏览器对话框不在 DOM 中，walker 无法翻译。
// alert 改为右上角 toast（非阻塞）；颜色按文本启发式判断（成功/失败/警告/信息）
// confirm 保留原生阻塞对话框（用于 if (!confirm(...)) 同步判断；toast 改造需 Promise 化）
const _origAlert = window.alert;
const _toastType = (m) => {
  const s = String(m || '');
  if (/失败|错误|不允许|不能|无法|不可用|无效|非法|禁止|禁用|权限|已存在|重复|冲突|找不到|不存在|已被|forbidden|error|fail|denied|conflict|not\s*found|invalid|exist/i.test(s)) return 'err';
  if (/警告|注意|小心|warn/i.test(s)) return 'warn';
  if (/成功|已完成|已保存|已删除|已更新|已复制|已发送|已提交|ok|saved|created|updated|deleted|submitted/i.test(s)) return 'ok';
  return 'info';
};
window.alert = (m) => {
  // toast 工具已 hoist，调用安全
  if (typeof toast === 'function') return toast(t(m), _toastType(m));
  return _origAlert.call(window, t(m));
};
const _origConfirm = window.confirm;
window.confirm = (q) => _origConfirm.call(window, t(q));

let state = {user:null, token:null, view:'dashboard', stats:null, apps:[], devs:[], gws:[], ups:[], users:[], evs:[], regions:['EU868','US915','CN470','AS923','AU915','CN779','EU433','IN865','KR920','RU864'], upsFilter:'', upsAppFilter:'', dlDevFilter:'', dlAppFilter:'', evsDevFilter:'', evsGwFilter:'', dps:[], appSel:null, intAppSel:null, mcDetail:null, tenantFilter:'', devAppFilter:'', apiLogFilter:{path:'',ip:'',status:'',method:'',tenant_id:'',application_id:''}, upsSort:{col:'time',dir:'desc'}, dlsSort:{col:'time',dir:'desc'}, evsSort:{col:'time',dir:'desc'}, apiLogSort:{col:'time',dir:'desc'}, appsSort:{col:'time',dir:'desc'}, devsSort:{col:'time',dir:'desc'}, gwsSort:{col:'time',dir:'desc'}, usersSort:{col:'time',dir:'desc'}, apiKeysSort:{col:'time',dir:'desc'}, intgSort:{col:'time',dir:'desc'}, dpsSort:{col:null,dir:'desc'}, upsFStatus:'', dlsFStatus:'', evsFLevel:'', evsFType:'', apiLogFStatus:'', devsFActivation:'', devsFCls:'', devsFOnline:'', devsFStatus:'', gwsFOnline:'', dpsFRegion:'', dpsFCls:'', upsPage:1, dlsPage:1, evsPage:1, apiLogPage:1, appsPage:1, devsPage:1, gwsPage:1, usersPage:1, apiKeysPage:1, intgPage:1, dpsPage:1, upsLimit:50, dlsLimit:50, evsLimit:50, apiLogLimit:50, appsLimit:50, devsLimit:50, gwsLimit:50, usersLimit:50, apiKeysLimit:50, intgLimit:50, dpsLimit:50, upsOffset:0, dlsOffset:0, evsOffset:0, apiLogOffset:0, appsOffset:0, devsOffset:0, gwsOffset:0, usersOffset:0, apiKeysOffset:0, intgOffset:0, dpsOffset:0, upsTotal:0, dlsTotal:0, evsTotal:0, apiLogTotal:0, appsTotal:0, devsTotal:0, gwsTotal:0, usersTotal:0, apiKeysTotal:0, intgTotal:0, dpsTotal:0};

async function boot(){
  state.token = localStorage.getItem('elw_token') || null;
  try {
    const opt = {headers: state.token ? {'X-Elw-Token': state.token} : {}};
    const r = await fetch('/api/me', opt);
    if (r.ok) { const j = await r.json(); state.user = j.user; }
  } catch(e){}
  try { const rr = await fetch('/api/regions'); if (rr.ok) { const j = await rr.json(); if (j.regions && j.regions.length) state.regions = j.regions; } } catch(e){}
  // 预取当前语言字典与可用语言清单，供界面渲染与「界面语言」下拉使用
  if (!window.LANGS) { try { await loadDict(window.UI_LANG || 'zh'); } catch(e){} }
  // 在判断登录态之前先对登录页（始终在 DOM 里的静态片段）做一次 i18n，
  // 否则切换语言后登录页仍显示旧语言。renderShell 之后会再翻译一次。
  applyI18n(document);
  // 拉取公开设置（站点名/logo/公告/footer）— 登录页与登录后界面都需用到
  applyPublicSettings();
  renderShell();
}
const regionOptions = (sel) => state.regions.map(r=>`<option ${r===sel?'selected':''}>${r}</option>`).join('');
function renderShell(){
  if (!state.user) {
    document.getElementById('topbar').classList.add('hidden');
    document.getElementById('view').classList.add('hidden');
    document.getElementById('login').classList.remove('hidden');
    return;
  }
  document.getElementById('login').classList.add('hidden');
  document.getElementById('topbar').classList.remove('hidden');
  document.getElementById('view').classList.remove('hidden');
  document.getElementById('who').textContent = state.user.username;
  renderNav();
  // 每次登录/切换账号都刷新公共设置（站点名/logo/API 基础地址等），避免残留上一账号的旧值
  applyPublicSettings();
  // 支持直接访问 #hash 页面（如 /#settings）；无 hash 默认概览
  nav((location.hash||'').slice(1)||'dashboard');
  // 首次渲染完成后翻译整个文档，并启动动态内容自动翻译
  applyI18n(document);
  startI18nObserver();
}
const isAdmin = () => state.user && state.user.role === 'admin';
const isTenant = () => state.user && state.user.role === 'tenant';
const isDemo = () => state.user && state.user.role === 'operator';
// 可写角色：admin（全局）/ tenant（仅本租户）；operator（演示）只读
const canWrite = () => state.user && ['admin','tenant'].includes(state.user.role);
// demo 角色：按钮不隐藏，可以打开弹窗查看内容，但保存/删除按钮会被禁用
const adminBtn = (html) => html;

// 导航按功能分类；桌面端渲染为二级下拉，移动端渲染为双列分类面板。
const NAV_GROUPS = [
  { label:'运行监控', items:[
    {v:'dashboard', text:'概览'},
    {v:'uplinks', text:'上行消息日志'},
    {v:'downlinks', text:'下行消息日志'},
    {v:'events', text:'网关日志'},
  ]},
  { label:'设备管理', items:[
    {v:'applications', text:'应用'},
    {v:'devices', text:'设备'},
    {v:'gateways', text:'网关'},
    {v:'device-profiles', text:'设备模板'},
    {v:'multicast-groups', text:'组播组'},
  ]},
  { label:'工具集成', items:[
    {v:'integrations', text:'外部集成'},
    {v:'api-keys', text:'API 密钥'},
    {v:'api-logs', text:'API 调用日志'},
    {v:'apidocs', text:'API 文档'},
    {v:'loracalc', text:'LoRa 计算器'},
  ]},
  { label:'系统管理', admin:true, items:[
    {v:'tenants', text:'用户配置'},
    {v:'users', text:'用户管理'},
    {v:'settings', text:'站点设置'},
  ]},
];

function renderNav(){
  const desk = document.getElementById('deskNav');
  const mob = document.getElementById('mobilePanel');
  if (!desk || !mob) return;
  const groups = NAV_GROUPS.filter(g => !g.admin || isAdmin());
  desk.innerHTML = groups.map(g => {
    const sub = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${it.text}</a>`).join('');
    return `<div class="navgrp"><button class="navgrp-btn" onclick="toggleGrp(this)">${g.label}<span class="caret">▾</span></button><div class="navsub">${sub}</div></div>`;
  }).join('');
  const accountGrid = `<a href="javascript:void(0)" onclick="closeNav();changePw()">修改密码</a>`
    + `<a href="javascript:void(0)" class="mp-danger" onclick="closeNav();logout()">退出登录</a>`;
  mob.innerHTML = groups.map(g => {
    const grid = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${it.text}</a>`).join('');
    return `<div class="mp-group"><div class="mp-glabel">${g.label}</div><div class="mp-grid">${grid}</div></div>`;
  }).join('') + `<div class="mp-group"><div class="mp-glabel">账户</div><div class="mp-grid">${accountGrid}</div></div>`;
  bindNavLinks();
  document.querySelectorAll('.nav').forEach(a => a.classList.toggle('active', a.dataset.v === state.view));
}
function bindNavLinks(){
  document.querySelectorAll('.nav').forEach(a => a.onclick = () => { nav(a.dataset.v); closeNav(); closeGrps(); });
}
function toggleGrp(btn){
  const grp = btn.parentElement;
  const wasOpen = grp.classList.contains('open');
  closeGrps();
  if (!wasOpen) grp.classList.add('open');
}
function closeGrps(){
  document.querySelectorAll('.navgrp.open').forEach(g => g.classList.remove('open'));
}
// 点击页面其他区域时收起已展开的分类下拉
document.addEventListener('click', (e) => {
  if (!e.target.closest('.navgrp')) closeGrps();
});

async function doLogin(){
  const u = document.getElementById('l_user').value.trim();
  const p = document.getElementById('l_pass').value;
  const err = document.getElementById('l_err');
  err.textContent = '';
  try {
    const r = await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:u,password:p})});
    let j;
    try { j = await r.json(); }
    catch(e){ err.textContent = '服务器返回异常（HTTP '+r.status+'），请查看服务器错误日志'; return; }
    if (j.ok && j.token){ state.user = j.user; state.token = j.token; localStorage.setItem('elw_token', j.token); renderShell(); }
    else err.textContent = j.error || ('登录失败 (HTTP '+r.status+')');
  } catch(e){ err.textContent = e.message || '网络错误，登录失败'; }
}
async function logout(){
  const opt = {method:'POST', headers: state.token ? {'X-Elw-Token': state.token} : {}};
  await fetch('/api/logout', opt);
  state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell();
}

const api = async (m,p,body) => {
  const opt = {method:m, headers:{'Content-Type':'application/json'}};
  if (state.token) opt.headers['X-Elw-Token'] = state.token;
  if (body) opt.body = JSON.stringify(body);
  const r = await fetch(p, opt);
  const ct = r.headers.get('content-type') || '';
  const text = await r.text();
  if (r.status === 401) { state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell(); throw new Error('unauthorized'); }
  if (r.status === 403) {
    // 演示账号写操作被后端 guardWrite 拒绝时给出友好提示（toast）
    try { const ej = JSON.parse(text); if (ej.error && ej.error.indexOf('forbidden') !== -1) toast(t('演示模式：当前为只读账号，不能进行实际操作。如需体验完整功能，请联系管理员获取写权限账号。'), 'warn'); } catch(e) {}
  }
  if (r.status < 200 || r.status >= 300) {
    throw new Error('HTTP ' + r.status + '：' + text.slice(0, 300));
  }
  if (ct.indexOf('application/json') === -1) {
    throw new Error('服务器返回了非 JSON 响应（可能是错误页）：' + text.slice(0, 300));
  }
  let j;
  try { j = JSON.parse(text); } catch (e) { throw new Error('JSON 解析失败：' + text.slice(0, 300)); }
  // 业务错误（HTTP 200 且带 error 字段，如「名称已存在」）：不抛异常，作为正常返回值交给调用方，
  // 以便前端 `if(r.error){alert(t(r.error));return;}` 能展示友好提示（此前抛异常被 busy 吞掉→无提示）。
  if (j && j.error) return j;
  return j;
};
const hex = s => s || '-';
const esc = s => (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));

/**
 * 通用提示条（toast）：右上角悬浮，2.5s 后自动消失；同类合并；最多 3 条堆叠
 *   type: 'ok' | 'info' | 'warn' | 'err' | 'copy' （决定配色；'copy' = 复制成功，绿色 ok 色）
 *   支持点击 × 立即关闭
 */
function toast(msg, type){
  const host = document.getElementById('toastHost') || (() => {
    const el = document.createElement('div');
    el.id = 'toastHost';
    el.style.cssText = 'position:fixed;top:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none';
    document.body.appendChild(el);
    return el;
  })();
  const item = document.createElement('div');
  item.className = 'toast ' + (type || 'info');
  // 视觉（背景/边框/圆角/阴影）统一走 .toast CSS 类；inline 只保留布局与动画
  item.style.cssText = 'pointer-events:auto;min-width:180px;max-width:380px;padding:10px 14px;display:flex;align-items:flex-start;gap:10px;font-size:13px;line-height:1.45;opacity:0;transform:translateX(20px);transition:opacity .2s, transform .2s';
  // 颜色按 type 走 CSS class
  const text = document.createElement('div');
  text.style.cssText = 'flex:1;word-break:break-word';
  text.textContent = msg;
  const close = document.createElement('button');
  close.textContent = '×';
  close.style.cssText = 'background:transparent;border:0;color:inherit;opacity:.6;cursor:pointer;font-size:18px;line-height:1;padding:0 0 0 4px';
  close.onclick = () => removeToast(item);
  item.appendChild(text);
  item.appendChild(close);
  // 限制最多 3 条
  while (host.children.length >= 3) host.removeChild(host.firstChild);
  host.appendChild(item);
  // 触发动画
  requestAnimationFrame(() => { item.style.opacity = '1'; item.style.transform = 'translateX(0)'; });
  setTimeout(() => removeToast(item), 2500);
}
function removeToast(item){
  if (!item || !item.parentNode) return;
  item.style.opacity = '0';
  item.style.transform = 'translateX(20px)';
  setTimeout(() => { if (item.parentNode) item.parentNode.removeChild(item); }, 220);
}
/**
 * hex → 文本（UTF-8 解码）
 *   不可打印字节（控制字符 / 非 UTF-8）替换为 '·'；空字符串返回 '-'。
 *   仅作展示用，不改变原始 hex 字段（仍可在"JSON"弹窗看到原值）。
 */
const hexToText = (s) => {
  if (!s) return '-';
  const clean = String(s).replace(/\s+/g, '');
  if (!clean) return '-';
  if (!/^[0-9a-fA-F]+$/.test(clean) || (clean.length & 1)) return s; // 不是合法 hex，原样返回
  const bytes = new Uint8Array(clean.length / 2);
  for (let i = 0; i < bytes.length; i++) bytes[i] = parseInt(clean.substr(i*2, 2), 16);
  try {
    // 先按 UTF-8 解码；含非 UTF-8 字节时浏览器会替换为 U+FFFD
    const txt = new TextDecoder('utf-8', { fatal: false }).decode(bytes);
    // 控制字符（除 \t \n \r）替换为 '·'，便于对齐
    return txt.replace(/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/g, '·');
  } catch (_) {
    return s;
  }
};

function showLoader(){ const l=document.getElementById('loader'); if(l) l.classList.add('show'); }
function hideLoader(){ const l=document.getElementById('loader'); if(l) l.classList.remove('show'); }
// 弹窗内保存/删除/发送：显示遮罩→执行异步操作→finally 隐藏，避免操作耗时无反馈、或接口卡住时页面无响应
async function busy(label, fn){ showLoader(label); try { return await fn(); } finally { hideLoader(); } }

// ===== 通用可排序/可筛选表格 =====
// 排序规则（极简版）：
//   - 默认只有「时间」列才可排序（firstDir=desc），其它列通过 sortable:false 关闭
//   - 业务方需要给非时间列加排序时，单独给该列去掉 sortable:false，并指定 firstDir
//   - 状态列（type='status'）若需要排序：opts.values 的顺序就是 sort 顺序（如 error > warn > info）
/**
 * 构造带「点击表头排序 + 状态下拉筛选」的表格 HTML。
 * 排序与状态筛选完全在内存中完成（基于传入的 rows），不重新请求后端。
 *
 * 字段：
 *  - id 容器 id（用于点击事件作用域）
 *  - stateKey 排序状态写入 state[stateKey]，键形如 {col:'time', dir:'desc'}
 *  - cols: [{key, label, type, firstDir?, sortable?, opts?}]
 *      type: 'time' | 'num' | 'str' | 'status' | 'raw'  ← status 列会渲染下拉；raw 是占位列
 *      firstDir: 'asc' | 'desc'   ← 该列首次点击时的方向（time 列习惯 desc，其余 asc）
 *      sortable: false            ← 关闭该列排序：不显示箭头 + 不响应点击（仅 status 列还可保留下拉筛选）
 *      opts: 当 type='status' 时必填，{values:[{value,label,cls?}], getValue:row=>row.status}
 *  - rows: 当前数据集
 *  - rowHtml: (row) => string   ← 单行 HTML
 *  - emptyText: 数据空时显示的 colspan
 *  - defaultSort: {col, dir}
 *  - filterStatus: {col, value}   ← 当前状态过滤
 *  - onSortChange: (newSort) => void   ← 可选：排序变化时通知外层（用于持久化或重新 fetch）
 */
// 管理列表页做「全量筛选+排序后分页」时使用：返回 [filteredSortedRows, total]
// 与 buildSortableTable 内部逻辑保持一致（cfg 结构相同），避免两处实现漂移
function filterAndSortRows(cfg){
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  let rows = (cfg.rows || []).slice();
  for (const f of filterList) {
    if (!f || !f.col || !f.value) continue;
    const fcol = cfg.cols.find(c => c.key === f.col);
    if (fcol && fcol.opts && fcol.opts.getValue) {
      const getV = fcol.opts.getValue;
      const opt = fcol.opts.values.find(o => o.value === f.value);
      const matchFn = opt && opt.match ? opt.match : (v => String(v) === f.value);
      rows = rows.filter(r => matchFn(getV(r)));
    }
  }
  const sort = cfg.state[cfg.stateKey] || cfg.defaultSort;
  if (sort && sort.col) {
    const c = cfg.cols.find(x => x.key === sort.col);
    if (c) {
      const dir = sort.dir === 'asc' ? 1 : -1;
      rows = rows.sort((a,b) => {
        let va, vb;
        if (c.type === 'time' || c.type === 'num') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'status') {
          const vals = c.opts && c.opts.values ? c.opts.values : [];
          const getV = c.opts && c.opts.getValue ? c.opts.getValue : (r => r[c.key]);
          const vaRaw = getV(a), vbRaw = getV(b);
          const ia = vals.findIndex(o => String(o.value) === String(vaRaw));
          const ib = vals.findIndex(o => String(o.value) === String(vbRaw));
          const idxA = ia >= 0 ? ia : Number.MAX_SAFE_INTEGER;
          const idxB = ib >= 0 ? ib : Number.MAX_SAFE_INTEGER;
          if (idxA === idxB) return String(vaRaw ?? '').localeCompare(String(vbRaw ?? '')) * dir;
          va = idxA; vb = idxB;
        }
        else { va = String(cfg.cellValue(a, c.key) ?? ''); vb = String(cfg.cellValue(b, c.key) ?? ''); }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return 0;
      });
    }
  }
  return [rows, rows.length];
}
// 分页切片：rows 全量 → [当前页 rows, total, page, limit, offset]
function paginateRows(rows, state, keys){
  const page = Math.max(1, +(state[keys.pageKey] || 1));
  const limit = Math.max(1, Math.min(500, +(state[keys.limitKey] || 50)));
  const total = rows.length;
  const offset = Math.max(0, (page - 1) * limit);
  state[keys.offsetKey] = offset;
  return [rows.slice(offset, offset + limit), total, page, limit, offset];
}
function buildSortableTable(cfg){
  // 把 cfg 暴露到 window 上，让 _tableToggleSort 能在点表头时查 firstDir
  if (cfg.refresh) {
    window.__tableCfg = window.__tableCfg || {};
    window.__tableCfg[cfg.refresh] = cfg;
  }
  const sort = cfg.state[cfg.stateKey] || cfg.defaultSort;
  // presorted: 管理列表页已用 filterAndSortRows 完成筛选+排序+分页切片，
  // 这里直接渲染传入的 rows（cfg.rows 已是当前页），不再重复筛选/排序。
  if (cfg.presorted) {
    return renderTable(cfg, sort);
  }
  // 状态筛选：支持多列同时筛（cfg.filterStatusList 数组），单列 cfg.filterStatus 兼容
  //   形如 [{col:'level', value:'error'}, {col:'type', value:'uplink'}]
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  const fk = cfg.filterStatus ? cfg.filterStatus.col : null;
  const fv = cfg.filterStatus ? cfg.filterStatus.value : '';
  // 应用状态过滤（按列分别匹配；多列 AND 关系）
  let rows = cfg.rows || [];
  for (const f of filterList) {
    if (!f || !f.col || !f.value) continue;
    const fcol = cfg.cols.find(c => c.key === f.col);
    if (fcol && fcol.opts && fcol.opts.getValue) {
      const getV = fcol.opts.getValue;
      // 支持「全部/具体值/类别（2xx/4xx）」三种匹配
      const opt = fcol.opts.values.find(o => o.value === f.value);
      const matchFn = opt && opt.match ? opt.match : (v => String(v) === f.value);
      rows = rows.filter(r => matchFn(getV(r)));
    }
  }
  // 应用排序
  if (sort && sort.col) {
    const c = cfg.cols.find(x => x.key === sort.col);
    if (c) {
      const dir = sort.dir === 'asc' ? 1 : -1;
      rows = rows.slice().sort((a,b) => {
        let va, vb;
        if (c.type === 'time') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'num') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'status') {
          // 状态列：按 opts.values 数组的「下标」排序（业务顺序）
          //   - DL_STATUS 示例：[pending, scheduled, sent, acknowledged, failed, timeout, error]
          //   - desc 时 error(6) 排首，pending(0) 排末（业务严重度降序）
          //   - 不在 values 列表里的"未知状态"统一排到末尾（不参与业务顺序）
          const vals = c.opts && c.opts.values ? c.opts.values : [];
          const getV = c.opts && c.opts.getValue ? c.opts.getValue : (r => r[c.key]);
          const vaRaw = getV(a), vbRaw = getV(b);
          const ia = vals.findIndex(o => String(o.value) === String(vaRaw));
          const ib = vals.findIndex(o => String(o.value) === String(vbRaw));
          const idxA = ia >= 0 ? ia : Number.MAX_SAFE_INTEGER;
          const idxB = ib >= 0 ? ib : Number.MAX_SAFE_INTEGER;
          // 索引相等时（如两个都是未知状态）按 value 字典序做稳定排序
          if (idxA === idxB) return String(vaRaw ?? '').localeCompare(String(vbRaw ?? '')) * dir;
          va = idxA; vb = idxB;
        }
        else { va = String(cfg.cellValue(a, c.key) ?? ''); vb = String(cfg.cellValue(b, c.key) ?? ''); }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return 0;
      });
    }
  }
  return renderTable(cfg, sort);
}
// 表格 HTML 渲染（表头 + 行）。cfg.rows 即待渲染行：
//   - 日志页：buildSortableTable 内部筛选/排序后的当前页
//   - 管理列表页（presorted）：已由 filterAndSortRows + paginateRows 处理过的当前页
function renderTable(cfg, sort){
  const rows = cfg.rows || [];
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  // 表头
  const arrow = (k) => {
    if (!sort || sort.col !== k) return '<span class="sort-arrow" style="opacity:.3;margin-left:4px">↕</span>';
    return sort.dir === 'asc'
      ? '<span class="sort-arrow" style="opacity:1;margin-left:4px;color:var(--acc)">↑</span>'
      : '<span class="sort-arrow" style="opacity:1;margin-left:4px;color:var(--acc)">↓</span>';
  };
  const header = cfg.cols.map(c => {
    // 是否可排序：默认 true（除 raw 占位列）；c.sortable=false 显式关闭（不显示箭头 + 不响应点击）
    // status 类型列默认 true（业务顺序排序）；如不需要可在列定义里 sortable:false（只保留下拉筛选）
    const sortable = c.sortable !== false && c.type !== 'raw';
    const cursor = sortable ? 'cursor:pointer' : '';
    const title = sortable ? `点表头排序（${c.label}）` : '';
    const onclick = sortable
      ? ` onclick="window['${cfg.stateKey}_sort']('${c.key}')"`
      : '';
    if (c.type === 'status') {
      // 状态列表头：下拉负责筛选，箭头仅在 sortable=true 时显示
      //   - opts.values 是「业务顺序」（用于 sort 时按下标排），如 [info, warn, error] 让 desc 时 error 排前
      //   - 若 values 第一项 value 非空，会自动前置「全部」选项（value=''），UX 不变
      const vals = c.opts.values || [];
      const allOpt = (vals[0] && vals[0].value !== '') ? [{value:'',label:'全部'}, ...vals] : vals;
      // 命中本列的当前筛选值（多列筛选 filterStatusList）
      const curF = filterList.find(f => f.col === c.key);
      const curVal = curF ? curF.value : '';
      const sel = `<select style="font-weight:600;background:transparent;border:0;color:var(--txt);${sortable?'cursor:pointer':'cursor:default'}" onchange="event.stopPropagation();window['${cfg.stateKey}_fstatus']('${c.key}', this.value)">` +
        allOpt.map(o => `<option value="${esc(o.value)}" ${o.value===curVal?'selected':''}>${esc(o.label)}</option>`).join('') +
        `</select>`;
      return `<th style="${cursor}" ${title?`title="${title}"`:''} ${onclick}>${sel}${sortable?arrow(c.key):''}</th>`;
    }
    return `<th style="${cursor}" ${title?`title="${title}"`:''} ${onclick}>${esc(c.label)}${sortable?arrow(c.key):''}</th>`;
  }).join('');
  // 行
  const bodyHtml = rows.length
    ? rows.map(r => cfg.rowHtml(r)).join('')
    : `<tr><td colspan="${cfg.cols.length}" class="muted">${esc(cfg.emptyText||'暂无数据')}</td></tr>`;
  return `<table class="sortable"><thead><tr>${header}</tr></thead><tbody>${bodyHtml}</tbody></table>`;
}

// 通用排序调度：state 上读/写 {col, dir}，三态循环（firstDir → 翻转 → 清除）
//   - 点新列：进入 cfg[col].firstDir
//     time 列习惯 desc（最新在上），数字列习惯 asc（弱信号 / 小值排前 → 排查问题），
//     状态列习惯 desc（失败 / 错误排前），字符串列习惯 asc（字典序 / 0-9）
//   - 点同列：翻转 dir（firstDir↔other）
//   - 再点一次：清除排序，回原始顺序
// key 是 state 上的字段名（upsSort / dlsSort / evsSort / apiLogSort），re 是对应刷新函数名
function _tableToggleSort(key, re, col){
  const cur = state[key] || {col:null, dir:'desc'};
  if (cur.col !== col) {
    // 新列：进入 firstDir
    const cfg = (window.__tableCfg && window.__tableCfg[re]) || null;
    const c = cfg && cfg.cols ? cfg.cols.find(x => x.key === col) : null;
    const firstDir = (c && c.firstDir) ? c.firstDir : 'desc';
    state[key] = {col, dir:firstDir};
  }
  else {
    // 同列：根据 firstDir 判断"再点是否进入清除"
    const cfg = (window.__tableCfg && window.__tableCfg[re]) || null;
    const c = cfg && cfg.cols ? cfg.cols.find(x => x.key === col) : null;
    const firstDir = (c && c.firstDir) ? c.firstDir : 'desc';
    if (cur.dir === firstDir) state[key] = {col, dir: firstDir==='desc' ? 'asc' : 'desc'}; // 翻转
    else state[key] = {col:null, dir:'desc'}; // 已翻转过，再点清除
  }
  window[re]();
}
// 状态下拉筛选回调：按列写回对应 state 字段
//   fieldName: 要写入的 state 字段名（如 evsFType），由各 view 注册时指定
function _tableSetFStatus(fieldName, re, val){
  state[fieldName] = val;
  window[re]();
}

/**
 * 通用分页条：每页 N 条下拉 + 上一页/下一页/页码 + 跳到 X 页 + 共 N 条
 *  cfg: { total, limit, offset, pageKey, limitKey, refresh }
 *   - state[pageKey]   当前页号（1-based）
 *   - state[limitKey]  每页大小
 *   - refresh          调 window[refresh]()
 */
function buildPager(cfg){
  const total = +cfg.total || 0;
  const limit = +cfg.limit || 50;
  const offset = +cfg.offset || 0;
  const cur = Math.floor(offset / Math.max(1, limit)) + 1;
  const pages = Math.max(1, Math.ceil(total / Math.max(1, limit)));
  const from = total === 0 ? 0 : (offset + 1);
  const to = Math.min(offset + limit, total);
  // 窗口化页码：cur-2 .. cur+2
  const win = [];
  for (let i = Math.max(1, cur - 2); i <= Math.min(pages, cur + 2); i++) win.push(i);
  const pageBtn = (p, label, extra) => {
    const dis = extra && extra.disabled;
    const cls = extra && extra.cur ? 'btn' : 'ghost';
    return `<button class="${cls}" style="padding:4px 9px;font-size:12px" ${dis?'disabled':''} onclick="window['${cfg.refresh}__page'](${p})">${label||p}</button>`;
  };
  return `<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:12px;flex-wrap:wrap">
    <div class="muted" style="font-size:12px">共 <b>${total}</b> 条 · 第 ${from}-${to} 条 · ${pages>0?cur:0} / ${pages} 页</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <label style="margin:0;font-size:12px;color:var(--mut)">每页</label>
      <select style="width:auto;padding:4px 8px;font-size:12px" onchange="window['${cfg.refresh}__limit'](+this.value)">
        ${[20,50,100,200,500].map(n => `<option value="${n}" ${n===limit?'selected':''}>${n}</option>`).join('')}
      </select>
      ${pageBtn(Math.max(1, cur-1), '‹ 上一页', {disabled: cur<=1})}
      ${win[0] > 1 ? pageBtn(1, '1') + (win[0] > 2 ? '<span class="muted">…</span>' : '') : ''}
      ${win.map(p => pageBtn(p, p, {cur: p===cur})).join('')}
      ${win[win.length-1] < pages ? (win[win.length-1] < pages-1 ? '<span class="muted">…</span>' : '') + pageBtn(pages, pages) : ''}
      ${pageBtn(Math.min(pages, cur+1), '下一页 ›', {disabled: cur>=pages})}
      <label style="margin:0;font-size:12px;color:var(--mut)">跳到</label>
      <input type="number" min="1" max="${pages}" value="${cur}" style="width:64px;padding:4px 6px;font-size:12px" onchange="window['${cfg.refresh}__page'](+this.value)">
      <span class="muted" style="font-size:12px">页</span>
    </div>
  </div>`;
}
function _pagerGo(key, re, page){
  const lim = +state[key.limitKey] || 50;
  const total = +state[key.totalKey] || 0;
  const pages = Math.max(1, Math.ceil(total / lim));
  const p = Math.max(1, Math.min(pages, page|0));
  state[key.pageKey] = p;
  state[key.offsetKey] = (p - 1) * lim;
  window[re]();
}
function _pagerSetLimit(key, re, lim){
  state[key.limitKey] = +lim;
  state[key.pageKey] = 1;
  state[key.offsetKey] = 0;
  window[re]();
}
// 切换页面：显示加载遮罩，渲染完成后（finally）再隐藏；渲染抛错时显示错误提示而非冻结页面。
// silent=true 用于每 5 秒自动刷新，避免遮罩频繁闪烁。
async function nav(v, silent=false){
  state.view = v;
  closeNav(); closeGrps();
  document.querySelectorAll('.nav').forEach(a=>a.classList.toggle('active', a.dataset.v===v));
  if(!silent){
    // 同步地址栏 hash（不触发 hashchange，避免双渲染）；浏览器前进/后退/手改 hash 由 hashchange 监听处理
    if((location.hash||'').slice(1)!==v) history.replaceState(null,'','#'+v);
    showLoader();
  }
  try {
    if (v==='dashboard') await viewDashboard();
    else if (v==='applications') await viewApplications();
    else if (v==='devices') await viewDevices();
    else if (v==='gateways') await viewGateways();
    else if (v==='uplinks') await viewUplinks();
    else if (v==='downlinks') await viewDownlinks();
    else if (v==='events') await viewEvents();
    else if (v==='device-profiles') await viewDeviceProfiles();
    else if (v==='tenants') await viewTenants();
    else if (v==='integrations') await viewIntegrations();
    else if (v==='api-keys') await viewApiKeys();
    else if (v==='multicast-groups') await viewMulticastGroups();
    else if (v==='users') await viewUsers();
    else if (v==='api-logs') await viewApiLogs();
    else if (v==='loracalc') await viewLoraCalc();
    else if (v==='apidocs') { await applyPublicSettings(); await viewApiDocs(); }
    else if (v==='settings') await viewSettings();
    else document.getElementById('view').innerHTML = '<div class="muted">未知页面</div>';
    // 演示账号：列表页写操作按钮（删除/新建/停用/启用）灰禁用，但"编辑"可点击打开弹窗
    if (isDemo()) disableDemoWriteButtons();
  } catch(e){
    if(!silent) document.getElementById('view').innerHTML = `<div class="err-box">加载失败：${esc(e && e.message ? e.message : e)}</div>`;
  } finally {
    if(!silent) hideLoader();
  }
}
function toggleNav(){ document.getElementById('mobilePanel').classList.toggle('open'); }
function closeNav(){ document.getElementById('mobilePanel').classList.remove('open'); }

// 地址栏 hash 变化（浏览器前进/后退、手动修改 URL）→ 切换对应页面
window.addEventListener('hashchange', ()=>{
  const v = (location.hash||'').slice(1) || 'dashboard';
  if (v !== state.view) nav(v);
});

// 自动刷新：只读的实时视图（概览/网关/上行/下行/日志）每 5 秒刷新；模态框打开时暂停，避免打断编辑
const AUTO_REFRESH_VIEWS = ['dashboard','devices','gateways','uplinks','downlinks','events'];
setInterval(()=>{
  if (document.getElementById('modal').classList.contains('show')) return; // 弹窗打开时暂停
  if (AUTO_REFRESH_VIEWS.includes(state.view)) nav(state.view, true);
}, 5000);

async function viewDashboard(){
  const s = await api('GET','/api/stats'); state.stats = s;
  const devTotal = s.devices|0, devOn = s.devices_online|0, devOff = s.devices_offline|0;
  const gwTotal = s.gateways|0, gwOn = s.gateways_online|0, gwOff = s.gateways_offline|0;
  const appTotal = s.applications|0;
  const msgTotal = (s.uplinks|0) + (s.downlinks|0);
  const devLogs = s.device_logs||[], gwLogs = s.gateway_logs||[];
  document.getElementById('view').innerHTML = `
    <h2>概览</h2>
    <div class="rings">
      ${dashRingCard('设备', devTotal, devOn, devOff, true)}
      ${dashRingCard('网关', gwTotal, gwOn, gwOff, true)}
      ${dashRingCard('应用', appTotal, 0, 0, false)}
    </div>

    <div class="msg-bar">
      <div class="msg-main"><span class="msg-num">${msgTotal}</span><span class="msg-lbl">消息总数</span></div>
      <div class="msg-split">
        <div><span class="up">▲</span> 上行 <b>${s.uplinks|0}</b></div>
        <div><span class="down">▼</span> 下行 <b>${s.downlinks|0}</b></div>
      </div>
    </div>

    <div class="log-cols">
      <div class="log-col">
        <h3>最近设备日志</h3>
        ${devLogs.length? devLogs.map(e=>dashLogRow(e,'dev #'+(e.dev_id||''))).join('') : '<div class="log-empty">暂无设备日志</div>'}
      </div>
      <div class="log-col">
        <h3>最近网关日志</h3>
        ${gwLogs.length? gwLogs.map(e=>dashLogRow(e,'网关 '+esc(e.gateway_id||''))).join('') : '<div class="log-empty">暂无网关日志</div>'}
      </div>
    </div>

    <p class="muted" style="margin-top:16px">网络服务器监听 UDP 端口由 ELW_GW_UDP_PORT 配置（默认 1700）。先创建应用，再创建设备（OTAA 或 ABP，Class A/B/C），然后用网关连接并发送数据。</p>`;
}
/* 环形图卡片：split=true 时按 online(绿)/offline(红) 分段；否则整圈 accent 色显示总数。 */
function dashRingCard(title, total, online, offline, split){
  const r=70, cx=90, cy=90, sw=18, C=2*Math.PI*r;
  // 从 CSS 变量取色，确保主题切换即时生效
  const cs = getComputedStyle(document.documentElement);
  const cLine = cs.getPropertyValue('--line').trim() || '#2b3650';
  const cTxt  = cs.getPropertyValue('--txt').trim() || '#e6ecf5';
  const cMut  = cs.getPropertyValue('--mut').trim() || '#8b97ad';
  const cOk   = cs.getPropertyValue('--ok').trim() || '#36d399';
  const cErr  = cs.getPropertyValue('--err').trim() || '#f87272';
  const cAcc  = cs.getPropertyValue('--acc').trim() || '#3da9fc';
  let arcs;
  if(!total){
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cLine}" stroke-width="${sw}"/>`;
  } else if(split){
    const onLen = C*online/total, offLen = C*offline/total;
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cLine}" stroke-width="${sw}"/>
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cOk}" stroke-width="${sw}" stroke-dasharray="${onLen.toFixed(2)} ${C.toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"/>
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cErr}" stroke-width="${sw}" stroke-dasharray="${offLen.toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-onLen).toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"/>`;
  } else {
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cAcc}" stroke-width="${sw}"/>`;
  }
  let legend;
  if(split){
    legend = `
      <div class="hl-row hl-online"><span><span class="dot"></span>在线</span><b>${online} <span class="pct">(${total?Math.round(online/total*100):0}%)</span></b></div>
      <div class="hl-row hl-offline"><span><span class="dot"></span>离线</span><b>${offline} <span class="pct">(${total?Math.round(offline/total*100):0}%)</span></b></div>`;
  } else {
    legend = `<div class="hl-row"><span>应用总数</span><b>${total}</b></div>`;
  }
  return `<div class="ring-card">
    <svg viewBox="0 0 180 180" class="ring">${arcs}
      <text x="${cx}" y="${cy-2}" text-anchor="middle" fill="${cTxt}" font-size="32" font-weight="700">${total}</text>
      <text x="${cx}" y="${cy+18}" text-anchor="middle" fill="${cMut}" font-size="12">${title}</text>
    </svg>
    <div class="ring-legend">${legend}</div>
  </div>`;
}
function dashLogRow(ev, who){
  const lvl = (ev.level==='error')?'err':((ev.level==='warn'||ev.level==='warning')?'pending':'ok');
  const t = ev.created_at? new Date(ev.created_at*1000).toLocaleString() : '-';
  return `<div class="log-row">
    <div class="log-top"><span class="tag">${esc(ev.type)}</span><span class="tag ${lvl}">${esc(ev.level)}</span><span class="log-who">${esc(who)}</span><span class="log-time">${esc(t)}</span></div>
    <div class="log-msg">${esc(ev.message||'')}</div>
  </div>`;
}
// 用户配置筛选下拉（仅 admin 可见；tenant 角色用户由后端强制过滤，无需此控件）
async function tenantFilterHtml(){
  if (!isAdmin()) return '';
  let opts = '';
  try {
    const r = await api('GET','/api/tenants');
    opts = (r.data||[]).map(row=>`<option value="${row.id}" ${String(state.tenantFilter)===String(row.id)?'selected':''}>${esc(row.name)}</option>`).join('');
  } catch(e){}
  return `<div style="flex:0 0 220px"><label>用户配置筛选</label><select id="tf" onchange="state.tenantFilter=this.value;nav(state.view)"><option value="">全部用户配置</option>${opts}</select></div>`;
}
async function viewApplications(){
  const q = state.tenantFilter ? `?tenant_id=${state.tenantFilter}` : '';
  const [r, tf] = await Promise.all([api('GET','/api/applications'+q), tenantFilterHtml()]);
  state.apps = r.data||[];
  const cfg = {
    state, stateKey:'appsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (a, k) => ({id:a.id, name:a.name, app_eui:a.app_eui, cb:a.callback_url||'', time:a.created_at}[k]),
    cols:[
      {key:'id',      label:'ID',        type:'num', firstDir:'asc', sortable:false},
      {key:'name',    label:'名称',       type:'str', firstDir:'asc', sortable:false},
      {key:'app_eui', label:'AppEUI',    type:'str', firstDir:'asc', sortable:false},
      {key:'cb',      label:'回调 URL',   type:'str', firstDir:'asc', sortable:false},
      {key:'time',    label:'创建时间',   type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',          type:'raw'},
    ],
    rows: state.apps,
    rowHtml: a => `<tr><td>${a.id}</td><td>${esc(a.name)}</td><td class="muted">${esc(a.app_eui)}</td><td class="muted">${esc(a.callback_url||'')}</td><td class="muted">${new Date(a.created_at*1000).toLocaleString()}</td>
     <td>${adminBtn(`<button class="btn ghost" onclick="editApplication(${a.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delApplication(${a.id}))">删除</button>`)} <button class="btn ghost" onclick="newDevice(${a.id})">+ 设备</button></td></tr>`,
    emptyText:'暂无应用',
  };
  // 管理列表页分页通用套路：全量筛选+排序 → 取总数 → 切片当前页 → presorted 渲染 + buildPager
  const [filteredRows, filteredTotal] = filterAndSortRows(cfg);
  cfg.rows = paginateRows(filteredRows, state, {pageKey:'appsPage', limitKey:'appsLimit', offsetKey:'appsOffset'})[0];
  cfg.presorted = true;
  const table = buildSortableTable(cfg);
  const pager = buildPager({ total: filteredTotal, limit: state.appsLimit, offset: state.appsOffset, pageKey:'appsPage', limitKey:'appsLimit', offsetKey:'appsOffset', totalKey:'appsTotal', refresh:'viewApplications' });
  window.appsSort_sort = col => _tableToggleSort('appsSort','viewApplications',col);
  window.viewApplications__page = p => _pagerGo({pageKey:'appsPage',limitKey:'appsLimit',offsetKey:'appsOffset',totalKey:'appsTotal'},'viewApplications',p);
  window.viewApplications__limit = l => _pagerSetLimit({pageKey:'appsPage',limitKey:'appsLimit',offsetKey:'appsOffset',totalKey:'appsTotal'},'viewApplications',l);
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>应用</h2>${adminBtn('<button onclick="newApplication()">+ 新建应用</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}</div>
    ${table}
    ${pager}`;
}
async function viewDevices(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const q = [tq, state.devAppFilter ? ('app_id='+state.devAppFilter) : ''].filter(Boolean).join('&');
  const [r, ar, tf] = await Promise.all([
    api('GET','/api/devices'+(q?'?'+q:'')),
    api('GET','/api/applications'+(tq?'?'+tq:'')),
    tenantFilterHtml()
  ]);
  state.devs = r.data||[];
  const apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? esc(a.name) : ('#'+id); };
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.devAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');
  // 分类下拉筛选：激活方式 / Class / 在线状态 / 入网状态（全部在最前）
  const activationValues = [
    {value:'', label:'全部'},
    {value:'OTAA', label:'OTAA'},
    {value:'ABP',  label:'ABP'},
  ];
  const classValues = [
    {value:'', label:'全部'},
    {value:'A', label:'Class A'},
    {value:'B', label:'Class B'},
    {value:'C', label:'Class C'},
  ];
  const onlineValues = [
    {value:'', label:'全部'},
    {value:'online',  label:'在线'},
    {value:'offline', label:'离线'},
  ];
  const devStatusValues = [
    {value:'', label:'全部'},
    {value:'active',   label:'active · 已入网'},
    {value:'pending',  label:'pending · 待入网'},
    {value:'disabled', label:'disabled · 已禁用'},
  ];
  const devCfg = {
    state, stateKey:'devsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (d, k) => ({id:d.id, name:d.name, app:appName(d.app_id), activation:d.activation, cls:d.class, online:d.online==='online'?'online':'offline', dev_eui:d.dev_eui, dev_addr:d.dev_addr, status:d.status, time:+d.last_seen||0}[k]),
    cols:[
      {key:'id',         label:'ID',      type:'num', firstDir:'asc', sortable:false},
      {key:'name',       label:'名称',     type:'str', firstDir:'asc', sortable:false},
      {key:'app',        label:'应用',     type:'str', firstDir:'asc', sortable:false},
      {key:'activation', label:'激活',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.activation, values:activationValues}},
      {key:'cls',        label:'Class',   type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.class, values:classValues}},
      {key:'online',     label:'状态',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.online==='online'?'online':'offline', values:onlineValues}},
      {key:'dev_eui',    label:'DevEUI',  type:'str', firstDir:'asc', sortable:false},
      {key:'dev_addr',   label:'DevAddr', type:'str', firstDir:'asc', sortable:false},
      {key:'status',     label:'入网',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.status, values:devStatusValues}},
      {key:'time',       label:'最近/遥测', type:'time', firstDir:'desc'},
      {key:'_raw',       label:'',        type:'raw'},
    ],
    filterStatusList: [
      {col:'activation', value: state.devsFActivation},
      {col:'cls',        value: state.devsFCls},
      {col:'online',     value: state.devsFOnline},
      {col:'status',     value: state.devsFStatus},
    ],
    rows: state.devs,
    rowHtml: d => {
      const online = d.online==='online';
      const tel = [];
      if (d.battery!==null && d.battery!==undefined && +d.battery>=0) tel.push('电量'+(+d.battery===0?'外电':(+d.battery)+'%'));
      if (d.margin!==null && d.margin!==undefined && d.margin!=='') tel.push('余量'+(+d.margin)+'dB');
      if (d.latitude && +d.latitude!==0 && d.longitude!==null) tel.push('GPS '+ (+d.latitude).toFixed(5)+','+(+d.longitude).toFixed(5));
      const telStr = tel.length? `<div class="muted" style="font-size:11px">${tel.join(' · ')}</div>`:'';
      const seen = (d.last_seen_fmt && d.last_seen_fmt!=='-') ? d.last_seen_fmt : '-';
      return `<tr>
        <td>${d.id}</td><td>${esc(d.name)}</td>
        <td class="muted"><span class="pill" style="margin:0">${appName(d.app_id)}</span></td>
        <td><span class="tag">${d.activation}</span></td>
        <td><span class="tag ${d.class}">${d.class}</span></td>
        <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
        <td class="muted">${hex(d.dev_eui)}</td><td class="muted">${hex(d.dev_addr)}</td>
        <td><span class="tag ${d.status==='active'?'ok':'pending'}">${d.status}</span></td>
        <td class="muted">${seen}${telStr}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editDevice(${d.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDevice(${d.id}))">删除</button>`)} <button class="btn ghost" onclick="deviceDetail(${d.id})">密钥</button> <button class="btn ghost" onclick="downlink(${d.id})">下行</button></td></tr>`;
    },
    emptyText:'暂无设备',
  };
  const [filteredDevs, devsTotal] = filterAndSortRows(devCfg);
  devCfg.rows = paginateRows(filteredDevs, state, {pageKey:'devsPage', limitKey:'devsLimit', offsetKey:'devsOffset'})[0];
  devCfg.presorted = true;
  const table = buildSortableTable(devCfg);
  const pager = buildPager({ total: devsTotal, limit: state.devsLimit, offset: state.devsOffset, pageKey:'devsPage', limitKey:'devsLimit', offsetKey:'devsOffset', totalKey:'devsTotal', refresh:'viewDevices' });
  window.devsSort_sort = col => _tableToggleSort('devsSort','viewDevices',col);
  // 4 个分类列的下拉筛选：colKey → state 字段
  window.devsSort_fstatus = (col, v) => {
    const map = {activation:'devsFActivation', cls:'devsFCls', online:'devsFOnline', status:'devsFStatus'};
    _tableSetFStatus(map[col] || 'devsFStatus', 'viewDevices', v);
  };
  window.viewDevices__page = p => _pagerGo({pageKey:'devsPage',limitKey:'devsLimit',offsetKey:'devsOffset',totalKey:'devsTotal'},'viewDevices',p);
  window.viewDevices__limit = l => _pagerSetLimit({pageKey:'devsPage',limitKey:'devsLimit',offsetKey:'devsOffset',totalKey:'devsTotal'},'viewDevices',l);
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>设备</h2>${adminBtn('<button onclick="newDevice()">+ 添加设备</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 240px"><label>按应用筛选</label><select id="devAppFilter" onchange="state.devAppFilter=this.value;viewDevices()">${appOpts}</select></div>
    <button class="btn ghost" onclick="state.devAppFilter='';state.devsFActivation='';state.devsFCls='';state.devsFOnline='';state.devsFStatus='';state.devsSort={col:'time',dir:'desc'};viewDevices()">重置</button></div>
    ${table}
    ${pager}`;
}
async function deviceDetail(id){
  const r = await api('GET','/api/devices'); state.devs = r.data||[];
  const d=(state.devs||[]).find(x=>x.id===id); if(!d)return;
  // 设备密钥：readonly input，点一下自动复制 + 右上角 toast
  const kv=(label,val)=>`<label>${label}</label><input value="${esc(val||'')}" readonly style="cursor:pointer" title="点击自动复制" onclick="copyKeyField(this, '${label}')">`;
  openModal(`<h3>${t('设备密钥')} #${id} ${esc(d.name)}</h3>
    ${kv('DevEUI', d.dev_eui)}
    ${d.activation==='OTAA'
      ? kv('JoinEUI', d.join_eui) + kv('AppKey', d.app_key)
      : kv('DevAddr', d.dev_addr) + kv('NwkSKey', d.nwk_s_key) + kv('AppSKey', d.app_s_key)}
    <p class="muted" style="font-size:12px">点击任意输入框即可复制到剪贴板（右上角有提示）。修改请点"编辑"。</p>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
/**
 * 复制 readonly input 的值到剪贴板 + 右上角 toast
 *   优先用 navigator.clipboard（异步），失败降级 document.execCommand
 */
async function copyKeyField(input, label){
  const val = input.value || '';
  let ok = false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(val);
      ok = true;
    } else {
      input.select();
      document.execCommand && document.execCommand('copy');
      ok = true;
    }
  } catch (e) { ok = false; }
  toast(ok ? `${label} 已复制` : `复制失败，请手动选中`, ok ? 'copy' : 'err');
}
async function viewGateways(){
  const q = state.tenantFilter ? `?tenant_id=${state.tenantFilter}` : '';
  const [r, tf] = await Promise.all([api('GET','/api/gateways'+q), tenantFilterHtml()]);
  state.gws = r.data||[];
  const onlineValues = [
    {value:'', label:'全部'},
    {value:'online',  label:'在线'},
    {value:'offline', label:'离线'},
  ];
  const gwCfg = {
    state, stateKey:'gwsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (g, k) => ({gw_id:g.gw_id, name:g.name, online:g.status==='online'?'online':'offline', region:g.region||'', uplinks:g.uplinks||0, time:+g.last_seen||0}[k]),
    cols:[
      {key:'gw_id',   label:'GatewayID', type:'str', firstDir:'asc', sortable:false},
      {key:'name',    label:'名称',       type:'str', firstDir:'asc', sortable:false},
      {key:'online',  label:'状态',       type:'status', firstDir:'asc', sortable:false, opts:{getValue:g=>g.status==='online'?'online':'offline', values:onlineValues}},
      {key:'region',  label:'区域',       type:'str', firstDir:'asc', sortable:false},
      {key:'uplinks', label:'上行数',     type:'num', firstDir:'asc', sortable:false},
      {key:'time',    label:'最近心跳',   type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',          type:'raw'},
    ],
    filterStatus: {col:'online', value: state.gwsFOnline},
    rows: state.gws,
    rowHtml: g => {
      const online = g.status==='online';
      const seen = g.last_seen ? new Date(g.last_seen*1000).toLocaleString() : '-';
      return `<tr><td class="muted">${g.gw_id}</td><td>${esc(g.name)}</td>
        <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
        <td class="muted">${esc(g.region)}</td><td class="muted">${g.uplinks||0}</td><td class="muted">${seen}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editGateway('${g.gw_id}')">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delGateway('${g.gw_id}'))">删除</button>`)}</td></tr>`;
    },
    emptyText:'暂无网关（网关连接后自动出现，亦可手动添加）',
  };
  const [filteredGws, gwsTotal] = filterAndSortRows(gwCfg);
  gwCfg.rows = paginateRows(filteredGws, state, {pageKey:'gwsPage', limitKey:'gwsLimit', offsetKey:'gwsOffset'})[0];
  gwCfg.presorted = true;
  const table = buildSortableTable(gwCfg);
  const pager = buildPager({ total: gwsTotal, limit: state.gwsLimit, offset: state.gwsOffset, pageKey:'gwsPage', limitKey:'gwsLimit', offsetKey:'gwsOffset', totalKey:'gwsTotal', refresh:'viewGateways' });
  window.gwsSort_sort = col => _tableToggleSort('gwsSort','viewGateways',col);
  window.gwsSort_fstatus = (col, v) => _tableSetFStatus('gwsFOnline', 'viewGateways', v);
  window.viewGateways__page = p => _pagerGo({pageKey:'gwsPage',limitKey:'gwsLimit',offsetKey:'gwsOffset',totalKey:'gwsTotal'},'viewGateways',p);
  window.viewGateways__limit = l => _pagerSetLimit({pageKey:'gwsPage',limitKey:'gwsLimit',offsetKey:'gwsOffset',totalKey:'gwsTotal'},'viewGateways',l);
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>网关</h2>${adminBtn('<button onclick="newGateway()">+ 新建网关</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="state.gwsFOnline='';state.gwsSort={col:'time',dir:'desc'};viewGateways()">重置</button></div>
    ${table}
    ${pager}`;
}

async function viewUplinks(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const qs = [tq, state.upsFilter ? ('dev_id='+state.upsFilter) : '', state.upsAppFilter ? ('app_id='+state.upsAppFilter) : '', 'limit='+state.upsLimit, 'offset='+state.upsOffset].filter(Boolean).join('&');
  const r = await api('GET','/api/uplinks' + (qs ? '?'+qs : '')); state.ups = r.data||[];
  if (typeof r.total === 'number') state.upsTotal = r.total;
  // 设备下拉随应用筛选联动（后端 listDevices 支持 app_id）
  const devQ = [tq, state.upsAppFilter ? ('app_id='+state.upsAppFilter) : ''].filter(Boolean).join('&');
  const [dr, ar, tf] = await Promise.all([
    api('GET','/api/devices' + (devQ ? '?'+devQ : '')),
    api('GET','/api/applications' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  const devs = dr.data||[], apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? a.name : ('#'+id); };
  const devOpts = `<option value="">全部设备</option>` + devs.map(d=>`<option value="${d.id}" ${String(d.id)===String(state.upsFilter)?'selected':''}>#${d.id} ${esc(d.name)} (${hex(d.dev_eui)})</option>`).join('');
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.upsAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');
  const table = buildSortableTable({
    state, stateKey:'upsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (u, k) => ({id:u.id, app:appName(u.app_id), dev_addr:u.dev_addr, fcnt:u.fcnt, port:u.port, confirmed:u.confirmed?1:0, payload:u.decrypted_hex, text:hexToText(u.decrypted_hex), phy:u.phy_payload, gw:u.gateway_id, rssi_snr:(u.rssi??'-') + ' / ' + (u.snr??'-'), time:u.received_at}[k]),
    cols:[
      {key:'id',        label:'ID',                   type:'str', firstDir:'asc', sortable:false},
      {key:'app',       label:'应用',                  type:'str', firstDir:'asc', sortable:false},
      {key:'dev_addr',  label:'DevAddr',              type:'str', firstDir:'asc', sortable:false},
      {key:'fcnt',      label:'FCnt',                 type:'num', firstDir:'asc', sortable:false},
      {key:'port',      label:'Port',                 type:'num', firstDir:'asc', sortable:false},
      {key:'confirmed', label:'确认',                  type:'num', firstDir:'asc', sortable:false},
      {key:'payload',   label:'解密 payload (hex)',   type:'str', firstDir:'asc', sortable:false},
      {key:'text',      label:'解密 payload (文本)',  type:'str', firstDir:'asc', sortable:false},
      {key:'phy',       label:'原始帧 phy',           type:'str', firstDir:'asc', sortable:false},
      {key:'gw',        label:'网关',                  type:'str', firstDir:'asc', sortable:false},
      {key:'rssi_snr',  label:'RSSI / SNR',           type:'str', firstDir:'asc', sortable:false},
      {key:'time',      label:'时间',                  type:'time', firstDir:'desc'},
      {key:'_raw',      label:'',                     type:'raw'},
    ],
    rows: state.ups,
    rowHtml: u => {
      const textDisp = hexToText(u.decrypted_hex);
      return `<tr><td>${u.id}</td>
      <td class="muted"><span class="pill" style="margin:0">${esc(appName(u.app_id))}</span></td>
      <td class="muted"><a href="javascript:void(0)" style="color:var(--acc);text-decoration:none" onclick="deviceDetail(${u.dev_id})">${hex(u.dev_addr)}</a></td>
      <td>${u.fcnt}</td><td>${u.port}</td><td>${u.confirmed?'✓':'-'}</td>
      <td><code>${hex(u.decrypted_hex)}</code></td>
      <td class="muted" style="font-family:monospace;word-break:break-all;max-width:280px">${esc(textDisp)}</td>
      <td><code class="muted">${hex(u.phy_payload)}</code></td>
      <td class="muted">${u.gateway_id||'-'}</td>
      <td class="muted">${u.rssi} / ${u.snr}</td>
      <td class="muted">${new Date(u.received_at*1000).toLocaleString()}</td>
      <td><button class="btn ghost" onclick="showRaw(${u.id})">JSON</button></td></tr>`;
    },
    emptyText:'暂无上行',
  });
  const pager = buildPager({ total: state.upsTotal, limit: state.upsLimit, offset: state.upsOffset, pageKey:'upsPage', limitKey:'upsLimit', offsetKey:'upsOffset', totalKey:'upsTotal', refresh:'viewUplinks' });
  document.getElementById('view').innerHTML = `<h2>上行消息日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="upAppFilter" onchange="state.upsAppFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="upFilter" onchange="state.upsFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="state.upsFilter='';state.upsAppFilter='';state.upsSort={col:'time',dir:'desc'};state.upsPage=1;state.upsOffset=0;state.upsLimit=50;state.tenantFilter='';viewUplinks()">重置</button>
    </div>
    <p class="muted">应用维度已在每行"应用"标签中区分；phy 列为原始 LoRaWAN 帧（hex）；点 DevAddr 跳转到设备；点"JSON"查看网关上报元数据。点击表头可排序（再次点击切换方向，第三次清除）。</p>
    ${table}
    ${pager}`;
  window.upsSort_sort = col => _tableToggleSort('upsSort','viewUplinks',col);
  window.viewUplinks__page = p => _pagerGo({pageKey:'upsPage',limitKey:'upsLimit',offsetKey:'upsOffset',totalKey:'upsTotal'},'viewUplinks',p);
  window.viewUplinks__limit = l => _pagerSetLimit({pageKey:'upsPage',limitKey:'upsLimit',offsetKey:'upsOffset',totalKey:'upsTotal'},'viewUplinks',l);
}
async function showRaw(id){
  const u=(state.ups||[]).find(x=>x.id===id); if(!u)return;
  let j={}; try { j = u.raw_json ? JSON.parse(u.raw_json) : {}; } catch(e){}
  openModal(`<h3>${t('原始 JSON')} #${id}</h3><pre>${esc(JSON.stringify(j,null,2))}</pre><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

// ----------------------- 下行日志模块 -----------------------
const DL_STATUS = {
  pending:    {label:'待发送', cls:'pending'},
  scheduled:  {label:'已调度', cls:'pending'},
  sent:       {label:'已发送', cls:'ok'},
  acknowledged:{label:'已确认', cls:'ok'},
  failed:     {label:'失败',   cls:'err'},
  timeout:    {label:'超时',   cls:'err'},
  error:      {label:'错误',   cls:'err'}
};
async function viewDownlinks(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const qs = [tq, state.dlDevFilter ? ('dev_id='+state.dlDevFilter) : '', state.dlAppFilter ? ('app_id='+state.dlAppFilter) : '', 'limit='+state.dlsLimit, 'offset='+state.dlsOffset].filter(Boolean).join('&');
  const r = await api('GET','/api/downlinks' + (qs ? '?'+qs : '')); state.dls = r.data||[];
  if (typeof r.total === 'number') state.dlsTotal = r.total;
  // 设备下拉随应用筛选联动
  const devQ = [tq, state.dlAppFilter ? ('app_id='+state.dlAppFilter) : ''].filter(Boolean).join('&');
  const [dr, ar, tf] = await Promise.all([
    api('GET','/api/devices' + (devQ ? '?'+devQ : '')),
    api('GET','/api/applications' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  const devs = dr.data||[], apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? a.name : ('#'+id); };
  const devName = id => { const d = devs.find(x=>x.id===id); return d ? (d.name+' (#'+id+')') : ('#'+id); };
  const devOpts = `<option value="">全部设备</option>` + devs.map(d=>`<option value="${d.id}" ${String(d.id)===String(state.dlDevFilter)?'selected':''}>#${d.id} ${esc(d.name)} (${hex(d.dev_eui)})</option>`).join('');
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.dlAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');
  // 状态筛选：状态列下拉；按 DL_STATUS 全部枚举 + 1 个"全部"
  const statusValues = [
    {value:'',label:'全部'},
    ...Object.entries(DL_STATUS).map(([k,v]) => ({value:k,label:v.label})),
  ];
  const table = buildSortableTable({
    state, stateKey:'dlsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (d, k) => ({id:d.id, app:appName(d.app_id), dev:devName(d.dev_id), port:d.port, confirmed:d.confirmed?1:0, payload:d.payload_hex, text:hexToText(d.payload_hex), status:d.status, time:d.created_at||d.sent_at, tx:d.transmissions||0, ack:d.acknowledged_at}[k]),
    cols:[
      {key:'id',        label:'ID',        type:'num', firstDir:'asc', sortable:false},
      {key:'app',       label:'应用',       type:'str', firstDir:'asc', sortable:false},
      {key:'dev',       label:'设备',       type:'str', firstDir:'asc', sortable:false},
      {key:'port',      label:'FPort',     type:'num', firstDir:'asc', sortable:false},
      {key:'confirmed', label:'确认',       type:'num', firstDir:'asc', sortable:false},
      {key:'payload',   label:'负载 (hex)',  type:'str', firstDir:'asc', sortable:false},
      {key:'text',      label:'负载 (文本)', type:'str', firstDir:'asc', sortable:false},
      {key:'status',    label:'状态',       type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: d => d.status,
        values: statusValues,
      }},
      {key:'time',      label:'发送时间',   type:'time', firstDir:'desc'},
      {key:'tx',        label:'重传',       type:'num', firstDir:'asc', sortable:false},
      {key:'ack',       label:'确认时间',   type:'time', firstDir:'desc'},
      {key:'_raw',      label:'',          type:'raw'},
    ],
    rows: state.dls,
    rowHtml: d => {
      const st = DL_STATUS[d.status] || {label:d.status||'-', cls:''};
      const sent = d.sent_at ? new Date(d.sent_at*1000).toLocaleString() : '—';
      const ack = d.acknowledged_at ? new Date(d.acknowledged_at*1000).toLocaleString() : '—';
      const textDisp = hexToText(d.payload_hex);
      return `<tr><td>${d.id}</td>
        <td class="muted"><span class="pill" style="margin:0">${esc(appName(d.app_id))}</span></td>
        <td class="muted">${esc(devName(d.dev_id))}</td>
        <td>${d.port}</td><td>${d.confirmed?'✓':'-'}</td>
        <td><code>${hex(d.payload_hex)}</code></td>
        <td class="muted" style="font-family:monospace;word-break:break-all;max-width:280px">${esc(textDisp)}</td>
        <td><span class="tag ${st.cls}">${st.label}</span></td>
        <td class="muted">${sent}</td><td class="muted">${d.transmissions||0}</td>
        <td class="muted">${ack}</td>
        <td><button class="btn ghost" onclick="showDownlinkRaw(${d.id})">JSON</button></td></tr>`;
    },
    emptyText:'暂无下行',
  });
  const pager = buildPager({ total: state.dlsTotal, limit: state.dlsLimit, offset: state.dlsOffset, pageKey:'dlsPage', limitKey:'dlsLimit', offsetKey:'dlsOffset', totalKey:'dlsTotal', refresh:'viewDownlinks' });
  document.getElementById('view').innerHTML = `<h2>下行消息日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="dlAppFilter" onchange="state.dlAppFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="dlDevFilter" onchange="state.dlDevFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="state.dlDevFilter='';state.dlAppFilter='';state.dlsSort={col:'time',dir:'desc'};state.dlsPage=1;state.dlsOffset=0;state.dlsLimit=50;state.dlsFStatus='';state.tenantFilter='';viewDownlinks()">重置</button>
    </div>
    <p class="muted">点"JSON"查看下行记录的结构化（格式化）与解析展示（含 payload 解码）。点击表头可排序（再次点击切换方向，第三次清除）；状态列是下拉筛选器。</p>
    ${table}
    ${pager}`;
  window.dlsSort_sort = col => _tableToggleSort('dlsSort','viewDownlinks',col);
  window.dlsSort_fstatus = (col, v) => _tableSetFStatus('dlsFStatus', 'viewDownlinks', v);
  window.viewDownlinks__page = p => _pagerGo({pageKey:'dlsPage',limitKey:'dlsLimit',offsetKey:'dlsOffset',totalKey:'dlsTotal'},'viewDownlinks',p);
  window.viewDownlinks__limit = l => _pagerSetLimit({pageKey:'dlsPage',limitKey:'dlsLimit',offsetKey:'dlsOffset',totalKey:'dlsTotal'},'viewDownlinks',l);
}
async function showDownlinkRaw(id){
  const d=(state.dls||[]).find(x=>x.id===id); if(!d)return;
  // 解析 payload_hex -> 字节数组 + 可打印 ASCII
  let bytes=[], ascii='';
  const hexStr = (d.payload_hex||'').replace(/\s+/g,'');
  for (let i=0;i<hexStr.length;i+=2){ const b=parseInt(hexStr.substr(i,2),16); bytes.push(b); ascii += (b>=32&&b<127)?String.fromCharCode(b):'.'; }
  const parsed = {
    id: d.id, app_id: d.app_id, dev_id: d.dev_id,
    port: d.port, confirmed: !!d.confirmed, fcnt: d.fcnt,
    status: d.status, transmissions: d.transmissions||0,
    created_at: d.created_at ? new Date(d.created_at*1000).toISOString() : null,
    sent_at: d.sent_at ? new Date(d.sent_at*1000).toISOString() : null,
    acknowledged_at: d.acknowledged_at ? new Date(d.acknowledged_at*1000).toISOString() : null,
    payload_hex: d.payload_hex||'',
    payload_bytes: bytes,
    payload_ascii: ascii
  };
  const pretty = esc(JSON.stringify(parsed, null, 2));
  const hexRows = bytes.length ? bytes.map((b,i)=>`<span class="mono">${hexStr.substr(i*2,2).toUpperCase()}</span>`).join(' ') : '(空)';
  openModal(`<h3>${t('下行 JSON')} #${id}</h3>
    <p class="muted" style="margin:4px 0 10px">格式化结构（payload 已解析为字节数组与 ASCII）</p>
    <pre>${pretty}</pre>
    <h4 style="margin:16px 0 6px">Payload 十六进制字节</h4>
    <div class="mono" style="line-height:1.9">${hexRows}</div>
    <h4 style="margin:14px 0 6px">ASCII</h4>
    <div class="mono">${esc(ascii)||'(不可打印)'}</div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

async function viewEvents(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  // 拉取设备/网关列表供下拉筛选（随租户筛选联动）
  const [rd, rg, tf] = await Promise.all([
    api('GET','/api/devices' + (tq ? '?'+tq : '')),
    api('GET','/api/gateways' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  state.devs = rd.data||[]; state.gws = rg.data||[];
  // 构建筛选查询串
  let q = [];
  if (tq) q.push(tq);
  if (state.evsDevFilter) q.push('dev_id=' + state.evsDevFilter);
  if (state.evsGwFilter)  q.push('gw_id=' + encodeURIComponent(state.evsGwFilter));
  if (state.evsFType)     q.push('type=' + encodeURIComponent(state.evsFType));
  q.push('limit=' + state.evsLimit);
  q.push('offset=' + state.evsOffset);
  const qs = q.length ? ('?' + q.join('&')) : '';
  const r = await api('GET','/api/events' + qs); state.evs = r.data||[];
  if (typeof r.total === 'number') state.evsTotal = r.total;
  const devOpts = ['<option value="">全部设备</option>'].concat(
    state.devs.map(d=>`<option value="${d.id}" ${String(d.id)===state.evsDevFilter?'selected':''}>${esc(d.name)} · ${hex(d.dev_eui)}</option>`)
  ).join('');
  const gwOpts = ['<option value="">全部网关</option>'].concat(
    state.gws.map(g=>`<option value="${esc(g.gw_id)}" ${g.gw_id===state.evsGwFilter?'selected':''}>${esc(g.gw_id)} · ${esc(g.name)}</option>`)
  ).join('');
  // 级别筛选下拉（info / warn / error / 全部）
  //   「全部」放在最前以符合用户习惯；排序按下标 0/1/2 走业务严重度（失败优先）
  const levelValues = [
    {value:'', label:'全部'},
    {value:'info',  label:'info · 信息'},
    {value:'warn',  label:'warn · 警告'},
    {value:'error', label:'error · 错误'},
  ];
  // 类型筛选下拉：与 NetworkServer::logEvent 写入的 type 对齐（真实 DB 事件类型）
  //   「全部」在最前；option 顺序即业务顺序（网关上下线 → 入网 → 上下行 → 确认 → FUOTA）
  const typeValues = [
    {value:'',       label:'全部'},
    {value:'gateway', label:'网关上下线'},
    {value:'join',    label:'入网 join'},
    {value:'uplink',  label:'上行 uplink'},
    {value:'downlink',label:'下行 downlink'},
    {value:'txack',   label:'发射确认 txack'},
    {value:'ack',     label:'确认 ack'},
    {value:'fuota',   label:'FUOTA'},
    {value:'status',  label:'状态 status'},
  ];
  const table = buildSortableTable({
    state, stateKey:'evsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (e, k) => ({type:e.type, level:e.level, who:(e.gateway_id?('gw '+e.gateway_id):(e.dev_id?('dev #'+e.dev_id):'')), msg:e.message, time:e.created_at}[k]),
    cols:[
      {key:'type',  label:'类型',  type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: e => e.type, values: typeValues,
      }},
      {key:'level', label:'级别',  type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: e => e.level, values: levelValues,
      }},
      {key:'who',   label:'对象',  type:'str',    firstDir:'asc', sortable:false},
      {key:'msg',   label:'消息',  type:'str',    firstDir:'asc', sortable:false},
      {key:'time',  label:'时间',  type:'time',   firstDir:'desc'},
      {key:'_raw',  label:'',     type:'raw'},
    ],
    filterStatusList: [
      {col:'type',  value: state.evsFType},
      {col:'level', value: state.evsFLevel},
    ],
    rows: state.evs,
    rowHtml: e => {
      const lvl = e.level==='error' ? 'err' : (e.level==='warn' ? 'pending' : 'ok');
      const who = e.gateway_id ? ('gw '+e.gateway_id) : (e.dev_id ? ('dev #'+e.dev_id) : '');
      // 类型徽章按真实事件类型分色：join 绿 / downlink 黄 / uplink 蓝灰 / gateway 灰 / 其余默认
      const tCls = e.type==='join' ? 'ok' : (e.type==='downlink' || e.type==='txack' ? 'pending' : (e.type==='gateway' ? 'muted' : ''));
      return `<tr><td><span class="tag ${tCls}">${esc(e.type)}</span></td><td><span class="tag ${lvl}">${e.level}</span></td>
        <td class="muted">${esc(who)}</td><td>${esc(e.message)}</td><td class="muted">${new Date(e.created_at*1000).toLocaleString()}</td>
        <td><button class="btn ghost" onclick="showEventRaw(${e.id})">JSON</button></td></tr>`;
    },
    emptyText:'暂无事件',
  });
  const pager = buildPager({ total: state.evsTotal, limit: state.evsLimit, offset: state.evsOffset, pageKey:'evsPage', limitKey:'evsLimit', offsetKey:'evsOffset', totalKey:'evsTotal', refresh:'viewEvents' });
  document.getElementById('view').innerHTML = `<h2>网关日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="evs_dev" onchange="state.evsDevFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${devOpts}</select></div>
      <div style="flex:0 0 300px"><label>按网关筛选</label><select id="evs_gw" onchange="state.evsGwFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${gwOpts}</select></div>
      <button class="btn ghost" onclick="state.evsDevFilter=''; state.evsGwFilter=''; state.evsSort={col:'time',dir:'desc'}; state.evsFType=''; state.evsFLevel=''; state.evsPage=1; state.evsOffset=0; state.evsLimit=50; state.tenantFilter=''; viewEvents()">重置</button>
    </div>
    <p class="muted">网关上下线 / 入网 / 上行 / 下行 / 错误等事件。点"JSON"查看事件原始数据，上行事件的 JSON 含网关上报元数据（rxpk）。点击表头可排序（仅时间列），类型与级别列是下拉筛选器。</p>
    ${table}
    ${pager}`;
  window.evsSort_sort = col => _tableToggleSort('evsSort','viewEvents',col);
  window.evsSort_fstatus = (col, v) => _tableSetFStatus(col === 'type' ? 'evsFType' : 'evsFLevel', 'viewEvents', v);
  window.viewEvents__page = p => _pagerGo({pageKey:'evsPage',limitKey:'evsLimit',offsetKey:'evsOffset',totalKey:'evsTotal'},'viewEvents',p);
  window.viewEvents__limit = l => _pagerSetLimit({pageKey:'evsPage',limitKey:'evsLimit',offsetKey:'evsOffset',totalKey:'evsTotal'},'viewEvents',l);
}
async function showEventRaw(id){
  const e=(state.evs||[]).find(x=>x.id===id); if(!e)return;
  let j={}; try { j = e.raw_json ? JSON.parse(e.raw_json) : {}; } catch(err){}
  if (!Object.keys(j).length) { j = e; delete j.raw_json; }
  openModal(`<h3>${t('事件 JSON')} #${id}</h3><pre>${esc(JSON.stringify(j,null,2))}</pre><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
async function viewUsers(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/users'); state.users = r.data||[];
  const userCfg = {
    state, stateKey:'usersSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (u, k) => ({id:u.id, username:u.username, role:u.role, tenant:u.tenant_id||0, time:u.created_at}[k]),
    cols:[
      {key:'id',       label:'ID',       type:'num', firstDir:'asc', sortable:false},
      {key:'username', label:'用户名',    type:'str', firstDir:'asc', sortable:false},
      {key:'role',     label:'角色',      type:'str', firstDir:'asc', sortable:false},
      {key:'tenant',   label:'用户配置',  type:'num', firstDir:'asc', sortable:false},
      {key:'time',     label:'创建时间',  type:'time', firstDir:'desc'},
      {key:'_raw',     label:'',         type:'raw'},
    ],
    rows: state.users,
    rowHtml: u => `<tr><td>${u.id}</td><td>${esc(u.username)}</td><td><span class="tag">${u.role}</span></td>
     <td class="muted">${u.tenant_id ? esc(u.tenant_name || ('#用户配置'+u.tenant_id)) : '—'}</td>
     <td class="muted">${new Date(u.created_at*1000).toLocaleString()}</td>
     <td><button class="btn danger" onclick="busy('删除中…', ()=>delUser(${u.id}))">删除</button> <button class="btn ghost" onclick="changePwFor(${u.id})">改密</button></td></tr>`,
    emptyText:'暂无用户',
  };
  const [filteredUsers, usersTotal] = filterAndSortRows(userCfg);
  userCfg.rows = paginateRows(filteredUsers, state, {pageKey:'usersPage', limitKey:'usersLimit', offsetKey:'usersOffset'})[0];
  userCfg.presorted = true;
  const table = buildSortableTable(userCfg);
  const pager = buildPager({ total: usersTotal, limit: state.usersLimit, offset: state.usersOffset, pageKey:'usersPage', limitKey:'usersLimit', offsetKey:'usersOffset', totalKey:'usersTotal', refresh:'viewUsers' });
  window.usersSort_sort = col => _tableToggleSort('usersSort','viewUsers',col);
  window.viewUsers__page = p => _pagerGo({pageKey:'usersPage',limitKey:'usersLimit',offsetKey:'usersOffset',totalKey:'usersTotal'},'viewUsers',p);
  window.viewUsers__limit = l => _pagerSetLimit({pageKey:'usersPage',limitKey:'usersLimit',offsetKey:'usersOffset',totalKey:'usersTotal'},'viewUsers',l);
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>用户管理</h2><button onclick="newUser()">+ 新建用户</button></div>
    ${table}
    ${pager}`;
}

// ================= API 调用日志 =================
// admin 看全部 + 租户/应用/IP 筛选；tenant 仅看本租户 + 应用筛选；operator 演示可看全部只读
async function viewApiLogs(){
  const showTenant = isAdmin() || isDemo();
  const params = [];
  if (state.apiLogFilter.path) params.push('path_contains=' + encodeURIComponent(state.apiLogFilter.path));
  if (state.apiLogFilter.ip) params.push('ip=' + encodeURIComponent(state.apiLogFilter.ip));
  // 状态筛选改为本地（更灵活：支持 2xx/3xx/4xx/5xx/具体码 几类合一）
  if (state.apiLogFilter.method) params.push('method=' + state.apiLogFilter.method);
  if (showTenant && state.apiLogFilter.tenant_id) params.push('tenant_id=' + state.apiLogFilter.tenant_id);
  if (state.apiLogFilter.application_id) params.push('application_id=' + state.apiLogFilter.application_id);
  // 分页：每页条数 + 当前偏移（由 buildPager / _pagerGo 维护）
  params.push('limit=' + (state.apiLogLimit|0 || 50));
  params.push('offset=' + (state.apiLogOffset|0 || 0));
  const url = '/api/api-logs' + (params.length ? '?' + params.join('&') : '');
  const r = await api('GET', url);
  const rowsAll = (r.data || []);
  state.apiLogTotal = +r.total || 0;
  // 拉取租户/应用下拉选项（admin）
  let tenantOpts = '';
  let appOpts = '';
  if (showTenant) {
    try { const tr = await api('GET','/api/tenants'); tenantOpts = (tr.data||[]).map(x=>`<option value="${x.id}" ${String(state.apiLogFilter.tenant_id)===String(x.id)?'selected':''}>${esc(x.name)}</option>`).join(''); } catch(e){}
  }
  try {
    const aq = showTenant && state.apiLogFilter.tenant_id ? ('?tenant_id=' + state.apiLogFilter.tenant_id) : '';
    const ar = await api('GET', '/api/applications' + aq);
    appOpts = (ar.data||[]).map(x=>`<option value="${x.id}" ${String(state.apiLogFilter.application_id)===String(x.id)?'selected':''}>${esc(x.name)}</option>`).join('');
  } catch(e){}
  const statusTag = s => {
    if (!s) return `<span class="tag">-</span>`;
    if (s>=200 && s<300) return `<span class="tag ok">${s}</span>`;
    if (s>=400 && s<500) return `<span class="tag err">${s}</span>`;
    if (s>=500) return `<span class="tag pending">${s}</span>`;
    return `<span class="tag">${s}</span>`;
  };
  // 状态分类下拉：全部 / 2xx / 3xx / 4xx / 5xx / 具体码（在前端筛选）
  const statusValues = [
    {value:'',label:'全部', match: () => true},
    {value:'2xx',label:'2xx 成功', match: s => s>=200 && s<300},
    {value:'3xx',label:'3xx 重定向', match: s => s>=300 && s<400},
    {value:'4xx',label:'4xx 客户端错', match: s => s>=400 && s<500},
    {value:'5xx',label:'5xx 服务端错', match: s => s>=500 && s<600},
  ];
  const table = buildSortableTable({
    state, stateKey:'apiLogSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (r, k) => ({time:r.created_at, method:r.method, path:r.path, status:r.status, latency:r.latency_ms, ip:r.ip, user:r.username||'', tenant:r.tenant_id||0, app:r.application_id||0, body:r.body_size||0}[k]),
    cols:[
      {key:'time',    label:t('时间'), type:'time', firstDir:'desc'},
      {key:'method',  label:t('方法'), type:'str',  firstDir:'asc', sortable:false},
      {key:'path',    label:t('路径'), type:'str',  firstDir:'asc', sortable:false},
      {key:'status',  label:t('状态'), type:'status', firstDir:'asc', sortable:false, opts:{getValue: r => r.status, values: statusValues}},
      {key:'latency', label:t('耗时'), type:'num',  firstDir:'asc', sortable:false},
      {key:'ip',      label:t('IP'),   type:'str',  firstDir:'asc', sortable:false},
      {key:'user',    label:t('用户'), type:'str',  firstDir:'asc', sortable:false},
      ...(showTenant ? [{key:'tenant', label:t('租户'), type:'num', firstDir:'asc', sortable:false}] : []),
      {key:'app',     label:t('应用'), type:'num',  firstDir:'asc', sortable:false},
      {key:'body',    label:t('Body'), type:'num', firstDir:'asc', sortable:false},
    ],
    filterStatus: {col:'status', value: state.apiLogFStatus},
    rows: rowsAll,
    rowHtml: r => `<tr>
      <td class="muted">${new Date(r.created_at*1000).toLocaleString()}</td>
      <td><span class="tag">${esc(r.method)}</span></td>
      <td class="muted" style="font-family:monospace;font-size:12px;word-break:break-all">${esc(r.path)}${r.query ? '?' + esc(r.query) : ''}</td>
      <td>${statusTag(r.status)}</td>
      <td class="muted">${r.latency_ms}ms</td>
      <td class="muted" style="font-family:monospace">${esc(r.ip||'-')}</td>
      <td class="muted">${esc(r.username||'-')}${r.role?` <span class="tag">${esc(r.role)}</span>`:''}</td>
      ${showTenant ? `<td class="muted">${r.tenant_id?('#'+r.tenant_id):'-'}</td>` : ''}
      <td class="muted">${r.application_id?('#'+r.application_id):'-'}</td>
      <td class="muted">${r.body_size||0}B</td>
    </tr>`,
    emptyText: t('暂无日志'),
  });
  const filterId = (k) => 'alf_' + k;
  const pager = buildPager({ total: state.apiLogTotal, limit: state.apiLogLimit, offset: state.apiLogOffset, pageKey:'apiLogPage', limitKey:'apiLogLimit', offsetKey:'apiLogOffset', totalKey:'apiLogTotal', refresh:'viewApiLogs' });
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>${t('API 调用日志')}</h2><div class="muted" style="font-size:12px">${t('共')} ${state.apiLogTotal} ${t('条')}${t('（仅保留最近 10000 条）')}</div></div>
   <div class="card" style="margin-bottom:12px">
     <div class="row" style="align-items:flex-end">
       <div><label>${t('路径包含')}</label><input id="${filterId('path')}" value="${esc(state.apiLogFilter.path||'')}" placeholder="/v1/devices"></div>
       <div><label>${t('IP')}</label><input id="${filterId('ip')}" value="${esc(state.apiLogFilter.ip||'')}" placeholder="192.168.1.1"></div>
       <div><label>${t('方法')}</label><select id="${filterId('method')}">
         <option value="">${t('全部')}</option>
         <option value="GET" ${state.apiLogFilter.method==='GET'?'selected':''}>GET</option>
         <option value="POST" ${state.apiLogFilter.method==='POST'?'selected':''}>POST</option>
         <option value="PUT" ${state.apiLogFilter.method==='PUT'?'selected':''}>PUT</option>
         <option value="DELETE" ${state.apiLogFilter.method==='DELETE'?'selected':''}>DELETE</option>
       </select></div>
       ${showTenant ? `<div><label>${t('租户')}</label><select id="${filterId('tenant_id')}"><option value="">${t('全部租户')}</option>${tenantOpts}</select></div>` : ''}
       <div><label>${t('应用')}</label><select id="${filterId('application_id')}"><option value="">${t('全部应用')}</option>${appOpts}</select></div>
       <div style="flex:0 0 auto"><button onclick="applyApiLogFilter()">${t('应用筛选')}</button> <button class="ghost" onclick="resetApiLogFilter()">${t('重置')}</button></div>
     </div>
   </div>
   <p class="muted" style="margin:-4px 0 10px">${t('点击表头可排序（再次点击切换方向，第三次清除）；状态列是下拉筛选器（全部/2xx/3xx/4xx/5xx/具体码）。')}</p>
   ${table}
   ${pager}`;
  window.apiLogSort_sort = col => _tableToggleSort('apiLogSort','viewApiLogs',col);
  window.apiLogSort_fstatus = (col, v) => _tableSetFStatus('apiLogFStatus', 'viewApiLogs', v);
  window.viewApiLogs__page = p => _pagerGo({pageKey:'apiLogPage',limitKey:'apiLogLimit',offsetKey:'apiLogOffset',totalKey:'apiLogTotal'},'viewApiLogs',p);
  window.viewApiLogs__limit = l => _pagerSetLimit({pageKey:'apiLogPage',limitKey:'apiLogLimit',offsetKey:'apiLogOffset',totalKey:'apiLogTotal'},'viewApiLogs',l);
}
function applyApiLogFilter(){
  const get = k => (document.getElementById('alf_' + k) || {}).value || '';
  state.apiLogFilter = {
    path: get('path').trim(),
    ip: get('ip').trim(),
    status: '',
    method: get('method'),
    tenant_id: get('tenant_id'),
    application_id: get('application_id'),
  };
  state.apiLogFStatus = '';
  state.apiLogSort = {col:'time',dir:'desc'};
  state.apiLogPage = 1;
  state.apiLogOffset = 0;
  viewApiLogs();
}
function resetApiLogFilter(){
  state.apiLogFilter = { path:'', ip:'', status:'', method:'', tenant_id:'', application_id:'' };
  state.apiLogFStatus = '';
  state.apiLogSort = {col:'time',dir:'desc'};
  state.apiLogPage = 1;
  state.apiLogOffset = 0;
  state.apiLogLimit = 50;
  viewApiLogs();
}

// ================= 站点设置（仅 admin） =================
async function viewSettings(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/settings'); const s = r.data||{};
  const val = (k) => esc(s[k] || '');
  document.getElementById('view').innerHTML = `<h2>站点设置</h2>
   <div class="card" style="max-width:680px">
     <label>网站名称</label><input id="st_name" value="${val('site_name')}" placeholder="HolaStack">
     <label>顶部图标 URL（可选，留空则显示文字名称）</label><input id="st_logo" value="${val('site_logo_url')}" placeholder="https://example.com/logo.png">
     <label>站点 Favicon URL（可选，浏览器标签页小图标，推荐 .ico/.png/.svg）</label><input id="st_favicon" value="${val('favicon_url')}" placeholder="https://example.com/favicon.ico">
     <label>登录页 LOGO 图片 URL（可选）</label><input id="st_login_img" value="${val('login_logo_url')}" placeholder="https://example.com/login-logo.png">
     <label>登录页 LOGO 文字（无图片时显示）</label><input id="st_login_text" value="${val('login_logo_text')}" placeholder="HolaStack">
     <label>登录页公告（留空则隐藏公告框，支持多行）</label><textarea id="st_notice" rows="3" placeholder="例如：系统将于本周六 23:00 停机维护。">${esc(s.login_notice||'')}</textarea>
     <label>页面底部 Footer（支持 HTML，留空使用默认"© 年份 网站名称"，可用占位符 {Y}/{YEAR}/{SITE}）</label><textarea id="st_footer" rows="2" placeholder="&copy; {Y} {SITE}">${esc(s.footer||'')}</textarea>
     <label>API 基础地址（用于 API 文档页的 curl 示例链接，留空则用当前站点地址）</label><input id="st_api_url" value="${val('api_base_url')}" placeholder="https://your-server.example.com">
     <label>界面语言</label><select id="st_lang">${(window.LANGS||{zh:'中文'}) && Object.entries(window.LANGS||{zh:'中文'}).map(([k,n])=>`<option value="${k}" ${s.ui_lang===k?'selected':''}>${n}</option>`).join('')}</select>
     <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
       <button class="ghost" onclick="nav('dashboard')">取消</button>
       <button onclick="busy('保存中…', saveSettings)">保存</button>
     </div>
   </div>`;
}
async function saveSettings(){
  const langSel = document.getElementById('st_lang');
  const body = {
    site_name: v('st_name'),
    site_logo_url: v('st_logo'),
    favicon_url: v('st_favicon'),
    login_logo_url: v('st_login_img'),
    login_logo_text: v('st_login_text'),
    login_notice: v('st_notice'),
    footer: v('st_footer'),
    api_base_url: v('st_api_url'),
    ui_lang: langSel ? langSel.value : 'zh',
  };
  const r = await api('POST','/api/settings', body);
  if (r.error) { toast(r.error, 'err'); return; }
  await applyPublicSettings();
  // 应用新语言：拉取对应字典并静默重渲染当前页（模板源语言为中文，双向切换都正确）
  await applyLanguage(body.ui_lang);
  toast(t('设置已保存'), 'ok');
}
// 拉取公开设置并应用到顶栏品牌、登录页 LOGO、页面标题（无需登录即可读取）
async function applyPublicSettings(){
  try {
    const r = await fetch('/api/public-settings');
    const d = (await r.json()).data || {};
    const brand = document.getElementById('brand');
    if (brand) {
      if (d.site_logo_url) brand.innerHTML = `<a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit"><img src="${esc(d.site_logo_url)}" alt="logo"></a>`;
      else brand.innerHTML = `<a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit">${esc(d.site_name || 'HolaStack')}</a>`;
    }
    const ll = document.getElementById('loginLogo');
    if (ll) {
      if (d.login_logo_url) ll.innerHTML = `<img src="${esc(d.login_logo_url)}" alt="logo">`;
      else if (d.login_logo_text) ll.innerHTML = `<div style="font-size:24px;font-weight:700;color:var(--txt)">${esc(d.login_logo_text)}</div>`;
      else ll.innerHTML = '';
    }
    if (d.site_name) document.title = d.site_name;
    // API 文档页 curl 示例的基础地址（留空则用当前站点 origin）
    window.ELW_API_BASE_URL = d.api_base_url || '';
    const fav = document.getElementById('faviconLink');
    if (fav && d.favicon_url) fav.href = d.favicon_url;
    // 页脚：服务端给的是 HTML 字符串，允许富文本（链接/版权符号等）；仅剥离 <script> 防止 XSS
    // 注意：登录框内不再显示 footer，避免与底部页脚重复
    const siteName = d.site_name || 'HolaStack';
    const rawFooter = d.footer || ('© ' + new Date().getFullYear() + ' ' + siteName);
    const safeFooter = String(rawFooter).replace(/<script[\s\S]*?<\/script>/gi, '');
    const lf = document.getElementById('loginFooter');
    if (lf) { lf.innerHTML = ''; lf.classList.add('hidden'); }
    const sf = document.getElementById('siteFooter');
    if (sf) { sf.innerHTML = safeFooter; sf.classList.remove('hidden'); }
    const ln = document.getElementById('loginNotice');
    if (ln) {
      if (d.login_notice && d.login_notice.trim()) {
        ln.innerHTML = `<span class="ln-ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg></span><span class="ln-txt">${esc(d.login_notice)}</span>`;
        // 单行公告整体居中；多行（含换行）保持左对齐便于阅读
        ln.classList.toggle('single', !/(\r\n|\n|\r)/.test(d.login_notice.trim()));
        ln.classList.remove('hidden');
      }
      else { ln.innerHTML = ''; ln.classList.add('hidden'); }
    }
  } catch(e) {}
}
async function changePw(){
  // operator（演示）只读，禁止修改密码（含自己的）
  if (isDemo()) {
    toast(t('演示模式：当前为只读账号，不能修改密码'), 'warn');
    return;
  }
  let targetSel = '';
  if (isAdmin()) {
    const r = await api('GET','/api/users');
    targetSel = `<label>目标用户（管理员可改他人；留空=自己）</label><select id="m_pw_uid"><option value="">我自己</option>${(r.data||[]).map(u=>`<option value="${u.id}">${esc(u.username)}</option>`).join('')}</select>`;
  }
  openModal(`<h3>修改密码</h3>${targetSel}
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', savePw)">保存</button></div>`);
}
async function savePw(){
  const np=v('m_pw_new'), cf=v('m_pw_cfm'); const err=document.getElementById('pw_err');
  if(np.length<6){ err.textContent='密码至少 6 位'; return; }
  if(np!==cf){ err.textContent='两次输入不一致'; return; }
  const body={new_password:np};
  const uid=document.getElementById('m_pw_uid'); if(uid && uid.value) body.user_id=+uid.value;
  const r=await api('POST','/api/users/password',body); if(r.error){err.textContent=r.error;return;} closeModal();
  if(!body.user_id){ alert('密码已修改，请重新登录'); state.token=null; state.user=null; localStorage.removeItem('elw_token'); renderShell(); }
  else alert('已修改该用户密码');
}
async function changePwFor(id){
  openModal(`<h3>${t('修改用户')} #${id} ${t('密码')}</h3>
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>savePwFor(${id}))">保存</button></div>`);
}
async function savePwFor(id){
  const np=v('m_pw_new'), cf=v('m_pw_cfm'); const err=document.getElementById('pw_err');
  if(np.length<6){ err.textContent='密码至少 6 位'; return; }
  if(np!==cf){ err.textContent='两次输入不一致'; return; }
  const r=await api('POST','/api/users/password',{user_id:id,new_password:np}); if(r.error){err.textContent=r.error;return;} closeModal(); alert('已修改该用户密码');
}

// 表单
const randHex = (n) => Array.from({length:n},()=>Math.floor(Math.random()*16).toString(16)).join('');
function newApplication(){ openModal(`<h3>新建应用</h3><label>名称</label><input id="m_name">
  <label>AppEUI（可选，留空自动随机生成）</label>
  <div class="row"><div><input id="m_app_eui" placeholder="0000000000000000" oninput="hexOnly(this)"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div>
  <label>回调 URL（可选，设备上行/遥测 Webhook，留空不回调）</label><input id="m_cb" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveApp)">保存</button></div>`); }
async function saveApp(){ const r = await api('POST','/api/applications',{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(t(r.error));return;} closeModal(); viewApplications(); }
async function editApplication(id){ const r = await api('GET','/api/applications'); const a = (r.data||[]).find(x=>x.id===id); if(!a)return;
  openModal(`<h3>${t('编辑应用')} #${id}</h3><label>名称</label><input id="m_name" value="${esc(a.name)}">
  <label>AppEUI</label><div class="row"><div><input id="m_app_eui" value="${esc(a.app_eui)}" oninput="hexOnly(this)"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div></label>
  <label>回调 URL</label><input id="m_cb" value="${esc(a.callback_url||'')}" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc" value="${esc(a.description)}">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveAppEdit(${id}))">保存</button></div>`); }
async function saveAppEdit(id){ const r = await api('PUT',`/api/applications/${id}`,{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(t(r.error));return;} closeModal(); viewApplications(); }
async function delApplication(id){ if(!confirm('确认删除该应用及其下所有设备？'))return; const r = await api('DELETE',`/api/applications/${id}`); if(r.error){alert(t(r.error));return;} viewApplications(); }

async function newDevice(appId){ const regions=regionOptions(""); const dps=await dpOptions(0);
  const ar = await api('GET','/api/applications'); const apps = ar.data||[];
  const appOpts = apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(appId)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  const appSel = apps.length ? `<label>应用</label><select id="m_app_sel" ${appId?'disabled':''}>${appOpts}</select>` : '<p class="muted">系统中暂无应用，请先在「应用」页面创建应用。</p>';
  openModal(`<h3>${t('新建设备')}${appId?` (${t('应用')} #${appId})`:''}</h3>${appSel}<label>名称</label><input id="m_name"><label>DevEUI (16 hex)</label><input id="m_dev_eui" oninput="hexOnly(this)"><label>激活方式</label><select id="m_act" onchange="toggleAct()"><option value="OTAA">OTAA</option><option value="ABP">ABP</option></select>
    <div id="otaa"><label>JoinEUI (16 hex)</label><input id="m_join_eui" oninput="hexOnly(this)"><label>AppKey (32 hex)</label><input id="m_app_key" oninput="hexOnly(this)"></div>
    <div id="abp" class="hidden"><label>DevAddr (8 hex)</label><input id="m_dev_addr" oninput="hexOnly(this)"><label>NwkSKey (32 hex)</label><input id="m_nwk" oninput="hexOnly(this)"><label>AppSKey (32 hex)</label><input id="m_app" oninput="hexOnly(this)"></div>
    <label>Class</label><select id="m_class"><option>A</option><option>B</option><option>C</option></select>
    <label>区域</label><select id="m_region">${regions}</select>
    <label>设备模板 (可选)</label><select id="m_dp">${dps}</select>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDevice(${appId||0}))">保存</button></div>`); }
function toggleAct(){ const a=v('m_act')==='OTAA'; document.getElementById('otaa').classList.toggle('hidden',!a); document.getElementById('abp').classList.toggle('hidden',a); }
async function saveDevice(appId){ const sel=document.getElementById('m_app_sel'); const finalAppId = appId ? appId : (sel ? +sel.value : 0); if(!finalAppId){alert('请先选择应用');return;}
  const act=v('m_act'); const body={app_id:finalAppId,name:v('m_name'),dev_eui:v('m_dev_eui'),activation:act,region:v('m_region'),class:v('m_class'),device_profile_id:+v('m_dp')};
  if(act==='OTAA'){ body.join_eui=v('m_join_eui'); body.app_key=v('m_app_key'); } else { body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('POST','/api/devices',body); if(r.error){alert(t(r.error));return;} closeModal(); viewDevices(); }
async function editDevice(id){ const r = await api('GET','/api/devices'); const d=(r.data||[]).find(x=>x.id===id); if(!d)return;
  const otaa=d.activation==='OTAA'; const dps=await dpOptions(d.device_profile_id||0);
  openModal(`<h3>${t('编辑设备')} #${id}</h3><label>名称</label><input id="m_name" value="${esc(d.name)}">
    <label>激活方式</label><input value="${d.activation}" disabled>
    <label>Class</label><select id="m_class"><option ${d.class==='A'?'selected':''}>A</option><option ${d.class==='B'?'selected':''}>B</option><option ${d.class==='C'?'selected':''}>C</option></select>
    <label>区域</label><select id="m_region">${regionOptions(d.region)}</select>
    <label>设备模板 (可选)</label><select id="m_dp">${dps}</select>
    ${otaa?`<label>DevEUI (16 hex，留空不改)</label><input id="m_dev_eui" value="${esc(d.dev_eui)}" placeholder="留空保持不变" oninput="hexOnly(this)"><label>JoinEUI (16 hex，留空不改)</label><input id="m_join_eui" value="${esc(d.join_eui)}" placeholder="留空保持不变" oninput="hexOnly(this)"><label>AppKey (32 hex，留空不改)</label><input id="m_app_key" placeholder="留空保持不变" oninput="hexOnly(this)">`:`<label>DevAddr (8 hex)</label><input id="m_dev_addr" value="${esc(d.dev_addr)}" oninput="hexOnly(this)"><label>NwkSKey (32 hex)</label><input id="m_nwk" value="${esc(d.nwk_s_key)}" oninput="hexOnly(this)"><label>AppSKey (32 hex)</label><input id="m_app" value="${esc(d.app_s_key)}" oninput="hexOnly(this)"`}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceEdit(${id}))">保存</button></div>`); }
async function saveDeviceEdit(id){ const body={name:v('m_name'),class:v('m_class'),region:v('m_region'),device_profile_id:+v('m_dp')};
  if(document.getElementById('m_app_key')) body.app_key=v('m_app_key');
  if(document.getElementById('m_dev_eui')) body.dev_eui=v('m_dev_eui');
  if(document.getElementById('m_join_eui')) body.join_eui=v('m_join_eui');
  if(document.getElementById('m_dev_addr')){ body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('PUT',`/api/devices/${id}`,body); if(r.error){alert(t(r.error));return;} closeModal(); viewDevices(); }
async function delDevice(id){ if(!confirm('确认删除该设备及其上下行记录？'))return; const r = await api('DELETE',`/api/devices/${id}`); if(r.error){alert(t(r.error));return;} viewDevices(); }

function newGateway(){ openModal(`<h3>新建网关</h3><label>Gateway ID (16/32 hex)</label><input id="m_gwid" oninput="hexOnly(this)"><label>名称</label><input id="m_name"><label>区域</label><select id="m_region">${regionOptions("")}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveGateway)">保存</button></div>`); }
async function saveGateway(){ const r = await api('POST','/api/gateways',{gw_id:v('m_gwid'),name:v('m_name'),region:v('m_region')}); if(r.error){alert(t(r.error));return;} closeModal(); viewGateways(); }
async function editGateway(gwId){ const r = await api('GET','/api/gateways'); const g=(r.data||[]).find(x=>x.gw_id===gwId); if(!g)return;
  openModal(`<h3>${t('编辑网关')} ${gwId}</h3><label>名称</label><input id="m_name" value="${esc(g.name)}"><label>区域</label><select id="m_region">${regionOptions(g.region)}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveGatewayEdit('${gwId}'))">保存</button></div>`); }
async function saveGatewayEdit(gwId){ const r = await api('PUT',`/api/gateways/${gwId}`,{name:v('m_name'),region:v('m_region')}); if(r.error){alert(t(r.error));return;} closeModal(); viewGateways(); }
async function delGateway(gwId){ if(!confirm('确认删除该网关？'))return; const r = await api('DELETE',`/api/gateways/${gwId}`); if(r.error){alert(t(r.error));return;} viewGateways(); }

function downlink(devId){ openModal(`<h3>${t('下发数据')} (${t('设备')} #${devId})</h3><label>端口 (1..223)</label><input id="m_port" value="10"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"><label class="check"><input type="checkbox" id="m_confirmed"> 确认下行 (Confirmed)</label>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('发送中…', ()=>sendDown(${devId}))">发送</button></div>`); }
async function sendDown(devId){ const r = await api('POST',`/api/devices/${devId}/downlink`,{port:+v('m_port'),payload:v('m_payload'),confirmed:document.getElementById('m_confirmed').checked}); if(r.error){alert(t(r.error));return;} closeModal(); alert('已加入下行队列（Class C 立即下发；Class A 于下次上行 RX1/RX2；Class B 于 ping 时隙下发）。'); }

async function newUser(){
  let tenants = '';
  try { const r = await api('GET','/api/tenants'); tenants = (r.data||[]).map(row=>`<option value="${row.id}">${esc(row.name)}</option>`).join(''); } catch(e){}
  openModal(`<h3>新建用户</h3><label>用户名</label><input id="m_user"><label>密码（≥6 位）</label><input id="m_pass" type="password">
    <label>角色</label><select id="m_role" onchange="roleTenantToggle()">
      <option value="operator">operator（演示：只读 + 模拟数据）</option>
      <option value="tenant">用户配置（仅本用户配置数据，可写）</option>
      <option value="admin">admin（全部权限）</option>
    </select>
    <div id="m_tenant_box" class="hidden"><label>绑定用户配置（用户配置角色；留空则自动新建同名用户配置）</label>
      <select id="m_tenant"><option value="">— 自动新建同名用户配置 —</option>${tenants}</select>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveUser)">保存</button></div>`);
  roleTenantToggle();
}
function roleTenantToggle(){
  const box = document.getElementById('m_tenant_box');
  if (box) box.classList.toggle('hidden', (document.getElementById('m_role')||{}).value !== 'tenant');
}
async function saveUser(){
  const role = v('m_role');
  const body = {username:v('m_user'), password:v('m_pass'), role};
  if (role === 'tenant') {
    const t = v('m_tenant');
    if (t && +t > 0) body.tenant_id = +t;
    else body.new_tenant_name = v('m_user'); // 未指定 → 自动新建同名租户
  }
  const r = await api('POST','/api/users',body); if(r.error){alert(t(r.error));return;} closeModal(); viewUsers();
}
async function delUser(id){ if(!confirm('确认删除该用户？'))return; const r = await api('DELETE',`/api/users/${id}`); if(r.error){alert(t(r.error));return;} viewUsers(); }

// 设备模板下拉（含“默认模板”）
async function dpOptions(sel){
  if(!state.dps.length){ const r=await api('GET','/api/device-profiles'); state.dps=r.data||[]; }
  return `<option value="0" ${(sel==0||sel===''||sel==null)?'selected':''}>默认模板</option>`+(state.dps||[]).map(d=>`<option value="${d.id}" ${String(d.id)===String(sel)?'selected':''}>${esc(d.name)}</option>`).join('');
}

// ================= 设备模板 =================
async function viewDeviceProfiles(){
  const q = state.tenantFilter ? ('?tenant_id='+state.tenantFilter) : '';
  const [r, tf] = await Promise.all([api('GET','/api/device-profiles'+q), tenantFilterHtml()]);
  state.dps = r.data||[];
  // Class 列显示：A / B / C / B+C（由 supports_class_b / supports_class_c 组合）
  const clsOf = d => {
    const cls = []; if(+d.supports_class_b) cls.push('B'); if(+d.supports_class_c) cls.push('C');
    return cls.length ? cls.join('+') : 'A';
  };
  // 区域筛选：从数据去重（EU868 / CN470 / ...）
  const regions = [...new Set((state.dps||[]).map(d=>d.region).filter(Boolean))].sort();
  const regionValues = [{value:'', label:'全部'}, ...regions.map(rg=>({value:rg, label:rg}))];
  const classValues = [
    {value:'', label:'全部'},
    {value:'A',   label:'Class A'},
    {value:'B',   label:'Class B'},
    {value:'C',   label:'Class C'},
    {value:'B+C', label:'Class B+C'},
  ];
  const dpsCfg = {
    state, stateKey:'dpsSort',
    defaultSort:{col:null,dir:'desc'},
    cellValue: (d, k) => ({id:d.id, name:d.name, region:d.region, mac:d.mac_version, adr:d.adr_algorithm, codec:d.payload_codec_runtime, cls:clsOf(d)}[k]),
    cols:[
      {key:'id',     label:'ID',    type:'num',  firstDir:'asc', sortable:false},
      {key:'name',   label:'名称',   type:'str',  firstDir:'asc', sortable:false},
      {key:'region', label:'区域',   type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.region, values:regionValues}},
      {key:'mac',    label:'MAC',    type:'str',  firstDir:'asc', sortable:false},
      {key:'adr',    label:'ADR',    type:'str',  firstDir:'asc', sortable:false},
      {key:'codec',  label:'编解码',  type:'str',  firstDir:'asc', sortable:false},
      {key:'cls',    label:'Class',  type:'status', firstDir:'asc', sortable:false, opts:{getValue:clsOf, values:classValues}},
      {key:'_raw',   label:'',       type:'raw'},
    ],
    filterStatusList: [
      {col:'region', value: state.dpsFRegion},
      {col:'cls',    value: state.dpsFCls},
    ],
    rows: state.dps,
    rowHtml: d => `<tr><td>${d.id}</td><td>${esc(d.name)}</td><td class="muted">${esc(d.region)}</td>
      <td class="muted">${esc(d.mac_version)}</td><td class="muted">${esc(d.adr_algorithm)}</td>
      <td class="muted">${esc(d.payload_codec_runtime)}</td><td class="muted">${clsOf(d)}</td>
      <td>${adminBtn(`<button class="btn ghost" onclick="editDeviceProfile(${d.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDeviceProfile(${d.id}))">删除</button>`)}</td></tr>`,
    emptyText:'暂无设备模板',
  };
  const [filteredDps, dpsTotal] = filterAndSortRows(dpsCfg);
  dpsCfg.rows = paginateRows(filteredDps, state, {pageKey:'dpsPage', limitKey:'dpsLimit', offsetKey:'dpsOffset'})[0];
  dpsCfg.presorted = true;
  const table = buildSortableTable(dpsCfg);
  const pager = buildPager({ total: dpsTotal, limit: state.dpsLimit, offset: state.dpsOffset, pageKey:'dpsPage', limitKey:'dpsLimit', offsetKey:'dpsOffset', totalKey:'dpsTotal', refresh:'viewDeviceProfiles' });
  window.dpsSort_fstatus = (col, v) => {
    const map = {region:'dpsFRegion', cls:'dpsFCls'};
    _tableSetFStatus(map[col] || 'dpsFRegion', 'viewDeviceProfiles', v);
  };
  window.viewDeviceProfiles__page = p => _pagerGo({pageKey:'dpsPage',limitKey:'dpsLimit',offsetKey:'dpsOffset',totalKey:'dpsTotal'},'viewDeviceProfiles',p);
  window.viewDeviceProfiles__limit = l => _pagerSetLimit({pageKey:'dpsPage',limitKey:'dpsLimit',offsetKey:'dpsOffset',totalKey:'dpsTotal'},'viewDeviceProfiles',l);
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>设备模板</h2>${adminBtn('<button onclick="newDeviceProfile()">+ 新建模板</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="state.dpsFRegion='';state.dpsFCls='';state.dpsSort={col:null,dir:'desc'};viewDeviceProfiles()">重置</button></div>
    ${table}
    ${pager}`;
}

// ---------------- 用户配置 ----------------
async function viewTenants(){
  const r = await api('GET','/api/tenants'); state.tenants = r.data||[];
  const rows = state.tenants.map(row=>{
    const unlimited = +row.private_gateways_unlimited === 1;
    return `<tr><td>${row.id}</td><td>${esc(row.name)}</td><td class="muted">${esc(row.description||'')}</td>
    <td class="muted">${unlimited ? t('无限制') : t('上限') + ' ' + (row.private_gateways_limit||0)}</td>
    <td>${adminBtn(`<button class="btn ghost" onclick="editTenant(${row.id})">${t('编辑')}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delTenant(${row.id}))">${t('删除')}</button>`)}</td></tr>`;
  }).join('')||`<tr><td colspan="5" class="muted">${t('暂无用户配置')}</td></tr>`;
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>${t('用户配置')}</h2>${adminBtn(`<button onclick="newTenant()">${t('+ 新建用户配置')}</button>`)}</div>
    <table><thead><tr><th>ID</th><th>${t('名称')}</th><th>${t('描述')}</th><th>${t('私有网关上限')}</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
function tenantForm(t){
  t = t || {};
  const unlimited = +t.private_gateways_unlimited === 1;
  const limit = t.private_gateways_limit || 0;
  return `<label>${t('名称')}</label><input id="t_name" value="${esc(t.name||'')}">
  <label>${t('描述')}</label><input id="t_desc" value="${esc(t.description||'')}">
  <div class="row" style="align-items:flex-end">
    <div><label>${t('启用私有网关上限')}</label>
      <label class="check" style="margin:6px 0 0">
        <input type="checkbox" id="t_unlimited" ${unlimited?'':'checked'} onchange="document.getElementById('t_limit_div').style.display=this.checked?'':'none'">
        <span>${unlimited?t('已关闭（无限额）'):t('已启用（受上限约束）')}</span>
      </label>
      <div class="muted" style="font-size:11px;margin-top:4px">${t('取消勾选后该用户配置可创建任意数量的网关；勾选时按下方上限约束。')}</div>
    </div>
    <div id="t_limit_div" style="${unlimited?'display:none':''}"><label>${t('私有网关上限')}</label><input id="t_limit" type="number" min="0" value="${limit}"><div class="muted" style="font-size:11px;margin-top:4px">${t('0 = 不允许创建网关；正值 = 允许的最大私有网关数。')}</div></div>
  </div>`;
}
function newTenant(){
  // 新建默认：启用上限 + 上限 0（用户明确要求"默认创建的用户为有上限且上限为0"）
  openModal(`<h3>${t('新建用户配置')}</h3>${tenantForm({ private_gateways_unlimited: 0, private_gateways_limit: 0 })}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('取消')}</button><button onclick="busy('保存中…', ()=>saveTenant(0))">${t('保存')}</button></div>`);
}
async function saveTenant(id){
  const unlimited = !document.getElementById('t_unlimited').checked; // 勾选=受限，不勾=无限
  const body = {
    name: v('t_name'),
    description: v('t_desc'),
    private_gateways_unlimited: unlimited ? 1 : 0,
    private_gateways_limit: +v('t_limit') || 0,
  };
  const r = id ? await api('PUT',`/api/tenants/${id}`,body) : await api('POST','/api/tenants',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewTenants();
}
async function editTenant(id){
  const row = state.tenants.find(x=>x.id==id)||{};
  openModal(`<h3>${t('编辑用户配置')}</h3>${tenantForm(row)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('取消')}</button><button onclick="busy('保存中…', ()=>saveTenant(${id}))">${t('保存')}</button></div>`);
}
async function delTenant(id){
  if(!confirm(t('删除用户配置？其下资源将回退到默认用户配置。'))) return;
  const r = await api('DELETE',`/api/tenants/${id}`); if(r.error){alert(t(r.error));return;} viewTenants();
}
function deviceProfileForm(d){
  d = d||{};
  const regions=regionOptions(d.region||"");
  const codec = (sel)=>['NONE','CAYENNE_LPP','JS'].map(c=>`<option value="${c}" ${c===sel?'selected':''}>${c}</option>`).join('');
  const yesno=(v)=>`<option value="1" ${v?'selected':''}>是</option><option value="0" ${v?'':'selected'}>否</option>`;
  return `<label>名称</label><input id="m_name" value="${esc(d.name||'')}">
  <label>描述</label><input id="m_desc" value="${esc(d.description||'')}">
  <div class="row">
    <div><label>区域</label><select id="m_region">${regions}</select></div>
    <div><label>MAC 版本</label><input id="m_mac" value="${esc(d.mac_version||'1.0.4')}"></div>
    <div><label>区域参数版本</label><input id="m_reg" value="${esc(d.reg_params_revision||'RP002-1.0.3')}"></div>
  </div>
  <div class="row">
    <div><label>ADR 算法</label><input id="m_adr" value="${esc(d.adr_algorithm||'default')}"></div>
    <div><label>编解码运行时</label><select id="m_codec">${codec(d.payload_codec_runtime||'NONE')}</select></div>
  </div>
  <label>编解码脚本（JS / Cayenne 说明；纯 PHP 环境不支持 JS 运行时，仅 NONE/CAYENNE_LPP 生效）</label><textarea id="m_script">${esc(d.payload_codec_script||'')}</textarea>
  <div class="row">
    <div><label>支持 OTAA</label><select id="m_otaa">${yesno(d.supports_otaa)}</select></div>
    <div><label>支持 Class B</label><select id="m_cb">${yesno(d.supports_class_b)}</select></div>
    <div><label>支持 Class C</label><select id="m_cc">${yesno(d.supports_class_c)}</select></div>
  </div>
  <div class="row">
    <div><label>激活清空队列</label><select id="m_flush">${yesno(d.flush_queue_on_activate)}</select></div>
    <div><label>上行间隔(s,0=不限)</label><input id="m_upl" value="${d.uplink_interval||0}"></div>
    <div><label>状态查询间隔(s,0=关)</label><input id="m_streq" value="${d.device_status_req_interval||0}"></div>
  </div>
  <div class="row">
    <div><label>ClassB Ping 周期</label><input id="m_bpp" value="${d.class_b_ping_slot_periodicity||0}"></div>
    <div><label>ClassB Ping DR</label><input id="m_bpd" value="${d.class_b_ping_slot_dr||0}"></div>
    <div><label>ClassB Ping 频率</label><input id="m_bpf" value="${d.class_b_ping_slot_freq||0}"></div>
  </div>
  <div class="row">
    <div><label>ClassC 超时(s)</label><input id="m_cto" value="${d.class_c_timeout||0}"></div>
    <div><label>ABP RX1 Delay</label><input id="m_ard" value="${d.abp_rx1_delay||1}"></div>
    <div><label>ABP RX1 DR Offset</label><input id="m_ardo" value="${d.abp_rx1_dr_offset||0}"></div>
  </div>
  <div class="row">
    <div><label>ABP RX2 DR</label><input id="m_ar2d" value="${d.abp_rx2_dr||0}"></div>
    <div><label>ABP RX2 频率</label><input id="m_ar2f" value="${d.abp_rx2_freq||0}"></div>
    <div><label>允许漫游</label><select id="m_roam">${yesno(d.allow_roaming)}</select></div>
  </div>`;
}
function newDeviceProfile(){
  openModal(`<h3>新建设备模板</h3>${deviceProfileForm()}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceProfile(0))">保存</button></div>`);
}
async function saveDeviceProfile(id){
  const body={
    name:v('m_name'), description:v('m_desc'), region:v('m_region'),
    mac_version:v('m_mac'), reg_params_revision:v('m_reg'), adr_algorithm:v('m_adr'),
    payload_codec_runtime:v('m_codec'), payload_codec_script:v('m_script'),
    supports_otaa:+v('m_otaa'), supports_class_b:+v('m_cb'), supports_class_c:+v('m_cc'),
    flush_queue_on_activate:+v('m_flush'), uplink_interval:+v('m_upl'), device_status_req_interval:+v('m_streq'),
    class_b_ping_slot_periodicity:+v('m_bpp'), class_b_ping_slot_dr:+v('m_bpd'), class_b_ping_slot_freq:+v('m_bpf'),
    class_c_timeout:+v('m_cto'), abp_rx1_delay:+v('m_ard'), abp_rx1_dr_offset:+v('m_ardo'),
    abp_rx2_dr:+v('m_ar2d'), abp_rx2_freq:+v('m_ar2f'), allow_roaming:+v('m_roam')
  };
  const r = id ? await api('PUT',`/api/device-profiles/${id}`,body) : await api('POST','/api/device-profiles',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewDeviceProfiles();
}
async function editDeviceProfile(id){
  const r = await api('GET','/api/device-profiles'); const d=(r.data||[]).find(x=>x.id===id); if(!d)return;
  openModal(`<h3>${t('编辑模板')} #${id}</h3>${deviceProfileForm(d)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceProfile(${id}))">保存</button></div>`);
}
async function delDeviceProfile(id){ if(!confirm('确认删除该模板？引用该模板的设备将回退到默认模板。'))return; const r=await api('DELETE',`/api/device-profiles/${id}`); if(r.error){alert(t(r.error));return;} viewDeviceProfiles(); }

// ================= API 密钥 =================
async function viewApiKeys(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">选择应用…</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let ks=[];
  if(state.appSel){
    const r=await api('GET','/api/api-keys?app_id='+state.appSel+(tq?'&'+tq:'')); ks=r.data||[];
  }
  const akCfg = {
    state, stateKey:'apiKeysSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (k, ck) => ({id:k.id, name:k.name, token:k.token_preview||'', time:k.created_at}[ck]),
    cols:[
      {key:'id',    label:'ID',          type:'num', firstDir:'asc', sortable:false},
      {key:'name',  label:'名称',         type:'str', firstDir:'asc', sortable:false},
      {key:'token', label:'Token(预览)', type:'str', firstDir:'asc', sortable:false},
      {key:'time',  label:'创建时间',     type:'time', firstDir:'desc'},
      {key:'_raw',  label:'',            type:'raw'},
    ],
    rows: ks,
    rowHtml: k => `<tr><td>${k.id}</td><td>${esc(k.name)}</td><td class="muted"><code>${esc(k.token_preview)}…</code></td><td class="muted">${new Date(k.created_at*1000).toLocaleString()}</td>
      <td>${adminBtn(`<button class="btn danger" onclick="busy('删除中…', ()=>delApiKey(${k.id}))">删除</button>`)}</td></tr>`,
    emptyText: state.appSel ? '该应用暂无 API 密钥' : '请先在上方选择应用',
  };
  const [filteredKeys, keysTotal] = filterAndSortRows(akCfg);
  akCfg.rows = paginateRows(filteredKeys, state, {pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset'})[0];
  akCfg.presorted = true;
  const table = buildSortableTable(akCfg);
  const pager = buildPager({ total: keysTotal, limit: state.apiKeysLimit, offset: state.apiKeysOffset, pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset', totalKey:'apiKeysTotal', refresh:'viewApiKeys' });
  window.apiKeysSort_sort = col => _tableToggleSort('apiKeysSort','viewApiKeys',col);
  window.viewApiKeys__page = p => _pagerGo({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',p);
  window.viewApiKeys__limit = l => _pagerSetLimit({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',l);
  document.getElementById('view').innerHTML=`<h2>API 密钥</h2>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="ak_app" onchange="state.appSel=this.value;state.apiKeysPage=1;state.apiKeysOffset=0;nav('api-keys')">${opts}</select></div>${state.appSel?adminBtn('<button onclick="newApiKey()">+ 新建 API 密钥</button>'):''}</div>
   ${table}
   ${pager}`;
}
function newApiKey(){
  if(!state.appSel){alert('请先选择应用');return;}
  openModal(`<h3>${t('新建 API 密钥')} (${t('应用')} #${state.appSel})</h3><label>名称</label><input id="m_name">
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveApiKey)">保存</button></div>`);
}
async function saveApiKey(){
  const r=await api('POST','/api/api-keys',{application_id:+state.appSel,name:v('m_name')});
  if(r.error){alert(t(r.error));return;}
  const token=r.token||'';
  openModal(`<h3>API 密钥已创建</h3><p class="muted">请立即复制保存，关闭后将无法再查看明文：</p>
   <label>Token</label><input id="m_tok" value="${esc(token)}" readonly onclick="this.select()">
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button onclick="(navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('m_tok').value));closeModal();viewApiKeys()">我已复制，关闭</button></div>`);
}
async function delApiKey(id){ if(!confirm('确认删除该 API 密钥？'))return; const r=await api('DELETE',`/api/api-keys/${id}`); if(r.error){alert(t(r.error));return;} viewApiKeys(); }

// ================= 外部集成 =================
async function viewIntegrations(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">选择应用…</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.intAppSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let its=[];
  if(state.intAppSel){
    const r=await api('GET','/api/integrations?app_id='+state.intAppSel+(tq?'&'+tq:'')); its=r.data||[];
  }
  const summaryOf = it => {
    let cfg={}; try{ if(it.config_json) cfg=JSON.parse(it.config_json)||{}; }catch(e){}
    return it.kind==='HTTP' ? (cfg.url||'') : it.kind==='INFLUX_DB' ? (cfg.endpoint||'') : it.kind==='MQTT_GLOBAL' ? (cfg.server||'') : it.kind==='AWS_SNS' ? (cfg.topic_arn||'') : it.kind==='AZURE_SERVICE_BUS' ? (cfg.publish_name||'') : it.kind==='GCP_PUBSUB' ? (cfg.topic_name||'') : it.kind==='AMQP' ? (cfg.url||'') : it.kind==='KAFKA' ? (cfg.topic||'') : '';
  };
  const intgCfg = {
    state, stateKey:'intgSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (it, k) => ({kind:it.kind, enabled:it.enabled?1:0, summary:summaryOf(it), time:it.created_at}[k]),
    cols:[
      {key:'kind',    label:'类型',     type:'str', firstDir:'asc', sortable:false},
      {key:'enabled', label:'状态',     type:'num', firstDir:'asc', sortable:false},
      {key:'summary', label:'配置',     type:'str', firstDir:'asc', sortable:false},
      {key:'time',    label:'创建时间', type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',        type:'raw'},
    ],
    rows: its,
    rowHtml: it => `<tr><td><span class="tag">${it.kind}</span></td>
        <td><span class="tag ${it.enabled?'ok':'off'}">${it.enabled?'启用':'停用'}</span></td>
        <td class="muted">${esc(summaryOf(it))}</td>
        <td class="muted">${new Date(it.created_at*1000).toLocaleString()}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="busy('处理中…', ()=>toggleIntegration(${it.id},${it.enabled?0:1}))">${it.enabled?'停用':'启用'}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delIntegration(${it.id}))">删除</button>`)}</td></tr>`,
    emptyText: state.intAppSel ? '该应用暂无外部集成' : '请先在上方选择应用',
  };
  const [filteredInts, intgTotal] = filterAndSortRows(intgCfg);
  intgCfg.rows = paginateRows(filteredInts, state, {pageKey:'intgPage', limitKey:'intgLimit', offsetKey:'intgOffset'})[0];
  intgCfg.presorted = true;
  const table = buildSortableTable(intgCfg);
  const pager = buildPager({ total: intgTotal, limit: state.intgLimit, offset: state.intgOffset, pageKey:'intgPage', limitKey:'intgLimit', offsetKey:'intgOffset', totalKey:'intgTotal', refresh:'viewIntegrations' });
  window.intgSort_sort = col => _tableToggleSort('intgSort','viewIntegrations',col);
  window.viewIntegrations__page = p => _pagerGo({pageKey:'intgPage',limitKey:'intgLimit',offsetKey:'intgOffset',totalKey:'intgTotal'},'viewIntegrations',p);
  window.viewIntegrations__limit = l => _pagerSetLimit({pageKey:'intgPage',limitKey:'intgLimit',offsetKey:'intgOffset',totalKey:'intgTotal'},'viewIntegrations',l);
  document.getElementById('view').innerHTML=`<h2>外部集成</h2>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="int_app" onchange="state.intAppSel=this.value;state.intgPage=1;state.intgOffset=0;nav('integrations')">${opts}</select></div>${state.intAppSel?adminBtn('<button onclick="newIntegration()">+ 新建外部集成</button>'):''}</div>
   ${table}
   ${pager}`;
}
function newIntegration(){
  if(!state.intAppSel){alert('请先选择应用');return;}
  const httpFields=`<div id="f_http"><label>HTTP URL</label><input id="m_url" placeholder="https://example.com/uplink"><label>Headers (JSON, 可选)</label><input id="m_headers" placeholder='{"X-Api-Key":"..."}'></div>`;
  const influxFields=`<div id="f_influx" class="hidden"><label>InfluxDB Endpoint</label><input id="m_endpoint" placeholder="http://localhost:8086/api/v2/write"><label>Measurement (可选)</label><input id="m_measurement" placeholder="device_uplink"><label>Token (可选)</label><input id="m_token" placeholder="Token xxx"></div>`;
  const mqttFields=`<div id="f_mqtt" class="hidden"><label>Server</label><input id="m_server" placeholder="tcp://127.0.0.1:1883"><label>Topic 模板</label><input id="m_topic" placeholder="application/{app_id}/device/{dev_eui}/up"><label>QoS</label><select id="m_qos"><option>0</option><option>1</option></select><label>用户名(可选)</label><input id="m_user"><label>密码(可选)</label><input id="m_pass" type="password"></div>`;
  const awsFields=`<div id="f_aws" class="hidden"><label>AWS Region</label><input id="m_aws_region" placeholder="eu-west-1"><label>Access Key ID</label><input id="m_aws_key"><label>Secret Access Key</label><input id="m_aws_secret" type="password"><label>Topic ARN</label><input id="m_aws_topic" placeholder="arn:aws:sns:eu-west-1:123456789012:my-topic"></div>`;
  const azureFields=`<div id="f_azure" class="hidden"><label>Connection String</label><input id="m_az_conn" placeholder="Endpoint=sb://ns.servicebus.windows.net/;SharedAccessKeyName=...;SharedAccessKey=..."><label>Publish Mode</label><select id="m_az_mode"><option value="topic">topic</option><option value="queue">queue</option></select><label>Topic/Queue Name</label><input id="m_az_name"></div>`;
  const gcpFields=`<div id="f_gcp" class="hidden"><label>Project ID</label><input id="m_gcp_project"><label>Topic Name</label><input id="m_gcp_topic"><label>Credentials JSON (服务账号)</label><textarea id="m_gcp_cred" placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"..."}'></textarea><label>或 Credentials 文件</label><input id="m_gcp_credfile" placeholder="/path/to/sa.json"></div>`;
  const amqpFields=`<div id="f_amqp" class="hidden"><label>AMQP URL</label><input id="m_amqp_url" placeholder="amqp://user:pass@host:5672"><label>Exchange</label><input id="m_amqp_exchange" placeholder="amq.topic"><label>Routing Key 模板</label><input id="m_amqp_rk" placeholder="application.{app_id}.device.{dev_eui}.event.{event}"></div>`;
  const kafkaFields=`<div id="f_kafka" class="hidden"><label>Brokers</label><input id="m_kafka_brokers" placeholder="host1:9092,host2:9092"><label>Topic</label><input id="m_kafka_topic"><label>TLS</label><select id="m_kafka_tls"><option value="0">否</option><option value="1">是</option></select><label>SASL 用户名(可选)</label><input id="m_kafka_user"><label>SASL 密码(可选)</label><input id="m_kafka_pass" type="password"></div>`;
  openModal(`<h3>${t('新建外部集成')} (${t('应用')} #${state.intAppSel})</h3>
   <label>类型</label><select id="m_kind" onchange="toggleIntFields()"><option value="HTTP">HTTP</option><option value="INFLUX_DB">InfluxDB</option><option value="MQTT_GLOBAL">MQTT</option><option value="AWS_SNS">AWS SNS</option><option value="AZURE_SERVICE_BUS">Azure Service Bus</option><option value="GCP_PUBSUB">GCP Pub/Sub</option><option value="AMQP">AMQP (RabbitMQ)</option><option value="KAFKA">Kafka</option></select>
   <label>启用</label><select id="m_enabled"><option value="1" selected>是</option><option value="0">否</option></select>
   ${httpFields}${influxFields}${mqttFields}${awsFields}${azureFields}${gcpFields}${amqpFields}${kafkaFields}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveIntegration)">保存</button></div>`);
}
function toggleIntFields(){
  const k=v('m_kind');
  const map={HTTP:'f_http',INFLUX_DB:'f_influx',MQTT_GLOBAL:'f_mqtt',AWS_SNS:'f_aws',AZURE_SERVICE_BUS:'f_azure',GCP_PUBSUB:'f_gcp',AMQP:'f_amqp',KAFKA:'f_kafka'};
  for(const id of ['f_http','f_influx','f_mqtt','f_aws','f_azure','f_gcp','f_amqp','f_kafka']){
    document.getElementById(id).classList.toggle('hidden', map[k]!==id);
  }
}
async function saveIntegration(){
  const kind=v('m_kind'); let config={};
  if(kind==='HTTP'){ config={url:v('m_url')}; const h=v('m_headers'); if(h){try{config.headers=JSON.parse(h)}catch(e){alert('Headers 不是合法 JSON');return;}} }
  else if(kind==='INFLUX_DB'){ config={endpoint:v('m_endpoint'),measurement:v('m_measurement'),token:v('m_token')}; }
  else if(kind==='MQTT_GLOBAL'){ config={server:v('m_server'),topic:v('m_topic'),qos:+v('m_qos'),username:v('m_user'),password:v('m_pass')}; }
  else if(kind==='AWS_SNS'){ config={aws_region:v('m_aws_region'),aws_access_key_id:v('m_aws_key'),aws_secret_access_key:v('m_aws_secret'),topic_arn:v('m_aws_topic')}; }
  else if(kind==='AZURE_SERVICE_BUS'){ config={connection_string:v('m_az_conn'),publish_mode:v('m_az_mode'),publish_name:v('m_az_name')}; }
  else if(kind==='GCP_PUBSUB'){ config={project_id:v('m_gcp_project'),topic_name:v('m_gcp_topic'),credentials_json:v('m_gcp_cred')||'',credentials_file:v('m_gcp_credfile')||''}; }
  else if(kind==='AMQP'){ config={url:v('m_amqp_url'),exchange:v('m_amqp_exchange'),routing_key_template:v('m_amqp_rk')}; }
  else if(kind==='KAFKA'){ config={brokers:v('m_kafka_brokers'),topic:v('m_kafka_topic'),tls:+v('m_kafka_tls'),username:v('m_kafka_user'),password:v('m_kafka_pass')}; }
  const body={application_id:+state.intAppSel, kind, enabled:+v('m_enabled'), config};
  const r=await api('POST','/api/integrations',body); if(r.error){alert(t(r.error));return;} closeModal(); viewIntegrations();
}
async function toggleIntegration(id,enabled){ const r=await api('PUT',`/api/integrations/${id}`,{enabled}); if(r.error){alert(t(r.error));return;} viewIntegrations(); }
async function delIntegration(id){ if(!confirm('确认删除该外部集成？'))return; const r=await api('DELETE',`/api/integrations/${id}`); if(r.error){alert(t(r.error));return;} viewIntegrations(); }

// ================= 组播组 =================
async function viewMulticastGroups(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">全部应用</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let q=[]; if(tq) q.push(tq); if(state.appSel) q.push('app_id='+state.appSel);
  const r=await api('GET','/api/multicast-groups'+(q.length?'?'+q.join('&'):'')); const ms=r.data||[];
  const appName=(id)=>{const a=(state.apps||[]).find(x=>x.id===id);return a?esc(a.name):('#'+id);};
  const rows=ms.map(m=>`<tr><td>${m.id}</td><td>${esc(m.name)}</td><td class="muted">${appName(m.application_id)}</td>
     <td class="muted">${esc(m.region)}</td><td><span class="tag ${m.group_type}">${m.group_type}</span></td>
     <td class="muted"><code>${esc(m.mc_addr)}</code></td><td class="muted">DR${m.dr}</td><td class="muted">${m.f_cnt}</td>
     <td>${adminBtn(`<button class="btn ghost" onclick="mcDetail(${m.id})">详情</button> <button class="btn ghost" onclick="editMulticast(${m.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delMulticast(${m.id}))">删除</button>`)}</td></tr>`).join('')||`<tr><td colspan="9" class="muted">暂无组播组</td></tr>`;
  document.getElementById('view').innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center"><h2>组播组</h2>${adminBtn('<button onclick="newMulticast()">+ 新建组播组</button>')}</div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>按应用筛选</label><select id="mc_app" onchange="state.appSel=this.value;nav('multicast-groups')">${opts}</select></div></div>
   <table><thead><tr><th>ID</th><th>名称</th><th>应用</th><th>区域</th><th>类型</th><th>MC Addr</th><th>DR</th><th>FCnt</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
function multicastForm(m){
  m=m||{}; const regions=regionOptions(m.region||"");
  const type=(s)=>['A','B','C'].map(cls=>`<option value="${cls}" ${cls===s?'selected':''}>${cls}</option>`).join('');
  const sched=(s)=>['DELAY','FIXED'].map(t=>`<option value="${t}" ${t===s?'selected':''}>${t}</option>`).join('');
  const appOpts=(state.apps||[]).map(a=>`<option value="${a.id}" ${String(a.id)===String(m.application_id||state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  return `<label>名称</label><input id="m_name" value="${esc(m.name||'')}">
   <div class="row"><div><label>应用</label><select id="m_app">${appOpts}</select></div>
     <div><label>区域</label><select id="m_region">${regions}</select></div>
     <div><label>组类型</label><select id="m_type">${type(m.group_type||'C')}</select></div></div>
   <div class="row"><div><label>MC Addr (8 hex)</label><input id="m_mcaddr" value="${esc(m.mc_addr||'')}" oninput="hexOnly(this)"></div>
     <div><label>MC NwkSKey (32 hex)</label><input id="m_mcnwk" value="${esc(m.mc_nwk_s_key||'')}" oninput="hexOnly(this)"></div>
     <div><label>MC AppSKey (32 hex)</label><input id="m_mcapp" value="${esc(m.mc_app_s_key||'')}" oninput="hexOnly(this)"></div></div>
   <div style="margin-bottom:8px"><button class="btn ghost" type="button" onclick="genMc()">随机生成组播密钥</button></div>
   <div class="row"><div><label>DR</label><input id="m_dr" value="${m.dr||0}"></div>
     <div><label>频率 (Hz,0=区域默认)</label><input id="m_freq" value="${m.frequency||0}"></div>
     <div><label>ClassB Ping 周期</label><input id="m_bpp" value="${m.class_b_ping_slot_periodicity||0}"></div></div>
   <div class="row"><div><label>ClassC 调度类型</label><select id="m_sched">${sched(m.class_c_scheduling_type||'DELAY')}</select></div></div>`;
}
function genMc(){ document.getElementById('m_mcaddr').value=randHex(8); document.getElementById('m_mcnwk').value=randHex(32); document.getElementById('m_mcapp').value=randHex(32); }
function newMulticast(){ if(!state.apps.length){alert('请先创建应用');return;} openModal(`<h3>新建组播组</h3>${multicastForm()}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveMulticast(0))">保存</button></div>`); }
async function saveMulticast(id){
  const body={name:v('m_name'),application_id:+v('m_app'),region:v('m_region'),group_type:v('m_type'),
    mc_addr:v('m_mcaddr'),mc_nwk_s_key:v('m_mcnwk'),mc_app_s_key:v('m_mcapp'),
    dr:+v('m_dr'),frequency:+v('m_freq'),class_b_ping_slot_periodicity:+v('m_bpp'),class_c_scheduling_type:v('m_sched')};
  const r= id?await api('PUT',`/api/multicast-groups/${id}`,body):await api('POST','/api/multicast-groups',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewMulticastGroups();
}
async function editMulticast(id){ const r=await api('GET',`/api/multicast-groups/${id}`); const m=r; if(!m||m.error){alert('未找到');return;}
  openModal(`<h3>${t('编辑组播组')} #${id}</h3>${multicastForm(m)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveMulticast(${id}))">保存</button></div>`); }
async function delMulticast(id){ if(!confirm('确认删除该组播组及其设备/网关/队列？'))return; const r=await api('DELETE',`/api/multicast-groups/${id}`); if(r.error){alert(t(r.error));return;} viewMulticastGroups(); }
async function mcDetail(id){
  const g = await api('GET',`/api/multicast-groups/${id}`);
  const devs = await api('GET',`/api/multicast-groups/${id}/devices`);
  const gws = await api('GET',`/api/multicast-groups/${id}/gateways`);
  state.mcDetail = {id, g, devs:(devs.data||[]).map(x=>x.dev_eui), gws:(gws.data||[]).map(x=>x.gw_id)};
  const devList=(state.mcDetail.devs.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcDev(${id},'${esc(e)}'))">移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无设备</td></tr>`;
  const gwList=(state.mcDetail.gws.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcGw(${id},'${esc(e)}'))">移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无网关（为空则广播到全部网关）</td></tr>`;
  openModal(`<h3>${t('组播组')} #${id} ${esc(g.name||'')}</h3>
   <p class="muted">MC Addr: <code>${esc(g.mc_addr||'')}</code> · 类型 ${g.group_type} · DR${g.dr} · f_cnt ${g.f_cnt} · 应用 #${g.application_id}</p>
   <h4 style="margin-top:6px">下发数据</h4>
   <div class="row"><div style="flex:0 0 120px"><label>端口 (1..223)</label><input id="m_port" value="10"></div><div style="flex:2"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"></div></div>
   <button onclick="enqueueMc(${id})">加入下发队列</button>
   <h4 style="margin-top:14px">设备（仅用于展示/管理，不参与单播）</h4>
   <div class="row"><div><input id="m_mcdev" placeholder="DevEUI 16 hex" oninput="hexOnly(this)"></div><button onclick="addMcDev(${id})">添加设备</button></div>
   <table style="margin-top:8px"><thead><tr><th>DevEUI</th><th></th></tr></thead><tbody>${devList}</tbody></table>
   <h4 style="margin-top:14px">网关（空=全部网关）</h4>
   <div class="row"><div><input id="m_mcgw" placeholder="Gateway ID" oninput="hexOnly(this)"></div><button onclick="addMcGw(${id})">添加网关</button></div>
   <table style="margin-top:8px"><thead><tr><th>GatewayID</th><th></th></tr></thead><tbody>${gwList}</tbody></table>
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
async function enqueueMc(id){ const r=await api('POST',`/api/multicast-groups/${id}/enqueue`,{port:+v('m_port'),payload:v('m_payload')}); if(r.error){alert(t(r.error));return;} closeModal(); alert('已加入组播下发队列（NS 调度线程将按队列发送）。'); }
async function addMcDev(id){ const e=v('m_mcdev'); if(!e){alert('请输入 DevEUI');return;} const r=await api('POST',`/api/multicast-groups/${id}/devices`,{dev_eui:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function rmMcDev(id,e){ const r=await api('DELETE',`/api/multicast-groups/${id}/devices`,{dev_eui:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function addMcGw(id){ const e=v('m_mcgw'); if(!e){alert('请输入 Gateway ID');return;} const r=await api('POST',`/api/multicast-groups/${id}/gateways`,{gw_id:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function rmMcGw(id,e){ const r=await api('DELETE',`/api/multicast-groups/${id}/gateways`,{gw_id:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }

function openModal(html){
  document.getElementById('modalBox').innerHTML=html;
  document.getElementById('modal').classList.add('show');
  // 演示账号：禁用弹窗内写操作按钮（保存/删除等），保留"取消/关闭"与"随机生成"（后者只填表单不写库）
  if (isDemo()) {
    document.querySelectorAll('#modalBox button').forEach(btn => {
      const txt = (btn.textContent || '').trim();
      if (txt === '取消' || txt === '关闭' || txt.includes('随机')) return;
      btn.disabled = true;
      btn.style.opacity = '0.45';
      btn.style.cursor = 'not-allowed';
      btn.title = '演示模式：只读账号不能进行实际操作';
    });
  }
}
// 演示账号：禁用列表页中的"删除"/"停用"/"启用"按钮（不可逆写操作）。
// "新建"/"编辑"不禁用（允许打开弹窗查看表单内容，弹窗内保存按钮由 openModal 禁用）。
function disableDemoWriteButtons(){
  document.querySelectorAll('#view button').forEach(btn => {
    const txt = (btn.textContent || '').trim();
    if (/删除|停用|启用/.test(txt)) {
      btn.disabled = true;
      btn.style.opacity = '0.45';
      btn.style.cursor = 'not-allowed';
      btn.title = '演示模式：只读账号不能进行实际操作';
    }
  });
}
function closeModal(){ document.getElementById('modal').classList.remove('show'); }
function v(id){ return document.getElementById(id).value.trim(); }
// 粘贴 MAC/EUI/密钥时自动去除冒号、连字符等分隔符，只保留十六进制字符
function hexOnly(el){ el.value = el.value.replace(/[^0-9a-fA-F]/g,''); }

boot();
applyPublicSettings();
</script>
<script src="/assets/loracalc.js"></script>
<script src="/assets/apidocs.js"></script>
</body>
</html>
HTML;
}
