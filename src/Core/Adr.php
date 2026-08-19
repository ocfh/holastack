<?php
namespace holastack\Core;

use holastack\Region\Region;















class Adr
{
    

    public const DEFAULT_INSTALLATION_MARGIN = 5.0;
    

    public const REQUIRED_HISTORY = 20;

    




    public static function compute(array $req): array
    {
        $resp = [
            'dr'            => (int) ($req['dr'] ?? 0),
            'tx_power_index' => (int) ($req['tx_power_index'] ?? 0),
            'nb_trans'      => (int) ($req['nb_trans'] ?? 1),
        ];

        

        if (empty($req['adr'])) {
            return $resp;
        }

        

        $region = $req['region'] ?? null;
        $maxDr = (int) ($req['max_dr'] ?? 0);
        $maxLoraDr = self::maxLoraDr($region, $maxDr);
        if ($maxDr > $maxLoraDr) {
            $maxDr = $maxLoraDr;
        }

        

        if ($resp['dr'] > $maxDr) {
            $resp['dr'] = $maxDr;
        }

        

        $resp['nb_trans'] = self::getNbTrans($req['nb_trans'], self::packetLossPct($req));

        $snrMax = self::maxSnr($req);
        $requiredSnr = (float) ($req['required_snr_for_dr'] ?? 0.0);
        $margin = (float) ($req['installation_margin'] ?? self::DEFAULT_INSTALLATION_MARGIN);
        $snrMargin = $snrMax - $requiredSnr - $margin;
        $nStep = (int) floor($snrMargin / 3.0);

        

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

    


    private static function idealTxPowerAndDr(int $nbStep, int $txPowerIndex, int $dr, int $maxTxPowerIndex, int $maxDr): array
    {
        if ($nbStep === 0) {
            return ['tx_power_index' => $txPowerIndex, 'dr' => $dr];
        }

        if ($nbStep > 0) {
            if ($dr < $maxDr) {
                $dr += 1;               

            } elseif ($txPowerIndex < $maxTxPowerIndex) {
                $txPowerIndex += 1;     

            }
            $nbStep -= 1;
        } else {
            

            $txPowerIndex = max(0, $txPowerIndex - 1);
            $nbStep += 1;
        }

        return self::idealTxPowerAndDr($nbStep, $txPowerIndex, $dr, $maxTxPowerIndex, $maxDr);
    }

    


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
