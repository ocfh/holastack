<?php
/**
 * 认证模块单元测试：
 *  - 用户创建 / 密码校验（password_hash）
 *  - 错误密码、未知用户拒绝
 *  - 角色判断（admin / operator）
 * 运行：php tests/test_auth.php
 */
require __DIR__ . '/../../bootstrap.php';
use holastack\DB\Database;
use holastack\Auth\Auth;

Database::migrate();

$pass = 0; $fail = 0;
function check(string $n, bool $c): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

Database::execute("DELETE FROM users WHERE username=?", ['authtest']);

$id = Auth::createUser('authtest', 'secret123', Auth::ROLE_OPERATOR);
check('createUser returns id', $id > 0);

$u = Auth::authenticate('authtest', 'secret123');
check('authenticate valid password', $u !== null && ($u['role'] ?? '') === Auth::ROLE_OPERATOR);

$nope = Auth::authenticate('authtest', 'wrong');
check('authenticate rejects wrong password', $nope === null);

$nope2 = Auth::authenticate('nope', 'x');
check('authenticate rejects unknown user', $nope2 === null);

// 角色判断（基于令牌中的当前用户：模拟请求头携带令牌）
$tokOp = Auth::issueToken(['id' => 2, 'username' => 'op', 'role' => Auth::ROLE_OPERATOR]);
$_SERVER['HTTP_X_ELW_TOKEN'] = $tokOp;
check('operator has operator role', Auth::hasRole(Auth::ROLE_OPERATOR) === true);
check('operator lacks admin role', Auth::hasRole(Auth::ROLE_ADMIN) === false);

$tokAdm = Auth::issueToken(['id' => 1, 'username' => 'adm', 'role' => Auth::ROLE_ADMIN]);
$_SERVER['HTTP_X_ELW_TOKEN'] = $tokAdm;
check('admin has admin role', Auth::hasRole(Auth::ROLE_ADMIN) === true);

Database::execute("DELETE FROM users WHERE username=?", ['authtest']);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
