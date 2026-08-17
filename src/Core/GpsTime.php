<?php
namespace holastack\Core;

/**
 * GPS 时间工具（对齐 ChirpStack gpstime）。
 *
 * LoRaWAN / 漫游 Backend Interface 中多处使用 GPS 时间：
 *  - Roaming ULMetaData.RecvTime：自 GPS 纪元（1980-01-06 00:00:00 UTC）起的秒数；
 *  - FUOTA 组播会话时间（multicast_session_start/end）以 GPS 秒表示。
 *
 * GPS 纪元相对 Unix 纪元偏移 = 315964800 秒，并扣除累计闰秒（1980→至今约 18 秒）。
 */
class GpsTime
{
    /** Unix 时间戳（秒）表示的 GPS 纪元（1980-01-06 00:00:00 UTC）。 */
    public const GPS_EPOCH_UNIX = 315964800;
    /** 1980 年至今累计闰秒（截至 2026 年 = 18 秒）。 */
    public const LEAP_SECONDS = 18;

    /** Unix 秒 → GPS 秒（截断到 32 位）。 */
    public static function toGps(int $unixSec): int
    {
        return (int) (($unixSec - self::GPS_EPOCH_UNIX - self::LEAP_SECONDS) & 0xFFFFFFFF);
    }

    /** GPS 秒 → Unix 秒。 */
    public static function fromGps(int $gpsSec): int
    {
        return (int) ($gpsSec + self::GPS_EPOCH_UNIX + self::LEAP_SECONDS);
    }

    /** 当前 Unix 秒 → GPS 秒。 */
    public static function nowGps(): int
    {
        return self::toGps(time());
    }
}
