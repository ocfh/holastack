<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;
use holastack\Core\Multicast;

/**
 * FUOTA（固件无线升级，TR005 / TS005）。
 *
 * FUOTA 由三段组成，均通过 MAC 命令下发：
 *   1) 组播会话建立（McGroupSetupReq 0x0B / McClassCSessionReq / DevStatusReq）；
 *   2) 固件分片传输（FragSessionSetupReq 0x08 + FragDataBlockReq 0x09）；
 *   3) 时钟同步（ClkSyncReq 0x0C）+ 应用层 FUOTA 会话（FRMPayload on FPort 200/201）。
 *
 * 真机升级流程：先建组播组（Multicast）→ 下发 FragSessionSetupReq 与一系列
 * FragDataBlockReq（每个分片一条 MAC 命令，经组播组下发）→ 终端收齐后 CRC 校验 →
 * 触发应用层 FUOTA 完成。
 *
 * 本类职责：固件分片（纯逻辑）、三类 MAC 命令构造、FUOTA 计划/部署的 DB 管理，
 * 以及把分片打包为可经组播下发的物理层帧。
 */
class Fuota
{
    // FUOTA 相关 MAC 命令 CID（RP002-1.0.3）
    public const CID_FRAG_SESSION_SETUP_REQ = 0x08;
    public const CID_FRAG_DATA_BLOCK_REQ    = 0x09;
    public const CID_MC_GROUP_SETUP_REQ     = 0x0B;
    public const CID_CLK_SYNC_REQ           = 0x0C;

    // ---- 计划 / 部署 CRUD ----

    public static function listCampaigns(): array
    {
        return Database::fetchAll("SELECT * FROM fuota_campaigns ORDER BY id DESC");
    }

