<?php
/**
 * 电子 QSL 卡片（公开页）
 * 访问：/card.php?u=BH1ABC
 */
require __DIR__ . '/../includes/bootstrap.php';

$call = str_param('u', '', 20);
$owner = $call !== '' ? user_by_callsign($call) : null;

if (!$owner) {
    app_header('QSL 卡片');
    ?>
    <div class="card">
      <h1>未找到该台站</h1>
      <p class="muted">呼号 <b class="mono"><?= e($call) ?></b> 尚未在本站注册。</p>
      <a class="btn" href="<?= e(url('index.php')) ?>">返回首页</a>
    </div>
    <?php
    app_footer();
    exit;
}

$viewer = current_user();
$oid    = (int)$owner['id'];

// 访客与该台站的已验证通联
$shared = [];
$sharedCount = 0;
if ($viewer && (int)$viewer['id'] !== $oid) {
    $shared = q_all(
        "SELECT q.* FROM qsos q
          JOIN qsos p ON p.id = q.matched_qso_id
         WHERE q.user_id = ? AND p.user_id = ? AND q.verified = 1
         ORDER BY q.qso_date DESC, q.time_on DESC LIMIT 20",
        [(int)$viewer['id'], $oid]
    );
    $sharedCount = (int)q_val(
        "SELECT COUNT(*) FROM qsos q JOIN qsos p ON p.id = q.matched_qso_id
          WHERE q.user_id = ? AND p.user_id = ? AND q.verified = 1",
        [(int)$viewer['id'], $oid], 0
    );
}

$ownerStats = qso_stats($oid);
$imgUrl = $owner['card_image'] ? url(cfg('upload.url_path', '/uploads') . '/' . $owner['card_image']) : '';

app_header($owner['callsign'] . ' 的电子 QSL 卡');
?>

<div class="page-head">
  <div><h1><?= e($owner['callsign']) ?> 的电子 QSL 卡</h1>
  <p class="subtitle">本站电子卡片 —— 与纸质 QSL 具有同样的交换意义，但瞬间送达。</p></div>
  <div class="spacer"></div>
  <?php if ($viewer && (int)$viewer['id'] === $oid): ?>
    <a class="btn primary" href="<?= e(url('profile.php')) ?>">编辑卡片内容</a>
  <?php endif; ?>
</div>

<?php if ($viewer && (int)$viewer['id'] !== $oid && $sharedCount > 0): ?>
  <div class="note">你与 <b><?= e($owner['callsign']) ?></b> 之间已有 <b><?= $sharedCount ?></b> 张双向验证的 QSL。</div>
<?php endif; ?>

<div class="grid g2">
  <div>
    <div class="qslcard <?= $imgUrl ? 'withimg' : '' ?>">
      <div class="inner" <?= $imgUrl ? 'style="background-image:url(' . e($imgUrl) . ')"' : '' ?>>
        <div class="stamp">CONFIRMED<br>QSL</div>
        <div class="hd">
          <span class="call"><?= e($owner['callsign']) ?></span>
          <span class="to">To Radio Amateur / 致 业余无线电爱好者</span>
        </div>
        <dl>
          <dt>OP</dt><dd><?= e($owner['display_name'] ?: '—') ?></dd>
          <dt>QTH</dt><dd><?= e($owner['qth'] ?: '—') ?></dd>
          <dt>网格</dt><dd class="mono"><?= e($owner['locator'] ?: '—') ?></dd>
          <dt>DXCC</dt><dd><?= e($owner['country'] ?: dxcc_entity((string)$owner['callsign'])) ?></dd>
          <dt>坐标</dt><dd><?= $owner['latitude'] !== null ? e($owner['latitude']) . ', ' . e($owner['longitude']) : '—' ?></dd>
          <dt>设备</dt><dd><?= e($owner['rig'] ?: '—') ?></dd>
          <dt>天线</dt><dd><?= e($owner['antenna'] ?: '—') ?></dd>
          <dt>已验证 QSO</dt><dd><?= (int)$ownerStats['verified'] ?> 条 · DXCC <?= (int)$ownerStats['dxcc_count'] ?></dd>
          <?php if ($owner['bio']): ?><dt>附言</dt><dd><?= e($owner['bio']) ?></dd><?php endif; ?>
        </dl>
        <div class="ft">
          <span>本卡片由 <?= e(cfg('site_name', 'eQSL')) ?> 生成</span>
          <span>73 &amp; Good DX!</span>
        </div>
      </div>
      <div class="confirm">
        <span>✅ 卡片内容由台站本人维护，通联经双方日志交叉验证</span>
        <span class="mono">ID <?= e(mb_substr((string)$owner['callsign'], 0, 8)) ?>-<?= (int)$owner['id'] ?></span>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h2>附加呼号</h2>
      <?php
      $aliases = q_all('SELECT * FROM user_callsigns WHERE user_id = ? ORDER BY callsign', [$oid]);
      ?>
      <?php if (!$aliases): ?>
        <p class="muted small">未登记附加呼号。</p>
      <?php else: ?>
        <div class="chips">
          <?php foreach ($aliases as $a): ?>
            <span class="chip gray mono"><?= e($a['callsign']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($viewer && (int)$viewer['id'] !== $oid): ?>
      <div class="card">
        <h2>我们之间的已验证通联（<?= $sharedCount ?>）</h2>
        <?php if (!$shared): ?>
          <div class="empty">还没有双向验证的通联。<br>导入日志后，若对方也上传了同一通联，系统会自动配对。</div>
        <?php else: ?>
          <div class="tablewrap">
            <table class="tbl">
              <thead><tr><th>UTC</th><th>波段</th><th>模式</th><th>RST 收/发</th><th>验证页</th></tr></thead>
              <tbody>
              <?php foreach ($shared as $r): ?>
                <tr>
                  <td class="mono"><?= e($r['qso_date']) ?> <?= e(substr((string)$r['time_on'], 0, 5)) ?></td>
                  <td><?= e($r['band']) ?></td>
                  <td><?= e($r['mode']) ?></td>
                  <td class="mono"><?= e($r['rst_rcvd']) ?> / <?= e($r['rst_sent']) ?></td>
                  <td><a class="btn sm" href="<?= e(url('verify.php?code=' . urlencode($r['verify_code']))) ?>">查看</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="card">
        <h2>如何交换？</h2>
        <ol class="small" style="padding-left:20px;margin:0;color:var(--ink-soft)">
          <li>注册台站并导入你的 ADIF 日志；</li>
          <li>当双方日志出现同一通联（呼号对 + UTC 时间 + 波段 + 模式一致），系统自动双向验证；</li>
          <li>已验证的通联会立刻出现在彼此的 QSL 卡页；</li>
          <li>每条通联都有独立的公开验证码，可发给第三方核验。</li>
        </ol>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php app_footer();
