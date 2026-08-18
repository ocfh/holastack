<?php
/*
 * 全局服务端界面翻译助手（无命名空间，供任意入口调用）。
 * 机制与前端 t(s) 一致：按界面语言加载 lang/<lang>.php 字典，key 缺失回退为原文（默认中文源串）。
 * 新增语言只需在 lang/ 下添加一个 <lang>.php 文件，无需改任何业务代码。
 */

/**
 * 翻译单个字符串。elw_t('中文', 'fr') —— 第二参可显式指定语言；缺省用当前站点设置 ui_lang。
 */
function elw_t(string $s, ?string $lang = null): string
{
    static $cache = [];
    $lang = $lang ?: ELW_currentLang();
    if (!isset($cache[$lang])) {
        $cache[$lang] = ELW_loadLang($lang);
    }
    return $cache[$lang][$s] ?? $s;
}

/**
 * 载入指定语言的字典数组；文件缺失时返回空数组（安全回退为原文）。
 */
function ELW_loadLang(string $lang): array
{
    $file = __DIR__ . '/../lang/' . preg_replace('/[^A-Za-z0-9_-]/', '', $lang) . '.php';
    return file_exists($file) ? (require $file) : [];
}

/**
 * 当前界面语言：优先站点设置 ui_lang，缺省 zh。结果缓存，避免反复查库。
 * 直接返回设置值（可为任意语言标识，如 zh/en/fr/ja...），由 lang/ 下的文件决定是否存在翻译。
 */
function ELW_currentLang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $lang = 'zh';
    if (class_exists(\holastack\Web\Setting::class)) {
        try {
            $v = \holastack\Web\Setting::get('ui_lang', 'zh');
            if (is_string($v) && $v !== '') {
                $lang = $v;
            }
        } catch (\Throwable $e) {
            $lang = 'zh';
        }
    }
    return $lang;
}

/**
 * 可用语言清单：扫描 lang/<lang>.php，返回 [lang => 原生显示名, ...]。
 * 每个语言文件约定携带 '__name' 键标明其原生名称；缺失时回退为语言标识本身。
 * 新增语言：在 lang/ 下加一个带 '__name' 的 <lang>.php 即可自动出现在下拉列表中。
 */
function ELW_langOptions(): array
{
    static $opts = null;
    if ($opts !== null) {
        return $opts;
    }
    $opts = [];
    $dir = __DIR__ . '/../lang';
    foreach (glob($dir . '/*.php') as $f) {
        $base = basename($f);
        if ($base === 'meta.php' || !preg_match('/^([A-Za-z0-9_-]+)\.php$/', $base, $m)) {
            continue;
        }
        $lang = $m[1];
        $dict = require $f;
        $name = is_array($dict) ? ($dict['__name'] ?? $lang) : $lang;
        $opts[$lang] = (string)$name;
    }
    uksort($opts, function ($a, $b) {
        return $a === 'zh' ? -1 : ($b === 'zh' ? 1 : strcmp($a, $b));
    });
    return $opts;
}