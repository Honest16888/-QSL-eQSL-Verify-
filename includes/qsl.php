<?php
/**
 * QSL 交换验证核心
 *
 * 验证模型（与 eQSL / LotW 一致）：
 *   一次通联在双方日志里各产生一条记录。两条记录若「呼号对 + UTC 时间(分) + 波段 + 模式」
 *   归一化后一致，则指纹(match_key)相同 —— 把两条记录互相关联并标记为双向已验证(verified)。
 *   时间有出入的（±容差）进入「疑似匹配」列表，由用户人工确认。
 */
declare(strict_types=1);

/**
 * 计算通联指纹
 * @param string $date Y-m-d
 * @param string $time H:i[:s]
 */
function qso_match_key(string $station, string $call, string $date, string $time, string $band, string $mode): string
{
    $a = norm_call($station);
    $b = norm_call($call);
    $pair = [$a, $b];
    sort($pair, SORT_STRING);

    $ts   = qso_ts($date, norm_time($time));
    $band = canon_band($band);
    $mode = canon_mode($mode);

    return sha1($pair[0] . '|' . $pair[1] . '|' . gmdate('Y-m-d H:i', $ts) . '|' . $band . '|' . $mode);
}

/** 呼号对指纹（用于容差候选检索，与具体时间无关） */
function qso_pair_key(string $station, string $call): string
{
    $pair = [norm_call($station), norm_call($call)];
    sort($pair, SORT_STRING);
    return sha1($pair[0] . '|' . $pair[1]);
}

/** 生成不重复的公开验证码 */
function new_verify_code(): string
{
    for ($i = 0; $i < 8; $i++) {
        $code = substr(bin2hex(random_bytes(12)), 0, 16);
        if (!q_val('SELECT 1 FROM qsos WHERE verify_code = ?', [$code])) {
            return $code;
        }
    }
    return substr(bin2hex(random_bytes(16)), 0, 16);
}

/**
 * 校验并规范化一条 QSO 输入
 * @return array{0: array<string,string>, 1: array<string,mixed>} [错误列表, 规范化数据]
 */
function qso_validate(array $in, string $defaultStation = ''): array
{
    $errs = [];

    $station = norm_call((string)($in['station_callsign'] ?? $defaultStation));
    $call    = norm_call((string)($in['call'] ?? ''));

    if ($station === '' || !valid_call($station)) {
        $errs['station_callsign'] = '本方呼号无效';
    }
    if ($call === '' || !valid_call($call)) {
        $errs['call'] = '对方呼号无效';
    }
    if ($station !== '' && $call !== '' && norm_call($station) === norm_call($call)) {
        $errs['call'] = '双方呼号不能相同';
    }

    $date = trim((string)($in['qso_date'] ?? ''));
    $time = trim((string)($in['time_on'] ?? ''));
    if (!valid_date($date)) {
        $errs['qso_date'] = '日期格式须为 YYYY-MM-DD';
    }
    if (!valid_time($time)) {
        $errs['time_on'] = 'UTC 时间格式须为 HH:MM';
    } elseif (valid_date($date) && qso_ts($date, norm_time($time)) > time() + 86400) {
        $errs['time_on'] = '时间不能晚于当前 UTC 太多';
    }

    $band = canon_band((string)($in['band'] ?? ''));
    $freq = (float)($in['freq'] ?? 0);
    if ($band === '' && $freq > 0) {
        $band = band_from_freq($freq);
    }
    if ($band === '') {
        $errs['band'] = '请填写波段或频率';
    }

    $mode = canon_mode((string)($in['mode'] ?? ''));
    if ($mode === '') {
        $errs['mode'] = '请填写模式';
    }

    $rstSent = mb_substr(trim((string)($in['rst_sent'] ?? '')), 0, 12);
    $rstRcvd = mb_substr(trim((string)($in['rst_rcvd'] ?? '')), 0, 12);

    $data = [
        'station_callsign' => $station,
        'call'             => $call,
        'qso_date'         => $date,
        'time_on'          => norm_time($time),
        'band'             => $band,
        'mode'             => $mode,
        'submode'          => mb_substr(trim((string)($in['submode'] ?? '')), 0, 20) ?: null,
        'freq'             => $freq > 0 ? round($freq, 4) : null,
        'rst_sent'         => $rstSent,
        'rst_rcvd'         => $rstRcvd,
        'tx_pwr'           => ($in['tx_pwr'] ?? '') === '' ? null : round((float)$in['tx_pwr'], 2),
        'gridsquare'       => mb_substr(strtoupper(trim((string)($in['gridsquare'] ?? ''))), 0, 12) ?: null,
        'qth'              => mb_substr(trim((string)($in['qth'] ?? '')), 0, 120) ?: null,
        'name'             => mb_substr(trim((string)($in['name'] ?? '')), 0, 80) ?: null,
        'comment'          => mb_substr(trim((string)($in['comment'] ?? '')), 0, 255) ?: null,
    ];

    return [$errs, $data];
}

