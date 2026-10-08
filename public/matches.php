<?php
/**
 * 待确认 —— 疑似匹配（时间有出入）与等待对方上传的记录
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];
$tol = (int)cfg('match_tolerance_min', 15);

$suspects = qso_suspects($uid, $tol, 200);

$waiting = q_all(
    'SELECT * FROM qsos WHERE user_id = ? AND verified = 0
      ORDER BY qso_date DESC, time_on DESC LIMIT 100',
    [$uid]
);
$waitingTotal = (int)q_val('SELECT COUNT(*) FROM qsos WHERE user_id = ? AND verified = 0', [$uid], 0);

app_header('待确认');
?>
<div class="page-head">
  <div><h1>待确认匹配</h1>
  <p class="subtitle">呼号对一致但时间有偏差的记录需要你人工核对；确认后双方同时标记为已验证。</p></div>
</div>

<div class="grid g3" style="margin-bottom:18px">
  <div class="stat warn"><div class="n"><?= count($suspects) ?></div><div class="k">疑似匹配（±<?= $tol ?> 分钟内）</div></div>
  <div class="stat"><div class="n"><?= $waitingTotal ?></div><div class="k">等待对方上传</div></div>
  <div class="stat brand"><div class="n"><?= (int)q_val('SELECT COUNT(*) FROM qsos WHERE user_id = ? AND verified = 1', [$uid], 0) ?></div><div class="k">已验证</div></div>
</div>

<div class="card">
  <h2>疑似匹配</h2>
  <?php if (!$suspects): ?>
    <div class="empty">没有需要人工核对的疑似匹配。<br>若对方也导入了同一通联且时间完全一致，系统会自动验证，无需操作。</div>
  <?php else: ?>
    <div class="tablewrap">
      <table class="tbl">
        <thead>
          <tr><th>我的记录</th><th>对方记录</th><th>呼号对</th><th>波段/模式</th><th>时间差</th><th>操作</th></tr>
        </thead>
        <tbody>
        <?php foreach ($suspects as $s): ?>
          <tr>
            <td class="mono"><?= e($s['my_date']) ?> <?= e(substr((string)$s['my_time'], 0, 5)) ?>
              <div class="small muted"><?= e($s['my_station']) ?> ↔ <?= e($s['my_peer']) ?></div></td>
            <td class="mono"><?= e($s['peer_date']) ?> <?= e(substr((string)$s['peer_time'], 0, 5)) ?>
              <div class="small muted"><?= e($s['peer_station']) ?> ↔ <?= e($s['peer_call']) ?></div></td>
            <td class="mono"><?= e($s['my_station']) ?> / <?= e($s['my_peer']) ?></td>
            <td><?= e($s['my_band']) ?> <?= e($s['my_mode']) ?>
              <?php if ($s['my_band'] !== $s['peer_band'] || $s['my_mode'] !== $s['peer_mode']): ?>
                <div class="small muted">对方记录为 <?= e($s['peer_band']) ?> <?= e($s['peer_mode']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= ((int)$s['diff_min'] <= 2 ? 'ok' : 'wait') ?>"><?= (int)$s['diff_min'] ?> 分钟</span></td>
            <td>
              <form method="post" action="<?= e(url('confirm.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="my_id" value="<?= (int)$s['my_id'] ?>">
                <input type="hidden" name="peer_id" value="<?= (int)$s['peer_id'] ?>">
                <button class="btn sm primary" type="submit">确认是同一次通联</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted" style="margin-top:10px">提示：时间差来自时钟误差或日志记录习惯。确认前请比对自己的原始日志。</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>等待对方上传（<?= $waitingTotal ?> 条，显示最近 100 条）</h2>
  <?php if (!$waiting): ?>
    <div class="empty">全部记录都已验证，73！</div>
  <?php else: ?>
    <div class="tablewrap">
      <table class="tbl">
        <thead><tr><th>UTC 日期/时间</th><th>对方呼号</th><th>波段</th><th>模式</th><th>DXCC</th><th>对方是否已注册</th></tr></thead>
        <tbody>
        <?php foreach ($waiting as $r):
            $peer = user_by_callsign((string)$r['call']); ?>
          <tr>
            <td class="mono"><?= e($r['qso_date']) ?> <?= e(substr((string)$r['time_on'], 0, 5)) ?></td>
            <td class="mono"><b><?= e($r['call']) ?></b></td>
            <td><?= e($r['band']) ?></td>
            <td><?= e($r['mode']) ?></td>
            <td><?= e(dxcc_entity((string)$r['call'])) ?></td>
            <td>
              <?php if ($peer): ?>
                <a href="<?= e(url('card.php?u=' . urlencode($peer['callsign']))) ?>"><?= e($peer['callsign']) ?> 已注册</a>
              <?php else: ?>
                <span class="badge gray">尚未注册</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php app_footer();
