<?php
/**
 * 编辑单条 QSO
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$row = q_one('SELECT * FROM qsos WHERE id = ? AND user_id = ?', [$id, $uid]);
if (!$row) {
    flash('记录不存在或无权访问', 'err');
    redirect(url('qsos.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$errs, $d] = qso_validate($_POST, (string)$u['callsign']);
    if ($errs) {
        flash(implode('；', $errs), 'err');
    } else {
        $mk = qso_match_key($d['station_callsign'], $d['call'], $d['qso_date'], $d['time_on'], $d['band'], $d['mode']);
        $pk = qso_pair_key($d['station_callsign'], $d['call']);

        // 关键字段变化会改变指纹，先解除既有验证
        qso_unlink($id);

        db()->prepare(
            'UPDATE qsos SET station_callsign=?, call=?, qso_date=?, time_on=?, band=?, mode=?, submode=?,
             freq=?, rst_sent=?, rst_rcvd=?, tx_pwr=?, gridsquare=?, qth=?, name=?, comment=?,
             match_key=?, pair_key=? WHERE id=? AND user_id=?'
        )->execute([
            $d['station_callsign'], $d['call'], $d['qso_date'], $d['time_on'], $d['band'], $d['mode'],
            $d['submode'], $d['freq'], $d['rst_sent'], $d['rst_rcvd'], $d['tx_pwr'], $d['gridsquare'],
            $d['qth'], $d['name'], $d['comment'], $mk, $pk, $id, $uid,
        ]);

        $ok = qso_try_auto_match($id);
        flash($ok ? '已保存，并自动匹配到对方日志 ✅' : '已保存，等待对方日志配对');
        redirect(url('qsos.php'));
    }
    $row = array_merge($row, $_POST);
}

app_header('编辑 QSO');
?>
<div class="page-head"><div><h1>编辑 QSO</h1>
  <p class="subtitle mono"><?= e($row['qso_date']) ?> <?= e(substr((string)$row['time_on'], 0, 5)) ?> · <?= e($row['call']) ?> · <?= e($row['band']) ?> <?= e($row['mode']) ?></p></div></div>

<div class="card" style="max-width:860px">
  <form method="post" action="<?= e(url('qso_edit.php?id=' . $id)) ?>">
    <?= csrf_field() ?>
    <div class="form-row c4">
      <div class="field"><label>本方呼号</label><input type="text" name="station_callsign" class="mono" value="<?= e($row['station_callsign']) ?>" required></div>
      <div class="field"><label>对方呼号</label><input type="text" name="call" class="mono" value="<?= e($row['call']) ?>" required></div>
      <div class="field"><label>日期 (UTC)</label><input type="date" name="qso_date" value="<?= e($row['qso_date']) ?>" required></div>
      <div class="field"><label>时间 (UTC)</label><input type="time" name="time_on" value="<?= e(substr((string)$row['time_on'], 0, 5)) ?>" required></div>
    </div>
    <div class="form-row c4">
      <div class="field"><label>波段</label><input type="text" name="band" value="<?= e($row['band']) ?>" required></div>
      <div class="field"><label>模式</label><input type="text" name="mode" value="<?= e($row['mode']) ?>" required></div>
      <div class="field"><label>频率 MHz</label><input type="number" step="0.0001" name="freq" value="<?= e($row['freq']) ?>"></div>
      <div class="field"><label>功率 W</label><input type="number" step="0.1" name="tx_pwr" value="<?= e($row['tx_pwr']) ?>"></div>
    </div>
    <div class="form-row c4">
      <div class="field"><label>RST 发送</label><input type="text" name="rst_sent" value="<?= e($row['rst_sent']) ?>"></div>
      <div class="field"><label>RST 接收</label><input type="text" name="rst_rcvd" value="<?= e($row['rst_rcvd']) ?>"></div>
      <div class="field"><label>对方网格</label><input type="text" name="gridsquare" class="mono" value="<?= e($row['gridsquare']) ?>"></div>
      <div class="field"><label>对方 OP</label><input type="text" name="name" value="<?= e($row['name']) ?>"></div>
    </div>
    <div class="field"><label>备注</label><input type="text" name="comment" value="<?= e($row['comment']) ?>"></div>
    <div class="btn-row">
      <button class="btn primary" type="submit">保存</button>
      <a class="btn" href="<?= e(url('qsos.php')) ?>">返回列表</a>
      <span class="small muted">修改呼号 / 时间 / 波段 / 模式会重算指纹并解除原有验证</span>
    </div>
  </form>
</div>
<?php app_footer();
