<?php
namespace holastack\Region;









class Region
{
    private $name;
    private $cfg;

    private static $REGIONS = [
        'EU868' => [
            'rx2_frequency' => 869525000,
            'rx2_dr' => 0,
            'beacon_frequency' => 869525000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 7,  'bw' => 250, 'desc' => 'SF7BW250'],
                7 => ['sf' => 0,  'bw' => 0,   'desc' => 'FSK'],
            ],
        ],
        'US915' => [
            'rx2_frequency' => 923300000,
            'rx2_dr' => 8,
            'beacon_frequency' => 923300000,
            'beacon_dr' => 8,
            'beacon_rfu1' => 5,
            'beacon_rfu2' => 3,
            'beacon_nb_channels' => 8,
            'beacon_channel_step' => 600000,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 4,
            'data_rates' => [
                0 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                1 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                2 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                3 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                4 => ['sf' => 8,  'bw' => 500, 'desc' => 'SF8BW500'],
                8 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                9 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                10 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                11 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                12 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                13 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
            ],
        ],
        'CN470' => [
            'rx2_frequency' => 505300000,
            'rx2_dr' => 0,
            'beacon_frequency' => 508300000,
            'beacon_dr' => 2,
            'beacon_rfu1' => 3,
            'beacon_rfu2' => 1,
            'beacon_nb_channels' => 8,
            'beacon_channel_step' => 200000,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'rx1' => [
                // CN470：下行 RX1 用「配对频点」而非上行同频
                // 上行 470.3~489.3（96 信道 @0.2MHz）→ 下行 500.3~509.7（48 信道，idx%48）
                'type' => 'paired',
                'ul_start' => 470.3, 'ul_step' => 0.2,
                'dl_start' => 500.3, 'dl_step' => 0.2, 'dl_count' => 48,
            ],
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
            ],
        ],
        'AS923' => [
            'rx2_frequency' => 921400000,
            'rx2_dr' => 2,
            'beacon_frequency' => 923400000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 7,  'bw' => 250, 'desc' => 'SF7BW250'],
            ],
        ],
        'AU915' => [
            'rx2_frequency' => 923300000,
            'rx2_dr' => 8,
            'beacon_frequency' => 923300000,
            'beacon_dr' => 10,
            'beacon_rfu1' => 3,
            'beacon_rfu2' => 1,
            'beacon_nb_channels' => 8,
            'beacon_channel_step' => 600000,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 4,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 8,  'bw' => 500, 'desc' => 'SF8BW500'],
                8 => ['sf' => 12, 'bw' => 500, 'desc' => 'SF12BW500'],
                9 => ['sf' => 11, 'bw' => 500, 'desc' => 'SF11BW500'],
                10 => ['sf' => 10, 'bw' => 500, 'desc' => 'SF10BW500'],
                11 => ['sf' => 9,  'bw' => 500, 'desc' => 'SF9BW500'],
                12 => ['sf' => 8,  'bw' => 500, 'desc' => 'SF8BW500'],
                13 => ['sf' => 7,  'bw' => 500, 'desc' => 'SF7BW500'],
            ],
        ],
        'CN779' => [
            'rx2_frequency' => 786000000,
            'rx2_dr' => 0,
            'beacon_frequency' => 785000000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 7,  'bw' => 250, 'desc' => 'SF7BW250'],
            ],
        ],
        'EU433' => [
            'rx2_frequency' => 434665000,
            'rx2_dr' => 0,
            'beacon_frequency' => 434665000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 7,  'bw' => 250, 'desc' => 'SF7BW250'],
            ],
        ],
        'IN865' => [
            'rx2_frequency' => 866550000,
            'rx2_dr' => 0,
            'beacon_frequency' => 866550000,
            'beacon_dr' => 4,
            'beacon_rfu1' => 1,
            'beacon_rfu2' => 3,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                7 => ['sf' => 0,  'bw' => 0,   'desc' => 'FSK'],
            ],
        ],
        'KR920' => [
            'rx2_frequency' => 921900000,
            'rx2_dr' => 0,
            'beacon_frequency' => 923100000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
            ],
        ],
        'RU864' => [
            'rx2_frequency' => 869100000,
            'rx2_dr' => 0,
            'beacon_frequency' => 869100000,
            'beacon_dr' => 3,
            'beacon_rfu1' => 2,
            'beacon_rfu2' => 0,
            'beacon_nb_channels' => 1,
            'beacon_channel_step' => 0,
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            'data_rates' => [
                0 => ['sf' => 12, 'bw' => 125, 'desc' => 'SF12BW125'],
                1 => ['sf' => 11, 'bw' => 125, 'desc' => 'SF11BW125'],
                2 => ['sf' => 10, 'bw' => 125, 'desc' => 'SF10BW125'],
                3 => ['sf' => 9,  'bw' => 125, 'desc' => 'SF9BW125'],
                4 => ['sf' => 8,  'bw' => 125, 'desc' => 'SF8BW125'],
                5 => ['sf' => 7,  'bw' => 125, 'desc' => 'SF7BW125'],
                6 => ['sf' => 7,  'bw' => 250, 'desc' => 'SF7BW250'],
            ],
        ],
    ];

    public function __construct(string $name, array $cfg)
    {
        $this->name = $name;
        $this->cfg = $cfg;
    }

    public static function get(string $name): self
    {
        $name = strtoupper(trim($name));
        if (!isset(self::$REGIONS[$name])) {
            throw new \InvalidArgumentException("Unsupported region: $name");
        }
        return new self($name, self::$REGIONS[$name]);
    }

    public static function supported(): array
    {
        return array_keys(self::$REGIONS);
    }

    public static function allDetails(): array
    {
        $out = [];
        foreach (self::$REGIONS as $name => $cfg) {
            $out[$name] = [
                'rx2_frequency' => $cfg['rx2_frequency'],
                'rx2_dr' => $cfg['rx2_dr'],
                'beacon_frequency' => $cfg['beacon_frequency'],
                'beacon_dr' => $cfg['beacon_dr'],
                'receive_delay1' => $cfg['receive_delay1'],
                'receive_delay2' => $cfg['receive_delay2'],
                'join_accept_delay1' => $cfg['join_accept_delay1'],
                'join_accept_delay2' => $cfg['join_accept_delay2'],
                'max_ul_dr' => $cfg['max_ul_dr'],
                'data_rates' => array_map(function($dr) {
                    return $dr['desc'];
                }, $cfg['data_rates']),
            ];
        }
        return $out;
    }

    public function getName(): string { return $this->name; }

    

    public function getRx1DrOffset(): int { return $this->cfg['rx1_dr_offset']; }

    public function getDataRate(int $dr): array
    {
        return $this->cfg['data_rates'][$dr] ?? $this->cfg['data_rates'][0];
    }

    public function datrToDr(string $datr): ?int
    {
        foreach ($this->cfg['data_rates'] as $dr => $d) {
            if ($d['desc'] === $datr) {
                return $dr;
            }
        }
        return null;
    }

    public function drToDatr(int $dr): string
    {
        return ($this->cfg['data_rates'][$dr] ?? $this->cfg['data_rates'][0])['desc'];
    }

    


    public function getRx2Frequency(): int { return (int) $this->cfg['rx2_frequency']; }
    public function getRx2DataRate(): int { return (int) $this->cfg['rx2_dr']; }

    /**
     * RX1 下行频点。多数区域与上行同频；配对区域（如 CN470）按下行频段换算。
     * @param float $ulFreqMHz 上行频点（MHz）
     */
    public function getRx1Frequency(float $ulFreqMHz): float
    {
        $r = $this->cfg['rx1'] ?? null;
        if (!$r || ($r['type'] ?? 'same') !== 'paired') {
            return $ulFreqMHz;
        }
        $idx = (int) round(($ulFreqMHz - (float) $r['ul_start']) / (float) $r['ul_step']);
        $count = max(1, (int) ($r['dl_count'] ?? 48));
        $dlIdx = $idx % $count;
        if ($dlIdx < 0) {
            $dlIdx += $count;
        }
        return (float) $r['dl_start'] + $dlIdx * (float) $r['dl_step'];
    }
    

    public function getBeaconFrequency(): int { return (int) $this->cfg['beacon_frequency']; }
    public function getBeaconDataRate(): int { return (int) $this->cfg['beacon_dr']; }
    

    public function getBeaconRfu1(): int { return (int) ($this->cfg['beacon_rfu1'] ?? 0); }
    public function getBeaconRfu2(): int { return (int) ($this->cfg['beacon_rfu2'] ?? 0); }
    public function getBeaconNbChannels(): int { return (int) ($this->cfg['beacon_nb_channels'] ?? 1); }
    public function getBeaconChannelStep(): int { return (int) ($this->cfg['beacon_channel_step'] ?? 0); }

    








    public function getBeaconChannelFrequency(int $beaconGps): int
    {
        $base = (int) $this->cfg['beacon_frequency'];
        $nb = (int) ($this->cfg['beacon_nb_channels'] ?? 1);
        $step = (int) ($this->cfg['beacon_channel_step'] ?? 0);
        if ($nb <= 1 || $step <= 0) {
            return $base;
        }
        $idx = (intdiv($beaconGps, 128) % $nb + $nb) % $nb;
        return $base + $idx * $step;
    }
    public function getReceiveDelay1(): int { return (int) $this->cfg['receive_delay1']; }
    public function getReceiveDelay2(): int { return (int) $this->cfg['receive_delay2']; }
    public function getJoinAcceptDelay1(): int { return (int) $this->cfg['join_accept_delay1']; }
    public function getJoinAcceptDelay2(): int { return (int) $this->cfg['join_accept_delay2']; }
    public function getMaxUlDr(): int { return (int) $this->cfg['max_ul_dr']; }
    public function getCfList() { return $this->cfg['cf_list']; }

    public function getDefaultUplinkChannels(): array
    {
        switch ($this->name) {
            case 'US915':
            case 'AU915':
                return range(0, 63);
            case 'CN470':
                return range(0, 95);
            case 'AS923':
            case 'IN865':
            case 'KR920':
                return [0, 1];
            case 'EU868':
            case 'EU433':
            case 'CN779':
            case 'RU864':
            default:
                return [0, 1, 2];
        }
    }

    




    public function getMaxLoraDr(): int
    {
        $max = 0;
        foreach ($this->cfg['data_rates'] as $dr => $d) {
            if ((int) ($d['sf'] ?? 0) > 0 && (int) ($d['bw'] ?? 0) === 125) {
                $max = max($max, (int) $dr);
            }
        }
        return $max;
    }

    





    public function requiredSnrForDr(int $dr): float
    {
        $d = $this->cfg['data_rates'][$dr] ?? null;
        if (!$d) {
            return 0.0;
        }
        $sf = $d['sf'];
        $map = [
            12 => -20.0, 11 => -17.5, 10 => -15.0, 9 => -12.5, 8 => -10.0,
            7 => -7.5, 6 => -7.5, 5 => -5.0,
        ];
        return $map[$sf] ?? 0.0;
    }
}

