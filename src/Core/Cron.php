<?php
namespace holastack\Core;

class Cron
{
    private const RANGE = [
        0 => 59,
        1 => 23,
        2 => 31,
        3 => 12,
        4 => 6,
    ];

    public static function next(string $expr, int $from): ?int
    {
        $parts = self::parse($expr);
        if ($parts === null) {
            return null;
        }
        $t = $from - $from % 60 + 60;
        $limit = $t + 60 * 86400;
        $domFull = count($parts[2]) === 32;
        $dowFull = count($parts[4]) === 7;
        while ($t < $limit) {
            $ds = getdate($t);
            $monthOk = isset($parts[3][$ds['mon']]);
            $domOk = isset($parts[2][$ds['mday']]);
            $dowOk = isset($parts[4][$ds['wday']]);
            if ($domFull && $dowFull) {
                $dayOk = true;
            } elseif (!$domFull && !$dowFull) {
                $dayOk = $domOk || $dowOk;
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
