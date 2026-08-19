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
        switch ($type) {
            case 0x00: case 0x01: 

                $b = $need(1); if ($b === null) return null;
                return ['name' => $type === 0 ? 'digital_in' : 'digital_out', 'value' => ord($b)];
            case 0x02: case 0x03: 

                $b = $need(2); if ($b === null) return null;
                $v = unpack('s', $b)[1] / 100.0;
                return ['name' => $type === 0x02 ? 'analog_in' : 'analog_out', 'value' => $v];
            case 0x65: 

                $b = $need(2); if ($b === null) return null;
                return ['name' => 'luminosity', 'value' => unpack('n', $b)[1]];
            case 0x66: 

                $b = $need(1); if ($b === null) return null;
                return ['name' => 'presence', 'value' => ord($b)];
            case 0x67: 

                $b = $need(2); if ($b === null) return null;
                return ['name' => 'temperature', 'value' => unpack('s', $b)[1] / 10.0];
            case 0x68: 

                $b = $need(1); if ($b === null) return null;
                return ['name' => 'humidity', 'value' => ord($b) / 2.0];
            case 0x71: 

                $b = $need(3); if ($b === null) return null;
                $v = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
                return ['name' => 'barometric_pressure', 'value' => ($v / 10.0) - 6553.6];
            case 0x73: 

                $b = $need(6); if ($b === null) return null;
                return ['name' => 'accelerometer', 'value' => [
                    unpack('s', substr($b, 0, 2))[1] / 1000.0,
                    unpack('s', substr($b, 2, 2))[1] / 1000.0,
                    unpack('s', substr($b, 4, 2))[1] / 1000.0,
                ]];
            case 0x84: 

                $b = $need(6); if ($b === null) return null;
                return ['name' => 'gyrometer', 'value' => [
                    unpack('s', substr($b, 0, 2))[1] / 100.0,
                    unpack('s', substr($b, 2, 2))[1] / 100.0,
                    unpack('s', substr($b, 4, 2))[1] / 100.0,
                ]];
            case 0x86: 

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
