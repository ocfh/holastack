<?php
namespace holastack\Core;











class GpsTime
{
    

    public const GPS_EPOCH_UNIX = 315964800;
    

    public const LEAP_SECONDS = 18;

    public static function toGps(int $unixSec): int
    {
        return (int) (($unixSec - self::GPS_EPOCH_UNIX - self::LEAP_SECONDS) & 0xFFFFFFFF);
    }

    public static function fromGps(int $gpsSec): int
    {
        return (int) ($gpsSec + self::GPS_EPOCH_UNIX + self::LEAP_SECONDS);
    }

    public static function nowGps(): int
    {
        return self::toGps(time());
    }
}
