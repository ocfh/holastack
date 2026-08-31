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
            // RP002-1.0.1（L2 1.0.4 / RP 2-1.0.1）通道计划 Type A / 20 MHz（简称 A20）。
            // ⚠️ 上行分【两段】，不是连续的 470.3+0.2N：
            //   上行 ch0..31 : 470.3 + ch*0.2        → 470.3 ~ 476.5 MHz（TX1 段）
            //   上行 ch32..63: 503.5 + (ch-32)*0.2   → 503.5 ~ 509.7 MHz（TX2 段，500MHz 频段）
            // 下行只有一段，按同一个 ch 号映射：
            //   下行 ch0..31 : 483.9 + ch*0.2        → 483.9 ~ 490.1 MHz
            //   下行 ch32..63: 490.3 + (ch-32)*0.2   → 490.3 ~ 496.5 MHz
            // 依据：Middlewares/.../Mac/Region/RegionCN470A20.c
            //       RegionCN470A20InitializeChannels() / RegionCN470A20GetRx1Frequency()
            // 旧版 RP001 的 96 信道（下行 500.3 起）已废弃，勿再使用。
            'rx2_frequency' => 486900000,
            'rx2_dr' => 1,
            'beacon_frequency' => 508300000,
            'beacon_dr' => 2,
            'beacon_rfu1' => 3,
            'beacon_rfu2' => 1,
            'beacon_nb_channels' => 8,
            'beacon_channel_step' => 200000,
            // 数据上行的 RX1/RX2 延迟是标准值 1s/2s（实测：txDone 22.901s → RX_1 23.878s、RX_2 24.906s）。
            // 只有 Join 请求才用 5s/6s（join_accept_delay），二者不可混用。
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            // OTAA 设备入网后的 RX2 频点 = 本表[入网时使用的 Join 信道号 % 8]
            // 依据：RegionCN470A20.h CN470_A20_RX_WND_2_FREQ_OTAA + RegionCN470A20GetRx2Frequency()
            'rx2_frequency_otaa' => [485.3, 486.9, 488.5, 490.1, 491.7, 493.3, 494.0, 496.5],

            // Join 信道表（[上行频点 MHz, 下行 RX1 频点 MHz]）。
            // 固件侧已把 CN470_JOIN_CHANNELS 掩码收窄为 { 0x000F, 0x0000 }，只用 CN470_COMMON_JOIN_CHANNELS
            // 的前 4 条（RegionCN470.h）。这 4 条的下行恰好满足常规 A20 公式 483.9 + ch*0.2：
            //   470.9(ch=3)→484.5  472.5(ch=11)→486.1  474.1(ch=19)→487.7  475.7(ch=27)→489.3
            // 若固件掩码改回 20 条全开，需同步补回后 16 条（其中 479.9/499.9 为上下行同频）。
            'join_channels' => [
                [470.9, 484.5], [472.5, 486.1], [474.1, 487.7], [475.7, 489.3],
            ],
            'rx1' => [
                // CN470 下行 RX1 用「配对频点」而非上行同频，A20 计划分两段映射（见上方注释）。
                // 注意：本设备固件把 RECEIVE_DELAY1/2 与 JOIN_ACCEPT_DELAY1/2 的语义对调了——
                // 数据下行 RX1 实际在 txDone 后 ~5s 开窗（非标准 1s），故这里 receive_delay 也取 5000/6000 迁就设备；
                // 若以后接标准设备（1s）需改回 1000/2000。
                'type' => 'cn470_a20',
                // 上行两段：ch0..31 从 ul_start 起，ch32..63 从 ul_start2 起（500MHz 频段）
                'ul_start' => 470.3, 'ul_step' => 0.2, 'ul_count' => 64,
                'ul_start2' => 503.5,
                'dl_start' => 483.9, 'dl_step' => 0.2,
                'dl_split' => 32, 'dl_start2' => 490.3,
            ],
            // Class C 的 RX_C 频点必须用「区域默认 RX2」(486.9)，不能用设备级 rx2_frequency。
            // 依据 LoRaMac.c:4557 —— Reset 时 RxCChannel.Frequency = PHY_DEF_RX2_FREQUENCY；
            // 只有切回 Class A 才会把 RxCChannel 同步成 Rx2Channel（LoRaMac.c:2544）。
            // 而 CN470 入网后 Rx2Channel 会被改成 OTAA 值（485.3），与 RX_C 不同。
            'rx2_class_c_ignores_device' => true,
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
     * Class C 下行（RX_C）是否必须忽略设备级 rx2_frequency，直接用区域默认 RX2。
     * CN470 为 true：LoRaMac.c 在 Reset 时把 RxCChannel.Frequency 设为 PHY_DEF_RX2_FREQUENCY，
     * 只有切回 Class A 才同步成 Rx2Channel；而入网后 Rx2Channel 会变成 OTAA 值，二者不同。
     */
    public function classCIgnoresDeviceRx2(): bool
    {
        return (bool) ($this->cfg['rx2_class_c_ignores_device'] ?? false);
    }

    /**
     * RX1 下行频点。多数区域与上行同频；配对区域（如 CN470）按下行频段换算。
     * @param float $ulFreqMHz 上行频点（MHz）
     */
    public function getRx1Frequency(float $ulFreqMHz): float
    {
        $r = $this->cfg['rx1'] ?? null;
        if (!$r) {
            return $ulFreqMHz;
        }
        $type = $r['type'] ?? 'same';

        // RP002-1.0.1 CN470 Type A / 20MHz：上行分两段，下行按同一个 ch 号映射
        if ($type === 'cn470_a20') {
            $step  = (float) ($r['ul_step'] ?: 0.2);
            $count = max(1, (int) ($r['ul_count'] ?? 64));
            $split = (int) ($r['dl_split'] ?? 32);
            $dlStep = (float) ($r['dl_step'] ?: 0.2);
            $start2 = (float) ($r['ul_start2'] ?? 0);
            // 500MHz 段（TX2）：ch = split + (freq - ul_start2)/step
            if ($start2 > 0 && $ulFreqMHz >= $start2 - $step / 2) {
                $ch = (int) round(($ulFreqMHz - $start2) / $step);
                $max = $count - 1 - $split;
                if ($ch < 0) {
                    $ch = 0;
                } elseif ($ch > $max) {
                    $ch = $max;
                }
                return (float) $r['dl_start2'] + $ch * $dlStep;
            }
            // 470MHz 段（TX1）：ch = (freq - ul_start)/step
            $ch = (int) round(($ulFreqMHz - (float) $r['ul_start']) / $step);
            if ($ch < 0) {
                $ch = 0;
            } elseif ($ch > $split - 1) {
                $ch = $split - 1;
            }
            return (float) $r['dl_start'] + $ch * $dlStep;
        }

        if ($type !== 'paired') {
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

    /**
     * 本区域是否为「未入网 OTAA 设备 RX1/RX2 同频」的区域（RP002 CN470 的行为）。
     */
    public function hasJoinChannels(): bool
    {
        return is_array($this->cfg['join_channels'] ?? null) && count($this->cfg['join_channels']) > 0;
    }

    /**
     * 未入网 OTAA 设备的 Join 信道表查找（RegionCN470.h CN470_COMMON_JOIN_CHANNELS）。
     * @return array [joinChannelIndex, rx1FreqMHz]；查不到时 index = -1 并回落到常规 RX1 频点
     */
    public function findJoinChannel(float $ulFreqMHz): array
    {
        $tbl = $this->cfg['join_channels'] ?? null;
        if (is_array($tbl)) {
            foreach ($tbl as $i => $row) {
                if (abs((float) $row[0] - $ulFreqMHz) < 0.05) {
                    return [(int) $i, (float) $row[1]];
                }
            }
        }
        return [-1, $this->getRx1Frequency($ulFreqMHz)];
    }

    /**
     * Join Accept 的 RX1（也是 RX2）频点，单位 MHz。
     * 未入网的 OTAA 设备 RX1/RX2 都监听 join_channels 表里同一行的 Rx1Frequency
     * （RegionCN470.c RegionCN470RxConfig 的 NetworkActivation == ACTIVATION_TYPE_NONE 分支）。
     */
    public function getJoinRx1Frequency(float $ulFreqMHz): float
    {
        return $this->findJoinChannel($ulFreqMHz)[1];
    }

    /**
     * OTAA 设备入网后的 RX2 频点（Hz），由入网时使用的 Join 信道号决定
     * （RegionCN470A20GetRx2Frequency：otaaFrequencies[joinChannelIndex]）。
     */
    public function getRx2FrequencyForJoinChannel(int $joinChannelIndex): int
    {
        $list = $this->cfg['rx2_frequency_otaa'] ?? null;
        if ($joinChannelIndex >= 0 && is_array($list) && count($list) > 0) {
            $n = count($list);
            return (int) round((float) $list[$joinChannelIndex % $n] * 1000000);
        }
        return $this->getRx2Frequency();
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
                // RP002-1.0.1 A20 计划共 64 条上行信道，默认全开：
                //   ch0..31  → 470.3 ~ 476.5 MHz
                //   ch32..63 → 503.5 ~ 509.7 MHz（500MHz 段，网关必须能覆盖，否则一半上行收不到）
                // 旧版 RP001 的 96 信道「居民抄表 0~5/39~44/78~95」划分已不适用于本计划。
                return range(0, 63);
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

