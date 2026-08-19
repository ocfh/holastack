<?php













namespace holastack\Core;

class LoRaWANVersion
{
    

    public const DEFAULT = '1.0.3';

    public static function normalize(string $v): string
    {
        $v = strtolower(trim($v));
        $v = ltrim($v, 'v');
        if (!preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/', $v, $m)) {
            return '1.0';
        }
        return $m[1] . '.' . $m[2];
    }

    public static function is1_1(string $v): bool
    {
        $n = self::normalize($v);
        [$maj, $min] = array_map('intval', explode('.', $n));
        return $maj > 1 || ($maj === 1 && $min >= 1);
    }

    public static function is1_0(string $v): bool
    {
        return !self::is1_1($v);
    }

    public static function family(string $v): string
    {
        return self::is1_1($v) ? '1.1' : '1.0';
    }

    public static function value(string $v): string
    {
        $v = trim($v);
        return $v === '' ? self::DEFAULT : $v;
    }
}
