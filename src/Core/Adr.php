<?php
namespace holastack\Core;

use holastack\Region\Region;

/**
 * LoRaWAN 默认 ADR 算法（对齐 ChirpStack chirpstack/src/adr/default.rs）。
 *
 * 输入由设备会话状态 + 区域参数构造的 Request，输出期望的 dr / tx_power_index / nb_trans。
 * 当 ADR 开启且输出与设备当前状态不一致时，上层（NetworkServer）据此下发 LinkADRReq。
 *
 * 算法要点（与 Rust 实现逐行对齐）：
 *  - snr_margin = max_snr − required_snr_for_dr − installation_margin
 *  - n_step = floor(snr_margin / 3)
 *  - n_step > 0：优先提升 DR，DR 到顶后再降低（即提升索引）tx_power
 *  - n_step < 0：提升（即降低索引）tx_power；为避免抖动，需等历史凑齐才执行
 *  - nb_trans 由上行丢包率查表得到
 */
class Adr
{
    /** ChirpStack 默认安装余量（dB）。 */
    public const DEFAULT_INSTALLATION_MARGIN = 5.0;
    /** 触发计算所需的最小历史条数。 */
    public const REQUIRED_HISTORY = 20;

    /**
     * @param array $req 结构见 NetworkServer::processMacAndAdr 注释
     * @return array ['dr'=>int,'tx_power_index'=>int,'nb_trans'=>int]
     */
    public static function compute(array $req): array
    {
        $resp = [
            'dr'            => (int) ($req['dr'] ?? 0),
            'tx_power_index' => (int) ($req['tx_power_index'] ?? 0),
            'nb_trans'      => (int) ($req['nb_trans'] ?? 1),
        ];

        // ADR 关闭：返回当前值
        if (empty($req['adr'])) {
            return $resp;
        }

        // 仅作用于 LoRa 125kHz 数据速率（与 Rust max_lora_dr 过滤一致）
        $region = $req['region'] ?? null;
        $maxDr = (int) ($req['max_dr'] ?? 0);
        $maxLoraDr = self::maxLoraDr($region, $maxDr);
        if ($maxDr > $maxLoraDr) {
            $maxDr = $maxLoraDr;
        }

        // 超出 max_dr 时仅降级 DR
        if ($resp['dr'] > $maxDr) {
            $resp['dr'] = $maxDr;
        }

        // 由丢包率设定 nb_trans
        $resp['nb_trans'] = self::getNbTrans($req['nb_trans'], self::packetLossPct($req));

        $snrMax = self::maxSnr($req);
        $requiredSnr = (float) ($req['required_snr_for_dr'] ?? 0.0);
        $margin = (float) ($req['installation_margin'] ?? self::DEFAULT_INSTALLATION_MARGIN);
        $snrMargin = $snrMax - $requiredSnr - $margin;
        $nStep = (int) floor($snrMargin / 3.0);

        // 负步长：避免 TxPower 反复上下抖动，需等历史凑齐
        if ($nStep < 0 && self::historyCountForTxPower($req) !== self::REQUIRED_HISTORY) {
            return $resp;
        }

        $ideal = self::idealTxPowerAndDr(
            $nStep,
            $resp['tx_power_index'],
            $resp['dr'],
            (int) ($req['max_tx_power_index'] ?? 0),
            $maxDr
        );
        $resp['dr'] = $ideal['dr'];
        $resp['tx_power_index'] = $ideal['tx_power_index'];
        return $resp;
    }

    // ---- 内部：理想 DR / TxPower 递归 ----

    private static function idealTxPowerAndDr(int $nbStep, int $txPowerIndex, int $dr, int $maxTxPowerIndex, int $maxDr): array
    {
        if ($nbStep === 0) {
            return ['tx_power_index' => $txPowerIndex, 'dr' => $dr];
        }

        if ($nbStep > 0) {
            if ($dr < $maxDr) {
                $dr += 1;               // 提升数据速率
            } elseif ($txPowerIndex < $maxTxPowerIndex) {
                $txPowerIndex += 1;     // 否则降低发射功率（提升索引）
            }
            $nbStep -= 1;
        } else {
            // 提升发射功率（降低索引）；索引不会低于 0（saturating_sub）
            $txPowerIndex = max(0, $txPowerIndex - 1);
            $nbStep += 1;
        }

        return self::idealTxPowerAndDr($nbStep, $txPowerIndex, $dr, $maxTxPowerIndex, $maxDr);
    }

    // ---- 内部：统计计算 ----

    private static function requiredHistoryCount(): int
    {
        return self::REQUIRED_HISTORY;
    }

    private static function historyCountForTxPower(array $req): int
    {
        $history = $req['uplink_history'] ?? [];
        $tx = (int) $req['tx_power_index'];
        $n = 0;
        foreach ($history as $h) {
            if ((int) ($h['tx_power_index'] ?? -1) === $tx) {
                $n++;
            }
        }
        return $n;
    }

    private static function maxSnr(array $req): float
    {
        $max = -999.0;
        foreach (($req['uplink_history'] ?? []) as $h) {
            $s = (float) ($h['max_snr'] ?? -999.0);
            if ($s > $max) {
                $max = $s;
            }
        }
        return $max;
    }

    private static function packetLossPct(array $req): float
    {
        $history = $req['uplink_history'] ?? [];
        if (count($history) < self::requiredHistoryCount()) {
            return 0.0;
        }
        $lost = 0;
        $prev = null;
        foreach ($history as $h) {
            $fc = (int) ($h['f_cnt'] ?? 0);
            if ($prev === null) {
                $prev = $fc;
                continue;
            }
            $lost += max(0, $fc - $prev - 1);
            $prev = $fc;
        }
        return ($lost / count($history)) * 100.0;
    }

    private static function getNbTrans(int $currentNbTrans, float $pktLossRate): int
    {
        $table = [
            [1, 1, 2],
            [1, 2, 3],
            [2, 3, 3],
            [3, 3, 3],
        ];
        $current = max(1, min(3, $currentNbTrans));
        $idx = $current - 1;
        if ($pktLossRate < 5.0) {
            return $table[0][$idx];
        } elseif ($pktLossRate < 10.0) {
            return $table[1][$idx];
        } elseif ($pktLossRate < 30.0) {
            return $table[2][$idx];
        }
        return $table[3][$idx];
    }

    private static function maxLoraDr($region, int $maxDr): int
    {
        if ($region instanceof Region) {
            return $region->getMaxLoraDr();
        }
        return $maxDr;
    }
}
