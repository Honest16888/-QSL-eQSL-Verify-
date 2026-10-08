<?php
/**
 * 公开验证页 —— 凭验证码查询某次通联是否经双方日志交叉验证
 * 访问：/verify.php?code=xxxxxxxxxxxxxxxx
 */
require __DIR__ . '/../includes/bootstrap.php';

$code = str_param('code', '', 16);
$row  = $code !== '' ? q_one('SELECT * FROM qsos WHERE verify_code = ?', [$code]) : null;

app_header('QSO 验证查询');
?>
<div class="page-head">
  <div><h1>QSO 验证查询</h1>
  <p class="subtitle">任何人凭验证码均可核验一次通联是否经双方日志交叉确认。</p></div>
</div>

<?php if (!$row): ?>
  <div class="card">
    <form method="get" action="<?= e(url('verify.php')) ?>">
      <div class="field">
        <label>验证码</label>
        <input type="text" name="code" class="mono" placeholder="16 位验证码" value="<?= e($code) ?>" required>
      </div>
      <button class="btn primary" type="submit">查询</button>
    </form>
    <?php if ($code !== ''): ?>
      <div class="flash err" style="margin-top:14px">未找到该验证码对应的通联记录。</div>
    <?php endif; ?>
  </div>
<?php else:
  $dx   = dxcc_lookup((string)$row['call']);
  $peer = q_one('SELECT call, station_callsign FROM qsos WHERE id = ?', [(int)$row['matched_qso_id']]) ;
?>
  <div class="card" style="max-width:760px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
      <h1 style="margin:0;font-family:ui-monospace,Consolas,monospace">
        <?= e($row['station_callsign']) ?> <span class="muted">↔</span> <?= e($row['call']) ?>
      </h1>
      <?= badge_verified((int)$row['verified']) ?>
    </div>

    <dl class="kv">
      <dt>UTC 时间</dt><dd class="mono"><?= e($row['qso_date']) ?> <?= e(substr((string)$row['time_on'], 0, 5)) ?></dd>
      <dt>波段 / 模式</dt><dd><?= e($row['band']) ?> · <?= e($row['mode']) ?><?= $row['submode'] ? ' (' . e($row['submode']) . ')' : '' ?></dd>
      <?php if ($row['freq']): ?><dt>频率</dt><dd class="mono"><?= e(number_format((float)$row['freq'], 4)) ?> MHz</dd><?php endif; ?>
      <dt>DXCC</dt><dd><?= e($dx ? $dx['entity'] : '未知') ?><?= $dx && $dx['continent'] ? ' · ' . e(continent_cn((string)$dx['continent'])) : '' ?></dd>
      <dt>验证状态</dt>
      <dd>
        <?php if ($row['verified']): ?>
          <b style="color:var(--ok)">双方日志已交叉确认</b>
          <?php if ($row['verified_at']): ?>（<?= e($row['verified_at']) ?> UTC）<?php endif; ?>
        <?php else: ?>
          仅有一方日志，尚未获得对方确认
        <?php endif; ?>
      </dd>
      <dt>验证码</dt><dd class="mono"><?= e($row['verify_code']) ?></dd>
      <dt>记录时间</dt><dd class="mono"><?= e($row['created_at']) ?> UTC</dd>
    </dl>

    <hr class="hr">
    <p class="small muted">
      本页面仅用于核验通联事实，不公开 RST、网格、备注等日志细节。
      <?php if ($row['verified']): ?>
        对方日志中的对应记录同样标记为已验证。
      <?php endif; ?>
    </p>
    <div class="btn-row">
      <a class="btn" href="<?= e(url('verify.php')) ?>">查询其他验证码</a>
      <?php if (current_user()): ?>
        <a class="btn" href="<?= e(url('qsos.php')) ?>">我的 QSO</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php app_footer();
