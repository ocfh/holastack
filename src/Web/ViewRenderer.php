<?php
namespace holastack\Web;







class ViewRenderer
{
    private static function regionOptions(): string
    {
        $list = [
            'CN470' => 'CN470（中国 470MHz）',
            'CN779' => 'CN779（中国 779MHz）',
            'EU868' => 'EU868（欧洲 868MHz）',
            'US915' => 'US915（美国 915MHz）',
            'AU915' => 'AU915（澳洲 915MHz）',
            'AS923' => 'AS923（亚太 923MHz）',
            'KR920' => 'KR920（韩国 920MHz）',
            'IN865' => 'IN865（印度 865MHz）',
            'RU864' => 'RU864（俄罗斯 864MHz）',
            'EU433' => 'EU433（欧洲 433MHz）',
        ];
        $out = '';
        foreach ($list as $v => $label) {
            $out .= '<option value="' . $v . '">' . elw_t($label) . '</option>';
        }
        return $out;
    }

    private static function crOptions(): string
    {
        return '<option value="1" selected>4/5</option><option value="2">4/6</option>'
            . '<option value="3">4/7</option><option value="4">4/8</option>';
    }

    

    

    

    public static function renderLoraCalc(): string
    {
        $t = 'elw_t';
        $ro = self::regionOptions();
        $cr = self::crOptions();
        return <<<HTML
<div class="loracalc">
  <h2>{$t('LoRa / LoRaWAN 参数计算器')}</h2>
  <p class="hint">{$t('参照 Semtech LoRa Calculator · 中文 · 双计算器 · 所有计算均在浏览器本地完成，不上传任何数据。')}</p>
  <div class="tabs">
    <div class="tab active" id="tabPhy" onclick="Lc_switchTab('phy')">{$t('LoRa 计算器（物理层）')}</div>
    <div class="tab" id="tabLw" onclick="Lc_switchTab('lw')">{$t('LoRaWAN 计算器（能耗 / 占空比）')}</div>
  </div>

  <!-- TAB 1: LoRa (PHY) -->
  <div id="panelPhy">
    <div class="grid">
      <div class="panel">
        <h2>{$t('输入参数（物理层）')}</h2>
        <p class="hint">{$t('修改任意参数结果即时更新。')}</p>
        <fieldset>
          <legend>{$t('频段与频率')}</legend>
          <label>{$t('频段 / 区域')}</label>
          <select id="p_region" onchange="Lc_applyRegion('p')">
            $ro
          </select>
          <label>{$t('中心频率（MHz）')}</label>
          <input id="p_freq" type="number" step="0.0001" value="470.3">
        </fieldset>
        <fieldset>
          <legend>{$t('调制参数')}</legend>
          <div class="row">
            <div><label>{$t('带宽 BW（kHz）')}</label>
              <select id="p_bw">
                <option>7.8</option><option>10.4</option><option>15.6</option><option>20.8</option>
                <option>31.25</option><option>41.7</option><option>62.5</option>
                <option selected>125</option><option>250</option><option>500</option>
              </select></div>
            <div><label>{$t('扩频因子 SF')}</label>
              <select id="p_sf">
                <option>7</option><option>8</option><option>9</option><option>10</option>
                <option>11</option><option selected>12</option>
              </select></div>
            <div><label>{$t('编码率 CR')}</label>
              <select id="p_cr">$cr</select></div>
          </div>
          <div class="row">
            <div><label>{$t('前导码长度（符号）')}</label>
              <input id="p_preamble" type="number" step="1" value="8" min="1"></div>
            <div><label>{$t('数据包长度（字节）')}</label>
              <input id="p_payload" type="number" step="1" value="12" min="0"></div>
          </div>
          <div class="check"><input id="p_crc" type="checkbox" checked onchange="Lc_pCalc()"><label for="p_crc">{$t('CRC 校验开启')}</label></div>
          <div class="check"><input id="p_implicit" type="checkbox" onchange="Lc_pCalc()"><label for="p_implicit">{$t('隐式报头（Implicit Header）')}</label></div>
          <div class="check"><input id="p_ldro" type="checkbox" checked onchange="Lc_pCalc()"><label for="p_ldro">{$t('低速率优化 LDRO（符号时长 > 16ms 建议开启）')}</label></div>
        </fieldset>
        <fieldset>
          <legend>{$t('射频与功耗')}</legend>
          <div class="row">
            <div><label>{$t('发射功率 TX（dBm）')}</label><input id="p_txpwr" type="number" step="0.1" value="17"></div>
            <div><label>{$t('发射电流（mA）')}</label><input id="p_itx" type="number" step="0.1" value="30"></div>
            <div><label>{$t('接收电流（mA）')}</label><input id="p_irx" type="number" step="0.1" value="5"></div>
          </div>
          <div class="row">
            <div><label>{$t('发射天线增益（dBi）')}</label><input id="p_gtx" type="number" step="0.1" value="0"></div>
            <div><label>{$t('接收天线增益（dBi）')}</label><input id="p_grx" type="number" step="0.1" value="3"></div>
            <div><label>{$t('噪声系数 NF（dB）')}</label><input id="p_nf" type="number" step="0.1" value="6"></div>
          </div>
          <div class="row">
            <div><label>{$t('供电电压（V）')}</label><input id="p_volt" type="number" step="0.1" value="3.3"></div>
            <div><label>{$t('衰落余量（dB）')}</label><input id="p_margin" type="number" step="1" value="0"></div>
            <div><label>{$t('传播模型（n）')}</label>
              <select id="p_model" onchange="Lc_pSyncN()">
                <option value="2.0">{$t('自由空间 (n=2.0)')}</option>
                <option value="2.4">{$t('开阔地 / 农村 (n=2.4)')}</option>
                <option value="2.7" selected>{$t('郊区 (n=2.7)')}</option>
                <option value="3.0">{$t('城市 (n=3.0)')}</option>
                <option value="3.5">{$t('密集城市 (n=3.5)')}</option>
                <option value="custom">{$t('自定义…')}</option>
              </select></div>
          </div>
          <div class="row" id="p_nrow" style="display:none">
            <div><label>{$t('自定义路径损耗指数 n')}</label><input id="p_nval" type="number" step="0.1" value="2.7" oninput="Lc_pCalc()"></div>
          </div>
        </fieldset>
        <button class="calc" onclick="Lc_pCalc()">{$t('计算')}</button>
      </div>

      <div class="panel">
        <h2>{$t('计算结果（物理层）')}</h2>
        <p class="hint">{$t('基于 Semtech AN1200.22 空中时间公式与链路预算模型。')}</p>
        <div class="results">
          <div class="stat big"><div class="k">{$t('空中时间 Time on Air')}</div>
            <div class="v"><span id="p_toa">—</span><span class="u" id="p_toaUnit"></span></div></div>
          <div class="stat"><div class="k">{$t('符号时长 T_sym')}</div><div class="v"><span id="p_tsym">—</span><span class="u">{$t('ms')}</span></div></div>
          <div class="stat"><div class="k">{$t('总符号数')}</div><div class="v"><span id="p_syms">—</span><span class="u">{$t('sym')}</span></div></div>
          <div class="stat"><div class="k">{$t('前导码时长')}</div><div class="v"><span id="p_preambleDur">—</span><span class="u">{$t('ms')}</span></div></div>
          <div class="stat"><div class="k">{$t('有效数据速率')}</div><div class="v"><span id="p_dr">—</span><span class="u" id="p_drUnit"></span></div></div>
          <div class="stat"><div class="k">{$t('最大晶振容差')}</div><div class="v"><span id="p_xtal">—</span><span class="u">{$t('ppm')}</span></div></div>
          <div class="stat"><div class="k">{$t('接收灵敏度')}</div><div class="v"><span id="p_sens">—</span><span class="u">{$t('dBm')}</span></div></div>
          <div class="stat"><div class="k">{$t('链路预算')}</div><div class="v"><span id="p_lb">—</span><span class="u">{$t('dB')}</span></div></div>
          <div class="stat"><div class="k">{$t('TX 功耗 / RX 功耗')}</div><div class="v"><span id="p_pwr">—</span><span class="u">{$t('mW')}</span></div></div>
          <div class="stat big"><div class="k">{$t('理论最大通信距离（估算）')}</div>
            <div class="v"><span id="p_dist">—</span><span class="u" id="p_distUnit"></span></div></div>
        </div>
        <div class="note">
          <span class="pill">{$t('公式')}</span>
          {$t('空中时间')} <code>ToA = (Npreamble + 4.25 + Npayload) · T_sym</code>，<code>T_sym = 2^SF / BW</code>。<br>
          <span class="pill">{$t('灵敏度')}</span>
          <code>Sens = -174 + 10·log10(BW) + NF + SNR_min</code>（SF7..12 最小 SNR = −7.5/−10/−12.5/−15/−17.5/−20 dB）。<br>
          <span class="pill">{$t('晶振容差')}</span>
          {$t('解调对频偏容忍约 ±25% 带宽 →')} <code>ppm = 0.25·BW / f × 1e6</code>（收发两端各占一半）。<br>
          <span class="pill">{$t('距离')}</span>
          <code>d = 10^((LB − 32.45 − 20·log10(f_MHz)) / (10·n))</code> km，{$t('为理论上限，实测需预留衰落余量。')}
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 2: LoRaWAN (network / energy) -->
  <div id="panelLw" class="hidden">
    <div class="grid">
      <div class="panel">
        <h2>{$t('输入参数（LoRaWAN 网络层）')}</h2>
        <p class="hint">{$t('修改任意参数结果即时更新。数据率（DR）按区域自动映射 SF/BW。')}</p>
        <fieldset>
          <legend>{$t('LoRaWAN')}</legend>
          <div class="row">
            <div><label>{$t('区域 Region')}</label>
              <select id="l_region" onchange="Lc_lRegionChange()">
                <option value="CN470">CN470</option><option value="CN779">CN779</option>
                <option value="EU868">EU868</option><option value="US915">US915</option>
                <option value="AU915">AU915</option><option value="AS923">AS923</option>
                <option value="KR920">KR920</option><option value="IN865">IN865</option>
                <option value="RU864">RU864</option><option value="EU433">EU433</option>
              </select></div>
            <div><label>{$t('数据率 DR（上行）')}</label><select id="l_dr" onchange="Lc_lCalc()"></select></div>
            <div><label>{$t('RX2 数据率')}</label><select id="l_drRx2" onchange="Lc_lCalc()"></select></div>
          </div>
          <div class="row">
            <div><label>{$t('ADR')}</label><select id="l_adr"><option value="1" selected>{$t('开启')}</option><option value="0">{$t('关闭')}</option></select></div>
            <div><label>{$t('RX 延迟（s）')}</label><input id="l_rxdelay" type="number" step="0.1" value="1"></div>
            <div><label>{$t('Class')}</label><select id="l_class"><option value="A" selected>A</option><option value="B">B</option><option value="C">C</option></select></div>
          </div>
          <div class="row" id="l_classB" style="display:none">
            <div><label>{$t('Beacon 前导码长度')}</label><input id="l_beaconPre" type="number" value="8"></div>
            <div><label>{$t('Ping 时隙下行概率(%)')}</label><input id="l_pingProb" type="number" step="0.1" value="10"></div>
            <div><label>{$t('Beacon 周期')}</label><select id="l_beaconPer"><option value="128">128 s</option><option value="64">64 s</option><option value="32">32 s</option><option value="16">16 s</option><option value="8">8 s</option></select></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>{$t('上行包 Uplink')}</legend>
          <div class="row">
            <div><label>{$t('负载长度（字节）')}</label><input id="l_pl" type="number" value="12" min="0"></div>
            <div><label>{$t('重传次数')}</label><input id="l_retrans" type="number" value="0" min="0"></div>
            <div><label>{$t('上行间隔（s）')}</label><input id="l_interval" type="number" value="900" min="1"></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>{$t('下行 Downlink')}</legend>
          <div class="row">
            <div><label>{$t('RX 负载长度（字节）')}</label><input id="l_rxpl" type="number" value="8" min="0"></div>
            <div><label>{$t('RX 前导码（符号）')}</label><input id="l_rxpreamble" type="number" value="8"></div>
            <div><label>{$t('每日下行数')}</label><input id="l_dlday" type="number" value="2" min="0"></div>
            <div><label>{$t('RX1 占比(%)')}</label><input id="l_rx1pct" type="number" value="50" min="0" max="100"></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>{$t('功耗与电池')}</legend>
          <div class="row">
            <div><label>{$t('TX 电流（mA）')}</label><input id="l_itx" type="number" step="0.1" value="30"></div>
            <div><label>{$t('RX 电流（mA）')}</label><input id="l_irx" type="number" step="0.1" value="5"></div>
            <div><label>{$t('休眠电流（µA）')}</label><input id="l_isleep" type="number" step="0.1" value="1"></div>
          </div>
          <div class="row">
            <div><label>{$t('供电电压（V）')}</label><input id="l_volt" type="number" step="0.1" value="3.3"></div>
            <div><label>{$t('电池容量（mAh）')}</label><input id="l_batt" type="number" value="2400" min="1"></div>
            <div><label>{$t('衰落余量（dB）')}</label><input id="l_margin" type="number" step="1" value="0"></div>
          </div>
          <div class="row">
            <div><label>{$t('传播模型（n）')}</label>
              <select id="l_model" onchange="Lc_lSyncN()">
                <option value="2.0">{$t('自由空间 (n=2.0)')}</option>
                <option value="2.4">{$t('开阔地 / 农村 (n=2.4)')}</option>
                <option value="2.7" selected>{$t('郊区 (n=2.7)')}</option>
                <option value="3.0">{$t('城市 (n=3.0)')}</option>
                <option value="3.5">{$t('密集城市 (n=3.5)')}</option>
                <option value="custom">{$t('自定义…')}</option>
              </select></div>
            <div id="l_nwrap" style="display:none"><label>{$t('自定义 n')}</label><input id="l_nval" type="number" step="0.1" value="2.7" oninput="Lc_lCalc()"></div>
            <div><label>{$t('TX 功率（dBm）')}</label><input id="l_txpwr" type="number" step="0.1" value="17"></div>
          </div>
        </fieldset>
        <button class="calc" onclick="Lc_lCalc()">{$t('计算')}</button>
      </div>

      <div class="panel">
        <h2>{$t('计算结果（LoRaWAN）')}</h2>
        <p class="hint">{$t('能耗与占空比基于周期平均模型估算。')}</p>
        <div class="results">
          <div class="stat big"><div class="k">{$t('单次上行空中时间')}</div>
            <div class="v"><span id="l_toa">—</span><span class="u" id="l_toaUnit"></span></div></div>

          <div class="stat"><div class="k">{$t('设备 TX 电流')}</div><div class="v"><span id="l_itx_out">—</span><span class="u">{$t('mA')}</span></div></div>
          <div class="stat"><div class="k">{$t('设备 RX 电流')}</div><div class="v"><span id="l_irx_out">—</span><span class="u">{$t('mA')}</span></div></div>
          <div class="stat"><div class="k">{$t('平均 TX 功耗')}</div><div class="v"><span id="l_avgTx">—</span><span class="u">{$t('µA')}</span></div></div>
          <div class="stat"><div class="k">{$t('平均 RX 功耗')}</div><div class="v"><span id="l_avgRx">—</span><span class="u">{$t('µA')}</span></div></div>
          <div class="stat"><div class="k">{$t('平均休眠功耗')}</div><div class="v"><span id="l_avgSleep">—</span><span class="u">{$t('µA')}</span></div></div>
          <div class="stat"><div class="k">{$t('总平均功耗')}</div><div class="v"><span id="l_avgTot">—</span><span class="u">{$t('µA')}</span></div></div>

          <div class="stat"><div class="k">{$t('每小时上行 ToA')}</div><div class="v"><span id="l_toaH_tx">—</span><span class="u">{$t('ms/h')}</span></div></div>
          <div class="stat"><div class="k">{$t('每小时下行 ToA')}</div><div class="v"><span id="l_toaH_rx">—</span><span class="u">{$t('ms/h')}</span></div></div>
          <div class="stat warnv"><div class="k">{$t('占空比 (TX)')}</div><div class="v"><span id="l_duty">—</span><span class="u">{$t('%')}</span></div></div>
          <div class="stat"><div class="k">{$t('链路预算')}</div><div class="v"><span id="l_lb">—</span><span class="u">{$t('dB')}</span></div></div>

          <div class="stat"><div class="k">{$t('接收灵敏度')}</div><div class="v"><span id="l_sens">—</span><span class="u">{$t('dBm')}</span></div></div>
          <div class="stat big"><div class="k">{$t('理论最大通信距离')}</div><div class="v"><span id="l_dist">—</span><span class="u" id="l_distUnit"></span></div></div>
          <div class="stat big"><div class="k">{$t('电池寿命（估算）')}</div><div class="v"><span id="l_battlife">—</span><span class="u" id="l_battUnit"></span></div></div>
        </div>
        <div class="note">
          <span class="pill">{$t('能耗模型')}</span>
          {$t('周期 = 上行间隔；周期内：TX 时长 = (1+重传)·上行ToA；RX 时长 = 每周期下行数 × (RX1占比·RX1 ToA + (1−占比)·RX2 ToA)；休眠时长 = 间隔 − TX − RX。')}<br>
          {$t('平均电流')} <code>I_avg = (I_tx·t_tx + I_rx·t_rx + I_sleep·t_sleep) / 间隔</code>；
          {$t('电池寿命')} <code>= 容量(mAh)·1000 / I_avg(µA) / 24 / 365</code> {$t('年')}。<br>
          <span class="pill">{$t('占空比')}</span>
          <code>= 每小时 TX ToA / 3600 × 100%</code>。{$t('EU868 等区域法规上限通常为 1%（请结合实际区域核对）。')}<br>
          <span class="pill">{$t('说明')}</span>
          {$t('DR 由区域决定 SF/BW（US915/AU915 的 RX2 为 500kHz SF12）。结果为理想链路预算上限，实际部署受环境衰减影响。')}
        </div>
      </div>
    </div>
  </div>
HTML;
    }

    

    

    

