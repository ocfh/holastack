<?php
namespace holastack\Integration;

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
        return false;

    }

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

                return null;
            case self::RUNTIME_NONE:
            default:
                return null;
        }
    }

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
                break;

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

        $s16 = function (string $b): int {
            $v = unpack('n', $b)[1];
            return $v >= 0x8000 ? $v - 65536 : $v;
        };
        $s32 = function (string $b): int {
            $v = unpack('N', $b)[1];
            return $v >= 0x80000000 ? $v - 4294967296 : $v;
        };
        switch ($type) {
            case 0x00: case 0x01:

                $b = $need(1); if ($b === null) return null;
                return ['name' => $type === 0 ? 'digital_in' : 'digital_out', 'value' => ord($b)];
            case 0x02: case 0x03:

                $b = $need(2); if ($b === null) return null;
                return ['name' => $type === 0x02 ? 'analog_in' : 'analog_out', 'value' => $s16($b) / 100.0];
            case 0x65:

                $b = $need(2); if ($b === null) return null;
                return ['name' => 'luminosity', 'value' => unpack('n', $b)[1]];
            case 0x66:

                $b = $need(1); if ($b === null) return null;
                return ['name' => 'presence', 'value' => ord($b)];
            case 0x67:

                $b = $need(2); if ($b === null) return null;
                return ['name' => 'temperature', 'value' => $s16($b) / 10.0];
            case 0x68:

                $b = $need(1); if ($b === null) return null;
                return ['name' => 'humidity', 'value' => ord($b) / 2.0];
            case 0x71:

                $b = $need(6); if ($b === null) return null;
                return ['name' => 'accelerometer', 'value' => [
                    $s16(substr($b, 0, 2)) / 1000.0,
                    $s16(substr($b, 2, 2)) / 1000.0,
                    $s16(substr($b, 4, 2)) / 1000.0,
                ]];
            case 0x72:

                $b = $need(2); if ($b === null) return null;
                return ['name' => 'barometer', 'value' => unpack('n', $b)[1] / 10.0];
            case 0x73:

                $b = $need(6); if ($b === null) return null;
                return ['name' => 'gyrometer', 'value' => [
                    $s16(substr($b, 0, 2)) / 100.0,
                    $s16(substr($b, 2, 2)) / 100.0,
                    $s16(substr($b, 4, 2)) / 100.0,
                ]];
            case 0x88:

                $b = $need(11); if ($b === null) return null;
                $alt = unpack('N', "\x00" . substr($b, 8, 3))[1];
                if ($alt >= 0x800000) {
                    $alt -= 16777216;
                }
                return ['name' => 'gps', 'value' => [
                    'latitude'  => $s32(substr($b, 0, 4)) / 1e7,
                    'longitude' => $s32(substr($b, 4, 4)) / 1e7,
                    'altitude'  => $alt / 100.0,
                ]];
            default:
                return null;
        }
    }
}
