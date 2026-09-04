<?php
namespace holastack\Core;

/**
 * 极简 5 字段 cron 解析 + 下一次执行时间计算。
 * 支持：星号、步长（斜杠加数字，如 每5分钟）、单值、区间A-B、逗号列表（分钟/小时/日/月/周）。
 * 日(dom)与周(dow)按标准 cron 语义为 OR 关系。
 */
class Cron
{
    private const RANGE = [
        0 => 59, // minute
        1 => 23, // hour
        2 => 31, // day of month
        3 => 12, // month
        4 => 6,  // day of week (0=Sunday)
    ];

    /**
     * 返回 expr 在 from 之后的第一个匹配时间点（unix 秒），60 天内找不到返回 null。
     */
    public static function next(string $expr, int $from): ?int
    {
        $parts = self::parse($expr);
        if ($parts === null) {
            return null;
        }
        $t = $from - $from % 60 + 60; // 对齐到分钟并前进到下一分钟
        $limit = $t + 60 * 86400;
        $domFull = count($parts[2]) === 32; // 0..31 全量
        $dowFull = count($parts[4]) === 7;  // 0..6 全量
        while ($t < $limit) {
            $ds = getdate($t);
            $monthOk = isset($parts[3][$ds['mon']]);
            $domOk = isset($parts[2][$ds['mday']]);
            $dowOk = isset($parts[4][$ds['wday']]);
            if ($domFull && $dowFull) {
                $dayOk = true;
            } elseif (!$domFull && !$dowFull) {
                $dayOk = $domOk || $dowOk; // 标准 cron：任一字段匹配即可
            } elseif (!$domFull) {
                $dayOk = $domOk;
            } else {
                $dayOk = $dowOk;
            }
            if ($monthOk && $dayOk && isset($parts[1][$ds['hours']]) && isset($parts[0][$ds['minutes']])) {
                return $t;
            }
            $t += 60;
        }
        return null;
    }

    /**
     * @return array|null 5 个稀疏 true-map；非法表达式返回 null
     */
    public static function parse(string $expr): ?array
    {
        $fields = preg_split('/\s+/', trim($expr));
        if (count($fields) !== 5) {
            return null;
        }
        $out = [];
        foreach ($fields as $i => $raw) {
            $m = self::parseField($raw, self::RANGE[$i]);
            if ($m === null) {
                return null;
            }
            $out[$i] = $m;
        }
        return $out;
    }

    private static function parseField(string $field, int $max): ?array
    {
        $field = trim($field);
        if ($field === '' || $field === '*') {
            return self::full($max);
        }
        $map = [];
        foreach (explode(',', $field) as $token) {
            $step = 1;
            if (strpos($token, '/') !== false) {
                [$range, $step] = explode('/', $token, 2);
                $step = (int) $step;
                if ($step < 1) {
                    return null;
                }
            } else {
                $range = $token;
            }
            $range = trim($range);
            if ($range === '*') {
                $lo = 0;
                $hi = $max;
            } elseif (strpos($range, '-') !== false) {
                [$lo, $hi] = array_map('trim', explode('-', $range, 2));
                $lo = (int) $lo;
                $hi = (int) $hi;
            } else {
                $v = (int) $range;
                $lo = $hi = $v;
            }
            if ($lo < 0 || $hi > $max || $lo > $hi) {
                return null;
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $map[$v] = true;
            }
        }
        return $map === [] ? null : $map;
    }

    private static function full(int $max): array
    {
        $m = [];
        for ($i = 0; $i <= $max; $i++) {
            $m[$i] = true;
        }
        return $m;
    }
}