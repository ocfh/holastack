<?php
/**
 * 一键运行所有 /v1/ API 用例。
 * 每个分类用例以独立 php 进程执行（各自独立统计），最后汇总失败套件数。
 *
 * 运行：php tests/api/run_all.php
 */
$files = [];
foreach (glob(__DIR__ . '/*/test_*.php') as $f) {
    $files[] = $f;
}
sort($files);

if (empty($files)) {
    echo "未找到任何 test_*.php 用例。\n";
    exit(1);
}

$failedSuites = 0;
foreach ($files as $f) {
    echo "\n##### " . basename(dirname($f)) . '/' . basename($f) . " #####\n";
    $code = 0;
    system('php "' . $f . '"', $code);
    if ($code !== 0) {
        $failedSuites++;
    }
}

echo "\n==== run_all: 共 " . count($files) . " 个套件，{$failedSuites} 个失败 ====\n";
exit($failedSuites > 0 ? 1 : 0);
