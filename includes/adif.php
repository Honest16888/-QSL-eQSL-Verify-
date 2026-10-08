<?php
/**
 * ADIF 3.1.4 解析 / 生成，波段与模式归一化
 */
declare(strict_types=1);

/**
 * 解析 ADIF 文本为记录数组（字段名为大写）
 * 兼容 <EOR> 与 <APP_EOR> 结束符，兼容有无换行的格式。
 *
 * @return array<int, array<string,string>>
 */
function adif_parse(string $text): array
{
    // 去掉 BOM，统一换行
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $records = [];
    $cur     = [];
    $len     = strlen($text);
    $i       = 0;

    while ($i < $len) {
        $lt = strpos($text, '<', $i);
        if ($lt === false) {
            break;
        }
        $gt = strpos($text, '>', $lt);
        if ($gt === false) {
            break;
        }

        $tag = trim(substr($text, $lt + 1, $gt - $lt - 1));

        // 结束符
        if (preg_match('/^(APP_)?EOR$/i', $tag)) {
            if ($cur) {
                $records[] = $cur;
            }
            $cur = [];
            $i   = $gt + 1;
            continue;
        }
        // 头部结束符
        if (preg_match('/^(APP_)?EOH$/i', $tag)) {
            $cur = [];
            $i   = $gt + 1;
            continue;
        }

        if (!preg_match('/^([A-Za-z0-9_]+):(\d+)(?::([^>]*))?$/', $tag, $m)) {
            $i = $gt + 1;   // 无法识别的标签，跳过
            continue;
        }

        $field = strtoupper($m[1]);
        $size  = (int)$m[2];
        $value = substr($text, $gt + 1, $size);
        // 字段值里不应出现 '<'，若出现则截断（脏数据保护）
        $lt2 = strpos($value, '<');
        if ($lt2 !== false) {
            $value = substr($value, 0, $lt2);
        }
        $cur[$field] = trim($value);
        $i = $gt + 1 + $size;
    }

    if ($cur) {
        $records[] = $cur;
    }
    return $records;
}

/** ADIF 日期 YYYYMMDD -> Y-m-d */
function adif_date(?string $v): ?string
{
    $v = (string)$v;
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v;
    }
    return null;
}

/** ADIF 时间 HHMM / HHMMSS -> HH:MM:SS */
function adif_time(?string $v): ?string
{
    $v = (string)$v;
    if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
        return $m[1] . ':' . $m[2] . ':' . $m[3];
    }
    if (preg_match('/^(\d{2})(\d{2})$/', $v, $m)) {
        return $m[1] . ':' . $m[2] . ':00';
    }
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) {
        return strlen($v) === 5 ? $v . ':00' : $v;
    }
    return null;
}

/** 由频率(MHz)推导波段 */
function band_from_freq(?float $mhz): string
{
    if ($mhz === null || $mhz <= 0) {
        return '';
    }
    $ranges = [
        ['160m',   1.8,      2.0],
        ['80m',    3.5,      4.0],
        ['60m',    5.3,      5.5],
        ['40m',    7.0,      7.3],
        ['30m',   10.1,     10.15],
        ['20m',   14.0,     14.35],
        ['17m',   18.068,   18.168],
        ['15m',   21.0,     21.45],
        ['12m',   24.89,    24.99],
        ['10m',   28.0,     29.7],
        ['6m',    50.0,     54.0],
        ['4m',    70.0,     70.5],
        ['2m',   144.0,    148.0],
        ['1.25m', 219.0,    225.0],
        ['70cm', 420.0,    450.0],
        ['33cm', 902.0,    928.0],
        ['23cm', 1240.0,   1300.0],
        ['13cm', 2300.0,   2450.0],
        ['9cm',  3300.0,   3500.0],
        ['6cm',  5650.0,   5925.0],
        ['3cm', 10000.0,  10500.0],
        ['1.2cm',24000.0,  24250.0],
        ['6mm', 47000.0,  47200.0],
        ['4mm', 75500.0,  81000.0],
        ['2.5mm',122250.0,123000.0],
        ['2mm', 134000.0, 136000.0],
        ['1mm', 241000.0, 250000.0],
    ];
    foreach ($ranges as [$band, $lo, $hi]) {
        if ($mhz >= $lo && $mhz <= $hi) {
            return $band;
        }
    }
    // 短波兜底：按最近下限
    if ($mhz > 1.8 && $mhz < 30.0) {
        foreach ($ranges as [$band, $lo, $hi]) {
            if ($mhz < $hi) {
                return $band;
            }
        }
    }
    return '';
}

/** 波段名归一化（10M / 10 m / 10meters -> 10m） */
function canon_band(string $band): string
{
    $b = strtolower(trim($band));
    $b = preg_replace('/\s+/', '', $b);
    $b = str_replace(['meters', 'meter', 'metres', 'mtr', 'band'], '', $b);
    if ($b === '') {
        return '';
    }
    if (!str_ends_with($b, 'm')) {
        $b .= 'm';
    }
    // 厘米波段（70cm 等）不要被误处理，上面的规则已保留 "70cm"
    return $b;
}

