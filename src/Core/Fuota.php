<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;





















class Fuota
{
    

    public const CID_FRAG_SESSION_SETUP_REQ = 0x08;
    public const CID_FRAG_DATA_BLOCK_REQ    = 0x09;
    public const CID_FRAG_STATUS_REQ        = 0x0A;
    public const CID_MC_GROUP_SETUP_REQ     = 0x0B;
    public const CID_CLK_SYNC_REQ           = 0x0C;
    public const CID_MC_GROUP_DELETE_REQ    = 0x0D;

    

    public const FPORT_SETUP  = 200;
    public const FPORT_STATUS = 201;

    

    public const STATE_PENDING       = 'PENDING';
    public const STATE_SETUP         = 'SETUP';
    public const STATE_FRAGMENTATION = 'FRAGMENTATION';
    public const STATE_STATUS        = 'STATUS';
    public const STATE_DONE          = 'DONE';
    public const STATE_FAILED        = 'FAILED';

    

    public const ACTIVE_STATES = [self::STATE_SETUP, self::STATE_FRAGMENTATION, self::STATE_STATUS];

    

    public static function isFuotaCid(int $cid): bool
    {
        return in_array($cid, [
            self::CID_FRAG_SESSION_SETUP_REQ,
            self::CID_FRAG_DATA_BLOCK_REQ,
            self::CID_FRAG_STATUS_REQ,
            self::CID_MC_GROUP_SETUP_REQ,
            self::CID_CLK_SYNC_REQ,
            self::CID_MC_GROUP_DELETE_REQ,
        ], true);
    }

    




    public static function fuotaCidPayloadLen(int $cid): int
    {
        switch ($cid) {
            case self::CID_FRAG_SESSION_SETUP_REQ: return 1; 

            case self::CID_FRAG_DATA_BLOCK_REQ:    return 1; 

            case self::CID_FRAG_STATUS_REQ:        return 6; 

            case self::CID_MC_GROUP_SETUP_REQ:     return 1; 

            case self::CID_CLK_SYNC_REQ:           return 1; 

            case self::CID_MC_GROUP_DELETE_REQ:    return 1; 

            default:                               return -1;
        }
    }

    


    public static function listCampaigns(int $tenantId = 0, bool $admin = false): array
    {
        if ($admin || $tenantId <= 0) {
            return Database::fetchAll("SELECT * FROM fuota_campaigns ORDER BY id DESC");
        }
        return Database::fetchAll(
            "SELECT * FROM fuota_campaigns WHERE tenant_id IN (0,?) ORDER BY id DESC",
            [$tenantId]
        );
    }

