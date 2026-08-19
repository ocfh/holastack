<?php
namespace holastack\Web;

use holastack\DB\Database;






class Setting
{
    

    const KEYS = ['site_name', 'site_logo_url', 'site_icon_url', 'favicon_url', 'login_logo_url', 'login_logo_text', 'login_notice', 'api_base_url', 'ui_lang', 'footer'];

    

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
        

        $rawFooter = (string)($out['footer'] ?? '');
        if ($rawFooter === '') {
            $rawFooter = '© {Y} {SITE}';
        }
        $out['footer'] = self::renderFooter($rawFooter, (string)$out['site_name']);
        return $out;
    }
}
