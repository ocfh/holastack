<?php

/**
 * mbstring polyfill（服务器 PHP 未启用 mbstring 扩展时的兜底）。
 * 仅实现项目用到的最小集：mb_substr / mb_strlen。
 * 非 UTF-8 场景不在本项目范围内（全站 UTF-8）。
 * 若 mbstring 已加载则本文件不做任何事。
 */
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $enc = $encoding ?: 'UTF-8';
        if (function_exists('iconv_substr')) {
            $r = @iconv_substr($s, $start, $length ?? iconv_strlen($s, $enc), $enc);
            return $r === false ? (string) substr($s, $start, $length ?? -1) : (string) $r;
        }
        // 兜底：按 UTF-8 码点手工切分（/u 修饰符处理多字节）
        if ($start < 0) {
            $len = preg_match_all('/./us', $s);
            $start = max(0, $len + $start);
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($length === null) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, max(0, $length)));
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        if (function_exists('iconv_strlen')) {
            $r = @iconv_strlen($s, $encoding ?: 'UTF-8');
            return $r === false ? (int) strlen($s) : (int) $r;
        }
        $n = preg_match_all('/./us', $s);
        return $n === false ? (int) strlen($s) : (int) $n;
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $encoding = null): string
    {
        return strtolower($s); // ASCII 降级足够（项目仅用于 hex/枚举）
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s, ?string $encoding = null): string
    {
        return strtoupper($s);
    }
}
