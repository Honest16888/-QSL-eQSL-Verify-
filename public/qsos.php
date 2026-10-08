<?php
/**
 * 我的 QSO —— 列表 / 筛选 / 录入 / 删除
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

/* ------------------------------ 动作处理 ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = q_one('SELECT id FROM qsos WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($row) {
            qso_unlink($id);
            q('DELETE FROM qsos WHERE id = ?', [$id]);
            flash('已删除该 QSO' . ($row ? '' : ''));
        }
        redirect(url('qsos.php?' . http_build_query(array_filter([
            'call' => str_param('call'), 'band' => str_param('band'),
            'mode' => str_param('mode'), 'status' => str_param('status'),
        ]))));
    }

    if ($act === 'create') {
        $res = qso_create($uid, $_POST, (string)$u['callsign']);
        flash($res['ok']
            ? ($res['verified'] ? 'QSO 已记录并自动验证成功 ✅' : 'QSO 已记录，等待对方日志配对')
            : ($res['error'] ?? '保存失败'),
            $res['ok'] ? 'ok' : ($res['status'] === 'duplicate' ? 'warn' : 'err'));
        redirect(url('qsos.php'));
    }
}

/* ------------------------------ 查询条件 ------------------------------ */
$fCall   = str_param('call', '', 20);
$fBand   = str_param('band', '', 10);
$fMode   = str_param('mode', '', 20);
$fStatus = str_param('status', '', 8);
$fFrom   = str_param('from', '', 10);
$fTo     = str_param('to', '', 10);
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = ['q.user_id = ?'];
$params = [$uid];

if ($fCall !== '') {
    $where[]  = 'q.call LIKE ?';
    $params[] = '%' . mb_strtoupper($fCall) . '%';
}
if ($fBand !== '') {
    $where[]  = 'q.band = ?';
    $params[] = $fBand;
}
if ($fMode !== '') {
    $where[]  = 'q.mode = ?';
    $params[] = $fMode;
}
if ($fStatus === '1' || $fStatus === '0') {
    $where[]  = 'q.verified = ?';
    $params[] = (int)$fStatus;
}
if (valid_date($fFrom)) {
    $where[]  = 'q.qso_date >= ?';
    $params[] = $fFrom;
}
if (valid_date($fTo)) {
    $where[]  = 'q.qso_date <= ?';
    $params[] = $fTo;
}
$wSql = implode(' AND ', $where);

$total = (int)q_val("SELECT COUNT(*) FROM qsos q WHERE {$wSql}", $params, 0);
$p     = paginate($total, 25, $page);

$rows = q_all(
    "SELECT q.*, pu.callsign AS peer_owner
       FROM qsos q
       LEFT JOIN qsos pq ON pq.id = q.matched_qso_id
       LEFT JOIN users pu ON pu.id = pq.user_id
      WHERE {$wSql}
      ORDER BY q.qso_date DESC, q.time_on DESC
      LIMIT {$p['limit']} OFFSET {$p['offset']}",
    $params
);

$bands = q_all('SELECT DISTINCT band FROM qsos WHERE user_id = ? ORDER BY band', [$uid]);
$modes = q_all('SELECT DISTINCT mode FROM qsos WHERE user_id = ? ORDER BY mode', [$uid]);

app_header('我的 QSO');
?>

<div class="page-head">
  <div>
    <h1>我的 QSO</h1>
    <p class="subtitle">共 <?= $total ?> 条记录（按 UTC 时间倒序）</p>
  </div>
  <div class="spacer"></div>
  <div class="btn-row">
    <a class="btn" href="<?= e(url('import.php')) ?>">ADIF 导入</a>
    <a class="btn primary" href="<?= e(url('export.php')) ?>">导出 ADIF</a>
  </div>
</div>

<div class="tabs">
  <a href="#" class="on" onclick="document.getElementById('addbox').scrollIntoView({behavior:'smooth'});return false;">＋ 添加单条 QSO</a>
  <a href="<?= e(url('matches.php')) ?>">待确认匹配</a>
</div>

