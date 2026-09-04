<?php
namespace holastack\Auth;

use holastack\DB\Database;










class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_TENANT = 'tenant';
    public const ROLE_OPERATOR = 'operator';

    

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_TENANT, self::ROLE_OPERATOR];

    


    public static function tokenFromRequest(): ?string
    {
        // ChirpStack 风格头（v4 REST）：Grpc-Metadata-Authorization: Bearer <token>
        $grpc = $_SERVER['HTTP_GRPC_METADATA_AUTHORIZATION'] ?? null;
        if (is_string($grpc) && preg_match('/Bearer\s+(\S+)/i', $grpc, $m)) {
            return $m[1];
        }
        if (is_string($grpc) && $grpc !== '') {
            return $grpc;
        }
        $custom = $_SERVER['HTTP_X_ELW_TOKEN'] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($h) && preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
            return $m[1];
        }
        if (isset($_GET['token']) && is_string($_GET['token'])) {
            return $_GET['token'];
        }
        if (isset($_POST['token']) && is_string($_POST['token'])) {
            return $_POST['token'];
        }
        return null;
    }

    public static function authenticate(string $username, string $password): ?array
    {
        $user = Database::fetch(
            "SELECT * FROM users WHERE LOWER(username)=?",
            [strtolower(trim($username))]
        );
        if (!$user) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        return $user;
    }

    public static function issueToken(array $user): string
    {
        $token = bin2hex(random_bytes(32));
        Database::execute("DELETE FROM auth_tokens WHERE user_id=?", [$user['id']]);
        Database::execute(
            "INSERT INTO auth_tokens (token, user_id, created_at) VALUES (?,?,?)",
            [$token, $user['id'], time()]
        );
        return $token;
    }

    public static function userFromToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }
        return Database::fetch(
            "SELECT u.* FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=?",
            [$token]
        );
    }

    public static function currentUser(): ?array
    {
        $u = self::userFromToken(self::tokenFromRequest());
        if ($u !== null) {
            unset($u['password_hash']);
        }
        return $u;
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUser() !== null;
    }

    




    public static function hasRole(string $role): bool
    {
        $u = self::currentUser();
        if (!$u) {
            return false;
        }
        if ($role === self::ROLE_ADMIN) {
            return $u['role'] === self::ROLE_ADMIN;
        }
        if ($role === self::ROLE_TENANT) {
            return in_array($u['role'], [self::ROLE_ADMIN, self::ROLE_TENANT], true);
        }
        

        return true;
    }

    public static function logout(?string $token = null): void
    {
        $token = $token ?? self::tokenFromRequest();
        if ($token) {
            Database::execute("DELETE FROM auth_tokens WHERE token=?", [$token]);
        }
    }

    






    public static function createUser(string $username, string $password, string $role = self::ROLE_ADMIN, int $tenantId = 0, ?string $newTenantName = null, ?string $email = null, int $roleId = 0, int $departmentId = 0): int
    {
        $username = strtolower(trim($username));
        $email = strtolower(trim((string) ($email ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid email');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = in_array($role, self::ROLES, true) ? $role : self::ROLE_OPERATOR;
        $tid = (int) $tenantId;
        if ($role === self::ROLE_TENANT && $tid <= 0 && $newTenantName !== null && trim($newTenantName) !== '') {
            $name = trim($newTenantName);
            $exists = Database::fetch("SELECT id FROM tenants WHERE name=?", [$name]);
            if ($exists) {
                $tid = (int) $exists['id'];
            } else {
                Database::execute(
                    "INSERT INTO tenants (name, description, private_gateways_limit, private_gateways_unlimited, created_at) VALUES (?,?,0,0,?)",
                    [$name, '', time()]
                );
                $tid = Database::lastInsertId();
            }
        }
        if ($role !== self::ROLE_TENANT) {
            $tid = 0;
        }
        Database::execute(
            "INSERT INTO users (username, password_hash, role, tenant_id, email, role_id, department_id, created_at) VALUES (?,?,?,?,?,?,?,?)",
            [$username, $hash, $role, $tid, $email, (int) $roleId, (int) $departmentId, time()]
        );
        return Database::lastInsertId();
    }

    


    public static function guardApi(string $minRole = self::ROLE_OPERATOR): void
    {
        if (!self::isLoggedIn()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        if (!self::hasRole($minRole)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
    }

    public static function guardPage(): void
    {
        if (!self::isLoggedIn()) {
            $here = $_SERVER['REQUEST_URI'] ?? '/';
            if (strpos($here, '/login') === false && strpos($here, '/install') === false) {
                header('Location: /login');
                exit;
            }
        }
    }

    public static function guardWrite(): void
    {
        $u = self::currentUser();
        if (!$u) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        if (!in_array($u['role'], [self::ROLE_ADMIN, self::ROLE_TENANT], true)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => 'forbidden: operator is read-only']);
            exit;
        }
    }

    /** 权限目录：key => 展示名。用于角色权限点选与前端菜单过滤。 */
    public const PERMISSION_CATALOG = [
        'dashboard'        => '仪表盘',
        'applications'     => '应用',
        'devices'          => '设备',
        'gateways'         => '网关',
        'device-profiles'  => '设备模板',
        'multicast-groups' => '组播组',
        'thing-models'     => '物模型',
        'dashboard-data'   => '数据看板',
        'alerts'           => '告警管理',
        'notification-groups' => '通知组',
        'scheduled'        => '定时任务',
        'automations'      => '联动模型',
        'integrations'     => '外部集成',
        'api-keys'         => 'API 密钥',
        'api-logs'         => 'API 调用日志',
        'apidocs'          => 'API 文档',
        'loracalc'         => 'LoRa 计算器',
        'uplinks'          => '上行消息日志',
        'downlinks'        => '下行消息日志',
        'events'           => '网关日志',
        'noc'              => '运维仪表盘',
        'map'              => '位置地图',
        'roles'            => '角色管理',
        'departments'      => '部门管理',
        'users'            => '用户管理',
        'tenants'          => '用户配置',
        'settings'         => '站点设置',
    ];

    /** 租户管理员默认权限（除平台级项）。 */
    public const TENANT_PERMS = [
        'dashboard', 'applications', 'devices', 'gateways', 'device-profiles', 'multicast-groups', 'fuota',
        'thing-models', 'dashboard-data', 'alerts', 'notification-groups', 'scheduled',
        'integrations', 'api-keys', 'api-logs', 'apidocs', 'loracalc',
        'uplinks', 'downlinks', 'events', 'noc', 'map', 'roles', 'departments',
    ];

    /** 只读操作员默认权限。 */
    public const OPERATOR_PERMS = [
        'dashboard', 'applications', 'devices', 'gateways', 'device-profiles', 'multicast-groups', 'fuota',
        'thing-models', 'dashboard-data', 'alerts', 'notification-groups', 'scheduled', 'automations',
        'api-logs', 'apidocs', 'loracalc', 'uplinks', 'downlinks', 'events', 'noc', 'map',
    ];

    /**
     * 当前用户（须携带 role_id 经过 currentUser 加载）的有效权限集合。
     * admin 全量；否则优先取自定义角色 permissions；再退到系统角色默认集。
     */
    public static function permissionsFor(?array $user = null): array
    {
        $user = $user ?? self::currentUser();
        if (!$user) {
            return [];
        }
        if ($user['role'] === self::ROLE_ADMIN) {
            return array_keys(self::PERMISSION_CATALOG);
        }
        if (!empty($user['role_id'])) {
            $r = Database::fetch("SELECT permissions FROM roles WHERE id=?", [(int) $user['role_id']]);
            if ($r) {
                $list = json_decode((string) ($r['permissions'] ?? ''), true);
                if (is_array($list) && $list !== []) {
                    return $list;
                }
            }
        }
        if ($user['role'] === self::ROLE_TENANT) {
            return self::TENANT_PERMS;
        }
        return self::OPERATOR_PERMS;
    }

    /** 当前用户是否持有指定权限（admin 恒为 true）。 */
    public static function can(string $perm): bool
    {
        if (!self::isLoggedIn()) {
            return false;
        }
        $u = self::currentUser();
        if (!$u) {
            return false;
        }
        if ($u['role'] === self::ROLE_ADMIN) {
            return true;
        }
        return in_array($perm, self::permissionsFor($u), true);
    }
}
