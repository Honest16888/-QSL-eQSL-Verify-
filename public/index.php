<?php
/**
 * 总览仪表盘
 */
require __DIR__ . '/../includes/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];

// 快速录入
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = qso_create($uid, $_POST, (string)$u['callsign']);
    if ($res['ok']) {
        flash($res['verified']
            ? '已记录并与对方日志匹配成功 —— 该 QSL 已双向验证 ✅'
            : 'QSO 已记录，等待对方上传同一通联后自动验证');
    } else {
        flash($res['error'] ?? '保存失败', $res['status'] === 'duplicate' ? 'warn' : 'err');
    }
    redirect(url('index.php'));
}

$stats   = qso_stats($uid);
$pending = (int)q_val('SELECT COUNT(*) FROM qsos WHERE user_id = ? AND verified = 0', [$uid], 0);
$suspect = count(qso_suspects($uid, (int)cfg('match_tolerance_min', 15), 50));

$recent = q_all(
    'SELECT * FROM qsos WHERE user_id = ? ORDER BY qso_date DESC, time_on DESC LIMIT 12',
    [$uid]
);
$partners = qso_confirmed_partners($uid, 8);

app_header('总览');
?>

<div class="page-head">
  <div>
    <h1>73 de <?= e($u['callsign']) ?> 👋</h1>
    <p class="subtitle">欢迎回来。下面是你的 QSL 交换与验证进度。</p>
  </div>
  <div class="spacer"></div>
  <div class="btn-row">
    <a class="btn primary" href="<?= e(url('import.php')) ?>">导入 ADIF</a>
    <a class="btn" href="<?= e(url('export.php')) ?>">导出 ADIF</a>
    <a class="btn" href="<?= e(url('card.php?u=' . urlencode($u['callsign']))) ?>">查看我的卡片</a>
  </div>
</div>

<div class="grid g4">
  <div class="stat"><div class="n"><?= (int)$stats['total'] ?></div><div class="k">日志 QSO 总数</div></div>
  <div class="stat ok"><div class="n"><?= (int)$stats['verified'] ?></div><div class="k">已双向验证</div></div>
  <div class="stat warn"><div class="n"><?= (int)$stats['pending'] ?></div><div class="k">待确认</div></div>
  <div class="stat brand"><div class="n"><?= (int)$stats['dxcc_count'] ?></div><div class="k">DXCC 实体（已验证）</div></div>
</div>

<?php if ($suspect > 0): ?>
  <div class="note warn" style="margin:16px 0">
    检测到 <b><?= $suspect ?></b> 组「疑似匹配」——呼号对一致但时间有偏差，需要你人工核对。
    <a href="<?= e(url('matches.php')) ?>">前往确认 →</a>
  </div>
<?php endif; ?>

<div class="grid g2" style="margin-top:18px">
  <div class="card">
    <h2>快速记录一次通联</h2>
    <form method="post" action="<?= e(url('index.php')) ?>">
      <?= csrf_field() ?>
      <div class="form-row c3">
        <div class="field">
          <label>对方呼号</label>
          <input type="text" name="call" required placeholder="JA1ABC" class="mono" autocomplete="off">
        </div>
        <div class="field">
          <label>日期 (UTC)</label>
          <input type="date" name="qso_date" required value="<?= e(gmdate('Y-m-d')) ?>">
        </div>
        <div class="field">
          <label>时间 (UTC)</label>
          <input type="time" name="time_on" required value="<?= e(gmdate('H:i')) ?>">
        </div>
      </div>
      <div class="form-row c4">
        <div class="field">
          <label>波段</label>
          <select name="band">
            <?php foreach (['160m','80m','60m','40m','30m','20m','17m','15m','12m','10m','6m','2m','70cm','23cm'] as $b): ?>
              <option value="<?= e($b) ?>" <?= $b === '20m' ? 'selected' : '' ?>><?= e($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>模式</label>
          <select name="mode">
            <?php foreach (['SSB','CW','FT8','FT4','RTTY','PSK','AM','FM','DMR','D-STAR','C4FM','SSTV','PACKET','Q65','MSK144'] as $m): ?>
              <option value="<?= e($m) ?>"><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>RST 发送</label>
          <input type="text" name="rst_sent" value="59" placeholder="59">
        </div>
        <div class="field">
          <label>RST 接收</label>
          <input type="text" name="rst_rcvd" value="59" placeholder="59">
        </div>
      </div>
      <input type="hidden" name="station_callsign" value="<?= e($u['callsign']) ?>">
      <button class="btn primary" type="submit">记录并尝试验证</button>
      <span class="small muted" style="margin-left:10px">时间一律按 UTC 填写，分钟精度用于指纹匹配</span>
    </form>
  </div>

  <div class="card">
    <h2>最近的 QSL 卡友</h2>
    <?php if (!$partners): ?>
      <div class="empty">还没有双向验证的卡友。<br>导入 ADIF 或手动添加 QSO，等对方也上传同一通联即可自动配对。</div>
    <?php else: ?>
      <?php foreach ($partners as $p): ?>
        <div class="partner">
          <div class="avatar"><?= e(mb_substr((string)$p['callsign'], 0, 3)) ?></div>
          <div style="flex:1;min-width:0">
            <div><a href="<?= e(url('card.php?u=' . urlencode($p['callsign']))) ?>" class="mono"><b><?= e($p['callsign']) ?></b></a>
              <?php if ($p['display_name']): ?><span class="muted"> · <?= e($p['display_name']) ?></span><?php endif; ?></div>
            <div class="small muted"><?= e($p['country'] ?? '') ?> · <?= (int)$p['n'] ?> 张 · 最近 <?= e(substr((string)$p['last_at'], 0, 10)) ?></div>
          </div>
          <span class="badge ok">已验证</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>最近记录的 QSO</h2>
  <?php if (!$recent): ?>
    <div class="empty">日志为空。可从电台日志软件导出 ADIF 后批量导入。</div>
  <?php else: ?>
    <div class="tablewrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>日期 (UTC)</th><th>时间</th><th>对方呼号</th><th>波段</th><th>模式</th>
            <th>RST 收/发</th><th>DXCC</th><th>状态</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="mono"><?= e($r['qso_date']) ?></td>
            <td class="mono"><?= e(substr((string)$r['time_on'], 0, 5)) ?></td>
            <td class="mono"><b><?= e($r['call']) ?></b></td>
            <td><?= e($r['band']) ?></td>
            <td><?= e($r['mode']) ?></td>
            <td class="mono"><?= e($r['rst_rcvd']) ?> / <?= e($r['rst_sent']) ?></td>
            <td><?= e(dxcc_entity((string)$r['call'])) ?></td>
            <td><?= badge_verified((int)$r['verified']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px"><a class="btn sm" href="<?= e(url('qsos.php')) ?>">查看全部 →</a></div>
  <?php endif; ?>
</div>

<?php app_footer();