<form class="filters" method="get" action="<?= e(url('qsos.php')) ?>">
  <div class="field"><label>呼号包含</label><input type="text" name="call" class="mono" value="<?= e($fCall) ?>" placeholder="JA"></div>
  <div class="field"><label>波段</label>
    <select name="band"><option value="">全部</option>
      <?php foreach ($bands as $b): ?><option value="<?= e($b['band']) ?>" <?= $b['band'] === $fBand ? 'selected' : '' ?>><?= e($b['band']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>模式</label>
    <select name="mode"><option value="">全部</option>
      <?php foreach ($modes as $m): ?><option value="<?= e($m['mode']) ?>" <?= $m['mode'] === $fMode ? 'selected' : '' ?>><?= e($m['mode']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label>状态</label>
    <select name="status">
      <option value="">全部</option>
      <option value="1" <?= $fStatus === '1' ? 'selected' : '' ?>>已验证</option>
      <option value="0" <?= $fStatus === '0' ? 'selected' : '' ?>>待确认</option>
    </select>
  </div>
  <div class="field"><label>起</label><input type="date" name="from" value="<?= e($fFrom) ?>"></div>
  <div class="field"><label>止</label><input type="date" name="to" value="<?= e($fTo) ?>"></div>
  <button class="btn" type="submit">筛选</button>
  <a class="btn" href="<?= e(url('qsos.php')) ?>">重置</a>
</form>

<div class="card" id="addbox">
  <h2>添加单条 QSO</h2>
  <form method="post" action="<?= e(url('qsos.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="create">
    <div class="form-row c4">
      <div class="field"><label>本方呼号</label>
        <input type="text" name="station_callsign" class="mono" value="<?= e($u['callsign']) ?>" required>
      </div>
      <div class="field"><label>对方呼号 *</label>
        <input type="text" name="call" class="mono" required placeholder="JA1ABC">
      </div>
      <div class="field"><label>日期 (UTC) *</label>
        <input type="date" name="qso_date" required value="<?= e(gmdate('Y-m-d')) ?>">
      </div>
      <div class="field"><label>时间 (UTC) *</label>
        <input type="time" name="time_on" required value="<?= e(gmdate('H:i')) ?>">
      </div>
    </div>
    <div class="form-row c4">
      <div class="field"><label>波段 *</label>
        <select name="band">
          <?php foreach (['160m','80m','60m','40m','30m','20m','17m','15m','12m','10m','6m','4m','2m','1.25m','70cm','33cm','23cm','13cm'] as $b): ?>
            <option value="<?= e($b) ?>" <?= $b === '20m' ? 'selected' : '' ?>><?= e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>模式 *</label>
        <select name="mode">
          <?php foreach (['SSB','CW','FT8','FT4','RTTY','PSK','AM','FM','DMR','D-STAR','C4FM','SSTV','PACKET','Q65','MSK144','JS8','OLIVIA','MFSK','ATV'] as $m): ?>
            <option value="<?= e($m) ?>"><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>频率 MHz</label><input type="number" step="0.0001" name="freq" placeholder="14.074"></div>
      <div class="field"><label>功率 W</label><input type="number" step="0.1" name="tx_pwr" placeholder="100"></div>
    </div>
    <div class="form-row c4">
      <div class="field"><label>RST 发送</label><input type="text" name="rst_sent" value="59"></div>
      <div class="field"><label>RST 接收</label><input type="text" name="rst_rcvd" value="59"></div>
      <div class="field"><label>对方网格</label><input type="text" name="gridsquare" class="mono" placeholder="PM95"></div>
      <div class="field"><label>对方 OP</label><input type="text" name="name" placeholder="Taro"></div>
    </div>
    <div class="field"><label>备注</label><input type="text" name="comment" placeholder="QSL via bureau / eQSL"></div>
    <button class="btn primary" type="submit">保存 QSO</button>
  </form>
</div>

<div class="card">
  <h2>QSO 列表</h2>
  <?php if (!$rows): ?>
    <div class="empty">没有符合条件的记录。</div>
  <?php else: ?>
    <div class="tablewrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>UTC 日期/时间</th><th>对方呼号</th><th>本方呼号</th><th>波段</th><th>模式</th>
            <th>频率</th><th>RST 收/发</th><th>DXCC</th><th>状态</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono"><?= e($r['qso_date']) ?> <?= e(substr((string)$r['time_on'], 0, 5)) ?></td>
            <td class="mono"><b><?= e($r['call']) ?></b></td>
            <td class="mono"><?= e($r['station_callsign']) ?></td>
            <td><?= e($r['band']) ?></td>
            <td><?= e($r['mode']) ?></td>
            <td class="mono"><?= $r['freq'] !== null ? e(number_format((float)$r['freq'], 4)) : '—' ?></td>
            <td class="mono"><?= e($r['rst_rcvd']) ?> / <?= e($r['rst_sent']) ?></td>
            <td><?= e(dxcc_entity((string)$r['call'])) ?></td>
            <td><?= badge_verified((int)$r['verified']) ?>
              <?php if ($r['verified'] && $r['peer_owner']): ?>
                <div class="small muted">对方：<?= e($r['peer_owner']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn sm" href="<?= e(url('verify.php?code=' . urlencode($r['verify_code']))) ?>">验证页</a>
              <a class="btn sm" href="<?= e(url('qso_edit.php?id=' . (int)$r['id'])) ?>">编辑</a>
              <form method="post" action="<?= e(url('qsos.php')) ?>" style="display:inline"
                    onsubmit="return confirm('确定删除这条 QSO？')">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn sm danger" type="submit">删除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pager_links($p, url('qsos.php'), array_filter([
        'call' => $fCall, 'band' => $fBand, 'mode' => $fMode,
        'status' => $fStatus, 'from' => $fFrom, 'to' => $fTo,
    ])) ?>
  <?php endif; ?>
</div>
<?php app_footer();
