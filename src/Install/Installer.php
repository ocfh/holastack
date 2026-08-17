<?php
namespace holastack\Install;

/**
 * Web 安装向导：
 *  1) 收集数据库连接信息（MySQL 为主，SQLite 为可选零配置）
 *  2) 测试连接并自动导入数据库结构（schema/mysql.sql 或 sqlite.sql）
 *  3) 创建管理员账号
 *  4) 持久化写入 config/local.php（DSN + 账号密码 + installed 标志）
 * 安装完成后重定向至 /login。已安装则不应再访问。
 */
class Installer
{
    public static function handle(): void
    {
        elw_install_session_start();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::process();
        } else {
            echo self::renderForm([], []);
        }
    }

    private static function process(): void
    {
        $p = $_POST;
        $errors = [];
        $dbType = ($p['db_type'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';

        // ---- 1. 解析并测试数据库连接 ----
        if ($dbType === 'sqlite') {
            $dsn = 'sqlite:' . ELW_ROOT . '/runtime/server.db';
            $user = '';
            $pass = '';
            $connDsn = $dsn;
        } else {
            $host = trim($p['mysql_host'] ?? '127.0.0.1');
            $port = (int) ($p['mysql_port'] ?? 3306);
            $dbname = trim($p['mysql_db'] ?? '');
            $user = trim($p['mysql_user'] ?? '');
            $pass = $p['mysql_pass'] ?? '';
            if ($dbname === '') {
                $errors[] = 'MySQL 数据库名不能为空';
            }
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $connDsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        }

        $pdo = null;
        if (empty($errors)) {
            try {
                // 先不带库名连接以验证账号/主机；MySQL 下若目标库不存在则自动创建
                $pdo = new \PDO($connDsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                if ($dbType === 'mysql' && $dbname !== '') {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4");
                    $pdo = new \PDO($dsn, $user, $pass, [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ]);
                }
            } catch (\Throwable $e) {
                $errors[] = '数据库连接失败：' . $e->getMessage();
            }
        }

        // ---- 2. 管理员账号校验 ----
        $adminUser = trim($p['admin_user'] ?? '');
        $adminPass = $p['admin_pass'] ?? '';
        $adminPass2 = $p['admin_pass2'] ?? '';
        if ($adminUser === '') {
            $errors[] = '管理员用户名不能为空';
        }
        if (strlen($adminPass) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = '两次输入的密码不一致';
        }

        if (!empty($errors) || $pdo === null) {
            echo self::renderForm($errors, $p);
            return;
        }

        // ---- 3. 导入数据库结构 ----
        try {
            self::importSchema($pdo, $dbType);
        } catch (\Throwable $e) {
            echo self::renderForm(['导入数据库结构失败：' . $e->getMessage()], $p);
            return;
        }

        // ---- 4. 创建管理员账号（直接使用已连接的 PDO，避免常量缓存问题）----
        $user = strtolower($adminUser);
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $exists = $pdo->prepare("SELECT id FROM users WHERE username=?");
        $exists->execute([$user]);
        if ($exists->fetch() === false) {
            $pdo->prepare("INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,?)")
                ->execute([$user, $hash, 'admin', time()]);
        }

        // ---- 5. 持久化配置 ----
        self::writeConfig($dbType, $dsn, $user, $pass);

        echo self::renderSuccess();
    }

    private static function importSchema(\PDO $pdo, string $dbType): void
    {
        $file = ELW_ROOT . '/schema/' . ($dbType === 'sqlite' ? 'sqlite.sql' : 'mysql.sql');
        $sql = file_get_contents($file);
        if ($dbType === 'sqlite') {
            $pdo->exec($sql);
        } else {
            // 按分号切分后，逐条剥离行内 `--` 注释再执行；
            // 不能整段以 `--` 开头就跳过，否则首张表（如 applications）会被误删，
            // 后续外键引用不存在的表会报 1215 Cannot add foreign key constraint。
            foreach (explode(';', $sql) as $raw) {
                $lines = array_filter(array_map('trim', explode("\n", $raw)), function ($l) {
                    return $l !== '' && strpos($l, '--') !== 0;
                });
                $stmt = implode("\n", $lines);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
        }
    }

    private static function writeConfig(string $dbType, string $dsn, string $user, string $pass): void
    {
        $cfg = [
            'db_dsn'  => $dsn,
            'db_user' => $user,
            'db_pass' => $pass,
            'installed' => true,
        ];
        if ($dbType === 'sqlite') {
            $cfg['region'] = ELW_DEFAULT_REGION;
        }
        $export = '<?php' . PHP_EOL . '// 由安装向导自动生成，请勿手动编辑（已纳入 .gitignore）' . PHP_EOL
            . 'return ' . var_export($cfg, true) . ';' . PHP_EOL;
        file_put_contents(ELW_ROOT . '/config/local.php', $export, LOCK_EX);
    }

    // ---------------- 渲染 ----------------

    private static function renderForm(array $errors, array $values): string
    {
        $errHtml = '';
        foreach ($errors as $e) {
            $errHtml .= '<div class="err">' . htmlspecialchars($e, ENT_QUOTES) . '</div>';
        }
        $v = function (string $k, string $d = '') use ($values) {
            return htmlspecialchars($values[$k] ?? $d, ENT_QUOTES);
        };
        return self::page(<<<HTML
<h2>安装 holastack</h2>
<p class="muted">第一步：填写数据库连接信息并创建管理员账号，安装程序将自动导入表结构并保存配置。</p>
$errHtml
<form method="post">
  <label>数据库类型</label>
  <div class="row">
    <div><label><input type="radio" name="db_type" value="mysql" checked> MySQL（生产推荐）</label></div>
    <div><label><input type="radio" name="db_type" value="sqlite"> SQLite（零配置，仅验证用）</label></div>
  </div>
  <fieldset>
    <legend>MySQL 连接</legend>
    <div class="row">
      <div><label>主机</label><input name="mysql_host" value="{$v('mysql_host','127.0.0.1')}"></div>
      <div><label>端口</label><input name="mysql_port" value="{$v('mysql_port','3306')}"></div>
    </div>
    <div class="row">
      <div><label>数据库名</label><input name="mysql_db" value="{$v('mysql_db','lorawan')}"></div>
      <div><label>用户名</label><input name="mysql_user" value="{$v('mysql_user','lorawan')}"></div>
    </div>
    <label>密码</label><input type="password" name="mysql_pass" value="{$v('mysql_pass')}">
  </fieldset>
  <fieldset>
    <legend>管理员账号</legend>
    <div class="row">
      <div><label>用户名</label><input name="admin_user" value="{$v('admin_user','admin')}"></div>
      <div><label>密码</label><input type="password" name="admin_pass"></div>
    </div>
    <label>确认密码</label><input type="password" name="admin_pass2">
  </fieldset>
  <button type="submit" style="margin-top:16px">开始安装</button>
</form>
HTML
        );
    }

    private static function renderSuccess(): string
    {
        return self::page(<<<HTML
<h2>安装完成 ✅</h2>
<p>数据库结构已导入，管理员账号已创建，配置已写入 <code>config/local.php</code>。</p>
<p><a class="btn" href="/login">前往登录</a></p>
HTML
        );
    }

    private static function page(string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>holastack 安装</title>
<style>
:root,[data-theme="dark"]{--bg:#0f1420;--panel:#1a2233;--line:#2b3650;--txt:#e6ecf5;--mut:#8b97ad;--acc:#3da9fc;--ok:#36d399;--err:#f87272;--bg-deep:#0d1320;--bg-subtle:#161d2c;--bg-chip:#243049;--txt-on-acc:#04121f;--err-box-bg:#3a1620;--err-box-border:#5a2230;--shadow-rgb:0,0,0}
[data-theme="light"]{--bg:#f4f6fa;--panel:#ffffff;--line:#d0d6e0;--txt:#1a2332;--mut:#6a7588;--acc:#1a73e8;--ok:#1a9e5c;--err:#d63636;--bg-deep:#eef1f6;--bg-subtle:#f7f9fc;--bg-chip:#e8edf4;--txt-on-acc:#ffffff;--err-box-bg:#fce4e4;--err-box-border:#f0b0b0;--shadow-rgb:0,0,0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
main{max-width:560px;margin:40px auto;padding:24px;background:var(--panel);border:1px solid var(--line);border-radius:12px}
h2{margin-top:0;color:var(--acc)}.muted{color:var(--mut)}
label{display:block;color:var(--mut);margin:12px 0 4px;font-size:12px}
input{background:var(--bg-deep);color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:8px 10px;width:100%}
.row{display:flex;gap:12px}.row>div{flex:1}fieldset{border:1px solid var(--line);border-radius:10px;margin-top:16px;padding:8px 16px}
legend{color:var(--acc);padding:0 6px}.btn,.err{padding:8px 14px;border-radius:7px}
.btn{background:var(--acc);color:var(--txt-on-acc);border:0;font-weight:600;text-decoration:none;display:inline-block}
.err{background:var(--err-box-bg);color:var(--err);margin:8px 0;border:1px solid var(--err-box-border)}
a.btn{color:var(--txt-on-acc)}
</style>
<script>(function(){var s=localStorage.getItem('elw_theme');if(!s){s=window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}document.documentElement.setAttribute('data-theme',s);})();</script>
</head>
<body><main>$body</main></body></html>
HTML;
    }
}

function elw_install_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