    public static function createCampaign(array $p): array
    {
        if (empty($p['name']) || empty($p['application_id']) || empty($p['multicast_group_id'])) {
            return ['error' => 'name, application_id and multicast_group_id required'];
        }
        Database::execute(
            "INSERT INTO fuota_campaigns
             (tenant_id, name, application_id, multicast_group_id, fragment_size, redundancy,
              descriptor_version, fw_version, fw_length, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
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

    // ---- 固件分片（纯逻辑） ----

    /**
     * 把固件二进制按 fragSize 切成 N 片，计算 FragAuthDigest（SHA-256 前 4 字节）。
     * 返回：['frag_n'=>int,'frag_size'=>int,'digest'=>bin(4),'fragments'=>[bin,...],'redundancy'=>int]。
     */
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
        // 冗余分片（FEC，可选）
        for ($r = 0; $r < $redundancy; $r++) {
            // 简单重复尾部分片作为冗余（生产应接入 Reed-Solomon / 喷泉码）
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

    // ---- FUOTA MAC 命令构造 ----

    /**
     * FragSessionSetupReq（CID 0x08）。
     * 字节：CID + FragSession(1)[FragIndex(3)|FragSize(3)|UDROn(1)|RFU(1)]
     *        + FragN(2, LE) + FragAuthDigest(4) + Padding(0/1)。
     * FragSize 字段为指数（实际 = 2^value，1..128 字节）。
     */
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
        // Padding 使总长为偶（FragAuthDigest 后视需要补 0~1 字节）
        if (strlen($out) % 2 === 1) {
            $out .= "\x00";
        }
        return $out;
    }

    /**
     * FragDataBlockReq（CID 0x09）。
     * 字节：CID + Word(2)[FragIndex(3)|More(1)|BlockNumber(12)] + DataBlock(fragSize)。
     */
    public static function buildFragDataBlockReq(int $fragIndex, int $blockN, string $data, bool $more = false): string
    {
        $fragIndex &= 0x07;
        $blockN &= 0x0FFF;
        $word = (($fragIndex & 0x07) << 13) | (($more ? 1 : 0) << 12) | $blockN;
        return chr(self::CID_FRAG_DATA_BLOCK_REQ)
             . pack('v', $word)
             . $data;
    }

    /**
     * McGroupSetupReq（CID 0x0B）。
     * 字节：CID + McGroupID(1) + McAddr(4) + McKeyEncrypted(16, AES-ECB(McKEKey, McNwkSKey))
     *        + McDR(1) + McFreq(3, LE kHz)。
     */
    public static function buildMcGroupSetupReq(
        int $mcGroupId, string $mcAddrBin, string $mcNwkSKey, string $mcKEKey, int $mcDr, int $mcFreq
    ): string {
        $mcKeyEnc = AES::ecbEncrypt($mcKEKey, $mcNwkSKey);
        $mcFreqBytes = substr(pack('V', $mcFreq & 0xFFFFFF), 0, 3); // 低 3 字节（LE kHz）
        return chr(self::CID_MC_GROUP_SETUP_REQ)
             . chr($mcGroupId & 0xFF)
             . substr($mcAddrBin, 0, 4)
             . $mcKeyEnc
             . chr($mcDr & 0xFF)
             . $mcFreqBytes;
    }

    /**
     * ClkSyncReq（CID 0x0C）。
     * 字节：CID + Ctrl(1)[AnsRequired(1)|RFU(7)]。
     */
    public static function buildClkSyncReq(bool $ansRequired = true): string
    {
        return chr(self::CID_CLK_SYNC_REQ) . chr(($ansRequired ? 1 : 0) << 7);
    }

    // ---- 组播下行帧装配（带 FOpts） ----

    /**
     * 以组播密钥 + FOpts 构造一条下行物理层帧（FPort 可 0，用于 MAC 命令下发）。
     */
    public static function buildMulticastDown(array $group, int $fPort, string $payload, string $fopts = ''): string
    {
        $mcNwk = hex2bin($group['mc_nwk_s_key']);
        $mcApp = hex2bin($group['mc_app_s_key']);
        $devAddr = hex2bin($group['mc_addr']);
        $fcnt = (int) $group['f_cnt'];
        return Frame::buildDataDown($mcNwk, $mcApp, 1, $devAddr, $fcnt, false, false, $fPort, $payload, 0, $fopts);
    }

    /**
     * 把一个固件打包为一组可经组播组下发的物理层帧。
     * 返回 ['meta'=>..., 'downlinks'=>[['pdu'=>base64,'fcnt'=>int], ...]]。
     * 实际下发需把每条插入 multicast_queue 并推送给组内网关（见 Multicast）。
     */
    public static function enqueueCampaign(int $campaignId, string $firmwareBin): array
    {
        $camp = self::getCampaign($campaignId);
        if (!$camp) {
            return ['error' => 'campaign not found'];
        }
        $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [$camp['multicast_group_id']]);
        if (!$group) {
            return ['error' => 'multicast group not found'];
        }
        $frag = self::fragmentFirmware($firmwareBin, (int) $camp['fragment_size'], (int) $camp['redundancy']);
        $sessionSetup = self::buildFragSessionSetupReq(
            0, $frag['frag_size'], $frag['frag_n'], $frag['digest'], false
        );

        // 持久化分片
        $dep = Database::fetch(
            "SELECT id FROM fuota_deployments WHERE campaign_id=? LIMIT 1",
            [$campaignId]
        );
        $depId = $dep ? (int) $dep['id'] : null;

        $downlinks = [];
        $fcnt = (int) $group['f_cnt'];
        foreach ($frag['fragments'] as $idx => $data) {
            $more = ($idx + 1) < count($frag['fragments']);
            $dataBlock = self::buildFragDataBlockReq(0, $idx, $data, $more);
            // 会话建立命令只在首帧附加
            $fopts = ($idx === 0) ? ($sessionSetup . $dataBlock) : $dataBlock;
            $pdu = self::buildMulticastDown($group, 0, '', $fopts);
            $downlinks[] = ['pdu' => base64_encode($pdu), 'fcnt' => $fcnt, 'block' => $idx];
            if ($depId !== null) {
                Database::execute(
                    "INSERT INTO fuota_fragments (deployment_id, frag_index, data, created_at) VALUES (?,?,?,?)",
                    [$depId, $idx, base64_encode($data), time()]
                );
            }
        }
        return [
            'meta'      => ['frag_n' => $frag['frag_n'], 'frag_size' => $frag['frag_size'], 'digest' => bin2hex($frag['digest'])],
            'downlinks' => $downlinks,
        ];
    }
}