/**
 * 写入一条 QSO 并尝试自动交叉验证
 * @return array{ok:bool, status:string, id:?int, verified:bool, error:?string}
 *        status: inserted | duplicate | invalid
 */
function qso_create(int $userId, array $in, string $defaultStation = ''): array
{
    [$errs, $d] = qso_validate($in, $defaultStation);
    if ($errs) {
        return ['ok' => false, 'status' => 'invalid', 'id' => null, 'verified' => false,
                'error' => implode('；', $errs)];
    }

    $matchKey = qso_match_key($d['station_callsign'], $d['call'], $d['qso_date'], $d['time_on'], $d['band'], $d['mode']);
    $pairKey  = qso_pair_key($d['station_callsign'], $d['call']);

    // 同一用户重复记录同一通联 -> 视为重复，尝试更新补充信息
    $exists = q_one('SELECT id, verified FROM qsos WHERE user_id = ? AND match_key = ?', [$userId, $matchKey]);
    if ($exists) {
        return ['ok' => false, 'status' => 'duplicate', 'id' => (int)$exists['id'],
                'verified' => (bool)$exists['verified'], 'error' => '该通联已在你的日志中'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $code = new_verify_code();
        $st = $pdo->prepare(
            'INSERT INTO qsos
             (user_id, station_callsign, call, qso_date, time_on, band, mode, submode, freq,
              rst_sent, rst_rcvd, tx_pwr, gridsquare, qth, name, comment,
              match_key, pair_key, verify_code, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $userId, $d['station_callsign'], $d['call'], $d['qso_date'], $d['time_on'],
            $d['band'], $d['mode'], $d['submode'], $d['freq'], $d['rst_sent'], $d['rst_rcvd'],
            $d['tx_pwr'], $d['gridsquare'], $d['qth'], $d['name'], $d['comment'],
            $matchKey, $pairKey, $code, now_utc(),
        ]);
        $id = (int)$pdo->lastInsertId();

        $verified = qso_try_auto_match($id);
        $pdo->commit();

        return ['ok' => true, 'status' => 'inserted', 'id' => $id, 'verified' => $verified, 'error' => null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (cfg('debug')) {
            throw $e;
        }
        return ['ok' => false, 'status' => 'invalid', 'id' => null, 'verified' => false,
                'error' => '写入失败：' . $e->getMessage()];
    }
}

/**
 * 为指定 QSO 寻找对方记录并交叉验证
 * @return bool 是否建立验证
 */
function qso_try_auto_match(int $qsoId): bool
{
    $me = q_one('SELECT id, user_id, match_key FROM qsos WHERE id = ?', [$qsoId]);
    if (!$me) {
        return false;
    }
    $peer = q_one(
        'SELECT id FROM qsos
          WHERE match_key = ? AND user_id <> ? AND id <> ?
          ORDER BY verified ASC, id ASC
          LIMIT 1',
        [$me['match_key'], (int)$me['user_id'], $qsoId]
    );
    if (!$peer) {
        return false;
    }
    qso_link_pair((int)$me['id'], (int)$peer['id'], 'auto');
    return true;
}

/**
 * 关联两条 QSO 记录并双向置为已验证
 */
function qso_link_pair(int $aId, int $bId, string $method = 'manual'): bool
{
    $a = q_one('SELECT id, user_id FROM qsos WHERE id = ?', [$aId]);
    $b = q_one('SELECT id, user_id FROM qsos WHERE id = ?', [$bId]);
    if (!$a || !$b || (int)$a['user_id'] === (int)$b['user_id']) {
        return false;
    }

    $ts = now_utc();
    q('UPDATE qsos SET verified = 1, verified_at = ?, matched_qso_id = ? WHERE id = ?', [$ts, $bId, $aId]);
    q('UPDATE qsos SET verified = 1, verified_at = ?, matched_qso_id = ? WHERE id = ?', [$ts, $aId, $bId]);

    q('INSERT INTO verifications (qso_id, peer_qso_id, user_id, method, created_at) VALUES (?,?,?,?,?)',
        [$aId, $bId, (int)$a['user_id'], $method, $ts]);
    q('INSERT INTO verifications (qso_id, peer_qso_id, user_id, method, created_at) VALUES (?,?,?,?,?)',
        [$bId, $aId, (int)$b['user_id'], $method, $ts]);

    return true;
}

/** 解除验证（删除 QSO 或撤销确认时） */
function qso_unlink(int $qsoId): void
{
    $row = q_one('SELECT id, matched_qso_id FROM qsos WHERE id = ?', [$qsoId]);
    if (!$row || !$row['matched_qso_id']) {
        return;
    }
    $peerId = (int)$row['matched_qso_id'];
    q('UPDATE qsos SET verified = 0, verified_at = NULL, matched_qso_id = NULL WHERE id IN (?,?)', [$qsoId, $peerId]);
    q('DELETE FROM verifications WHERE qso_id IN (?,?) OR peer_qso_id IN (?,?)', [$qsoId, $peerId, $qsoId, $peerId]);
}

/**
 * 疑似匹配（时间有出入，需人工确认）
 * 条件：同一呼号对(pair_key)、不同用户、双方均未验证、时间差 <= 容差分钟
 */
function qso_suspects(int $userId, int $toleranceMin = 15, int $limit = 100): array
{
    return q_all(
        "SELECT m.id AS my_id, m.qso_date AS my_date, m.time_on AS my_time, m.band AS my_band,
                m.mode AS my_mode, m.call AS my_peer, m.station_callsign AS my_station,
                p.id AS peer_id, p.qso_date AS peer_date, p.time_on AS peer_time,
                p.band AS peer_band, p.mode AS peer_mode,
                p.station_callsign AS peer_station, p.call AS peer_call,
                pu.callsign AS peer_owner, pu.display_name AS peer_name,
                ABS(TIMESTAMPDIFF(MINUTE,
                    CONCAT(m.qso_date, ' ', m.time_on),
                    CONCAT(p.qso_date, ' ', p.time_on))) AS diff_min
           FROM qsos m
           JOIN qsos p
             ON p.pair_key = m.pair_key
            AND p.user_id <> m.user_id
            AND p.verified = 0
            AND p.match_key <> m.match_key
           JOIN users pu ON pu.id = p.user_id
          WHERE m.user_id = ?
            AND m.verified = 0
            AND ABS(TIMESTAMPDIFF(MINUTE,
                    CONCAT(m.qso_date, ' ', m.time_on),
                    CONCAT(p.qso_date, ' ', p.time_on))) <= ?
          ORDER BY m.qso_date DESC, diff_min ASC
          LIMIT {$limit}",
        [$userId, $toleranceMin]
    );
}

/**
 * 用户统计（默认只统计已验证，用于奖项/进度）
 */
function qso_stats(int $userId, bool $verifiedOnly = false): array
{
    $w    = $verifiedOnly ? ' AND verified = 1' : '';
    $base = "FROM qsos WHERE user_id = ?{$w}";

    $total    = (int)q_val("SELECT COUNT(*) {$base}", [$userId], 0);
    $verified = (int)q_val("SELECT COUNT(*) FROM qsos WHERE user_id = ? AND verified = 1", [$userId], 0);

    $byBand = q_all("SELECT band, COUNT(*) AS c {$base} GROUP BY band ORDER BY c DESC", [$userId]);
    $byMode = q_all("SELECT mode, COUNT(*) AS c {$base} GROUP BY mode ORDER BY c DESC", [$userId]);

    // DXCC（仅统计已验证）
    $dxcc = [];
    $rows = q_all('SELECT call FROM qsos WHERE user_id = ? AND verified = 1', [$userId]);
    foreach ($rows as $r) {
        $ent = dxcc_lookup((string)$r['call']);
        if ($ent) {
            $dxcc[$ent['entity']] = ($dxcc[$ent['entity']] ?? 0) + 1;
        }
    }
    arsort($dxcc);

    // 大洲：按「已通联的实体数」计（内存查表，不再逐条查库）
    $entCont = [];
    foreach (dxcc_table() as $t) {
        $entCont[$t['entity']] ??= $t['continent'];
    }
    $continents = [];
    foreach (array_keys($dxcc) as $ent) {
        $c = $entCont[$ent] ?? '';
        if ($c !== '') {
            $continents[$c] = ($continents[$c] ?? 0) + 1;
        }
    }

    return [
        'total'      => $total,
        'verified'   => $verified,
        'pending'    => max(0, $total - $verified),
        'rate'       => $total > 0 ? round($verified * 100 / $total, 1) : 0.0,
        'by_band'    => $byBand,
        'by_mode'    => $byMode,
        'dxcc'       => $dxcc,
        'dxcc_count' => count($dxcc),
        'continents' => $continents,
    ];
}

/** 已确认通联中出现的对方用户（用于「我的 QSL 卡友」） */
function qso_confirmed_partners(int $userId, int $limit = 24): array
{
    return q_all(
        "SELECT u.id, u.callsign, u.display_name, u.country, u.card_image,
                MAX(q.verified_at) AS last_at, COUNT(*) AS n
           FROM qsos q
           JOIN qsos p ON p.id = q.matched_qso_id
           JOIN users u ON u.id = p.user_id
          WHERE q.user_id = ? AND q.verified = 1
          GROUP BY u.id, u.callsign, u.display_name, u.country, u.card_image
          ORDER BY last_at DESC
          LIMIT {$limit}",
        [$userId]
    );
}
