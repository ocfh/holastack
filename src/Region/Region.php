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

    /** 返回频段详细信息（用于前端展示/设备创建时选择）。 */
    public static function allDetails(): array
    {
        $out = [];
        foreach (self::$REGIONS as $name => $cfg) {
            $out[$name] = [
                'rx2_frequency' => $cfg['rx2_frequency'],
                'rx2_dr' => $cfg['rx2_dr'],
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
    public function getReceiveDelay1(): int { return (int) $this->cfg['receive_delay1']; }
    public function getReceiveDelay2(): int { return (int) $this->cfg['receive_delay2']; }
    public function getJoinAcceptDelay1(): int { return (int) $this->cfg['join_accept_delay1']; }
    public function getJoinAcceptDelay2(): int { return (int) $this->cfg['join_accept_delay2']; }
    public function getMaxUlDr(): int { return (int) $this->cfg['max_ul_dr']; }
    public function getCfList() { return $this->cfg['cf_list']; }
}

