<?php
/**
 * 核心逻辑自测（离线，不需要 MySQL）
 *
 *   php cli/selftest.php
 *
 * 覆盖：ADIF 解析/导出、波段模式归一化、呼号归一化、DXCC 查询、
 *       双向验证（精确指纹 / 人工确认）、重复导入去重。
 * 注：qso_suspects() 使用 MySQL 的 TIMESTAMPDIFF，在 sqlite 下跳过。
 */
declare(strict_types=1);

putenv('QSL_CONFIG=' . __DIR__ . '/config.test.php');
require __DIR__ . '/../includes/bootstrap.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  \033[32m✓\033[0m {$msg}\n";
    } else {
        $fail++;
        echo "  \033[31m✗ {$msg}\033[0m\n";
    }
}
function section(string $t): void
{
    echo "\n\033[1m{$t}\033[0m\n";
}

/* ----------------------------- 建表（sqlite 方言） ---------------------------- */
section('准备测试数据库');
db()->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  callsign TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
  display_name TEXT, qth TEXT, locator TEXT, country TEXT,
  latitude REAL, longitude REAL, rig TEXT, antenna TEXT, bio TEXT,
  card_image TEXT, card_style TEXT DEFAULT 'classic',
  is_admin INTEGER NOT NULL DEFAULT 0, is_public INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL, updated_at TEXT
);
CREATE TABLE user_callsigns (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  callsign TEXT NOT NULL UNIQUE, note TEXT
);
CREATE TABLE qsos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, station_callsign TEXT NOT NULL, call TEXT NOT NULL,
  qso_date TEXT NOT NULL, time_on TEXT NOT NULL, band TEXT NOT NULL DEFAULT '',
  mode TEXT NOT NULL DEFAULT '', submode TEXT, freq REAL,
  rst_sent TEXT NOT NULL DEFAULT '', rst_rcvd TEXT NOT NULL DEFAULT '', tx_pwr REAL,
  gridsquare TEXT, qth TEXT, name TEXT, comment TEXT,
  match_key TEXT NOT NULL, pair_key TEXT NOT NULL, verify_code TEXT NOT NULL UNIQUE,
  verified INTEGER NOT NULL DEFAULT 0, verified_at TEXT, matched_qso_id INTEGER,
  created_at TEXT NOT NULL,
  UNIQUE (user_id, match_key)
);
CREATE TABLE verifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT, qso_id INTEGER NOT NULL, peer_qso_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL, method TEXT NOT NULL DEFAULT 'auto', created_at TEXT NOT NULL
);
CREATE TABLE dxcc (
  id INTEGER PRIMARY KEY AUTOINCREMENT, prefix TEXT NOT NULL UNIQUE, entity TEXT NOT NULL,
  continent TEXT NOT NULL DEFAULT '', cq_zone INTEGER, itu_zone INTEGER
);
CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT);
SQL);