    public static function renderApiDocs(): string
    {
        $t = 'elw_t';
        $groups = self::apiGroups();
        

        $side = '';
        $main = '';
        $first = true;
        foreach ($groups as $g) {
            $side .= '<h4>' . $t($g['title']) . '</h4>';
            $groupApis = '';
            foreach ($g['apis'] as $a) {
                $groupApis .= '<button class="ad-item" data-ad="' . htmlspecialchars($a['id'], ENT_QUOTES) . '" onclick="adSelect(\'' . htmlspecialchars($a['id'], ENT_QUOTES) . '\')">'
                    . '<span class="ad-method m-' . strtolower($a['method']) . '">' . $a['method'] . '</span>'
                    . '<span>' . $t($a['title']) . '</span></button>';
                $main .= self::adDetail($a, $first);
                $first = false;
            }
            $side .= $groupApis;
        }

        $pageTitle = '<h2>' . $t('API 文档') . '</h2>';
        $intro = '<p class="ad-note" style="margin-top:2px">'
            . $t('所有 API 统一以 /api 为前缀。认证方式（二选一）：')
            . '<code>Grpc-Metadata-Authorization: Bearer &lt;token&gt;</code>'
            . $t('（会话 token 经 /api/login 获取，或应用 API Key，或请求头')
            . ' <code>Authorization: Bearer &lt;token&gt;</code>'
            . $t('）。API Key 在「应用 → API Key」中创建，') . '<b>' . $t('明文仅显示一次') . '</b>' . $t('，请妥善保存。')
            . '</p>';
        return $pageTitle . $intro . <<<HTML
<div class="apidocs">
  <div class="ad-side" id="adSide">
    $side
  </div>
  <div class="ad-main">
    $main
  </div>
</div>
HTML;
    }

