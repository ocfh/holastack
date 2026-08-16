<?php
/**
 * LoRaWAN MAC 版本工具。
 *
 * 用于把用户在设备模板中填写的 mac_version（如 "1.0.3" / "1.0.4" / "1.1" / "1.1.0"）
 * 归一化并判定其所属大版本族，供加密/帧构造/ADR 等逻辑选择行为：
 *  - 1.0.x 族：单 NwkSKey，B0 单密钥 MIC，Join 用 AppKey。
 *  - 1.1.x 族：FNwkSIntKey / SNwkSIntKey / NwkSEncKey / AppSKey 四把独立会话密钥，
 *              上行 MIC 为 B0( FNwkSIntKey ) 与 B1( SNwkSIntKey ) 的 2+2 字节拼接，
 *              下行 MIC 用 SNwkSIntKey 且 B0 含 ConfFCnt，Join 用 NwkKey/AppKey 与 1.0 不同的派生。
 *
 * 默认族为 1.0（与既有行为一致，保证存量设备不受影响）。
 */
namespace holastack\Core;

class LoRaWANVersion
{
    /** 未知/空版本一律按 1.0 处理（向后兼容）。 */
    public const DEFAULT = '1.0.3';

    /**
     * 归一化版本字符串 → "major.minor" 两段式（如 "1.0" / "1.1"）。
     * 容忍 "1.0.4" / "1.0" / "1.1.0" / "v1.0.3" 等写法。
     */
    public static function normalize(string $v): string
    {
        $v = strtolower(trim($v));
        $v = ltrim($v, 'v');
        if (!preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/', $v, $m)) {
            return '1.0';
        }
        return $m[1] . '.' . $m[2];
    }

    /** 是否 LoRaWAN 1.1 及以上（含 Regional Parameters 随版本变化）。 */
    public static function is1_1(string $v): bool
    {
        $n = self::normalize($v);
        [$maj, $min] = array_map('intval', explode('.', $n));
        return $maj > 1 || ($maj === 1 && $min >= 1);
    }

    /** 是否 LoRaWAN 1.0.x 族（含 1.0.3 / 1.0.4 等）。 */
    public static function is1_0(string $v): bool
    {
        return !self::is1_1($v);
    }

    /** 大版本族标识：'1.0' 或 '1.1'。 */
    public static function family(string $v): string
    {
        return self::is1_1($v) ? '1.1' : '1.0';
    }

    /** 取 MAC 版本数字（如 "1.0.3"），空则返回默认。 */
    public static function value(string $v): string
    {
        $v = trim($v);
        return $v === '' ? self::DEFAULT : $v;
    }
}