db()->exec("INSERT INTO dxcc (prefix, entity, continent) VALUES
  ('JA','Japan','AS'), ('W','United States','NA'), ('K','United States','NA'),
  ('BY','China','AS'), ('BH','China','AS'), ('DL','Germany','EU'), ('G','England','EU')");

$uidA = (int)db()->lastInsertId();   // 占位
db()->prepare('INSERT INTO users (callsign, email, password_hash, created_at) VALUES (?,?,?,?)')
    ->execute(['BH1ABC', 'a@example.com', password_hash('password123', PASSWORD_DEFAULT), now_utc()]);
$uidA = (int)db()->lastInsertId();
db()->prepare('INSERT INTO users (callsign, email, password_hash, created_at) VALUES (?,?,?,?)')
    ->execute(['JA1XYZ', 'b@example.com', password_hash('password123', PASSWORD_DEFAULT), now_utc()]);
$uidB = (int)db()->lastInsertId();

ok($uidA > 0 && $uidB > $uidA, '创建两个测试台站 BH1ABC / JA1XYZ');

/* --------------------------------- 归一化 --------------------------------- */
section('呼号 / 波段 / 模式归一化');
ok(norm_call(' bh1abc/p ') === 'BH1ABC', 'norm_call 去除空白、大写、剥离 /P');
ok(norm_call('ja1xyz/m')   === 'JA1XYZ', 'norm_call 剥离 /M');
ok(norm_call('W1AW/7')     === 'W1AW/7', 'norm_call 保留区域后缀 /7');
ok(canon_band('20M') === '20m' && canon_band('20 meters') === '20m', 'canon_band 归一');
ok(band_from_freq(14.074) === '20m', 'band_from_freq(14.074) = 20m');
ok(band_from_freq(7.030)  === '40m', 'band_from_freq(7.030) = 40m');
ok(band_from_freq(145.0)  === '2m',  'band_from_freq(145.0) = 2m');
ok(canon_mode('usb') === 'SSB' && canon_mode('LSB') === 'SSB', 'canon_mode USB/LSB → SSB');
ok(canon_mode('FT8') === 'FT8' && canon_mode('RTTY') === 'RTTY', 'canon_mode 数字模式保留');

/* --------------------------------- ADIF --------------------------------- */
section('ADIF 解析与导出');
$adi = "<ADIF_VER:5>3.1.4\n<PROGRAMID:6>WSJT-X<EOH>\n"
     . "<QSO_DATE:8>20260101<TIME_ON:4>1230<CALL:6>JA1XYZ<BAND:3>20m<MODE:3>FT8"
     . "<RST_SENT:2>-5<RST_RCVD:2>-7<FREQ:8>14.0740<STATION_CALLSIGN:6>BH1ABC<EOR>\n"
     . "<QSO_DATE:8>20260102<TIME_ON:6>010500<CALL:4>W1AW<BAND:2>40<MODE:2>CW"
     . "<RST_SENT:3>599<RST_RCVD:3>589<EOR>\n";
$recs = adif_parse($adi);
ok(count($recs) === 2, '解析出 2 条记录');
ok(($recs[0]['CALL'] ?? '') === 'JA1XYZ', '第 1 条 CALL 正确');
ok(($recs[0]['FREQ'] ?? '') === '14.0740', '第 1 条 FREQ 正确');
ok(adif_time($recs[1]['TIME_ON'] ?? '') === '01:05:00', 'TIME_ON 6 位 → 01:05:00');
ok(adif_date($recs[0]['QSO_DATE'] ?? '') === '2026-01-01', 'QSO_DATE → 2026-01-01');

/* ------------------------------ 指纹与双向验证 ------------------------------ */
section('QSO 指纹与双向验证');
$keyA = qso_match_key('BH1ABC', 'JA1XYZ', '2026-01-01', '12:30', '20m', 'FT8');
$keyB = qso_match_key('JA1XYZ', 'BH1ABC/p', '2026-01-01', '12:30', '20m', 'FT8');
ok($keyA === $keyB, '呼号顺序与 /P 后缀不影响指纹');
ok(qso_match_key('BH1ABC', 'JA1XYZ', '2026-01-01', '12:31', '20m', 'FT8') !== $keyA, '相差 1 分钟指纹不同');

$r1 = qso_create($uidA, [
    'station_callsign' => 'BH1ABC', 'call' => 'JA1XYZ',
    'qso_date' => '2026-01-01', 'time_on' => '12:30',
    'band' => '20m', 'mode' => 'FT8', 'freq' => 14.074,
    'rst_sent' => '-05', 'rst_rcvd' => '-07',
]);
ok($r1['ok'] && $r1['status'] === 'inserted', 'A 方记录写入成功');
ok($r1['verified'] === false, 'A 方记录此时未验证');

$r2 = qso_create($uidA, [
    'station_callsign' => 'BH1ABC', 'call' => 'JA1XYZ',
    'qso_date' => '2026-01-01', 'time_on' => '12:30',
    'band' => '20m', 'mode' => 'FT8', 'rst_sent' => '-05', 'rst_rcvd' => '-07',
]);
ok($r2['ok'] === false && $r2['status'] === 'duplicate', '同一账号重复导入被去重');

$r3 = qso_create($uidB, [
    'station_callsign' => 'JA1XYZ', 'call' => 'BH1ABC',
    'qso_date' => '2026-01-01', 'time_on' => '12:30',
    'band' => '20m', 'mode' => 'FT8', 'rst_sent' => '-07', 'rst_rcvd' => '-05',
]);
ok($r3['ok'] && $r3['verified'] === true, 'B 方导入后立即自动验证');

$a1 = q_one('SELECT verified, matched_qso_id FROM qsos WHERE id = ?', [(int)$r1['id']]);
$b1 = q_one('SELECT verified, matched_qso_id FROM qsos WHERE id = ?', [(int)$r3['id']]);
ok((int)$a1['verified'] === 1, 'A 方记录被同步置为已验证');
ok((int)$a1['matched_qso_id'] === (int)$r3['id'], 'A → B 互指');
ok((int)$b1['matched_qso_id'] === (int)$r1['id'], 'B → A 互指');
ok((int)q_val('SELECT COUNT(*) FROM verifications', [], 0) === 2, '写入 2 条验证日志');

/* -------------------------------- 人工确认 -------------------------------- */
section('人工确认（时间有出入）');
$r4 = qso_create($uidA, [
    'station_callsign' => 'BH1ABC', 'call' => 'JA1XYZ',
    'qso_date' => '2026-02-02', 'time_on' => '08:00',
    'band' => '40m', 'mode' => 'CW', 'rst_sent' => '599', 'rst_rcvd' => '589',
]);
$r5 = qso_create($uidB, [
    'station_callsign' => 'JA1XYZ', 'call' => 'BH1ABC',
    'qso_date' => '2026-02-02', 'time_on' => '08:06',
    'band' => '40m', 'mode' => 'CW', 'rst_sent' => '589', 'rst_rcvd' => '599',
]);
ok($r4['ok'] && $r5['ok'] && !$r5['verified'], '相差 6 分钟的两条记录不会自动验证');
ok(qso_link_pair((int)$r4['id'], (int)$r5['id'], 'manual'), '人工确认成功');
ok((int)q_val('SELECT verified FROM qsos WHERE id = ?', [(int)$r4['id']], 0) === 1, '人工确认后 A 方已验证');
ok((int)q_val('SELECT verified FROM qsos WHERE id = ?', [(int)$r5['id']], 0) === 1, '人工确认后 B 方已验证');

section('撤销与删除');
qso_unlink((int)$r4['id']);
ok((int)q_val('SELECT verified FROM qsos WHERE id = ?', [(int)$r5['id']], 0) === 0, '解除关联后双方回到未验证');
qso_link_pair((int)$r4['id'], (int)$r5['id'], 'manual');

/* --------------------------------- DXCC --------------------------------- */
section('DXCC 查询');
ok(dxcc_entity('JA1XYZ') === 'Japan', 'JA1XYZ → Japan');
ok(dxcc_entity('BH1ABC') === 'China', 'BH1ABC → China');
ok(dxcc_entity('W1AW')   === 'United States', 'W1AW → United States');
ok(dxcc_entity('DL1ABC') === 'Germany', 'DL1ABC → Germany');
ok(dxcc_entity('ZZ9ZZ')  === '未知', '未收录前缀 → 未知');

/* ------------------------------- ADIF 导出 ------------------------------- */
section('ADIF 导出');
$rows = q_all('SELECT * FROM qsos WHERE user_id = ? ORDER BY id', [$uidA]);
$out  = adif_export($rows, ['program' => 'eQSL Verify', 'version' => '1.0']);
ok(str_contains($out, '<APP_EQSL_VERIFIED:1>Y'), '导出含 APP_EQSL_VERIFIED:Y');
ok(str_contains($out, '<CALL:6>JA1XYZ'), '导出含对方呼号');
ok(str_contains($out, '<TIME_ON:4>1230'), '导出时间格式为 HHMM');
$re = adif_parse($out);
ok(count($re) === 2, '导出的 ADIF 可被重新解析');

/* --------------------------------- 统计 --------------------------------- */
section('统计');
$st = qso_stats($uidA);
ok($st['total'] === 2 && $st['verified'] === 2, 'A 方 2 条全部已验证');
ok($st['dxcc_count'] === 1 && ($st['dxcc']['Japan'] ?? 0) === 2, 'DXCC 实体数 1（Japan 通联 ×2）');
ok(($st['continents']['AS'] ?? 0) === 1, '大洲统计：亚洲 1 个实体');

/* ------------------------------- 数据校验 ------------------------------- */
section('输入校验');
$bad = qso_create($uidA, [
    'station_callsign' => 'BH1ABC', 'call' => '@@@',
    'qso_date' => '2026-13-45', 'time_on' => '99:99', 'band' => '', 'mode' => '',
]);
ok(!$bad['ok'] && $bad['status'] === 'invalid', '非法输入被拒绝');
$sameCall = qso_create($uidA, [
    'station_callsign' => 'BH1ABC', 'call' => 'bh1abc',
    'qso_date' => '2026-03-03', 'time_on' => '10:00', 'band' => '20m', 'mode' => 'SSB',
]);
ok(!$sameCall['ok'], '双方呼号相同被拒绝');

section('疑似匹配 SQL（MySQL 专有函数，sqlite 下跳过）');
try {
    qso_suspects($uidA, 15, 10);
    ok(true, 'qso_suspects 可执行');
} catch (Throwable $e) {
    echo "  \033[33m~ 跳过：qso_suspects 使用 TIMESTAMPDIFF，需在 MySQL 环境验证\033[0m\n";
}

/* --------------------------------- 汇总 --------------------------------- */
echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "\033[32m全部通过：{$pass} 项\033[0m\n"
    : "\033[31m失败 {$fail} 项，通过 {$pass} 项\033[0m\n";
exit($fail === 0 ? 0 : 1);
