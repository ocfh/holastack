<?php
namespace holastack\Web;

use holastack\DB\Database;

/**
 * 站点级设置（键值存储），仅 admin 可写，部分字段对登录页公开。
 * 用途：自定义网站名称、图标、登录界面 LOGO（图片链接或文字）。
 */
class Setting
{
    /** 全部可配置键。 */
    const KEYS = ['site_name', 'site_logo_url', 'site_icon_url', 'login_logo_url', 'login_logo_text'];

    /** 对未登录用户（登录页）安全的公开键。 */
    const PUBLIC_KEYS = ['site_name', 'login_logo_url', 'login_logo_text'];

    /** 读取单个设置，缺失返回默认值。 */
    public static function get(string $key, string $default = ''): string
    {
        $r = Database::fetch("SELECT svalue FROM settings WHERE skey=?", [$key]);
        return $r ? (string) $r['svalue'] : $default;
    }

    /** 读取全部设置（关联数组 skey => svalue）。 */
    public static function getAll(): array
    {
        $rows = Database::fetchAll("SELECT skey, svalue FROM settings");
        $out = [];
        foreach ($rows as $r) {
            $out[$r['skey']] = $r['svalue'];
        }
        return $out;
    }

    /** 写入单个设置（存在则更新，不存在则插入）。 */
    public static function set(string $key, string $value): void
    {
        $exists = Database::fetch("SELECT skey FROM settings WHERE skey=?", [$key]);
        if ($exists) {
            Database::execute("UPDATE settings SET svalue=?, updated_at=? WHERE skey=?", [$value, time(), $key]);
        } else {
            Database::execute("INSERT INTO settings (skey, svalue, updated_at) VALUES (?,?,?)", [$key, $value, time()]);
        }
    }

    /** 批量写入（过滤掉非白名单键）。 */
    public static function setMany(array $pairs): void
    {
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $pairs)) {
                self::set($k, is_string($pairs[$k]) ? $pairs[$k] : (string) $pairs[$k]);
            }
        }
    }

    /** 读取公开设置（供登录页使用，不暴露敏感项）。 */
    public static function getPublic(): array
    {
        $all = self::getAll();
        $out = [];
        foreach (self::PUBLIC_KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        if (($out['site_name'] ?? '') === '') {
            $out['site_name'] = 'holastack';
        }
        if (($out['login_logo_text'] ?? '') === '') {
            $out['login_logo_text'] = $out['site_name'];
        }
        return $out;
    }
}
