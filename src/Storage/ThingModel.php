<?php
namespace holastack\Storage;

use holastack\DB\Database;

class ThingModel
{
    public const CODEC_SEGMENT = 'SEGMENT';
    public const CODEC_JSON = 'JSON';
    public const CODEC_LPP = 'LPP';

    public static function codecs(): array
    {
        return [self::CODEC_SEGMENT, self::CODEC_JSON, self::CODEC_LPP];
    }

    public static function list(int $appId, ?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll("SELECT * FROM thing_models WHERE application_id=? AND tenant_id=? ORDER BY id ASC", [$appId, $tenantId]);
        }
        return Database::fetchAll("SELECT * FROM thing_models WHERE application_id=? ORDER BY id ASC", [$appId]);
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM thing_models WHERE id=?", [$id]);
    }

    public static function byApp(int $appId): ?array
    {
        return Database::fetch("SELECT * FROM thing_models WHERE application_id=? ORDER BY id ASC LIMIT 1", [$appId]);
    }

    public static function create(array $p): array
    {
        $fields = self::normalizeFields($p['fields_json'] ?? '');
        Database::execute("INSERT INTO thing_models (tenant_id, application_id, name, fields_json, codec, created_at) VALUES (?,?,?,?,?,?)", [
            (int) ($p['tenant_id'] ?? 0),
            (int) ($p['application_id'] ?? 0),
            (string) ($p['name'] ?? ''),
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            strtoupper((string) ($p['codec'] ?? self::CODEC_SEGMENT)),
            time(),
        ]);
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $fields = self::normalizeFields(array_key_exists('fields_json', $p) ? $p['fields_json'] : $m['fields_json']);
        Database::execute("UPDATE thing_models SET name=?, fields_json=?, codec=? WHERE id=?", [
            (string) ($p['name'] ?? $m['name']),
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            strtoupper((string) ($p['codec'] ?? $m['codec'])),
            $id,
        ]);
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        Database::execute("DELETE FROM thing_models WHERE id=?", [$id]);
        return ['id' => $id];
    }

    /**
     * 校验并清洗字段定义，返回标准化后的字段数组。
     */
    public static function normalizeFields($fields): array
    {
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        if (!is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $key = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($f['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $f['key'] = $key;
            $f['name'] = (string) ($f['name'] ?? $key);
            $f['type'] = (string) ($f['type'] ?? 'number');
            $f['unit'] = (string) ($f['unit'] ?? '');
            $out[] = $f;
        }
        return $out;
    }

    public static function fields(array $model): array
    {
        $s = $model['fields_json'] ?? '';
        $a = json_decode((string) $s, true);
        return is_array($a) ? self::normalizeFields($a) : [];
    }

    /**
     * 根据模型 codec 对解密后的 payload(hex) 解码，返回 [key => ['value'=>?, 'text'=>string]]。
     */
    public static function decodePayload(array $fields, string $codec, string $hex): array
    {
        $codec = strtoupper($codec);
        if ($codec === self::CODEC_JSON) {
            return self::decodeJson($fields, $hex);
        }
        if ($codec === self::CODEC_LPP) {
            return self::decodeLpp($hex);
        }
        return self::decodeSegment($fields, $hex);
    }

    private static function decodeSegment(array $fields, string $hex): array
    {
        $bin = @hex2bin($hex);
        if ($bin === false) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            $type = strtolower((string) ($f['type'] ?? 'number'));
            $offset = (int) ($f['offset'] ?? 0);
            if ($offset < 0) {
                continue;
            }
            if ($type === 'json') {
                $raw = substr($bin, $offset, (int) ($f['len'] ?? strlen($bin) - $offset));
                $obj = json_decode((string) $raw, true);
                if (is_array($obj)) {
                    $val = self::pathGet($obj, (string) ($f['jsonKey'] ?? $f['key']));
                    if ($val !== null) {
                        $out[$f['key']] = self::wrapValue($f, $val);
                    }
                }
                continue;
            }

            $srcType = strtolower((string) ($f['dataType'] ?? self::defaultType($type)));
            $len = (int) ($f['len'] ?? self::defaultLen($srcType));
            $raw = substr($bin, $offset, $len);
            if (strlen($raw) < $len) {
                continue;
            }
            $out[$f['key']] = self::decodeToValue($f, $srcType, $raw);
        }
        return $out;
    }

    private static function decodeToValue(array $f, string $srcType, string $raw): array
    {
        $type = strtolower((string) ($f['type'] ?? 'number'));
        $endian = strtolower((string) ($f['endian'] ?? 'be'));

        if ($type === 'bool') {
            $u = ord($raw[0]);
            $truthy = $u !== 0;
            return ['value' => (int) $truthy, 'text' => $truthy
                ? ((string) ($f['trueText'] ?? ($f['name'] ?? 'ON')))
                : ((string) ($f['falseText'] ?? ($f['name'] ?? 'OFF')))];
        }

        if ($type === 'enum') {
            $u = (int) self::bytesToNumber($raw, $srcType, $endian);
            $map = (array) ($f['map'] ?? []);
            $label = $map[(string) $u] ?? $map[(int) $u] ?? (string) $u;
            return ['value' => $u, 'text' => $label];
        }

        if ($type === 'string') {
            $txt = self::cleanText($raw);
            return ['value' => $txt, 'text' => $txt];
        }

        // 数值类型
        $num = self::bytesToNumber($raw, $srcType, $endian);
        $scale = (float) ($f['scale'] ?? 1.0);
        $add = (float) ($f['add'] ?? 0.0);
        $val = $num * $scale + $add;
        $unit = (string) ($f['unit'] ?? '');
        $decimals = (int) ($f['decimals'] ?? ($scale < 1 ? 1 : 0));
        $text = number_format($val, $decimals, '.', '') . $unit;
        return ['value' => $val, 'text' => $text];
    }

    private static function decodeJson(array $fields, string $hex): array
    {
        $str = @hex2bin($hex);
        if ($str === false || trim($str) === '') {
            return [];
        }
        // 部分设备把 JSON 当作 UTF-8 文本传输，先尝试原样，若失败再尝试去掉末尾空白
        $obj = json_decode($str, true);
        if (!is_array($obj)) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            $val = self::pathGet($obj, (string) ($f['jsonKey'] ?? $f['key']));
            if ($val === null) {
                continue;
            }
            $out[$f['key']] = self::wrapValue($f, $val);
        }
        return $out;
    }

    private static function decodeLpp(string $hex): array
    {
        $bin = @hex2bin($hex);
        if ($bin === false) {
            return [];
        }
        $rows = Codec::decodeCayenneLpp($bin) ?? [];
        $out = [];
        foreach ($rows as $r) {
            $v = $r['value'];
            if (is_array($v)) {
                $i = 0;
                foreach ($v as $comp) {
                    $out[$r['type'] . '.' . $i] = ['value' => is_numeric($comp) ? (float) $comp : 0, 'text' => (string) $comp];
                    $i++;
                }
            } else {
                $out[$r['type']] = ['value' => is_numeric($v) ? (float) $v : 0, 'text' => (string) $v];
            }
        }
        return $out;
    }

    private static function wrapValue(array $f, $val): array
    {
        $type = strtolower((string) ($f['type'] ?? 'number'));
        if ($type === 'enum') {
            $map = (array) ($f['map'] ?? []);
            return ['value' => is_numeric($val) ? (float) $val : $val, 'text' => $map[(string) $val] ?? (string) $val];
        }
        if ($type === 'bool') {
            $truthy = (bool) $val;
            return ['value' => (int) $truthy, 'text' => $truthy ? ((string) ($f['trueText'] ?? 'ON')) : ((string) ($f['falseText'] ?? 'OFF'))];
        }
        if (is_numeric($val)) {
            $unit = (string) ($f['unit'] ?? '');
            $decimals = (int) ($f['decimals'] ?? (floor($val) == $val ? 0 : 1));
            return ['value' => (float) $val, 'text' => number_format((float) $val, $decimals, '.', '') . $unit];
        }
        return ['value' => $val, 'text' => (string) $val];
    }

    private static function pathGet($arr, string $path)
    {
        foreach (explode('.', $path) as $seg) {
            if (is_array($arr) && array_key_exists($seg, $arr)) {
                $arr = $arr[$seg];
            } else {
                return null;
            }
        }
        return $arr;
    }

    private static function bytesToNumber(string $raw, string $srcType, string $endian): float
    {
        $le = $endian === 'le';
        switch ($srcType) {
            case 'int8':
                $u = ord($raw[0]);
                return $u >= 128 ? $u - 256 : $u;
            case 'uint8':
                return ord($raw[0]);
            case 'int16':
                $u = $le ? unpack('v', $raw)[1] : unpack('n', $raw)[1];
                return $u >= 32768 ? $u - 65536 : $u;
            case 'uint16':
                return $le ? unpack('v', $raw)[1] : unpack('n', $raw)[1];
            case 'int32':
                $u = $le ? unpack('V', $raw)[1] : unpack('N', $raw)[1];
                return $u >= 2147483648 ? $u - 4294967296 : $u;
            case 'uint32':
                return $le ? unpack('V', $raw)[1] : unpack('N', $raw)[1];
            case 'float32':
                return $le ? unpack('g', $raw)[1] : unpack('G', $raw)[1];
            default:
                return 0;
        }
    }

    private static function defaultType(string $type): string
    {
        if (in_array($type, ['enum', 'bool'])) {
            return 'uint8';
        }
        return 'uint16';
    }

    private static function defaultLen(string $srcType): int
    {
        if (in_array($srcType, ['int8', 'uint8'])) {
            return 1;
        }
        if (in_array($srcType, ['int16', 'uint16'])) {
            return 2;
        }
        return 4;
    }

    private static function cleanText(string $s): string
    {
        // 去掉字符串两端的非可打印字节（C 风格字符串常有 \0 填充）
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return trim($s);
    }

    /**
     * 将解码结果写入读数历史并更新设备最新值。
     */
    public static function persist(int $devId, int $appId, array $decoded, int $fcnt): void
    {
        if (!$decoded) {
            return;
        }
        $ts = time();
        $latest = [];
        foreach ($decoded as $k => $v) {
            $val = $v['value'] ?? null;
            $num = is_numeric($val) ? (float) $val : 0;
            $txt = (string) ($v['text'] ?? '');
            if (mb_strlen($k) > 64) {
                continue;
            }
            Database::execute(
                "INSERT INTO device_readings (dev_id, app_id, field_key, value, text_value, fcnt, ts) VALUES (?,?,?,?,?,?,?)",
                [$devId, $appId, $k, $num, mb_substr($txt, 0, 255), $fcnt, $ts]
            );
            $latest[$k] = ['v' => $val, 't' => $txt, 'ts' => $ts];
        }
        if ($latest) {
            Database::execute("UPDATE devices SET latest_fields=? WHERE id=?", [json_encode($latest, JSON_UNESCAPED_UNICODE), $devId]);
        }
    }

    public static function latestByDevice(int $devId): array
    {
        $d = Database::fetch("SELECT latest_fields FROM devices WHERE id=?", [$devId]);
        $s = $d['latest_fields'] ?? '';
        $a = json_decode((string) $s, true);
        return is_array($a) ? $a : [];
    }

    public static function readings(int $devId, string $fieldKey, int $from, int $to): array
    {
        return Database::fetchAll(
            "SELECT value AS v, text_value AS t, fcnt, ts FROM device_readings WHERE dev_id=? AND field_key=? AND ts>=? AND ts<=? ORDER BY ts ASC",
            [$devId, $fieldKey, $from, $to]
        );
    }

    public static function deleteReadings(int $devId): void
    {
        Database::execute("DELETE FROM device_readings WHERE dev_id=?", [$devId]);
        Database::execute("UPDATE devices SET latest_fields='' WHERE id=?", [$devId]);
    }
}