/** 模式归一化（用于指纹匹配，宽松归并） */
function canon_mode(string $mode): string
{
    $m = strtoupper(trim($mode));
    $map = [
        'USB' => 'SSB', 'LSB' => 'SSB', 'SSB' => 'SSB',
        'CW'  => 'CW',
        'AM'  => 'AM',
        'FM'  => 'FM',
        'RTTY' => 'RTTY',
        'PSK31' => 'PSK', 'PSK63' => 'PSK', 'PSK125' => 'PSK', 'PSK' => 'PSK',
        'FT8' => 'FT8', 'FT4' => 'FT4',
        'JT65' => 'JT65', 'JT9' => 'JT9', 'Q65' => 'Q65',
        'MSK144' => 'MSK144', 'WSPR' => 'WSPR', 'JS8' => 'JS8',
        'MFSK' => 'MFSK', 'OLIVIA' => 'OLIVIA', 'CONTESTIA' => 'CONTESTIA',
        'THOR' => 'THOR', 'DOMINO' => 'DOMINO', 'MT63' => 'MT63',
        'SSTV' => 'SSTV', 'ATV' => 'ATV', 'FAX' => 'FAX',
        'PKT' => 'PACKET', 'PACKET' => 'PACKET', 'APRS' => 'PACKET',
        'DMR' => 'DMR', 'DSTAR' => 'D-STAR', 'D-STAR' => 'D-STAR',
        'C4FM' => 'C4FM', 'FUSION' => 'C4FM', 'P25' => 'P25', 'NXDN' => 'NXDN',
        'ALLSTAR' => 'ALLSTAR', 'ECHOLINK' => 'ECHOLINK', 'IRLP' => 'IRLP',
    ];
    if (isset($map[$m])) {
        return $map[$m];
    }
    // MFSKxx / Olivia xx 等变体
    if (preg_match('/^(MFSK|OLIVIA|CONTESTIA|PSK|JT|Q65|THOR)/', $m, $mm)) {
        return $mm[1];
    }
    return $m;
}

/** ADIF 字段封装：<NAME:LEN>value */
function adif_field(string $name, string $value): string
{
    $name  = strtoupper($name);
    $value = str_replace(["\r", "\n"], ' ', $value);
    return '<' . $name . ':' . strlen($value) . '>' . $value . "\n";
}

/**
 * 生成 ADIF 导出内容
 * @param array<int, array<string,mixed>> $rows
 */
function adif_export(array $rows, array $header = []): string
{
    $out = '';
    $out .= adif_field('ADIF_VER', '3.1.4');
    $out .= adif_field('PROGRAMID', $header['program'] ?? 'eQSL Verify');
    $out .= adif_field('PROGRAMVERSION', $header['version'] ?? '1.0');
    $out .= adif_field('CREATED_TIMESTAMP', gmdate('Ymd His'));
    foreach (($header['extra'] ?? []) as $k => $v) {
        $out .= adif_field($k, (string)$v);
    }
    $out .= "<EOH>\n\n";

    foreach ($rows as $r) {
        $date = str_replace('-', '', (string)$r['qso_date']);
        $time = str_replace(':', '', substr((string)$r['time_on'], 0, 5));
        $out .= adif_field('QSO_DATE', $date);
        $out .= adif_field('TIME_ON', $time);
        $out .= adif_field('CALL', (string)$r['call']);
        $out .= adif_field('STATION_CALLSIGN', (string)$r['station_callsign']);
        if (!empty($r['band'])) {
            $out .= adif_field('BAND', (string)$r['band']);
        }
        if (!empty($r['freq'])) {
            $out .= adif_field('FREQ', number_format((float)$r['freq'], 4, '.', ''));
        }
        if (!empty($r['mode'])) {
            $out .= adif_field('MODE', (string)$r['mode']);
        }
        if (!empty($r['submode'])) {
            $out .= adif_field('SUBMODE', (string)$r['submode']);
        }
        $out .= adif_field('RST_SENT', (string)$r['rst_sent']);
        $out .= adif_field('RST_RCVD', (string)$r['rst_rcvd']);
        if (!empty($r['tx_pwr'])) {
            $out .= adif_field('TX_PWR', number_format((float)$r['tx_pwr'], 2, '.', ''));
        }
        if (!empty($r['gridsquare'])) {
            $out .= adif_field('GRIDSQUARE', (string)$r['gridsquare']);
        }
        if (!empty($r['name'])) {
            $out .= adif_field('NAME', (string)$r['name']);
        }
        if (!empty($r['qth'])) {
            $out .= adif_field('QTH', (string)$r['qth']);
        }
        if (!empty($r['comment'])) {
            $out .= adif_field('COMMENT', (string)$r['comment']);
        }
        $out .= adif_field('APP_EQSL_VERIFIED', !empty($r['verified']) ? 'Y' : 'N');
        if (!empty($r['verified_at'])) {
            $out .= adif_field('APP_EQSL_VERIFIED_DATE', gmdate('Ymd', strtotime((string)$r['verified_at'])));
        }
        if (!empty($r['verify_code'])) {
            $out .= adif_field('APP_EQSL_CODE', (string)$r['verify_code']);
        }
        $out .= "<EOR>\n\n";
    }
    return $out;
}
