<?php
/**
 * 统计
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

$verifiedOnly = (int)($_GET['vo'] ?? 1) === 1;
$s = qso_stats($uid, $verifiedOnly);

$maxBand = max(1, (int)($s['by_band'][0]['c'] ?? 1));
$maxMode = max(1, (int)($s['by_mode'][0]['c'] ?? 1));

app_header('统计');
?>
<div class="page-head">
  <div><h1>通联统计</h1>
  <p class="subtitle">DXCC 与大陆统计仅计入<b>已双向验证</b>的通联，与奖项口径一致。</p></div>
  <div class="spacer"></div>
  <div class="btn-row">
    <a class="btn <?= $verifiedOnly ? 'primary' : '' ?>" href="<?= e(url('stats.php?vo=1')) ?>">仅已验证</a>
    <a class="btn <?= !$verifiedOnly ? 'primary' : '' ?>" href="<?= e(url('stats.php?vo=0')) ?>">全部日志</a>
  </div>
</div>

<div class="grid g4">
  <div class="stat"><div class="n"><?= (int)$s['total'] ?></div><div class="k"><?= $verifiedOnly ? '已验证 QSO' : '日志 QSO' ?></div></div>
  <div class="stat ok"><div class="n"><?= (int)$s['verified'] ?></div><div class="k">双向验证</div></div>
  <div class="stat warn"><div class="n"><?= (int)$s['pending'] ?></div><div class="k">待确认</div></div>
  <div class="stat brand"><div class="n"><?= (float)$s['rate'] ?>%</div><div class="k">验证率</div></div>
</div>

<div class="grid g2" style="margin-top:18px">
  <div class="card">
    <h2>波段分布</h2>
    <?php if (!$s['by_band']): ?><div class="empty">暂无数据</div><?php else: ?>
      <div class="bars">
        <?php foreach ($s['by_band'] as $b): ?>
          <div class="bar">
            <span><?= e($b['band']) ?></span>
            <span class="track"><span class="fill" style="width:<?= round(((int)$b['c']) * 100 / $maxBand, 1) ?>%"></span></span>
            <span class="cnt"><?= (int)$b['c'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>模式分布</h2>
    <?php if (!$s['by_mode']): ?><div class="empty">暂无数据</div><?php else: ?>
      <div class="bars">
        <?php foreach ($s['by_mode'] as $m): ?>
          <div class="bar">
            <span><?= e($m['mode']) ?></span>
            <span class="track"><span class="fill" style="width:<?= round(((int)$m['c']) * 100 / $maxMode, 1) ?>%"></span></span>
            <span class="cnt"><?= (int)$m['c'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <h2>DXCC 实体（已验证 <?= (int)$s['dxcc_count'] ?>）</h2>
    <?php if (!$s['dxcc']): ?>
      <div class="empty">还没有已验证的 DXCC</div>
    <?php else: ?>
      <div class="chips">
        <?php foreach ($s['dxcc'] as $ent => $n): ?>
          <span class="chip"><?= e($ent) ?> <span class="muted">×<?= (int)$n ?></span></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>大洲分布</h2>
    <?php if (!$s['continents']): ?>
      <div class="empty">暂无数据</div>
    <?php else: ?>
      <div class="chips">
        <?php foreach ($s['continents'] as $c => $n): ?>
          <span class="chip"><?= e(continent_cn((string)$c)) ?> (<?= e($c) ?>) <span class="muted">×<?= (int)$n ?></span></span>
        <?php endforeach; ?>
      </div>
      <hr class="hr">
      <p class="small muted">WAS / DXCC 百国 等奖项进度以「已双向验证」为准。导入对方日志后会自动更新。</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>导出</h2>
  <p class="small muted">导出的 ADIF 带有 <code>APP_EQSL_VERIFIED</code> 自定义字段，便于回填到你的日志软件。</p>
  <div class="btn-row">
    <a class="btn primary" href="<?= e(url('export.php?verified=1')) ?>">下载已验证 QSO (ADIF)</a>
    <a class="btn" href="<?= e(url('export.php')) ?>">下载全部 QSO (ADIF)</a>
  </div>
</div>
<?php app_footer();
