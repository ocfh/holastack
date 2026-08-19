<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Region\Region;



















class Station
{
    

    public const MSG_VERSION = 'version';
    public const MSG_ROUTER_CONFIG = 'router_config';
    public const MSG_JREQ = 'jreq';
    public const MSG_UPDF = 'updf';
    public const MSG_DNMSG = 'dnmsg';
    public const MSG_TIMESYNC = 'timesync';
    public const MSG_RMTSH = 'rmtsh';

    

    public const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    




    public static function wsAcceptKey(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::WS_GUID, true));
    }

    



    public static function wsFrame(string $payload, int $opcode = self::OP_TEXT): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0F)); 

        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        return $head . $payload;
    }

    






    public static function wsDecode(string $buf): ?array
    {
        $n = strlen($buf);
        if ($n < 2) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $fin = (bool) ($b0 & 0x80);
        $opcode = $b0 & 0x0F;
        $masked = (bool) ($b1 & 0x80);
        $len = $b1 & 0x7F;
        $off = 2;
        if ($len === 126) {
            if ($n < 4) {
                return null;
            }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if ($n < 10) {
                return null;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $off = 10;
        }
        $maskKey = '';
        if ($masked) {
            if ($n < $off + 4) {
                return null;
            }
            $maskKey = substr($buf, $off, 4);
            $off += 4;
        }
        if ($n < $off + $len) {
            return null;
        }
        $payload = substr($buf, $off, $len);
        $closeCode = null;
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }
        if ($opcode === self::OP_CLOSE && strlen($payload) >= 2) {
            $closeCode = unpack('n', substr($payload, 0, 2))[1];
        }
        return [
            'fin'        => $fin,
            'opcode'     => $opcode,
            'payload'    => $payload,
            'consumed'   => $off + $len,
            'close_code' => $closeCode,
        ];
    }

    




    public static function wsDecodeFragmented(string $buf): ?array
    {
        $frame = self::wsDecode($buf);
        if ($frame === null) {
            return null;
        }
        if ($frame['fin']) {
            return $frame;
        }
        

        $total = $frame['payload'];
        $consumed = $frame['consumed'];
        $opcode = $frame['opcode'];
        while (!$frame['fin']) {
            $rest = substr($buf, $consumed);
            $frame = self::wsDecode($rest);
            if ($frame === null) {
                return null;
            }
            if ($frame['opcode'] !== self::OP_CONT) {
                break; 

            }
            $total .= $frame['payload'];
            $consumed += $frame['consumed'];
        }
        return [
            'fin'        => true,
            'opcode'     => $opcode,
            'payload'    => $total,
            'consumed'   => $consumed,
            'close_code' => null,
        ];
    }

    








    public static function handleMessage(array $msg, ?array $station = null): array
    {
        $type = $msg['msgtype'] ?? '';
        switch ($type) {
            case self::MSG_VERSION:
                

                return self::routerConfig([
                    'region' => ($station['region'] ?? '') ?: ($msg['region'] ?? ELW_DEFAULT_REGION),
                ]);

            case self::MSG_JREQ:
            case self::MSG_UPDF:
                

                $raw = $msg[$type] ?? '';
                $pdu = base64_decode($raw, true);
                if ($pdu === false || $pdu === '') {
                    return ['_noop' => true, 'error' => 'bad pdu'];
                }
                $upinfo = is_array($msg['upinfo'] ?? null) ? $msg['upinfo'] : [];
                return [
                    '_forward' => true,
                    'msgtype'  => $type,
                    'phy'      => $pdu,
                    'upinfo'   => $upinfo,   

                    'gwEui'    => $station['gateway_id'] ?? '',
                ];

            case self::MSG_TIMESYNC:
                

                return [
                    'msgtype' => self::MSG_TIMESYNC,
                    'txtime'  => (int) round(microtime(true) * 1e6),
                ];

            case self::MSG_RMTSH:
                return [
                    'msgtype' => self::MSG_RMTSH,
                    'resp'    => (object) [],
                ];

            case self::MSG_ROUTER_CONFIG:
            default:
                return ['_noop' => true];
        }
    }

    












    public static function buildDnMsg(array $dl, int $xtime = 0, int $rctx = 0, string $dC = 'C'): array
    {
        $region = Region::get($dl['region'] ?? ELW_DEFAULT_REGION);
        $rx1Dr = null;
        if (!empty($dl['datr'])) {
            $rx1Dr = $region->datrToDr($dl['datr']);
        }
        return [
            'msgtype' => self::MSG_DNMSG,
            'dC'      => $dC,
            'diid'    => $dl['diid'] ?? 1,
            'pdu'     => $dl['pdu'],
            'RxDelay' => (int) ($dl['rx_delay'] ?? 1),
            'RX1DR'   => $rx1Dr ?? (int) ($dl['rx1_dr'] ?? 0),
            'RX1Freq' => (float) ($dl['freq'] ?? 0),
            'xtime'   => $xtime,
            'rctx'    => $rctx,
        ];
    }

    


    public static function list(): array
    {
        return Database::fetchAll("SELECT * FROM stations ORDER BY id DESC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM stations WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        if (empty($p['name']) || empty($p['gateway_id'])) {
            return ['error' => 'name and gateway_id required'];
        }
        Database::execute(
            "INSERT INTO stations (tenant_id, gateway_id, name, region, lns_secret, ca_cert, created_at)
             VALUES (?,?,?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                strtolower($p['gateway_id']),
                $p['name'],
                $p['region'] ?? ELW_DEFAULT_REGION,
                $p['lns_secret'] ?? bin2hex(random_bytes(16)),
                $p['ca_cert'] ?? '',
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $s = self::get($id);
        if (!$s) {
            return ['error' => 'station not found'];
        }
        $set = [];
        $params = [];
        foreach (['name', 'gateway_id', 'region', 'lns_secret', 'ca_cert'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = $c === 'gateway_id' ? strtolower($p[$c]) : $p[$c];
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE stations SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function delete(int $id): void
    {
        Database::execute("DELETE FROM stations WHERE id=?", [$id]);
    }

    




    public static function routerConfig(array $station): array
    {
        return [
            'msgtype'   => self::MSG_ROUTER_CONFIG,
            'NetID'     => [0],
            'JoinEui'   => [],            

            'region'    => $station['region'] ?? ELW_DEFAULT_REGION,
            'hwspec'    => (object) [],
            'class'     => ['A', 'B', 'C'],
            'nocca'     => 0,
            'nodc'      => 0,
            'nodwell'   => 0,
        ];
    }

    











    public static function serve(int $stationId): void
    {
        $s = self::get($stationId);
        if (!$s) {
            throw new \RuntimeException("station $stationId not found");
        }
        if (!class_exists('Ratchet\\App')) {
            throw new \RuntimeException(
                'LNS requires Ratchet (composer require cboden/ratchet). ' .
                'See bin/lns.php for the integration skeleton.'
            );
        }
        

        throw new \RuntimeException('LNS serve() must be driven by bin/lns.php (Ratchet App).');
    }
}
