<?php
namespace holastack\Integration;

/**
 * 上行负载编解码（对齐 ChirpStack device_profile.payload_codec_runtime）。
 * 纯 PHP 可运行：NONE（不解码）/ CAYENNE_LPP（Cayenne 低功耗载荷）。
 * JS 运行时需要 V8 沙箱，当前 PHP 环境无内置支持，故 JS 编解码标记为不可用（保留运行时枚举）。
 */
class Codec
{
    public const RUNTIME_NONE = 'NONE';
    public const RUNTIME_CAYENNE_LPP = 'CAYENNE_LPP';
    public const RUNTIME_JS = 'JS';

    public static function supported(): array
    {
        return [self::RUNTIME_NONE, self::RUNTIME_CAYENNE_LPP, self::RUNTIME_JS];
    }

    public static function isJsAvailable(): bool
    {
        return false; // 纯 PHP 运行时不支持 JS 沙箱
    }

    /**
     * 解码上行负载。
     * @param string $runtime NONE / CAYENNE_LPP / JS
     * @param string $hex 应用负载（hex）
     * @return array|null 解码结果（失败/不支持返回 null）
     */
    public static function decodeUplink(string $runtime, string $hex): ?array
    {
        $bin = @hex2bin($hex);
        if ($bin === false) {
            return null;
        }
        switch ($runtime) {
            case self::RUNTIME_CAYENNE_LPP:
                return self::decodeCayenneLpp($bin);
            case self::RUNTIME_JS:
                // 需要 V8 沙箱；纯 PHP 不支持
                return null;
            case self::RUNTIME_NONE:
            default:
                return null;
        }
    }

    // ---------------- Cayenne LPP 解码 ----------------

    public static function decodeCayenneLpp(string $bin): ?array
    {
        $out = [];
        $i = 0;
        $n = strlen($bin);
        while ($i + 2 <= $n) {
            $chan = ord($bin[$i]);
            $type = ord($bin[$i + 1]);
            $i += 2;
            $r = self::decodeType($type, $bin, $i);
            if ($r === null) {
                break; // 未知类型，停止
            }
            $out[] = ['channel' => $chan, 'type' => $r['name'], 'value' => $r['value']];
        }
        return $out ?: null;
    }

    private static function decodeType(int $type, string $bin, int &$i): ?array
    {
        $need = function (int $bytes) use ($bin, $i): ?string {
            if (strlen($bin) - $i < $bytes) {
                return null;
            }
            $s = substr($bin, $i, $bytes);
            $i += $bytes;
            return $s;
        };
        switch ($type) {
            case 0x00: case 0x01: // digital in/out (1 byte)
                $b = $need(1); if ($b === null) return null;
                return ['name' => $type === 0 ? 'digital_in' : 'digital_out', 'value' => ord($b)];
            case 0x02: case 0x03: // analog in/out (2 bytes, /100)
                $b = $need(2); if ($b === null) return null;
                $v = unpack('s', $b)[1] / 100.0;
                return ['name' => $type === 0x02 ? 'analog_in' : 'analog_out', 'value' => $v];
            case 0x65: // luminosity (2 bytes, /1, lux)
                $b = $need(2); if ($b === null) return null;
                return ['name' => 'luminosity', 'value' => unpack('n', $b)[1]];
            case 0x66: // presence (1 byte)
                $b = $need(1); if ($b === null) return null;
                return ['name' => 'presence', 'value' => ord($b)];
            case 0x67: // temperature (2 bytes, /10)
                $b = $need(2); if ($b === null) return null;
                return ['name' => 'temperature', 'value' => unpack('s', $b)[1] / 10.0];
            case 0x68: // humidity (1 byte, /2)
                $b = $need(1); if ($b === null) return null;
                return ['name' => 'humidity', 'value' => ord($b) / 2.0];
            case 0x71: // barometric pressure (3 bytes, /10 + 65536 baseline)
                $b = $need(3); if ($b === null) return null;
                $v = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
                return ['name' => 'barometric_pressure', 'value' => ($v / 10.0) - 6553.6];
            case 0x73: // accelerometer (3x2 bytes, /1000)
                $b = $need(6); if ($b === null) return null;
                return ['name' => 'accelerometer', 'value' => [
                    unpack('s', substr($b, 0, 2))[1] / 1000.0,
                    unpack('s', substr($b, 2, 2))[1] / 1000.0,
                    unpack('s', substr($b, 4, 2))[1] / 1000.0,
                ]];
            case 0x84: // gyrometer (3x2 bytes, /100)
                $b = $need(6); if ($b === null) return null;
                return ['name' => 'gyrometer', 'value' => [
                    unpack('s', substr($b, 0, 2))[1] / 100.0,
                    unpack('s', substr($b, 2, 2))[1] / 100.0,
                    unpack('s', substr($b, 4, 2))[1] / 100.0,
                ]];
            case 0x86: // GPS (3x4 bytes, lat/lon /1e7, alt /1e2? spec: lat/lon * 1e-7, alt /1e-2 ... 实际 lat/lon 4字节有符号 *1e-7, alt 3? LPP GPS 用 3 个 4 字节：lat,lon(×10^-7),alt(×1e-3? )). 简化：lat/lon(×1e-7), alt(×1e-2 -> /100)
                $b = $need(9); if ($b === null) return null;
                $lat = unpack('l', substr($b, 0, 4))[1] / 1e7;
                $lon = unpack('l', substr($b, 4, 4))[1] / 1e7;
                $alt = unpack('l', substr($b, 8, 4))[1] / 100.0;
                return ['name' => 'gps', 'value' => ['latitude' => $lat, 'longitude' => $lon, 'altitude' => $alt]];
            default:
                return null;
        }
    }
}
