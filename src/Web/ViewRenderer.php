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

        $hide = $active ? '' : ' hidden';
        return <<<HTML
<div class="ad-detail$hide" id="ad-$a[id]" data-ad="$a[id]">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="ad-method m-{$a['method']}">{$a['method']}</span>
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
                            ['name' => 'port', 'in' => 'body', 'type' => 'int', 'required' => true, 'desc' => 'FPort，范围 1–223'],
                            ['name' => 'payload', 'in' => 'body', 'type' => 'string', 'required' => true, 'desc' => '应用负载，hex 字符串（长度需为偶数）'],
                            ['name' => 'confirmed', 'in' => 'body', 'type' => 'bool', 'required' => false, 'desc' => '是否确认帧，默认 false'],
                        ],
                        'sample' => ['port' => 10, 'payload' => '48656c6c6f', 'confirmed' => false],
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