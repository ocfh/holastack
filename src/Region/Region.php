<?php
namespace holastack\Region;

/**
 * LoRaWAN 区域参数模板。
 * 参照 ChirpStack lrwn/src/region/ 各频段实现。
 *
 * 提供 RX1/RX2 频率、接收延迟、Join Accept 延迟、数据速率映射。
 * 时间单位：毫秒；频率单位：Hz。
 */
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

    /** EU868 / CN470 / CN779 / EU433 上行与 RX1 同频；US915/AU915 下行使用固定信道。 */
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

    // ---- 下行调度 / 入网窗口 所需 getter（NetworkServer 调用） ----

    public function getRx2Frequency(): int { return (int) $this->cfg['rx2_frequency']; }
    public function getRx2DataRate(): int { return (int) $this->cfg['rx2_dr']; }
    /** Class B 信标频点（Hz）/ 数据速率索引（对齐 ChirpStack region beacon 配置）。 */
    public function getBeaconFrequency(): int { return (int) $this->cfg['beacon_frequency']; }
    public function getBeaconDataRate(): int { return (int) $this->cfg['beacon_dr']; }
    /** Class B 信标 RFU1 长度（字节），区域相关（EU868=2 / CN470=3 / US915=5 / AU915=3 / IN865=1…）。 */
    public function getBeaconRfu1(): int { return (int) ($this->cfg['beacon_rfu1'] ?? 0); }
    public function getBeaconRfu2(): int { return (int) ($this->cfg['beacon_rfu2'] ?? 0); }
    public function getBeaconNbChannels(): int { return (int) ($this->cfg['beacon_nb_channels'] ?? 1); }
    public function getBeaconChannelStep(): int { return (int) ($this->cfg['beacon_channel_step'] ?? 0); }

    /**
     * Class B 信标频点（Hz），含跳频。
     *   freq = beacon_frequency + (floor(beaconGps / 128) % nb_channels) * channel_step
     * nb_channels<=1 或 step<=0 时返回固定 beacon_frequency（EU868 / AS923 / 单信道区域）。
     * 对齐固件 RegionCN470.c 的 PHY_BEACON_CHANNEL_FREQ 跳频公式：
     *   CN470      = 508.3MHz + (floor(beacon/128) % 8) * 200kHz
     *   US915/AU915 = 923.3MHz + (floor(beacon/128) % 8) * 600kHz
     */
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

    /**
     * 区域允许的最大「LoRa 125kHz」数据速率索引（用于 ADR 引擎的上限裁剪）。
     * 仅取 bw==125 且 sf>0 的 DR，与 ChirpStack lrwn region.get_enabled_uplink_data_rates 过滤一致。
     */
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

    /**
     * LoRa 解调门限（demodulation floor，单位 dB）——用于 ADR 余量计算与 LinkCheck 应答。
     * 取自 LoRa 物理层灵敏度规格（同 ChirpStack lrwn region required_snr_for_dr）。
     * DR 值按 data_rates 中的 sf 反推；FSK / 未知返回 0。
     */
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

