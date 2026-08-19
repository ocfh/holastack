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

    






    public static function createUser(string $username, string $password, string $role = self::ROLE_ADMIN, int $tenantId = 0, ?string $newTenantName = null): int
    {
        $username = strtolower(trim($username));
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
            "INSERT INTO users (username, password_hash, role, tenant_id, created_at) VALUES (?,?,?,?,?)",
            [$username, $hash, $role, $tid, time()]
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
}
