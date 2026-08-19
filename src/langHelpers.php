<?php











function elw_t(string $s, ?string $lang = null): string
{
    static $cache = [];
    $lang = $lang ?: ELW_currentLang();
    if (!isset($cache[$lang])) {
        $cache[$lang] = ELW_loadLang($lang);
    }
    return $cache[$lang][$s] ?? $s;
}





function ELW_loadLang(string $lang): array
{
    $file = __DIR__ . '/../lang/' . preg_replace('/[^A-Za-z0-9_-]/', '', $lang) . '.php';
    return file_exists($file) ? (require $file) : [];
}






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