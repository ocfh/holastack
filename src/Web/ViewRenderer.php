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
        $intro = '<h2>' . $t('应用开放 API（v1）') . '</h2>'
            . '<p class="ad-note" style="margin-top:2px">'
            . $t('使用「应用 API Key」调用，作用域限定到该 Key 所属应用。所有请求需在头部携带')
            . '<code>Authorization: Bearer &lt;API_KEY&gt;</code>（' . $t('或 URL 参数') . ' <code>?api_key=&lt;API_KEY&gt;</code>）。'
            . $t('API Key 在后台「应用 → API Key」中创建，') . '<b>' . $t('明文仅显示一次') . '</b>' . $t('，请妥善保存。')
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

    private static function apiGroups(): array
    {
        return [
            [
                'title' => '应用概览',
                'apis' => [
                    [
                        'id' => 'info', 'method' => 'GET', 'path' => '/v1/info',
                        'title' => '获取应用信息',
                        'desc' => '返回当前 API Key 所属应用的基础信息与数据统计（设备数、上行数、下行数）。',
                        'params' => [],
                        'respFields' => [
                            ['name' => 'application.id', 'type' => 'int', 'desc' => '应用 ID'],
                            ['name' => 'application.name', 'type' => 'string', 'desc' => '应用名称'],
                            ['name' => 'application.app_eui', 'type' => 'string', 'desc' => '应用 EUI（JoinEUI）'],
                            ['name' => 'application.description', 'type' => 'string', 'desc' => '应用描述'],
                            ['name' => 'counts.devices', 'type' => 'int', 'desc' => '该应用下设备总数'],
                            ['name' => 'counts.uplinks', 'type' => 'int', 'desc' => '该应用累计上行消息数'],
                            ['name' => 'counts.downlinks', 'type' => 'int', 'desc' => '该应用累计下行消息数'],
                        ],
                        'respExample' => [
                            'application' => ['id' => 1, 'name' => '我的传感器应用', 'app_eui' => '0000000000000000', 'description' => ''],
                        'counts' => ['devices' => 12, 'uplinks' => 4821, 'downlinks' => 37],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                    [
                        'id' => 'devices', 'method' => 'GET', 'path' => '/v1/devices',
                        'title' => '列出应用下所有设备',
                        'desc' => '返回该应用下的全部设备列表。响应已剥离 app_key / nwk_s_key / app_s_key 等敏感密钥。',
                        'params' => [],
                        'respFields' => [
                            ['name' => 'data[].id', 'type' => 'int', 'desc' => '设备 ID'],
                            ['name' => 'data[].name', 'type' => 'string', 'desc' => '设备名称'],
                            ['name' => 'data[].dev_eui', 'type' => 'string', 'desc' => '设备 EUI（16 hex）'],
                            ['name' => 'data[].dev_addr', 'type' => 'string', 'desc' => '设备地址（ABP/已入网后）'],
                            ['name' => 'data[].activation', 'type' => 'string', 'desc' => 'OTAA / ABP'],
                            ['name' => 'data[].class', 'type' => 'string', 'desc' => '工作模式 A / B / C'],
                            ['name' => 'data[].region', 'type' => 'string', 'desc' => '频段区域'],
                            ['name' => 'data[].status', 'type' => 'string', 'desc' => 'pending / active'],
                            ['name' => 'data[].online', 'type' => 'string', 'desc' => 'online / offline（按最近上报判定）'],
                        ],
                        'respExample' => [
                            'data' => [['id' => 3, 'name' => '温湿度节点-01', 'dev_eui' => 'aabbccddeeff0011', 'dev_addr' => '01ff02aa', 'activation' => 'OTAA', 'class' => 'A', 'region' => 'CN470', 'status' => 'active', 'online' => 'online', 'last_seen' => '2026-08-16 21:00:12', 'created_at' => 1754000000]],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                    [
                        'id' => 'device-detail', 'method' => 'GET', 'path' => '/v1/devices/{dev_eui}',
                        'title' => '获取单个设备详情',
                        'desc' => '根据 DevEUI 查询单个设备及其上行/下行计数。设备必须属于该 API Key 所属应用，否则返回 404。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '设备 EUI（16 hex，大小写均可）'],
                        ],
                        'respFields' => [
                            ['name' => 'device', 'type' => 'object', 'desc' => '设备对象（同 /v1/devices 中的单条）'],
                            ['name' => 'counts.uplinks', 'type' => 'int', 'desc' => '该设备累计上行数'],
                            ['name' => 'counts.downlinks', 'type' => 'int', 'desc' => '该设备累计下行数'],
                        ],
                        'respExample' => [
                            'device' => ['id' => 3, 'name' => '温湿度节点-01', 'dev_eui' => 'aabbccddeeff0011', 'dev_addr' => '01ff02aa', 'activation' => 'OTAA', 'class' => 'A', 'region' => 'CN470', 'status' => 'active', 'online' => 'online', 'last_seen' => '2026-08-16 21:00:12', 'created_at' => 1754000000],
                            'counts' => ['uplinks' => 1205, 'downlinks' => 9],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404 device_not_found', 'desc' => '设备不存在或不归该应用所有'],
                        ],
                    ],
                    [
                        'id' => 'device-uplinks', 'method' => 'GET', 'path' => '/v1/devices/{dev_eui}/uplinks',
                        'title' => '获取设备上行数据',
                        'desc' => '返回指定设备的最近上行消息列表（按 id 倒序）。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '设备 EUI'],
                            ['name' => 'limit', 'in' => 'query', 'type' => 'int', 'required' => false, 'desc' => '返回条数，默认 50，最大 500'],
                        ],
                        'respFields' => [
                            ['name' => 'data[].id', 'type' => 'int', 'desc' => '上行记录 ID'],
                            ['name' => 'data[].dev_addr', 'type' => 'string', 'desc' => '设备地址'],
                            ['name' => 'data[].fcnt', 'type' => 'int', 'desc' => '帧计数'],
                            ['name' => 'data[].port', 'type' => 'int', 'desc' => 'FPort'],
                            ['name' => 'data[].confirmed', 'type' => 'bool', 'desc' => '是否为确认帧'],
                            ['name' => 'data[].decrypted_hex', 'type' => 'string', 'desc' => '解密后的应用负载（hex）'],
                            ['name' => 'data[].gateway_id', 'type' => 'string', 'desc' => '接收网关 ID'],
                            ['name' => 'data[].rssi', 'type' => 'int', 'desc' => 'RSSI (dBm)'],
                            ['name' => 'data[].snr', 'type' => 'number', 'desc' => 'SNR (dB)'],
                            ['name' => 'data[].received_at', 'type' => 'int', 'desc' => '接收时间（Unix 秒）'],
                        ],
                        'respExample' => [
                            'data' => [['id' => 9981, 'dev_addr' => '01ff02aa', 'fcnt' => 1205, 'port' => 10, 'confirmed' => false, 'decrypted_hex' => '48656c6c6f', 'gateway_id' => '0080000000000001', 'rssi' => -73, 'snr' => 9.2, 'frequency' => 486.3, 'data_rate' => 'SF9BW125', 'received_at' => 1755349212]],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404 device_not_found', 'desc' => '设备不存在或不归该应用所有'],
                        ],
                    ],
                ],
                [
                    'id' => 'device-create', 'method' => 'POST', 'path' => '/v1/devices',
                    'title' => '添加设备',
                    'desc' => '在当前应用下创建设备。app_id 由 API Key 自动绑定，无需（也不允许）在请求体中指定。OTAA 需 app_key(32hex)/join_eui(16hex)；ABP 需 dev_addr(8hex)/nwk_s_key(32hex)/app_s_key(32hex)。',
                    'params' => [
                        ['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '设备名称，应用内唯一'],
                        ['name' => 'dev_eui', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '设备 EUI（16 hex）'],
                        ['name' => 'activation', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'OTAA（默认）/ ABP'],
                        ['name' => 'class', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'A（默认）/ B / C'],
                        ['name' => 'region', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '频段，默认应用默认区域（如 CN470）'],
                        ['name' => 'device_profile_id', 'in' => 'body', 'type' => 'int', 'required' => false, 'desc' => '设备模板 ID，缺省用默认模板'],
                        ['name' => 'app_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'OTAA 应用密钥（32 hex，OTAA 必填）'],
                        ['name' => 'join_eui', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'JoinEUI（16 hex，OTAA 必填）'],
                        ['name' => 'dev_addr', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '设备地址（8 hex，ABP 必填）'],
                        ['name' => 'nwk_s_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '网络会话密钥（32 hex，ABP 必填）'],
                        ['name' => 'app_s_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '应用会话密钥（32 hex，ABP 必填）'],
                    ],
                    'sample' => ['name' => '温湿度节点-02', 'dev_eui' => 'aabbccddeeff0022', 'activation' => 'OTAA', 'class' => 'A', 'region' => 'CN470', 'app_key' => '00000000000000000000000000000000', 'join_eui' => '0000000000000000'],
                    'respFields' => [
                        ['name' => 'id', 'type' => 'int', 'desc' => '新建设备 ID'],
                    ],
                    'respExample' => ['id' => 13],
                    'errors' => [
                        ['code' => '400', 'desc' => '参数错误（dev_eui 格式/重复、密钥长度、region 不支持等）'],
                        ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                    ],
                ],
                [
                    'id' => 'device-update', 'method' => 'PUT', 'path' => '/v1/devices/{dev_eui}',
                    'title' => '修改设备信息',
                    'desc' => '修改指定设备的部分字段（按需传参，缺省字段保持不变）。支持 name/class/region/device_profile_id，以及 OTAA 的 app_key/join_eui/dev_eui、ABP 的 dev_addr/nwk_s_key/app_s_key。',
                    'params' => [
                        ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '目标设备 EUI'],
                        ['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '新名称'],
                        ['name' => 'class', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'A / B / C'],
                        ['name' => 'region', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '频段区域'],
                        ['name' => 'device_profile_id', 'in' => 'body', 'type' => 'int', 'required' => false, 'desc' => '设备模板 ID'],
                        ['name' => 'app_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'OTAA 新应用密钥（32 hex）'],
                        ['name' => 'join_eui', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'OTAA 新 JoinEUI（16 hex）'],
                        ['name' => 'dev_eui', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'OTAA 新 DevEUI（16 hex，需全局唯一）'],
                        ['name' => 'dev_addr', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'ABP 新设备地址（8 hex）'],
                        ['name' => 'nwk_s_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'ABP 新网络会话密钥（32 hex）'],
                        ['name' => 'app_s_key', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'ABP 新应用会话密钥（32 hex）'],
                    ],
                    'sample' => ['name' => '温湿度节点-改名', 'class' => 'C'],
                    'respFields' => [
                        ['name' => 'id', 'type' => 'int', 'desc' => '设备 ID'],
                        ['name' => 'updated', 'type' => 'bool', 'desc' => '是否成功更新'],
                    ],
                    'respExample' => ['id' => 3, 'updated' => true],
                    'errors' => [
                        ['code' => '400', 'desc' => '参数错误（密钥长度、class 非法、名称重复等）'],
                        ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ['code' => '404 device_not_found', 'desc' => '设备不存在或不归该应用所有'],
                    ],
                ],
                [
                    'id' => 'device-delete', 'method' => 'DELETE', 'path' => '/v1/devices/{dev_eui}',
                    'title' => '删除设备',
                    'desc' => '删除指定设备，同时清理其上行、下行与关联记录。设备必须属于该应用。',
                    'params' => [
                        ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '目标设备 EUI'],
                    ],
                    'respFields' => [
                        ['name' => 'id', 'type' => 'int', 'desc' => '已删除设备 ID'],
                        ['name' => 'deleted', 'type' => 'bool', 'desc' => '是否成功删除'],
                    ],
                    'respExample' => ['id' => 3, 'deleted' => true],
                    'errors' => [
                        ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ['code' => '404 device_not_found', 'desc' => '设备不存在或不归该应用所有'],
                    ],
                ],
            ],
            [
                'title' => '网关管理',
                'apis' => [
                    [
                        'id' => 'gateways', 'method' => 'GET', 'path' => '/v1/gateways',
                        'title' => '列出网关',
                        'desc' => '返回当前租户（API Key 对应应用所属租户）下的网关列表，含在线状态。',
                        'params' => [],
                        'respFields' => [
                            ['name' => 'data[].gw_id', 'type' => 'string', 'desc' => '网关 ID（16/32 hex）'],
                            ['name' => 'data[].name', 'type' => 'string', 'desc' => '网关名称'],
                            ['name' => 'data[].region', 'type' => 'string', 'desc' => '频段区域'],
                            ['name' => 'data[].status', 'type' => 'string', 'desc' => 'online / offline'],
                            ['name' => 'data[].last_seen', 'type' => 'string', 'desc' => '最近上报时间'],
                        ],
                        'respExample' => ['data' => [['gw_id' => '0080000000000001', 'name' => '办公室网关', 'region' => 'CN470', 'status' => 'online', 'last_seen' => '2026-08-16 21:00:00']]],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                    [
                        'id' => 'gateway-create', 'method' => 'POST', 'path' => '/v1/gateways',
                        'title' => '添加网关',
                        'desc' => '创建一个网关。租户由 API Key 对应应用自动绑定。',
                        'params' => [
                            ['name' => 'gw_id', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '网关 ID（16 或 32 hex）'],
                            ['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '网关名称'],
                            ['name' => 'region', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '频段区域，默认空（不限制）'],
                            ['name' => 'rf_config', 'in' => 'body', 'type' => 'object', 'required' => false, 'desc' => '射频配置（对象或 JSON 字符串）'],
                        ],
                        'sample' => ['gw_id' => '0080000000000001', 'name' => '办公室网关', 'region' => 'CN470'],
                        'respFields' => [
                            ['name' => 'gw_id', 'type' => 'string', 'desc' => '已创建网关 ID'],
                        ],
                        'respExample' => ['gw_id' => '0080000000000001'],
                        'errors' => [
                            ['code' => '400', 'desc' => '参数错误（gw_id 格式/重复、名称缺失等）'],
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                    [
                        'id' => 'gateway-detail', 'method' => 'GET', 'path' => '/v1/gateways/{gw_id}',
                        'title' => '获取网关详情',
                        'desc' => '根据网关 ID 查询单个网关信息。',
                        'params' => [
                            ['name' => 'gw_id', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '网关 ID'],
                        ],
                        'respFields' => [
                            ['name' => 'gateway', 'type' => 'object', 'desc' => '网关对象（同列表单条，含 rf_config）'],
                        ],
                        'respExample' => ['gateway' => ['gw_id' => '0080000000000001', 'name' => '办公室网关', 'region' => 'CN470', 'status' => 'online', 'last_seen' => '2026-08-16 21:00:00', 'rf_config' => null]],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404', 'desc' => '网关不存在或无权限'],
                        ],
                    ],
                    [
                        'id' => 'gateway-update', 'method' => 'PUT', 'path' => '/v1/gateways/{gw_id}',
                        'title' => '修改网关信息',
                        'desc' => '修改网关的 name/region/rf_config。',
                        'params' => [
                            ['name' => 'gw_id', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '网关 ID'],
                            ['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '新名称'],
                            ['name' => 'region', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '新频段区域'],
                            ['name' => 'rf_config', 'in' => 'body', 'type' => 'object', 'required' => false, 'desc' => '新射频配置'],
                        ],
                        'sample' => ['name' => '办公室网关-2F', 'region' => 'CN470'],
                        'respFields' => [
                            ['name' => 'gw_id', 'type' => 'string', 'desc' => '网关 ID'],
                            ['name' => 'updated', 'type' => 'bool', 'desc' => '是否成功更新'],
                        ],
                        'respExample' => ['gw_id' => '0080000000000001', 'updated' => true],
                        'errors' => [
                            ['code' => '400', 'desc' => '参数错误'],
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404', 'desc' => '网关不存在或无权限'],
                        ],
                    ],
                    [
                        'id' => 'gateway-delete', 'method' => 'DELETE', 'path' => '/v1/gateways/{gw_id}',
                        'title' => '删除网关',
                        'desc' => '删除指定网关。',
                        'params' => [
                            ['name' => 'gw_id', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '网关 ID'],
                        ],
                        'respFields' => [
                            ['name' => 'gw_id', 'type' => 'string', 'desc' => '已删除网关 ID'],
                            ['name' => 'deleted', 'type' => 'bool', 'desc' => '是否成功删除'],
                        ],
                        'respExample' => ['gw_id' => '0080000000000001', 'deleted' => true],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404', 'desc' => '网关不存在或无权限'],
                        ],
                    ],
                ],
            ],
            [
                'title' => '下行队列与设备指标',
                'apis' => [
                    [
                        'id' => 'device-downlinks', 'method' => 'GET', 'path' => '/v1/devices/{dev_eui}/downlinks',
                        'title' => '列出设备下行队列',
                        'desc' => '返回该设备的下行记录（默认全部，可用 ?status=pending 只看待发）。pending 表示仍在队列等待网关下发。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '设备 EUI'],
                            ['name' => 'status', 'in' => 'query', 'type' => 'string', 'required' => false, 'desc' => '过滤状态：pending / sent / failed / canceled'],
                        ],
                        'respFields' => [
                            ['name' => 'data[].id', 'type' => 'int', 'desc' => '下行记录 ID'],
                            ['name' => 'data[].port', 'type' => 'int', 'desc' => 'FPort'],
                            ['name' => 'data[].payload_hex', 'type' => 'string', 'desc' => '负载（hex）'],
                            ['name' => 'data[].status', 'type' => 'string', 'desc' => 'pending / sent / failed / canceled'],
                        ],
                        'errors' => [['code' => '404', 'desc' => '设备不存在']],
                    ],
                    [
                        'id' => 'downlink-cancel', 'method' => 'DELETE', 'path' => '/v1/downlinks/{id}',
                        'title' => '取消待发下行',
                        'desc' => '取消一条状态为 pending 的下行（已下发的不允许取消）。取消后状态置为 canceled。',
                        'params' => [['name' => 'id', 'in' => 'path', 'type' => 'int', 'required' => true, 'desc' => '下行记录 ID（来自 /v1/devices/{dev_eui}/downlinks 的 id）']],
                        'respFields' => [['name' => 'canceled', 'type' => 'bool', 'desc' => '是否成功取消']],
                        'errors' => [
                            ['code' => '404', 'desc' => '下行不存在'],
                            ['code' => '403', 'desc' => '不属于当前应用'],
                            ['code' => '409', 'desc' => '下行已非 pending，无法取消（返回其当前 status）'],
                        ],
                    ],
                    [
                        'id' => 'device-metrics', 'method' => 'GET', 'path' => '/v1/devices/{dev_eui}/metrics',
                        'title' => '设备信号指标',
                        'desc' => '返回最近一段时间内的上行信号点（RSSI / SNR / FCnt），用于绘制信号质量曲线。默认近 24 小时，最大 720 小时。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '设备 EUI'],
                            ['name' => 'range', 'in' => 'query', 'type' => 'int', 'required' => false, 'desc' => '时间范围（小时），默认 24，最大 720'],
                        ],
                        'respFields' => [
                            ['name' => 'range_hours', 'type' => 'int', 'desc' => '实际统计小时数'],
                            ['name' => 'count', 'type' => 'int', 'desc' => '数据点数量'],
                            ['name' => 'points[].t', 'type' => 'int', 'desc' => '时间戳（秒）'],
                            ['name' => 'points[].rssi', 'type' => 'int', 'desc' => 'RSSI（dBm）'],
                            ['name' => 'points[].snr', 'type' => 'float', 'desc' => 'SNR（dB）'],
                            ['name' => 'points[].fcnt', 'type' => 'int', 'desc' => '帧计数'],
                        ],
                        'errors' => [['code' => '404', 'desc' => '设备不存在']],
                    ],
                ],
            ],
            [
                'title' => '设备模板与密钥',
                'apis' => [
                    [
                        'id' => 'device-profiles', 'method' => 'GET', 'path' => '/v1/device-profiles',
                        'title' => '列出设备模板',
                        'desc' => '返回设备模板（Device Profile）列表，可绑定到设备以统一频段/MAC 版本/Class 能力。',
                        'params' => [],
                        'respFields' => [['name' => 'data[]', 'type' => 'object', 'desc' => '模板对象（含 name/region/mac_version/supports_class_b/c/supports_otaa 等）']],
                        'errors' => [['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                    [
                        'id' => 'device-profile-create', 'method' => 'POST', 'path' => '/v1/device-profiles',
                        'title' => '创建设备模板',
                        'desc' => '创建一个设备模板。tenant 由 API Key 自动绑定。',
                        'params' => [
                            ['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '模板名称'],
                            ['name' => 'region', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => '频段，默认 EU868'],
                            ['name' => 'mac_version', 'in' => 'body', 'type' => 'string', 'required' => false, 'desc' => 'MAC 版本，默认 1.0.4'],
                            ['name' => 'supports_class_b', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否支持 Class B'],
                            ['name' => 'supports_class_c', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否支持 Class C'],
                            ['name' => 'supports_otaa', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否支持 OTAA'],
                        ],
                        'sample' => ['name' => 'CN470-ClassC', 'region' => 'CN470', 'mac_version' => '1.0.4', 'supports_class_c' => true, 'supports_otaa' => true],
                        'respFields' => [['name' => 'id', 'type' => 'int', 'desc' => '新模板 ID']],
                        'errors' => [['code' => '400', 'desc' => '参数错误'], ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                    [
                        'id' => 'device-profile-detail', 'method' => 'GET', 'path' => '/v1/device-profiles/{id}',
                        'title' => '获取/修改/删除模板',
                        'desc' => 'GET 获取详情；PUT/PATCH 修改；DELETE 删除。',
                        'params' => [['name' => 'id', 'in' => 'path', 'type' => 'int', 'required' => true, 'desc' => '模板 ID']],
                        'errors' => [['code' => '404', 'desc' => '模板不存在'], ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                    [
                        'id' => 'api-keys', 'method' => 'GET', 'path' => '/v1/api-keys',
                        'title' => '列出 API 密钥',
                        'desc' => '返回当前应用下的 API Key 列表（token 仅显示前 12 位预览）。',
                        'params' => [],
                        'respFields' => [['name' => 'data[].id', 'type' => 'int', 'desc' => 'Key ID'], ['name' => 'data[].name', 'type' => 'string', 'desc' => 'Key 名称'], ['name' => 'data[].token_preview', 'type' => 'string', 'desc' => 'token 预览']],
                        'errors' => [['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                    [
                        'id' => 'api-key-create', 'method' => 'POST', 'path' => '/v1/api-keys',
                        'title' => '创建 API 密钥',
                        'desc' => '为当前应用创建一个新的 API Key（仅创建时返回完整 token 一次，请妥善保存）。',
                        'params' => [['name' => 'name', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => 'Key 名称']],
                        'sample' => ['name' => '边缘网关采集'],
                        'respFields' => [['name' => 'id', 'type' => 'int', 'desc' => 'Key ID'], ['name' => 'api_key', 'type' => 'string', 'desc' => '完整 token（仅此一次）']],
                        'errors' => [['code' => '400', 'desc' => '参数错误'], ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                    [
                        'id' => 'api-key-delete', 'method' => 'DELETE', 'path' => '/v1/api-keys/{id}',
                        'title' => '吊销 API 密钥',
                        'desc' => '吊销指定 API Key，立即失效。',
                        'params' => [['name' => 'id', 'in' => 'path', 'type' => 'int', 'required' => true, 'desc' => 'Key ID']],
                        'respFields' => [['name' => 'deleted', 'type' => 'bool', 'desc' => '是否成功吊销']],
                        'errors' => [['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'], ['code' => '404', 'desc' => 'Key 不存在']],
                    ],
                ],
            ],
            [
                'title' => '实时事件流 (SSE)',
                'apis' => [
                    [
                        'id' => 'stream', 'method' => 'GET', 'path' => '/v1/stream',
                        'title' => '订阅实时事件',
                        'desc' => '基于 Server-Sent Events 的实时事件流（上行/下行/Join/网关等）。连接后持续推送 data: JSON，直到 55 秒超时（客户端自动重连并带 ?after=<last_id> 续传）。',
                        'params' => [['name' => 'after', 'in' => 'query', 'type' => 'int', 'required' => false, 'desc' => '从此事件 ID 之后开始推送（断线续传用）']],
                        'respFields' => [['name' => 'event', 'type' => 'stream', 'desc' => "每行 `data: {id,type,level,gateway_id,dev_id,message,created_at}`"]],
                        'errors' => [['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效']],
                    ],
                ],
            ],
            [
                'title' => '消息数据',

                'apis' => [
                    [
                        'id' => 'uplinks', 'method' => 'GET', 'path' => '/v1/uplinks',
                        'title' => '获取应用最近上行',
                        'desc' => '返回该应用最近的上行消息（按 id 倒序）。可通过 dev_eui 过滤单设备。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'query', 'type' => 'string', 'required' => false, 'desc' => '仅返回该设备上行'],
                            ['name' => 'limit', 'in' => 'query', 'type' => 'int', 'required' => false, 'desc' => '返回条数，默认 50，最大 500'],
                        ],
                        'respFields' => [
                            ['name' => 'data[]', 'type' => 'object[]', 'desc' => '同 /v1/devices/{dev_eui}/uplinks 的 data 元素'],
                        ],
                        'respExample' => [
                            'data' => [['id' => 9981, 'dev_addr' => '01ff02aa', 'fcnt' => 1205, 'port' => 10, 'confirmed' => false, 'decrypted_hex' => '48656c6c6f', 'gateway_id' => '0080000000000001', 'rssi' => -73, 'snr' => 9.2, 'received_at' => 1755349212]],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                    [
                        'id' => 'downlinks', 'method' => 'GET', 'path' => '/v1/downlinks',
                        'title' => '获取应用最近下行',
                        'desc' => '返回该应用最近的下行队列/发送记录（按 id 倒序）。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'query', 'type' => 'string', 'required' => false, 'desc' => '仅返回该设备下行'],
                            ['name' => 'limit', 'in' => 'query', 'type' => 'int', 'required' => false, 'desc' => '返回条数，默认 50，最大 500'],
                        ],
                        'respFields' => [
                            ['name' => 'data[].id', 'type' => 'int', 'desc' => '下行记录 ID'],
                            ['name' => 'data[].dev_id', 'type' => 'int', 'desc' => '目标设备 ID'],
                            ['name' => 'data[].port', 'type' => 'int', 'desc' => 'FPort'],
                            ['name' => 'data[].payload_hex', 'type' => 'string', 'desc' => '下行负载（hex）'],
                            ['name' => 'data[].confirmed', 'type' => 'bool', 'desc' => '是否确认帧'],
                            ['name' => 'data[].status', 'type' => 'string', 'desc' => 'pending / sent / acked / failed / timeout'],
                            ['name' => 'data[].sent_at', 'type' => 'int', 'desc' => '实际发送时间（Unix 秒）'],
                        ],
                        'respExample' => [
                            'data' => [['id' => 412, 'dev_id' => 3, 'port' => 10, 'payload_hex' => '48656c6c6f', 'confirmed' => false, 'status' => 'sent', 'sent_at' => 1755349300]],
                        ],
                        'errors' => [
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                        ],
                    ],
                ],
            ],
            [
                'title' => '下行控制',
                'apis' => [
                    [
                        'id' => 'downlink', 'method' => 'POST', 'path' => '/v1/devices/{dev_eui}/downlink',
                        'title' => '下发下行数据',
                        'desc' => '向指定设备入队一条下行。Class C 立即下发；Class A 于下次上行 RX1/RX2 窗口下发；Class B 于 ping 时隙下发。payload 为 hex 字符串。',
                        'params' => [
                            ['name' => 'dev_eui', 'in' => 'path', 'type' => 'string', 'required' => true, 'desc' => '目标设备 EUI'],
                            ['name' => 'port', 'in' => 'body', 'type' => 'int', 'required' => true, 'desc' => 'FPort。应用数据填 1–223；发送 MAC 命令时填 0（并置 mac=true）'],
                            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '负载 hex 字符串（长度需为偶数）。mac=true 时为 MAC 指令字节（如 03 0A 00 01 即 LinkADRReq）'],
                            ['name' => 'confirmed', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否确认帧，默认 false'],
                            ['name' => 'mac', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否为 MAC 命令（FPort=0，使用 NwkSKey 加密）。true 时 port 强制为 0，payload 作为 MAC 指令下发；默认 false'],
                        ],
                        'sample' => ['port' => 10, 'payload' => '48656c6c6f', 'confirmed' => false, 'mac' => false],
                        'respFields' => [
                            ['name' => 'id', 'type' => 'int', 'desc' => '下行记录 ID'],
                            ['name' => 'status', 'type' => 'string', 'desc' => '入队状态，成功为 pending'],
                        ],
                        'respExample' => ['id' => 413, 'status' => 'pending'],
                        'errors' => [
                            ['code' => '400', 'desc' => '参数错误（port 越界 / payload 非 hex / 长度非偶数）'],
                            ['code' => '401 invalid_api_key', 'desc' => 'API Key 缺失或无效'],
                            ['code' => '404 device_not_found', 'desc' => '设备不存在或不归该应用所有'],
                        ],
                    ],
                ],
            ],
        ];
    }
}