    private static function setting(string $key, string $default = ''): string
    {
        try {
            return Setting::get($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function adDetail(array $a, bool $active): string
    {
        $t = 'elw_t';
        

        $paramsSec = '';
        if (!empty($a['params'])) {
            $paramsRows = '';
            foreach ($a['params'] as $p) {
                $paramsRows .= '<tr><td><code>' . htmlspecialchars($p['name'], ENT_QUOTES) . '</code></td><td>' . $t($p['in']) . '</td><td>' . $p['type'] . '</td><td>'
                    . ($p['required'] ? '<span class="tag err">' . $t('必填') . '</span>' : '<span class="tag off">' . $t('可选') . '</span>')
                    . '</td><td class="ad-note">' . $t($p['desc']) . '</td></tr>';
            }
            $paramsSec = '<div class="ad-sec"><h3>' . $t('请求参数') . '</h3><table class="ad-tbl"><thead><tr><th>' . $t('参数') . '</th><th>' . $t('位置') . '</th><th>' . $t('类型') . '</th><th>' . $t('必填') . '</th><th>' . $t('说明') . '</th></tr></thead><tbody>' . $paramsRows . '</tbody></table></div>';
        }
        

        $respRows = '';
        if (!empty($a['respFields'])) {
            foreach ($a['respFields'] as $f) {
                $respRows .= '<tr><td><code>' . htmlspecialchars($f['name'], ENT_QUOTES) . '</code></td><td>' . $f['type'] . '</td><td class="ad-note">' . $t($f['desc']) . '</td></tr>';
            }
        } else {
            $respRows = '<tr><td colspan="3" class="ad-note">—</td></tr>';
        }
        

        $errRows = '';
        if (!empty($a['errors'])) {
            foreach ($a['errors'] as $e) {
                $errRows .= '<tr><td><code>' . htmlspecialchars($e['code'], ENT_QUOTES) . '</code></td><td class="ad-note">' . $t($e['desc']) . '</td></tr>';
            }
        } else {
            $errRows = '<tr><td colspan="2" class="ad-note">—</td></tr>';
        }
        

        $curl = self::adCurl($a);
        $body = self::adCode($curl);
        $copyBtn = self::adCopyBtn();
        

        $respJson = json_encode($a['respExample'] ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $respExample = self::adCode($respJson);
        $bodyBlock = ($a['method'] === 'POST' && !empty($a['sample']))
            ? '<div class="ad-sec"><h3>' . $t('请求体 (JSON)') . '</h3>' . self::adCode(json_encode($a['sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</div>'
            : '';

        $mcls = strtolower($a['method']);
        $hide = $active ? '' : ' hidden';
        return <<<HTML
<div class="ad-detail$hide" id="ad-$a[id]" data-ad="$a[id]">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="ad-method m-$mcls">{$a['method']}</span>
    <h2 style="margin:0">{$t($a['title'])}</h2>
  </div>
  <p class="ad-note" style="margin-top:6px">{$t($a['desc'])}</p>
  <div class="ad-path"><code>{$a['method']} {$a['path']}</code>$copyBtn</div>
  $paramsSec
  $bodyBlock
  <div class="ad-sec"><h3>{$t('请求示例')}</h3>$body</div>
  <div class="ad-sec"><h3>{$t('响应字段')}</h3><table class="ad-tbl"><thead><tr><th>{$t('字段')}</th><th>{$t('类型')}</th><th>{$t('说明')}</th></tr></thead><tbody>$respRows</tbody></table></div>
  <div class="ad-sec"><h3>{$t('响应示例')}</h3>$respExample</div>
  <div class="ad-sec"><h3>{$t('错误码')}</h3><table class="ad-tbl"><thead><tr><th>{$t('HTTP / 错误')}</th><th>{$t('说明')}</th></tr></thead><tbody>$errRows</tbody></table></div>
</div>
HTML;
    }

    private static function adCode(string $text): string
    {
        return '<div class="ad-req"><code>' . htmlspecialchars($text, ENT_QUOTES) . '</code>' . self::adCopyBtn() . '</div>';
    }

    private static function adCopyBtn(): string
    {
        return '<button class="ad-copy" onclick="adCopyFrom(this)">' . elw_t('复制') . '</button>';
    }

    private static function adCurl(array $a): string
    {
        $base = trim(self::setting('api_base_url', ''));
        $url = ($base !== '' ? rtrim($base, '/') : 'https://your-server.example.com') . $a['path'];
        if ($a['method'] === 'POST') {
            $sample = json_encode($a['sample'] ?? (object)[], JSON_UNESCAPED_UNICODE);
            return 'curl -X POST "' . $url . "\" \\\n  -H \"Authorization: Bearer <YOUR_API_KEY>\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . $sample . "'";
        }
        return 'curl -X ' . $a['method'] . ' "' . $url . "\" \\\n  -H \"Authorization: Bearer <YOUR_API_KEY>\"";
    }

    public static function apiGroups(): array
    {
        return self::apiPlatformGroups();
    }


    /** /api/* 平台 API 文档（会话 token 或应用 API Key） */
    private static function apiPlatformGroups(): array
    {
        $stdErr = static fn() => [
            ['code' => '401 unauthorized', 'desc' => '未认证（缺 Grpc-Metadata-Authorization 头）'],
            ['code' => '403 forbidden', 'desc' => '权限不足'],
            ['code' => '404 not_found (code 5)', 'desc' => '资源不存在'],
            ['code' => '400 invalid_argument (code 3)', 'desc' => '请求参数错误'],
        ];
        $t = static fn(string $id, string $method, string $path, string $title, string $desc, array $extra = []) => array_merge([
            'id' => $id, 'method' => $method, 'path' => $path, 'title' => $title, 'desc' => $desc,
            'params' => $extra['params'] ?? [],
            'sample' => $extra['sample'] ?? null,
            'respFields' => $extra['respFields'] ?? [],
            'respExample' => $extra['respExample'] ?? null,
            'errors' => $extra['errors'] ?? $stdErr(),
        ], []);
        $listEx = static fn(array $r) => ['totalCount' => 1, 'result' => [$r]];

        return [
            [
                'title' => 'API 概览与认证',
                'apis' => [
                    [
                        'id' => 'cs-overview', 'method' => 'GET', 'path' => '/api/…',
                        'title' => 'API 约定（必读）',
                        'desc' => 'holastack 的 /api/* 全量采用统一 REST 形状，可从任意标准客户端直接调用。约定：①认证头 Grpc-Metadata-Authorization: Bearer <token>（登录 token 或 API Key）；②列表一律 {totalCount, result}，支持 limit/offset/search（limit=0 仅返回 totalCount）；③id 为 UUID 形态 00000000-0000-0000-0000-<12hex>（设备路径同时接受 16 hex DevEUI）；④时间 RFC3339 UTC（零值 1970-01-01T00:00:00Z）；⑤错误 {error, code, message, details}（错误码：3=INVALID_ARGUMENT, 5=NOT_FOUND, 6=ALREADY_EXISTS, 7=PERMISSION_DENIED, 12=UNIMPLEMENTED, 13=INTERNAL）；⑥POST create 返回 200 + {id}（devices/gateways 返回 {}）；⑦字段统一 camelCase。holastack 扩展字段（numericId/region/online/appEui/callbackUrl 等）与标准字段并存，不影响标准客户端。',
                        'params' => [
                            ['name' => 'Grpc-Metadata-Authorization', 'in' => 'header', 'type' => 'string', 'required' => true, 'desc' => 'Bearer <token>（/api/login 获取的会话 token，或 API Key）'],
                            ['name' => 'limit / offset / search', 'in' => 'query', 'type' => 'int/string', 'required' => false, 'desc' => '通用分页与搜索参数（列表端点均支持）'],
                        ],
                        'respFields' => [
                            ['name' => '{totalCount, result}', 'type' => 'object', 'desc' => '列表统一形状'],
                            ['name' => '{error, code, message, details}', 'type' => 'object', 'desc' => '错误统一形状'],
                        ],
                        'respExample' => ['totalCount' => 1, 'result' => ['id' => '00000000-0000-0000-0000-000000000001', 'name' => 'demo']],
                        'errors' => $stdErr(),
                    ],
                    [
                        'id' => 'cs-login', 'method' => 'POST', 'path' => '/api/login',
                        'title' => '登录获取 token',
                        'desc' => '用户名密码换取会话 token。返回的 token 用于 Grpc-Metadata-Authorization: Bearer <token>。注意：同用户重复登录会使旧 token 失效。',
                        'params' => [
                            ['name' => 'username', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '用户名'],
                            ['name' => 'password', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '密码'],
                        ],
                        'sample' => ['username' => 'admin', 'password' => '******'],
                        'respFields' => [
                            ['name' => 'ok', 'type' => 'bool', 'desc' => '登录成功'],
                            ['name' => 'token', 'type' => 'string', 'desc' => '会话 token（64 hex）'],
                            ['name' => 'user.role', 'type' => 'string', 'desc' => 'admin / tenant / operator'],
                            ['name' => 'user.permissions', 'type' => 'string[]', 'desc' => '权限点列表'],
                        ],
                        'respExample' => ['ok' => true, 'token' => '6e34…', 'user' => ['id' => 2, 'username' => 'admin', 'role' => 'admin', 'permissions' => ['dashboard', 'devices']]],
                        'errors' => [['code' => '401 invalid credentials', 'desc' => '用户名或密码错误']],
                    ],
                ],
            ],
            [
                'title' => '设备管理',
                'apis' => [
                    $t('cs-devices', 'GET', '/api/devices?limit=10&offset=0&search=&tenantId=&applicationId=&deviceProfileId=', '列出设备 List',
                        '返回 {totalCount, result:[apiDeviceListItem]}。过滤参数均 camelCase（兼容 snake_case）。', [
                        'respFields' => [
                            ['name' => 'result[].id', 'type' => 'string(UUID)', 'desc' => '设备 UUID'],
                            ['name' => 'result[].devEui', 'type' => 'string', 'desc' => '16 hex DevEUI'],
                            ['name' => 'result[].name / applicationId / deviceProfileId', 'type' => 'string', 'desc' => '基础字段（UUID 形态外键）'],
                            ['name' => 'result[].lastSeenAt', 'type' => 'string(RFC3339)', 'desc' => '最近上线时间，零值 1970-…Z'],
                        ],
                        'respExample' => $listEx(['id' => '00000000-0000-0000-0000-000000000003', 'devEui' => '0011223344556677', 'name' => 'node-01', 'createdAt' => '2026-09-04T08:45:32Z', 'lastSeenAt' => '1970-01-01T00:00:00Z']),
                    ]),
                    $t('cs-device-get', 'GET', '/api/devices/{devEui}', '获取设备 Get',
                        '路径参数支持 16 hex DevEUI 或设备 UUID。返回 {device:{…}}，扩展字段 numericId/devAddr/activation/region/nwkKey/appKey 一并返回。'),
                    $t('cs-device-create', 'POST', '/api/devices', '创建设备 Create',
                        'body 为 {device:{…}} 包装。OTAA 必填 keys:{appKey(32hex), nwkKey(32hex)} 与 joinEui(16hex，缺省时自动填 0101010101010101)；deviceProfileId/applicationId 为 UUID。返回 {}。', [
                        'sample' => ['device' => ['name' => 'node-01', 'devEui' => '0011223344556677', 'applicationId' => '00000000-0000-0000-0000-000000000008', 'deviceProfileId' => '00000000-0000-0000-0000-000000000004', 'joinEui' => '0101010101010101', 'keys' => ['nwkKey' => '2b7e151628aed2a6abf7158809cf4f3c', 'appKey' => '2b7e151628aed2a6abf7158809cf4f3c']]],
                        'errors' => [
                            ['code' => '400 (code 3)', 'desc' => '参数错误：app_key/join_eui 长度、模板缺失等'],
                            ['code' => '409 (code 6)', 'desc' => 'DevEUI 已存在'],
                        ],
                    ]),
                    $t('cs-device-update', 'PUT', '/api/devices/{devEui}', '修改设备 Update',
                        'body {device:{…}} 部分字段更新。'),
                    $t('cs-device-delete', 'DELETE', '/api/devices/{devEui}', '删除设备 Delete',
                        '删除设备及其上行/下行/关联记录。返回 {}。'),
                    $t('cs-device-queue-post', 'POST', '/api/devices/{devEui}/queue', '下行入队 Enqueue',
                        '标准入队路径。body {deviceQueueItem:{fPort:1..223, data(Base64), confirmed}}（兼容扁平 queueItem 与 items[] 批量）。Class C 立即下发；Class A 等下次上行窗口。', [
                        'sample' => ['deviceQueueItem' => ['fPort' => 2, 'data' => 'aGVsbG8=', 'confirmed' => false]],
                        'respFields' => [['name' => 'id', 'type' => 'string(UUID)', 'desc' => '队列项 ID']],
                        'respExample' => ['id' => '00000000-0000-0000-0000-000000000006'],
                    ]),
                    $t('cs-device-queue-get', 'GET', '/api/devices/{devEui}/queue', '查看下行队列 GetQueue',
                        '返回 {totalCount, result:[{id, devEui, fPort, confirmed, isPending, fCntDown, data(Base64), expiresAt}]}。'),
                    $t('cs-device-queue-del', 'DELETE', '/api/devices/{devEui}/queue', '清空下行队列 FlushQueue',
                        '撤销该设备全部 pending 下行。DELETE /api/devices/{devEui}/queue/{queueItemId} 可撤销单条。'),
                    $t('cs-device-activate', 'POST', '/api/devices/{devEui}/activate', 'ABP 激活 Activate',
                        'body {deviceActivation:{devAddr(8hex), nwkSEncKey/sNwkSIKey/fNwkSIntKey/appSKey(32hex)}}。写入会话密钥并置 active。'),
                    $t('cs-device-activation', 'GET', '/api/devices/{devEui}/activation', '获取激活信息 GetActivation',
                        '返回 {deviceActivation:{devEui, devAddr, appSKey, fNwkSIntKey, sNwkSIntKey, nwkSEncKey, fCntUp, nFCntDown, aFCntDown}}。DELETE 同路径 = 清除会话（重置回 pending）。'),
                    $t('cs-device-keys', 'GET', '/api/devices/{devEui}/keys', '获取密钥 GetKeys',
                        '返回 {deviceKeys:{devEui, appKey, nwkKey, genAppKey}}。POST/PUT 同路径更新（{deviceKeys:{appKey, nwkKey}}，128-bit HEX），DELETE 清空。'),
                    $t('cs-device-fcnt', 'POST', '/api/devices/{devEui}/get-next-f-cnt-down', '取下一个下行帧计数 GetNextFCntDown',
                        'FCntDown 自增并返回。返回 {fCntDown:int}（仅 POST）。', ['respExample' => ['fCntDown' => 2]]),
                    $t('cs-device-devaddr', 'POST', '/api/devices/{devEui}/get-random-dev-addr', '取随机 DevAddr GetRandomDevAddr',
                        '返回 {devAddr:"8hex"}（最高位清 0，仅 POST）。', ['respExample' => ['devAddr' => '289c5eab']]),
                    $t('cs-device-nonces', 'DELETE', '/api/devices/{devEui}/dev-nonces', '清除 DevNonces',
                        '返回 {}（no-op，形状与标准约定一致）。'),
                    $t('cs-device-metrics', 'GET', '/api/devices/{devEui}/metrics', '设备指标 GetDeviceMetrics',
                        '近 24h 上行按小时聚合，metrics 形状 {rxPackets, rxPacketsPerDr, …:{name, kind:COUNTER, timestamps[], datasets[]}}。GET /link-metrics 同形状返回空指标。'),
                    $t('cs-device-link-metrics', 'GET', '/api/devices/{devEui}/link-metrics', '链路指标 GetDeviceLinkMetrics',
                        '标准形状 + 空数据集（暂无链路统计）。'),
                ],
            ],
            [
                'title' => '应用 / 模板 / 网关',
                'apis' => [
                    $t('cs-apps', 'GET', '/api/applications', '应用列表（List）',
                        '{totalCount, result:[apiApplication]}。GET 单体 {application:{…}}；POST {application:{name,…}} 返回 {id}；PUT/DELETE 同标准路径。扩展字段 appEui/callbackUrl/numericId。'),
                    $t('cs-app-tags', 'GET', '/api/applications/{id}/device-tags', '应用设备标签 DeviceTags',
                        '返回 {result:[{key, values}]}（聚合自设备 latest_fields 键名）。'),
                    $t('cs-app-profiles', 'GET', '/api/applications/{id}/device-profiles', '应用下模板列表',
                        'ListDeviceProfilesByApplication，返回 {totalCount, result:[apiDeviceProfileListItem]}。'),
                    $t('cs-app-integrations', 'GET', '/api/applications/{id}/integrations', '应用集成 Integrations',
                        'GET 列表 {totalCount, result:[{kind}]}；POST {integration:{kind:"HTTP"|"INFLUX_DB"|"THINGS_BOARD"|…（11 种）}} 创建；GET/PUT/DELETE /integrations/{kind} 单类型；POST /integrations/mqtt/certificate 返回空证书 {caCert:"", tlsCert:"", tlsKey:"", expiresAt}。'),
                    $t('cs-dps', 'GET', '/api/device-profiles', '设备模板列表',
                        '{totalCount, result:[apiDeviceProfileListItem]}（region/macVersion/regParamsRevision 已映射标准枚举）。POST {deviceProfile:{…}} → {id}。'),
                    $t('cs-dp-sub', 'GET', '/api/device-profiles/adr-algorithms', 'ADR 算法 / vendors / devices 目录',
                        '/device-profiles/adr-algorithms 返回内置算法；/vendors、/devices（vendor 目录）返回空列表；/device-profiles/{id}/devices/{vendorId}/{vendorProfileId} 返回 404（无目录数据）。'),
                    $t('cs-dpt', 'GET', '/api/device-profile-templates', '设备模板市场（DeviceProfileTemplates）',
                        'GET 列表返回空 {totalCount:0, result:[]}；POST/PUT 返回 501 unimplemented（不持久化市场模板）；GET/DELETE 单体 404。'),
                    $t('cs-gateways', 'GET', '/api/gateways', '网关列表（List）',
                        '{totalCount, result:[apiGatewayListItem]}；search 按 name/gatewayId。POST {gateway:{gatewayId, name, …}} 返回 {}；GET 单体 {gateway:{…}}；PUT/DELETE 同标准路径。'),
                    $t('cs-gw-metrics', 'GET', '/api/gateways/{gatewayId}/metrics', '网关指标 GetGatewayMetrics',
                        '近 24h RX/TX 按小时聚合（标准 metrics 形状）。/duty-cycle-metrics 返回空指标；POST /generate-certificate 返回 {}；GET /relay-gateways 列出 relay 网关。'),
                ],
            ],
            [
                'title' => '多播 / 租户 / 用户 / Relay',
                'apis' => [
                    $t('cs-mc', 'GET', '/api/multicast-groups', '多播组列表',
                        '{totalCount, result:[…]}。POST 创建、GET/PUT/DELETE 单体；POST /{id}/queue 或 /enqueue 入队（body {queueItem:{fPort, data(Base64)}}）返回 {fCnt}；GET /queue 返回 {items:[…]}；DELETE 清空队列；devices/gateways 子路由增删成员。'),
                    $t('cs-tenants', 'GET', '/api/tenants', '租户列表（List）',
                        '{totalCount, result:[apiTenant]}（admin only）。GET 单体；GET /{id}/users 列租户成员；POST /{id}/users 添加成员；/by-devaddr-prefix-overlap 返回空列表（标准形状）。'),
                    $t('cs-users', 'GET', '/api/users', '用户列表（List）',
                        '{totalCount, result:[apiUser]}（admin only）。POST/PUT/DELETE 同标准路径；POST /api/users/{userId}/password 修改密码（body {password}）。'),
                    $t('cs-relays', 'GET', '/api/relays', 'Relay 列表',
                        '映射 relay_gateways/relay_devices 表。GET /api/relays 列 relay 网关设备；GET /api/relays/{devEui}/devices 列 relay 下挂设备；POST 同路径添加下挂设备（body {deviceDevEui}）；DELETE /api/relays/{id} 删除。'),
                    $t('cs-misc', 'GET', '/api/api-keys | /api/uplinks | /api/downlinks | /api/me', '附加资源',
                        'api-keys/roles/departments/uplinks/downlinks/stats/regions 等沿用 {totalCount, result} 形状（api-keys 列表为 {data} 兼容形状），可在同一路由体系内直接使用。'),
                ],
            ],
            [
                'title' => '数据管理与平台功能',
                'apis' => [
                    $t('cs-thing-models', 'GET', '/api/thing-models?applicationId=', '物模型 Thing Models',
                        'GET 列表 {totalCount, result}；GET /{id} 返回 {thingModel:{…}}；POST {applicationId, name, fields…} 创建返回 {id}；PUT/DELETE /{id} 修改/删除。物模型定义设备上行字段的结构化描述，供看板自动解码。'),
                    $t('cs-device-readings', 'GET', '/api/device-readings?dev_id=&field=&from=&to=', '设备历史读数 Device Readings',
                        '按设备 + 字段名 + 时间范围（from/to Unix 秒）查询历史数据点，用于曲线绘制。'),
                    $t('cs-alert-rules', 'GET', '/api/alert-rules?applicationId=', '告警规则 Alert Rules',
                        'GET 列表；POST 创建（{applicationId, name, condition…}）返回 {id}；PUT/DELETE /{id}。规则命中后生成告警。'),
                    $t('cs-alerts', 'GET', '/api/alerts?scope=|active|counts&status=&devId=&limit=&offset=', '告警 Alerts',
                        '默认列表 {totalCount, result, counts}；?scope=active 只取活动告警；?scope=counts 只取计数。POST /{id}?action=resolve 解除一条告警。'),
                    $t('cs-notification-groups', 'GET', '/api/notification-groups', '通知组 Notification Groups',
                        'GET 列表；POST 创建返回 {id}；PUT/DELETE /{id}。通知组定义告警推送的目标（如 webhook/邮件组）。'),
                    $t('cs-scheduled-tasks', 'GET', '/api/scheduled-tasks', '定时任务 Scheduled Tasks',
                        'GET 列表；POST 创建（cron + 下行模板）返回 {id}；PUT/DELETE /{id}；POST /{id}?action=run 立即执行一次（返回 {id, downlinkId, nextRunAt}）；POST /{id}?action=toggle&enabled=1|0 启停。'),
                    $t('cs-automations', 'GET', '/api/automations', '联动模型 Automations',
                        'GET 列表；POST 创建（{applicationId, triggerDeviceId, condition, action…}）返回 {id}；PUT/DELETE /{id}。设备上行满足条件时自动触发下行。'),
                    $t('cs-fuota', 'GET', '/api/fuota', 'FUOTA 固件升级任务',
                        'GET 列表；POST 创建（{applicationId, multicastGroupId, payload…}）返回 {id}；GET /{id} 详情；DELETE /{id}；POST /{id}/start 开始升级；POST /{id}/devices 添加目标设备。'),
                    $t('cs-events', 'GET', '/api/events?devId=&gatewayId=&type=&limit=&offset=', '网关/系统事件 Events',
                        '返回 {totalCount, result:[{id, type, level, gatewayId, devId, message, time, rawJson}]}。type 可过滤（如 join/error/tx）。'),
                    $t('cs-api-logs', 'GET', '/api/api-logs?method=&status=&ip=&path_contains=&since=&limit=&offset=', 'API 调用日志 API Logs',
                        '返回 {totalCount, result:[{id, method, path, status, latencyMs, ip, username, role, query, bodySize, time}]}（admin/tenant/operator 均可查，范围按角色过滤）。'),
                    $t('cs-stream', 'GET', '/api/stream?after=', '实时事件流 SSE',
                        'Server-Sent Events：连接后持续推送 data: {id, type, level, gateway_id, dev_id, message, created_at}，55 秒超时，客户端带 ?after=<last_id> 重连续传。'),
                    $t('cs-settings', 'GET', '/api/settings', '站点设置 Settings（admin）',
                        'GET 返回 {data:{…}} 全部设置；POST 设置若干键值；POST {clear_logs:"uplinks|downlinks|events|alerts", clear_logs_tenant?} 清理日志（tenant 及以上）。'),
                    $t('cs-stats-regions', 'GET', '/api/stats | /api/regions | /api/me | /api/public-settings | /api/i18n', '基础信息端点',
                        'stats=仪表盘统计；regions=支持的频段列表；me=当前用户（含 permissions）；public-settings=公开站点设置；i18n?lang= 取语言字典。'),
                ],
            ],
        ];
    }
}