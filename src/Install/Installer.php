<?php
namespace holastack\Install;

/**
 * holastack Web 安装向导（三步走）：
 *  1) 语言设置（中文 / English，选择后立即生效，并写入 ui_lang）
 *  2) 数据库信息（MySQL 为主 / SQLite 零配置；「测试连接」成功才进入下一步）
 *  3) 配置管理员账号（用户名 / 密码 / 确认密码 三个横线编辑框）
 * 完成后导入数据库结构、创建管理员、持久化 config/local.php 并写入默认语言。
 * 已安装则不应再访问。
 */
class Installer
{
    private static function lang(): string
    {
        $l = (string)($_SESSION['install_lang'] ?? 'zh');
        return preg_replace('/[^A-Za-z0-9_-]/', '', $l) ?: 'zh';
    }

    private static function tl(string $zh): string
    {
        return elw_t($zh, self::lang());
    }

    public static function handle(): void
    {
        elw_install_session_start();
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        // 独立 AJAX 端点：测试数据库连接（GET /install?step=test-db&db_type=..&...），返回 JSON
        if (($path === '/install' || $path === '/install/') && ($_GET['step'] ?? '') === 'test-db') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(self::testDb($_GET), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($method === 'POST') {
            self::process();
            return;
        }
        // GET 步骤分发：默认语言页；?step=db 数据库页；?step=admin 管理员页
        $cur = (string) ($_GET['step'] ?? '');
        if ($cur === 'db') {
            echo self::renderStep2([], []);
        } elseif ($cur === 'admin') {
            echo self::renderStep3([], []);
        } else {
            echo self::renderStep1([]);
        }
    }

    private static function testDb(array $g): array
    {
        $dbType = ($g['db_type'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $errors = [];
        $connDsn = '';
        $dsn = '';
        $user = '';
        $pass = '';
        if ($dbType === 'sqlite') {
            $file = trim($g['sqlite_file'] ?? '');
            if ($file === '') {
                $file = 'runtime/server.db';
            }
            $abs = self::resolveSqlitePath($file);
            $dsn = 'sqlite:' . $abs;
            $connDsn = $dsn;
            // 若文件尚不存在，先校验其所在目录可写，避免连接报错误导
            $dir = dirname($abs);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                $errors[] = self::msg('数据库目录不可创建：', 'Cannot create database directory: ') . $dir;
            } elseif (!is_dir($dir) || !is_writable($dir)) {
                $errors[] = self::msg('数据库目录不可写：', 'Database directory not writable: ') . $dir;
            }
        } else {
            $host = trim($g['mysql_host'] ?? '127.0.0.1');
            $port = (int) ($g['mysql_port'] ?? 3306);
            $dbname = trim($g['mysql_db'] ?? '');
            $user = trim($g['mysql_user'] ?? '');
            $pass = (string) ($g['mysql_pass'] ?? '');
            if ($dbname === '') {
                $errors[] = self::msg('数据库名不能为空', 'Database name is required');
            }
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $connDsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        }
        if (empty($errors)) {
            try {
                $pdo = new \PDO($connDsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                if ($dbType === 'mysql' && $dbname !== '') {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4");
                    $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                }
                // 存 session 供最终安装复用，避免用户填一遍
                $_SESSION['install_db'] = ['db_type' => $dbType, 'dsn' => $dsn, 'user' => $user, 'pass' => $pass];
                $_SESSION['install_step'] = 'db';
                return ['ok' => true];
            } catch (\Throwable $e) {
                $errors[] = self::msg('连接失败：', 'Connection failed: ') . $e->getMessage();
            }
        }
        return ['ok' => false, 'error' => implode('；', $errors)];
    }

    private static function resolveSqlitePath(string $file): string
    {
        // 去掉潜在的 "sqlite:" 前缀与首尾空白
        $file = trim(preg_replace('/^sqlite:/', '', $file));
        // 绝对路径（含 C:\ 盘符、/、\ 开头）不改动
        if ($file === '' || strpos($file, '/') === 0 || strpos($file, '\\') === 0 || preg_match('/^[A-Za-z]:[\\\\\/]/', $file)) {
            $p = $file === '' ? ELW_ROOT . '/runtime/server.db' : $file;
        } else {
            $p = ELW_ROOT . '/' . $file;
        }
        // 统一反斜杠，便于 dirname
        return str_replace('\\', '/', $p);
    }

    private static function process(): void
    {
        $p = $_POST;
        $action = (string) ($p['action'] ?? '');

        // 步骤一：语言设置。分两种提交：
        //   a) 点击语言卡片 → 立即刷新换语言（stay，仍留在第 1 步）
        //   b) 点击「下一步」→ 进入数据库步骤
        if ($action === 'set-lang') {
            $l = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($p['lang'] ?? 'zh'));
            $_SESSION['install_lang'] = $l !== '' ? $l : 'zh';
            $_SESSION['install_step'] = 'lang';
            if (!empty($p['stay'])) {
                header('Location: /install');
            } else {
                header('Location: /install?step=db');
            }
            exit;
        }

        // 步骤三：最终安装
        $errors = [];
        $db = $_SESSION['install_db'] ?? null;
        $dbType = $db['db_type'] ?? 'sqlite';
        $dsn = $db['dsn'] ?? ('sqlite:' . ELW_ROOT . '/runtime/server.db');
        $user = $db['user'] ?? '';
        $pass = $db['pass'] ?? '';

        // 管理员账号校验
        $adminUser = trim($p['admin_user'] ?? '');
        $adminPass = (string) ($p['admin_pass'] ?? '');
        $adminPass2 = (string) ($p['admin_pass2'] ?? '');
        if ($adminUser === '') {
            $errors[] = self::msg('管理员用户名不能为空', 'Admin username is required');
        }
        if (strlen($adminPass) < 6) {
            $errors[] = self::msg('管理员密码至少 6 位', 'Admin password must be at least 6 characters');
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = self::msg('两次输入的密码不一致', 'Passwords do not match');
        }

        // 若之前没测过连接，也尝试连接一次
        $pdo = null;
        if (empty($errors)) {
            try {
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            } catch (\Throwable $e) {
                $errors[] = self::msg('数据库连接失败', 'Database connection failed') . '：' . $e->getMessage();
            }
        }

        if (!empty($errors) || $pdo === null) {
            echo self::renderStep3($errors, $p);
            return;
        }

        // 导入数据库结构 + 创建管理员
        try {
            self::importSchema($pdo, $dbType);
        } catch (\Throwable $e) {
            echo self::renderStep3([self::msg('导入数据库结构失败', 'Failed to import schema') . '：' . $e->getMessage()], $p);
            return;
        }

        $adminUser = strtolower($adminUser);
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $exists = $pdo->prepare('SELECT id FROM users WHERE username=?');
        $exists->execute([$adminUser]);
        if ($exists->fetch() === false) {
            $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,?)')
                ->execute([$adminUser, $hash, 'admin', time()]);
        }

        // 写入默认语言到 settings 表
        try {
            $lang = self::lang();
            $ins = $pdo->prepare('INSERT INTO settings (skey, svalue, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)');
            $ins->execute(['ui_lang', $lang, time()]);
        } catch (\Throwable $e) {
            // SQLite 无 ON DUPLICATE：回退为先查后插
            try {
                $ex = $pdo->prepare('SELECT skey FROM settings WHERE skey=?');
                $ex->execute(['ui_lang']);
                if ($ex->fetch() === false) {
                    $pdo->prepare('INSERT INTO settings (skey, svalue, updated_at) VALUES (?,?,?)')->execute(['ui_lang', $lang, time()]);
                }
            } catch (\Throwable $ignored) {
                // 忽略：settings 写入失败不影响安装主体
            }
        }

        self::writeConfig($dbType, $dsn, $user, $pass);
        header('Location: /login');
        exit;
    }

    /** @deprecated 兼容旧调用：等价 tl()，第二参忽略（英文由 lang/en.php 提供）。 */
    private static function msg(string $zh, string $en): string
    {
        return self::tl($zh);
    }

    /** 安装向导文案清单：key 为模板占位，value 经 tl() 查 lang/<lang>.php 按当前语言取译文。 */
    private static function labels(): array
    {
        return [
            'title' => self::tl('HolaStack 安装'),
            'step1' => self::tl('第 1 步 / 3 · 语言设置'),
            'step1_desc' => self::tl('请选择安装向导与后台的默认界面语言，点击后立即生效。安装完成后仍可在「站点设置 → 界面语言」随时切换。'),
            'chinese' => '中文',
            'english' => 'English',
            'next' => self::tl('下一步 →'),
            'step2' => self::tl('第 2 步 / 3 · 数据库信息'),
            'step2_desc' => self::tl('选择数据库类型并填写连接信息，点击「测试连接」，成功后才可继续下一步。'),
            'mysql_rec' => self::tl('MySQL'),
            'sqlite_opt' => self::tl('SQLite'),
            'mysql_conn' => self::tl('MySQL 连接'),
            'host' => self::tl('主机'),
            'port' => self::tl('端口'),
            'dbname' => self::tl('数据库名'),
            'user' => self::tl('用户名'),
            'pass' => self::tl('密码'),
            'sqlite_file' => self::tl('数据库文件'),
            'test_conn' => self::tl('测试连接'),
            'testing' => self::tl('正在测试…'),
            'test_ok' => self::tl('连接成功 ✔ 可继续下一步'),
            'back' => self::tl('← 上一步'),
            'step3' => self::tl('第 3 步 / 3 · 配置管理员账号'),
            'step3_desc' => self::tl('设置后台管理员登录账号（用户名、密码、确认密码）。'),
            'admin_user' => self::tl('管理员用户名'),
            'admin_pass' => self::tl('管理员密码'),
            'admin_pass2' => self::tl('确认密码'),
            'install_btn' => self::tl('开始安装'),
            'err_box' => self::tl('需要修正以下问题：'),
        ];
    }

    private static function importSchema(\PDO $pdo, string $dbType): void
    {
        $file = ELW_ROOT . '/schema/' . ($dbType === 'sqlite' ? 'sqlite.sql' : 'mysql.sql');
        $sql = file_get_contents($file);
        if ($dbType === 'sqlite') {
            $pdo->exec($sql);
        } else {
            foreach (explode(';', $sql) as $raw) {
                $lines = array_filter(array_map('trim', explode("\n", $raw)), function ($l) {
                    return $l !== '' && strpos($l, '--') !== 0;
                });
                $stmt = implode("\n", $lines);
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                    } catch (\Throwable $e) {
                        // 表兼容：已存在同名列/索引或多行语句失败时跳过
                        if (stripos($e->getMessage(), 'already exists') === false) {
                            throw $e;
                        }
                    }
                }
            }
        }
    }

    private static function writeConfig(string $dbType, string $dsn, string $user, string $pass): void
    {
        $cfg = [
            'db_dsn' => $dsn,
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

    private static function renderStep1(array $errors): string
    {
        $T = self::labels();
        $lang = self::lang();
        $zhActive = $lang === 'zh' ? 'active' : '';
        $enActive = $lang === 'en' ? 'active' : '';
        $errHtml = self::errHtml($errors);
        return self::page($T['title'], <<<HTML
<h2>{$T['step1']}</h2>
<p class="muted">{$T['step1_desc']}</p>
$errHtml
<form method="post" action="/install" id="langForm">
  <input type="hidden" name="action" value="set-lang">
  <input type="hidden" name="lang" id="langInput" value="$lang">
  <input type="hidden" name="stay" id="stayInput" value="">
  <div class="lang-list">
    <button type="button" class="lang-card $zhActive" data-lang="zh">
      <span class="lang-flag">中</span>
      <span class="lang-name">{$T['chinese']}</span>
    </button>
    <button type="button" class="lang-card $enActive" data-lang="en">
      <span class="lang-flag">EN</span>
      <span class="lang-name">{$T['english']}</span>
    </button>
  </div>
  <button style="margin-top:16px" type="submit" class="btn btn-block">{$T['next']}</button>
</form>
<script>
document.querySelectorAll('.lang-card').forEach(function(card){
  card.addEventListener('click', function(){
    document.getElementById('langInput').value = card.getAttribute('data-lang');
    document.getElementById('stayInput').value = '1'; // 切语言后留在本页
    document.getElementById('langForm').submit();
  });
});
</script>
HTML
        );
    }

    private static function renderStep2(array $errors, array $values): string
    {
        $T = self::labels();
        $v = function (string $k, string $d = '') use ($values) {
            return htmlspecialchars($values[$k] ?? $d, ENT_QUOTES);
        };
        $errHtml = self::errHtml($errors);
        return self::page($T['title'], <<<HTML
<h2>{$T['step2']}</h2>
<p class="muted">{$T['step2_desc']}</p>
<div id="errs">$errHtml</div>
<div class="dbtypes">
  <button type="button" class="lang-card active" data-db="mysql">
    <span class="lang-flag">M</span>
    <span class="lang-name">{$T['mysql_rec']}</span>
  </button>
  <button type="button" class="lang-card" data-db="sqlite">
    <span class="lang-flag">S</span>
    <span class="lang-name">{$T['sqlite_opt']}</span>
  </button>
  <input type="hidden" id="dbTypeInput" value="mysql">
</div>
<fieldset id="fs_mysql">
  <legend>{$T['mysql_conn']}</legend>
  <div class="row">
    <div><label>{$T['host']}</label><input id="db_host" value="{$v('mysql_host','127.0.0.1')}"></div>
    <div><label>{$T['port']}</label><input id="db_port" value="{$v('mysql_port','3306')}"></div>
  </div>
  <div class="row">
    <div><label>{$T['dbname']}</label><input id="db_name" value="{$v('mysql_db','lorawan')}"></div>
    <div><label>{$T['user']}</label><input id="db_user" value="{$v('mysql_user','lorawan')}"></div>
  </div>
  <label>{$T['pass']}</label><input type="password" id="db_pass">
</fieldset>
<fieldset id="fs_sqlite" class="hidden">
  <legend>{$T['sqlite_opt']}</legend>
  <label>{$T['sqlite_file']}</label><input id="db_file" value="{$v('sqlite_file','runtime/server.db')}">
</fieldset>
<div id="testResult" class="hidden"></div>
<div style="margin-top:16px;display:flex;gap:10px;align-items:center">
  <button class="btn ghost" onclick="location.href='/install'">{$T['back']}</button>
  <button class="btn" id="testBtn" onclick="testDb()">{$T['test_conn']}</button>
</div>
<div id="adminWrap" class="hidden"></div>
<script>
function currentDb(){ return document.getElementById('dbTypeInput').value; }
function toggleDbType(db){
  document.querySelectorAll('.dbtypes .lang-card').forEach(function(c){
    c.classList.toggle('active', c.getAttribute('data-db')===db);
  });
  document.getElementById('dbTypeInput').value = db;
  document.getElementById('fs_mysql').classList.toggle('hidden', db!=='mysql');
  document.getElementById('fs_sqlite').classList.toggle('hidden', db!=='sqlite');
}
document.querySelectorAll('.dbtypes .lang-card').forEach(function(card){
  card.addEventListener('click', function(){ toggleDbType(card.getAttribute('data-db')); });
});
function setResult(ok, html){
  var box = document.getElementById('testResult');
  box.classList.remove('hidden','ok','warn');
  box.classList.add(ok?'ok':'warn');
  box.innerHTML = html;
}
async function testDb(){
  var btn = document.getElementById('testBtn'); btn.disabled=true; btn.textContent='{$T['testing']}';
  setResult(false,'');
  var p = new URLSearchParams(); p.set('step','test-db');
  var dt = currentDb(); p.set('db_type', dt);
  if(dt==='mysql'){
    p.set('mysql_host', document.getElementById('db_host').value);
    p.set('mysql_port', document.getElementById('db_port').value);
    p.set('mysql_db', document.getElementById('db_name').value);
    p.set('mysql_user', document.getElementById('db_user').value);
    p.set('mysql_pass', document.getElementById('db_pass').value);
  } else {
    p.set('sqlite_file', document.getElementById('db_file').value);
  }
  try{
    var r = await fetch('/install?'+p.toString());
    var j = await r.json();
    if(j.ok){ setResult(true, '{$T['test_ok']}'); showAdmin(); }
    else { setResult(false, '<b>{$T['err_box']}</b><div class="warn-txt">' + (j.error||'') + '</div>'); }
  }catch(e){ setResult(false, '<b>{$T['err_box']}</b><div class="warn-txt">' + e + '</div>'); }
  btn.disabled=false; btn.textContent='{$T['test_conn']}';
}
function showAdmin(){
  document.getElementById('adminWrap').classList.remove('hidden');
  document.getElementById('adminWrap').innerHTML = `
  <form method="post" action="/install" style="margin-top:26px">
    <input type="hidden" name="action" value="final">
    <fieldset>
      <legend>{$T['step3']}</legend>
      <p class="muted">{$T['step3_desc']}</p>
      <div class="field sep-field"><label>{$T['admin_user']}</label><input name="admin_user" autocomplete="off"/></div>
      <div class="field sep-field"><label>{$T['admin_pass']}</label><input type="password" name="admin_pass" autocomplete="new-password"/></div>
      <div class="field sep-field"><label>{$T['admin_pass2']}</label><input type="password" name="admin_pass2" autocomplete="new-password"/></div>
      <button type="submit" class="btn" style="margin-top:18px">{$T['install_btn']}</button>
    </fieldset>
  </form>`;
}
</script>
HTML
        );
    }

    private static function renderStep3(array $errors, array $values): string
    {
        $T = self::labels();
        $v = function (string $k, string $d = '') use ($values) {
            return htmlspecialchars($values[$k] ?? $d, ENT_QUOTES);
        };
        $errHtml = self::errHtml($errors);
        return self::page($T['title'], <<<HTML
<h2>{$T['step3']}</h2>
<p class="muted">{$T['step3_desc']}</p>
$errHtml
<form method="post" action="/install">
  <input type="hidden" name="action" value="final">
  <fieldset>
    <legend>{$T['step3']}</legend>
    <div class="field sep-field"><label>{$T['admin_user']}</label><input name="admin_user" value="{$v('admin_user')}" autocomplete="off"/></div>
    <div class="field sep-field"><label>{$T['admin_pass']}</label><input type="password" name="admin_pass" autocomplete="new-password"/></div>
    <div class="field sep-field"><label>{$T['admin_pass2']}</label><input type="password" name="admin_pass2" autocomplete="new-password"/></div>
    <button type="submit" class="btn" style="margin-top:18px">{$T['install_btn']}</button>
  </fieldset>
</form>
HTML
        );
    }

    private static function errHtml(array $errors): string
    {
        if (!$errors) {
            return '';
        }
        $html = '';
        foreach ($errors as $e) {
            $html .= '<div class="err">' . htmlspecialchars($e, ENT_QUOTES) . '</div>';
        }
        return $html;
    }

    private static function page(string $title, string $body): string
    {
        $langAttr = self::langAttr();
        return <<<HTML
<!DOCTYPE html>
<html lang="$langAttr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
:root,[data-theme="dark"]{--bg:#0f1420;--panel:#1a2233;--line:#2b3650;--txt:#e6ecf5;--mut:#8b97ad;--acc:#3da9fc;--ok:#36d399;--err:#f87272;--bg-deep:#0d1320;--bg-chip:#243049;--txt-on-acc:#04121f;--err-box-bg:#3a1620;--err-box-border:#5a2230;--bg-subtle:#161d2c;--warn-bg:#3a1620;--warn-border:#a0453f;--warn-txt:#ffd7d7}
[data-theme="light"]{--bg:#f4f6fa;--panel:#ffffff;--line:#d0d6e0;--txt:#1a2332;--mut:#6a7588;--acc:#1a73e8;--ok:#1a9e5c;--err:#d63636;--bg-deep:#eef1f6;--txt-on-acc:#ffffff;--err-box-bg:#fce4e4;--err-box-border:#f0b0b0;--bg-subtle:#f7f9fc;--warn-bg:#fdeaea;--warn-border:#f0a9a9;--warn-txt:#8b2020}')
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
main{max-width:560px;margin:40px auto;padding:24px;background:var(--panel);border:1px solid var(--line);border-radius:12px}
h2{margin-top:0;color:var(--acc)}.muted{color:var(--mut)}.hidden{display:none!important}
label{display:block;color:var(--mut);margin:14px 0 6px;font-size:12px;font-weight:500}
input,select{background:var(--bg-deep);color:var(--txt);border:1px solid var(--line);border-radius:8px;padding:9px 12px;width:100%;font-family:inherit;min-width:0;box-sizing:border-box}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.row>div{min-width:0}fieldset{border:1px solid var(--line);border-radius:10px;margin-top:16px;padding:14px 18px}
input[type="checkbox"],input[type="radio"]{-webkit-appearance:none;appearance:none;-moz-appearance:none;width:18px;height:18px;min-width:18px;max-width:18px;padding:0;margin:0;background:var(--bg-deep);border:1.5px solid var(--line);border-radius:6px;display:inline-block;vertical-align:-2px;cursor:pointer;position:relative;flex:0 0 auto;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease}
input[type="radio"]{border-radius:50%}
input[type="checkbox"]:checked,input[type="radio"]:checked{background:var(--acc);border-color:var(--acc)}
input[type="checkbox"]:checked::after{content:"";position:absolute;left:5px;top:1px;width:5px;height:10px;border:solid var(--txt-on-acc);border-width:0 2px 2px 0;transform:rotate(45deg)}
input[type="radio"]:checked::after{content:"";position:absolute;left:5px;top:5px;width:6px;height:6px;border-radius:50%;background:var(--txt-on-acc)}
input[type="checkbox"]:focus-visible,input[type="radio"]:focus-visible{outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--acc) 25%,transparent)}
label:has(input[type="checkbox"]),label:has(input[type="radio"]),.check{display:inline-flex;align-items:center;gap:8px;margin:10px 0 4px;color:var(--txt);font-size:13px;cursor:pointer}
legend{color:var(--acc);padding:0 6px}.btn,.err{padding:8px 14px;border-radius:7px}
.btn{background:var(--acc);color:var(--txt-on-acc);border:0;font-weight:600;text-decoration:none;display:inline-block;cursor:pointer;font-family:inherit}
.btn.ghost{background:transparent;color:var(--acc);border:1px solid var(--line)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.err{background:var(--err-box-bg);color:var(--err);margin:8px 0;border:1px solid var(--err-box-border);padding:8px 14px;border-radius:7px}
/* ---- 语言 / 数据库类型 卡片列表 ---- */
.lang-list{display:flex;flex-direction:column;gap:10px;margin-top:18px}
.lang-card{display:flex;align-items:center;gap:12px;width:100%;text-align:left;background:var(--bg-deep);color:var(--txt);border:1.5px solid var(--line);border-radius:10px;padding:12px 14px;cursor:pointer;font-family:inherit;transition:border-color .15s ease,background .15s ease;margin:0}
.lang-card:hover{border-color:var(--acc)}
.lang-card.active{border-color:var(--acc);background:color-mix(in srgb,var(--acc) 12%,transparent)}
.lang-card .lang-flag{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:color-mix(in srgb,var(--acc) 16%,transparent);color:var(--acc);font-weight:700;font-size:14px}
.lang-card .lang-name{font-size:14px;font-weight:600;color:var(--txt)}
.dbtypes{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
.dbtypes .lang-flag{width:34px;height:34px;font-size:12px}
/* ---- 测试连接结果横幅（登录公告样式）：成功绿 / 失败淡红警告 ---- */
#testResult{display:flex;gap:10px;align-items:flex-start;background:linear-gradient(135deg,var(--bg-subtle),var(--panel));border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13px;margin-top:16px;white-space:pre-wrap;line-height:1.6;word-break:break-word;color:var(--txt)}
#testResult.ok{border-color:color-mix(in srgb,var(--ok) 50%,transparent);background:color-mix(in srgb,var(--ok) 8%,var(--panel));color:var(--ok)}
#testResult.warn{background:var(--warn-bg);border:1px solid var(--warn-border);color:var(--warn-txt)}
#testResult b{font-size:12px;margin-bottom:2px;display:block}
.warn-txt{white-space:pre-wrap;font-size:12px}
/* ---- 分离式横线编辑框（管理员账号）：仅底边一条横线 ---- */
.sep-field{padding-bottom:4px;margin-bottom:4px}
.sep-field label{font-size:12px;color:var(--mut);margin:0 0 2px}
.sep-field input{border:none;border-bottom:1px solid var(--line);border-radius:0;background:transparent;padding:8px 2px}
.sep-field input:focus{outline:none;border-bottom-color:var(--acc);box-shadow:0 1px 0 0 var(--acc)}
</style>
<script>(function(){var s=localStorage.getItem('elw_theme');if(!s){s=window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}document.documentElement.setAttribute('data-theme',s);})();</script>
</head>
<body><main>{$body}</main></body></html>
HTML;
    }

    private static function langAttr(): string
    {
        $l = self::lang();
        return $l === 'en' ? 'en' : ($l === 'zh' ? 'zh-CN' : $l);
    }
}

function elw_install_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}