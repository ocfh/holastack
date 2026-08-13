<?php
namespace holastack\Auth;

use holastack\DB\Database;

/**
 * 登录认证与令牌管理（基于 auth_tokens 表，不依赖浏览器 cookie / session）。
 *  - 用户表：users(id, username, password_hash, role, created_at)
 *  - 角色：admin（完整权限）/ operator（只读 + 下行入队）
 *  - 密码使用 password_hash（bcrypt/argon）存储，password_verify 校验
 *  - 登录后签发随机令牌，客户端在 Authorization: Bearer <token> 中携带，避免 cookie 的
 *    SameSite / Secure / 跨站 / 缓存等兼容性问题（对本地 phpStudy + SPA 尤其稳健）
 */
class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';

    /** 从请求中解析令牌：优先自定义头 X-Elw-Token（FastCGI 下最可靠，Authorization 常被网关剥离），
     *  其次 Authorization: Bearer，再次 ?token= / POST token。 */
    public static function tokenFromRequest(): ?string
    {
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

    /** 校验账号密码，成功返回用户记录（含 id/username/role），失败返回 null。 */
    public static function authenticate(string $username, string $password): ?array
    {
        $user = Database::fetch(
            "SELECT * FROM users WHERE username=?",
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

    /** 签发令牌：清掉该用户旧令牌后写入新令牌，返回令牌字符串。 */
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

    /** 按令牌查出用户记录；无效/过期返回 null。 */
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
        // operator 及以上
        return in_array($u['role'], [self::ROLE_ADMIN, self::ROLE_OPERATOR], true);
    }

    /** 注销：删除指定令牌（缺省则清除当前请求令牌）。 */
    public static function logout(?string $token = null): void
    {
        $token = $token ?? self::tokenFromRequest();
        if ($token) {
            Database::execute("DELETE FROM auth_tokens WHERE token=?", [$token]);
        }
    }

    /** 创建用户（安装向导与用户管理使用）。 */
    public static function createUser(string $username, string $password, string $role = self::ROLE_ADMIN): int
    {
        $username = strtolower(trim($username));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        Database::execute(
            "INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,?)",
            [$username, $hash, $role, time()]
        );
        return Database::lastInsertId();
    }

    // ---------------- 守卫（Guard）----------------

    /** API 守卫：未登录或角色不足则输出 JSON 错误并终止。 */
    public static function guardApi(string $minRole = self::ROLE_OPERATOR): void
    {
        if (!self::isLoggedIn()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        if ($minRole === self::ROLE_ADMIN && !self::hasRole(self::ROLE_ADMIN)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
    }

    /** 页面守卫：未登录跳转到 /login。 */
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
}
