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
            // RP002-1.0.1（L2 1.0.4 / RP 2-1.0.1）通道计划 Type A / 20MHz（简称 A20）。
            // 默认上行两段：
            //   ch0..31  : 470.3 + ch*0.2   → 470.3 ~ 476.5 MHz
            //   ch32..63 : 503.5 + (ch-32)*0.2 → 503.5 ~ 509.7 MHz
            // 默认 RX1（按 ch 号）走同一规则：
            //   ch<32  : 483.9 + ch*0.2  → 483.9 ~ 490.1 MHz
            //   ch≥32  : 490.3 + (ch-32)*0.2 → 490.3 ~ 496.5 MHz
            // RX2 默认 = 486.9 MHz（ABP/未入网）；OTAA 入网后由 otaaFrequencies[joinIdx] 决定。
            // 依据：Middlewares/.../Mac/Region/RegionCN470A20.c
            //       RegionCN470A20InitializeChannels() / RegionCN470A20GetRx1Frequency()
            // 2026-09-03：曾切到 B26（480.3-489.7 / 502.5 RX2）尝试对齐网关 481.5-482.9，
            // 联调不通，已回退 A20。保留 cn470_b26 分支作备用。 */
            'rx2_frequency' => 486900000,
            'rx2_dr' => 1,
            'beacon_frequency' => 508300000,
            'beacon_dr' => 2,
            'beacon_rfu1' => 3,
            'beacon_rfu2' => 1,
            'beacon_nb_channels' => 8,
            'beacon_channel_step' => 200000,
            // 数据上行的 RX1/RX2 延迟是标准值 1s/2s。
            'receive_delay1' => 1000,
            'receive_delay2' => 2000,
            'join_accept_delay1' => 5000,
            'join_accept_delay2' => 6000,
            'rx1_dr_offset' => 0,
            'cf_list' => null,
            'max_ul_dr' => 5,
            // OTAA 入网后的 RX2 频点 = A20 设备级表 RegionCN470A20OtaaFrequencies[]，
            // 与 Join idx 绑定。2026-09-04 对照 RP002-1.0.1 §2.9.7.1 Table 52 官方值修正：
            //   idx0=485.3 / idx1=486.9 / idx2=488.5 / idx3=490.1
            //   idx4=491.7 / idx5=493.3 / idx6=494.0 / idx7=496.5
            // （idx6 规范印 494.9，SDK 实现为 494.0，两端统一用 494.0。）
            // 本工程 Join 走 idx1 → RX2/RX_C = 486.9（与 ABP 默认同值）。
            'rx2_frequency_otaa' => [485.3, 486.9, 488.5, 490.1, 491.7, 493.3, 494.0, 496.5],

            // Join 信道表（[上行频点 MHz, 下行 RX1 频点 MHz]）。
            // 2026-09-03：79 工程把协议常量 FIRST_TX1 改成了 481.5（ch0..7 = 481.5..482.9），
            // 公共 Join 表 idx 0..3 上行也跟着改为 481.5/481.7/481.9/482.1。
            // 设备掩码 {0x0002,0x0000} 只开 idx 1 → Join 阶段发 481.7，期望 RX1=486.1。
            // 下列 8 条对应 idx 0~7（A20 计划 + 79 工程改后上行段）：
            'join_channels' => [
                [481.5, 484.5], [481.7, 486.1], [481.9, 487.7], [482.1, 489.3],
                [504.1, 490.9], [505.7, 492.5], [507.3, 494.1], [508.9, 495.7],
            ],
            'rx1' => [
                // A20 计划：双段上行 ch0..63，下行按 (ch 与 31) 映射。
                'type' => 'cn470_a20',
                'ul_start' => 470.3, 'ul_step' => 0.2, 'ul_count' => 64,
                'ul_start2' => 503.5, 'dl_split' => 32,
                'dl_start' => 483.9, 'dl_start2' => 490.3, 'dl_step' => 0.2,
            ],
            // Class C 的 RX_C 频点改用「设备级 rx2_frequency」（即 OTAA 入网后存的
            // otaaFrequencies[joinChannelIndex] 值），不再固定走「区域默认 RX2」。
            // 原因：CN470 A20 设备入网后，Class A RX2 / Class C RX_C 都是设备按
            // RegionCN470A20GetRx2Frequency(joinIdx, true) 拿的设备级值（如 79 工程 idx1=486.1），
            // 而 NS 区域默认 rx2_frequency=486900000，差 0.8 MHz → Class C 永远收不到。
            // 2026-09-04 改：true → false，让 Class C 走设备级（与设备 RX_C 完美对齐）。
            'rx2_class_c_ignores_device' => false,
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
            // 2026-09-03：79 工程把协议常量 FIRST_TX1 改成了 481.5（ch0..7 = 481.5..482.9），
            // 让设备能发到网关 UG67 8 频点。但 A20 默认 RX1 公式按 ul_start=470.3 算，
            // 收到 481.5 时算 ch=56→RX1=495.1（与设备按 A20 公式期望的 483.9 错位 11.2 MHz，
            // 设备 RX1 永远 timeout）。
            // 修复：检测 ul ∈ [481.5, 482.9] 时直接返回 A20 公式 ch0..7 对应的 RX1
            //   ch0=481.5 → 483.9, ch1=481.7 → 484.1, ..., ch7=482.9 → 485.3
            // 公式 RX1 = 483.9 + (ul - 481.5) / 0.2 * 0.2 = 481.5 + 2.4 + (ul-481.5)
            //        = ul + 2.4  （RX1 比 UL 高 2.4 MHz，匹配 A20 上下行偏移）
            if ($ulFreqMHz >= 481.0 && $ulFreqMHz < 483.0) {
                return round($ulFreqMHz + 2.4, 1);
            }
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

        if ($type === 'cn470_b26') {
            // B26 计划：单段上行 480.3 + ch*0.2 (ch0..47)；下行 500.1 + (ch%24)*0.2。
            $ch = (int) round(($ulFreqMHz - (float) $r['ul_start']) / (float) $r['ul_step']);
            $ch = max(0, min($ch, 47));
            $dlIdx = $ch % 24;
            return (float) $r['dl_start'] + $dlIdx * (float) $r['dl_step'];
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