    public static function createCampaign(array $p): array
    {
        if (empty($p['name']) || empty($p['application_id']) || empty($p['multicast_group_id'])) {
            return ['error' => 'name, application_id and multicast_group_id required'];
        }
        Database::execute(
            "INSERT INTO fuota_campaigns
             (tenant_id, name, application_id, multicast_group_id, fragment_size, redundancy,
              descriptor_version, fw_version, fw_length, mc_ke_key, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                $p['name'],
                (int) $p['application_id'],
                (int) $p['multicast_group_id'],
                (int) ($p['fragment_size'] ?? 200),
                (int) ($p['redundancy'] ?? 1),
                (int) ($p['descriptor_version'] ?? 0),
                $p['fw_version'] ?? '',
                (int) ($p['fw_length'] ?? 0),
                strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['mc_ke_key'] ?? '')),
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function getCampaign(int $id): ?array
    {
        return Database::fetch("SELECT * FROM fuota_campaigns WHERE id=?", [$id]);
    }

    public static function addDeployment(int $campaignId, int $devId): array
    {
        $existing = Database::fetch(
            "SELECT id FROM fuota_deployments WHERE campaign_id=? AND dev_id=?",
            [$campaignId, $devId]
        );
        if ($existing) {
            return ['id' => $existing['id'], 'exists' => true];
        }
        Database::execute(
            "INSERT INTO fuota_deployments (campaign_id, dev_id, state, fragments_received, created_at)
             VALUES (?,?,?,?,?)",
            [$campaignId, $devId, 'PENDING', 0, time()]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function campaignDetail(int $id): ?array
    {
        $camp = self::getCampaign($id);
        if (!$camp) {
            return null;
        }
        $camp['deployments'] = Database::fetchAll(
            "SELECT d.*, dv.dev_eui, dv.name AS dev_name
             FROM fuota_deployments d JOIN devices dv ON dv.id = d.dev_id
             WHERE d.campaign_id=? ORDER BY d.id",
            [$id]
        );
        $camp['frames_total'] = (int) Database::fetch(
            "SELECT COUNT(*) AS n FROM fuota_frames WHERE campaign_id=?", [$id]
        )['n'];
        $progress = [];
        foreach (self::ACTIVE_STATES as $s) {
            $progress[$s] = 0;
        }
        $progress[self::STATE_DONE] = 0;
        $progress[self::STATE_FAILED] = 0;
        foreach ($camp['deployments'] as $d) {
            $progress[$d['state']] = ($progress[$d['state']] ?? 0) + 1;
        }
        $camp['progress'] = $progress;
        return $camp;
    }

    


    




    public static function fragmentFirmware(string $bin, int $fragSize, int $redundancy = 1): array
    {
        if ($fragSize <= 0) {
            $fragSize = 200;
        }
        $len = strlen($bin);
        $fragN = max(1, (int) ceil($len / $fragSize));
        $fragments = [];
        for ($i = 0; $i < $fragN; $i++) {
            $fragments[] = substr($bin, $i * $fragSize, $fragSize);
        }
        

        for ($r = 0; $r < $redundancy; $r++) {
            

            $fragments[] = $fragments[($fragN - 1 - ($r % $fragN))] ?? '';
        }
        $digest = substr(hash('sha256', $bin, true), 0, 4);
        return [
            'frag_n'     => $fragN,
            'frag_size'  => $fragSize,
            'digest'     => $digest,
            'fragments'  => $fragments,
            'redundancy' => $redundancy,
        ];
    }

    


    






    public static function buildFragSessionSetupReq(
        int $fragIndex, int $fragSize, int $fragN, string $fragAuthDigest, bool $udrOn = false
    ): string {
        $exp = (int) round(log($fragSize, 2));
        if ($exp < 0) {
            $exp = 0;
        }
        if ($exp > 7) {
            $exp = 7;
        }
        $fragIndex &= 0x07;
        $session = (($fragIndex & 0x07) << 5) | (($exp & 0x07) << 2) | (($udrOn ? 1 : 0) << 1);
        $out = chr(self::CID_FRAG_SESSION_SETUP_REQ)
             . chr($session)
             . pack('v', $fragN & 0xFFFF)
             . substr($fragAuthDigest, 0, 4);
        

        if (strlen($out) % 2 === 1) {
            $out .= "\x00";
        }
        return $out;
    }

    




    public static function buildFragDataBlockReq(int $fragIndex, int $blockN, string $data, bool $more = false): string
    {
        $fragIndex &= 0x07;
        $blockN &= 0x0FFF;
        $word = (($fragIndex & 0x07) << 13) | (($more ? 1 : 0) << 12) | $blockN;
        return chr(self::CID_FRAG_DATA_BLOCK_REQ)
             . pack('v', $word)
             . $data;
    }

    



    public static function buildFragStatusReq(): string
    {
        return chr(self::CID_FRAG_STATUS_REQ);
    }

    





    public static function buildMcGroupSetupReq(
        int $mcGroupId, string $mcAddrBin, string $mcNwkSKey, string $mcKEKey, int $mcDr, int $mcFreq
    ): string {
        $mcKeyEnc = AES::ecbEncrypt($mcKEKey, $mcNwkSKey);
        $mcFreqBytes = substr(pack('V', $mcFreq & 0xFFFFFF), 0, 3); 

        return chr(self::CID_MC_GROUP_SETUP_REQ)
             . chr($mcGroupId & 0xFF)
             . substr($mcAddrBin, 0, 4)
             . $mcKeyEnc
             . chr($mcDr & 0xFF)
             . $mcFreqBytes;
    }

    




    public static function buildClkSyncReq(bool $ansRequired = true): string
    {
        return chr(self::CID_CLK_SYNC_REQ) . chr(($ansRequired ? 1 : 0) << 7);
    }

    



    public static function buildMcGroupDeleteReq(int $mcGroupId): string
    {
        return chr(self::CID_MC_GROUP_DELETE_REQ) . chr($mcGroupId & 0xFF);
    }

    


    




    public static function buildFuotaSetupReq(string $descriptor, string $fwVersion, int $fwSize, int $fwCrc): string
    {
        $ver = substr($fwVersion, 0, 255);
        return substr($descriptor, 0, 4)
             . chr(strlen($ver)) . $ver
             . pack('V', $fwSize & 0xFFFFFFFF)
             . pack('V', $fwCrc & 0xFFFFFFFF);
    }

    




    public static function buildFuotaStatusReq(int $fragIndex = 0, int $statusType = 0): string
    {
        return chr((($fragIndex & 0x07) << 5) | ($statusType & 0x1F));
    }

    


    

    public static function parseFragSessionSetupAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'session_error'    => (bool) ($b & 0x01),
            'wrong_digest'     => (bool) ($b & 0x02),
            'wrong_descriptor' => (bool) ($b & 0x04),
        ];
    }

    

    public static function parseFragDataBlockAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'missing'     => (bool) ($b & 0x01),
            'low_power'   => (bool) ($b & 0x02),
            'buffer_full' => (bool) ($b & 0x04),
        ];
    }

    

    public static function parseMcGroupSetupAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'group_id_error' => (bool) ($b & 0x01),
            'mc_addr_error'  => (bool) ($b & 0x02),
            'mc_key_error'   => (bool) ($b & 0x04),
        ];
    }

    

    public static function parseClkSyncAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return ['clk_sync_error' => (bool) ($b & 0x01)];
    }

    

    public static function parseFragStatusAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        $nbRecv = unpack('v', substr($payload, 1, 2))[1] ?? 0;
        $nbMiss = unpack('v', substr($payload, 3, 2))[1] ?? 0;
        return [
            'missing'           => (bool) ($b & 0x01),
            'low_power'         => (bool) ($b & 0x02),
            'buffer_full'       => (bool) ($b & 0x04),
            'frag_nb_received'  => $nbRecv,
            'frag_nb_missing'   => $nbMiss,
        ];
    }

    

    public static function parseFuotaSetupAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'no_image'         => (bool) ($b & 0x01),
            'descriptor_error' => (bool) ($b & 0x02),
            'fw_version_error' => (bool) ($b & 0x04),
            'fw_size_error'    => (bool) ($b & 0x08),
        ];
    }

    

    public static function parseFuotaStatusAns(string $payload): array
    {
        $hdr = ord($payload[0] ?? "\x00");
        $out = [
            'frag_index'  => ($hdr >> 5) & 0x07,
            'status_type' => $hdr & 0x1F,
        ];
        if (($out['status_type'] ?? 0) === 0) {
            $out['frag_nb_received'] = unpack('v', substr($payload, 1, 2))[1] ?? 0;
            $out['frag_nb_missing']  = unpack('v', substr($payload, 3, 2))[1] ?? 0;
        } else {
            

            $bitmap = substr($payload, 1);
            $missing = 0;
            for ($i = 0; $i < strlen($bitmap); $i++) {
                $missing += substr_count(sprintf('%08b', ord($bitmap[$i])), '1');
            }
            $out['missing_fragments_bitmap_hex'] = bin2hex($bitmap);
            $out['frag_nb_missing'] = $missing;
        }
        return $out;
    }

    


    




    public static function startCampaign(int $campaignId, string $firmwareBin, array $opts = []): array
    {
        $camp = self::getCampaign($campaignId);
        if (!$camp) {
            return ['error' => 'campaign not found'];
        }
        if ($camp['state'] !== self::STATE_PENDING) {
            return ['error' => "campaign already started (state={$camp['state']})"];
        }
        $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [$camp['multicast_group_id']]);
        if (!$group) {
            return ['error' => 'multicast group not found'];
        }
        $deployments = Database::fetchAll("SELECT id FROM fuota_deployments WHERE campaign_id=?", [$campaignId]);
        if (empty($deployments)) {
            return ['error' => 'no deployments in campaign'];
        }

        $frag = self::fragmentFirmware($firmwareBin, (int) $camp['fragment_size'], (int) $camp['redundancy']);
        $fragN = count($frag['fragments']);
        $sessionSetup = self::buildFragSessionSetupReq(0, $frag['frag_size'], $frag['frag_n'], $frag['digest'], false);

        

        Database::execute("DELETE FROM fuota_frames WHERE campaign_id=?", [$campaignId]);
        foreach ($frag['fragments'] as $idx => $data) {
            $more = ($idx + 1) < $fragN;
            $dataBlock = self::buildFragDataBlockReq(0, $idx, $data, $more);
            $fopts = ($idx === 0) ? ($sessionSetup . $dataBlock) : $dataBlock;
            Database::execute(
                "INSERT INTO fuota_frames (campaign_id, seq, fopts_hex, created_at) VALUES (?,?,?,?)",
                [$campaignId, $idx, bin2hex($fopts), time()]
            );
        }

        $now = time();
        $minDelay = max(0, (int) ($opts['min_delay'] ?? $camp['min_delay'] ?? 200));
        $maxDelay = max($minDelay, (int) ($opts['max_delay'] ?? $camp['max_delay'] ?? 1000));
        $timeout  = max(1, (int) ($opts['timeout'] ?? $camp['timeout'] ?? 3600));
        Database::execute(
            "UPDATE fuota_campaigns SET state=?, mc_ke_key=?, min_delay=?, max_delay=?, timeout=?,
             frames_sent=0, total_frames=?, next_frame_at=?, started_at=?, updated_at=?,
             firmware_sha256=?, firmware_crc=?, status_req_sent=0 WHERE id=?",
            [
                self::STATE_SETUP,
                strtolower(preg_replace('/[^0-9a-fA-F]/', '', $opts['mc_ke_key'] ?? $camp['mc_ke_key'] ?? '')),
                $minDelay, $maxDelay, $timeout,
                $fragN, $now, $now, $now,
                hash('sha256', $firmwareBin),
                crc32($firmwareBin) & 0xFFFFFFFF,
                $campaignId,
            ]
        );
        Database::execute(
            "UPDATE fuota_deployments SET state=?, updated_at=? WHERE campaign_id=? AND state='PENDING'",
            [self::STATE_SETUP, $now, $campaignId]
        );
        return [
            'campaign_id' => $campaignId,
            'state'       => self::STATE_SETUP,
            'total_frames'=> $fragN,
            'frag_n'      => $frag['frag_n'],
            'frag_size'   => $frag['frag_size'],
            'digest_hex'  => bin2hex($frag['digest']),
        ];
    }

    public static function enqueueCampaign(int $campaignId, string $firmwareBin): array
    {
        return self::startCampaign($campaignId, $firmwareBin, []);
    }

    




    public static function activeCampaignForDevice(string $devEui): ?array
    {
        $dev = Database::fetch("SELECT id FROM devices WHERE LOWER(dev_eui)=?", [strtolower($devEui)]);
        if (!$dev) {
            return null;
        }
        $row = Database::fetch(
            "SELECT c.*, d.id AS deployment_id, d.state AS dep_state, d.fragments_received,
                    d.frag_nb_missing, d.mc_group_ans, d.status_ans
             FROM fuota_campaigns c
             JOIN fuota_deployments d ON d.campaign_id = c.id AND d.dev_id = ?
             JOIN multicast_group_devices mgd ON mgd.multicast_group_id = c.multicast_group_id
             WHERE LOWER(mgd.dev_eui)=? AND c.state IN ('SETUP','FRAGMENTATION','STATUS')
             ORDER BY c.id DESC LIMIT 1",
            [$dev['id'], strtolower($devEui)]
        );
        if (!$row) {
            return null;
        }
        return ['campaign' => $row, 'deployment' => $row];
    }

    





    public static function handleMacAnswer(array $ctx): array
    {
        $cid = (int) $ctx['cid'];
        $camp = $ctx['campaign'];
        $depId = (int) $camp['deployment_id'];
        $now = time();
        $log = '';

        switch ($cid) {
            case self::CID_FRAG_SESSION_SETUP_REQ: 

                $ans = self::parseFragSessionSetupAns($ctx['payload']);
                $log = 'FragSessionSetupAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
                if ($ans['session_error'] || $ans['wrong_digest'] || $ans['wrong_descriptor']) {
                    Database::execute(
                        "UPDATE fuota_deployments SET state='FAILED', updated_at=? WHERE id=?",
                        [$now, $depId]
                    );
                } else {
                    Database::execute(
                        "UPDATE fuota_deployments SET state='FRAGMENTATION', updated_at=? WHERE id=?",
                        [$now, $depId]
                    );
                }
                break;

            case self::CID_FRAG_DATA_BLOCK_REQ: 

                $ans = self::parseFragDataBlockAns($ctx['payload']);
                $log = 'FragDataBlockAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
                if (!$ans['missing']) {
                    Database::execute(
                        "UPDATE fuota_deployments SET fragments_received = fragments_received + 1, updated_at=? WHERE id=?",
                        [$now, $depId]
                    );
                }
                break;

            case self::CID_FRAG_STATUS_REQ: 

                $ans = self::parseFragStatusAns($ctx['payload']);
                $log = 'FragStatusAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
                Database::execute(
                    "UPDATE fuota_deployments SET fragments_received=?, frag_nb_missing=?, state='STATUS', updated_at=? WHERE id=?",
                    [$ans['frag_nb_received'], $ans['frag_nb_missing'], $now, $depId]
                );
                break;

            case self::CID_MC_GROUP_SETUP_REQ: 

                $ans = self::parseMcGroupSetupAns($ctx['payload']);
                $log = 'McGroupSetupAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
                $ok = !($ans['group_id_error'] || $ans['mc_addr_error'] || $ans['mc_key_error']);
                Database::execute(
                    "UPDATE fuota_deployments SET mc_group_ans=?, updated_at=? WHERE id=?",
                    [$ok ? 1 : 0, $now, $depId]
                );
                if (!$ok) {
                    Database::execute(
                        "UPDATE fuota_deployments SET state='FAILED', updated_at=? WHERE id=?",
                        [$now, $depId]
                    );
                }
                break;

            case self::CID_CLK_SYNC_REQ: 

                $ans = self::parseClkSyncAns($ctx['payload']);
                $log = 'ClkSyncAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
                break;

            case self::CID_MC_GROUP_DELETE_REQ: 

                $log = 'McGroupDeleteAns';
                break;

            default:
                return ['log' => '', 'deployment_state' => $camp['dep_state'] ?? null];
        }
        $dep = Database::fetch("SELECT state FROM fuota_deployments WHERE id=?", [$depId]);
        return ['log' => $log, 'deployment_state' => $dep ? $dep['state'] : null];
    }

    




    public static function handleAppPayload(array $ctx): array
    {
        $camp = $ctx['campaign'];
        $depId = (int) $camp['deployment_id'];
        $now = time();
        $fport = (int) $ctx['fport'];

        if ($fport === self::FPORT_SETUP) {
            $ans = self::parseFuotaSetupAns($ctx['payload']);
            $log = 'FuotaSetupAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
            if ($ans['no_image'] || $ans['descriptor_error'] || $ans['fw_version_error'] || $ans['fw_size_error']) {
                Database::execute(
                    "UPDATE fuota_deployments SET state='FAILED', updated_at=? WHERE id=?",
                    [$now, $depId]
                );
            }
            return ['log' => $log];
        }

        if ($fport === self::FPORT_STATUS) {
            $ans = self::parseFuotaStatusAns($ctx['payload']);
            $log = 'FuotaStatusAns ' . json_encode($ans, JSON_UNESCAPED_UNICODE);
            $nbMissing = (int) ($ans['frag_nb_missing'] ?? -1);
            if (isset($ans['frag_nb_received'])) {
                Database::execute(
                    "UPDATE fuota_deployments SET fragments_received=?, frag_nb_missing=?, status_ans=1,
                     state=?, updated_at=? WHERE id=?",
                    [(int) $ans['frag_nb_received'], max(0, $nbMissing),
                     $nbMissing === 0 ? self::STATE_DONE : self::STATE_STATUS, $now, $depId]
                );
            } else {
                

                Database::execute(
                    "UPDATE fuota_deployments SET status_ans=1, frag_nb_missing=?,
                     state=?, updated_at=? WHERE id=?",
                    [max(0, $nbMissing), $nbMissing === 0 ? self::STATE_DONE : self::STATE_STATUS, $now, $depId]
                );
            }
            return ['log' => $log];
        }

        return ['log' => ''];
    }

    




    public static function buildGroupSetupForCampaign(array $camp, array $group): string
    {
        $keKey = hex2bin((string) ($camp['mc_ke_key'] ?? ''));
        if (strlen($keKey) !== 16) {
            $keKey = hex2bin($group['mc_nwk_s_key']);
        }
        $freqHz = (int) ($group['frequency'] ?? 0);
        if ($freqHz <= 0) {
            $freqHz = 868500000; 

        }
        return self::buildMcGroupSetupReq(
            0,
            hex2bin($group['mc_addr']),
            hex2bin($group['mc_nwk_s_key']),
            $keKey,
            (int) $group['dr'],
            (int) round($freqHz / 1000)
        );
    }

    public static function nextFrames(int $campaignId, int $offset, int $count): array
    {
        return Database::fetchAll(
            "SELECT seq, fopts_hex FROM fuota_frames WHERE campaign_id=? AND seq>=? ORDER BY seq ASC LIMIT ?",
            [$campaignId, $offset, $count]
        );
    }

    



    public static function finalizeCampaign(int $campaignId): array
    {
        $camp = self::getCampaign($campaignId);
        if (!$camp) {
            return ['error' => 'campaign not found'];
        }
        $now = time();
        Database::execute(
            "UPDATE fuota_deployments SET state='FAILED', updated_at=? WHERE campaign_id=? AND state NOT IN ('DONE','FAILED')",
            [$now, $campaignId]
        );
        $deps = Database::fetchAll("SELECT state FROM fuota_deployments WHERE campaign_id=?", [$campaignId]);
        $done = $failed = 0;
        foreach ($deps as $d) {
            if ($d['state'] === self::STATE_DONE) {
                $done++;
            } elseif ($d['state'] === self::STATE_FAILED) {
                $failed++;
            }
        }
        

        $state = ($done === 0 && $failed > 0) ? self::STATE_FAILED : self::STATE_DONE;
        Database::execute(
            "UPDATE fuota_campaigns SET state=?, updated_at=? WHERE id=?",
            [$state, $now, $campaignId]
        );
        return ['state' => $state, 'done' => $done, 'failed' => $failed, 'total' => count($deps)];
    }

    


    








    public static function buildMulticastDown(array $group, int $fPort, string $payload, string $fopts = ''): string
    {
        $mcNwk = hex2bin($group['mc_nwk_s_key']);
        $mcApp = hex2bin($group['mc_app_s_key']);
        $devAddr = hex2bin($group['mc_addr']);
        $fcnt = (int) $group['f_cnt'];
        if (strlen($fopts) > 15) {
            return Frame::buildDataDown($mcNwk, $mcApp, 1, $devAddr, $fcnt, false, false, 0, $fopts, 0, '');
        }
        return Frame::buildDataDown($mcNwk, $mcApp, 1, $devAddr, $fcnt, false, false, $fPort, $payload, 0, $fopts);
    }
}
