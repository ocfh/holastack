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
    const KEYS = ['site_name', 'site_logo_url', 'site_icon_url', 'favicon_url', 'login_logo_url', 'login_logo_text', 'login_notice', 'api_base_url', 'ui_lang', 'footer'];

    /** 对未登录用户（登录页）安全的公开键。 */
    const PUBLIC_KEYS = ['site_name', 'site_logo_url', 'favicon_url', 'login_logo_url', 'login_logo_text', 'login_notice', 'api_base_url', 'ui_lang', 'footer'];

    public static function get(string $key, string $default = ''): string
    {
        $r = Database::fetch("SELECT svalue FROM settings WHERE skey=?", [$key]);
        return $r ? (string) $r['svalue'] : $default;
    }

    public static function getAll(): array
    {
        $rows = Database::fetchAll("SELECT skey, svalue FROM settings");
        $out = [];
        foreach ($rows as $r) {
            $out[$r['skey']] = $r['svalue'];
        }
        return $out;
    }

    public static function set(string $key, string $value): void
    {
        $exists = Database::fetch("SELECT skey FROM settings WHERE skey=?", [$key]);
        if ($exists) {
            Database::execute("UPDATE settings SET svalue=?, updated_at=? WHERE skey=?", [$value, time(), $key]);
        } else {
            Database::execute("INSERT INTO settings (skey, svalue, updated_at) VALUES (?,?,?)", [$key, $value, time()]);
        }
    }

    public static function setMany(array $pairs): void
    {
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $pairs)) {
                self::set($k, is_string($pairs[$k]) ? $pairs[$k] : (string) $pairs[$k]);
            }
        }
    }

    /**
     * 渲染 footer：把 {Y} 替换为当前年份，把 {SITE} 替换为站点名。
     * 调用方传入原始字符串（可能是 HTML），函数只做占位替换，不做 XSS 过滤。
     */
    public static function renderFooter(string $raw, string $siteName = 'HolaStack'): string
    {
        $year = date('Y');
        $name = $siteName !== '' ? $siteName : 'HolaStack';
        return str_replace(['{Y}', '{YEAR}', '{SITE}'], [$year, $year, $name], $raw);
    }

    public static function getPublic(): array
    {
        $all = self::getAll();
        $out = [];
        foreach (self::PUBLIC_KEYS as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        if (($out['site_name'] ?? '') === '') {
            $out['site_name'] = 'HolaStack';
        }
        if (($out['login_logo_text'] ?? '') === '') {
            $out['login_logo_text'] = $out['site_name'];
        }
        // 默认 footer 模板：© {Y} {SITE}（占位符在 renderFooter 中替换为当前年份与站点名）
        $rawFooter = (string)($out['footer'] ?? '');
        if ($rawFooter === '') {
            $rawFooter = '© {Y} {SITE}';
        }
        $out['footer'] = self::renderFooter($rawFooter, (string)$out['site_name']);
        return $out;
    }
}
