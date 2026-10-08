<?php
/**
 * ADIF 导入
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $text = '';
    if (!empty($_FILES['adif']['tmp_name']) && is_uploaded_file($_FILES['adif']['tmp_name'])) {
        if ((int)$_FILES['adif']['size'] > 12 * 1024 * 1024) {
            flash('文件过大（上限 12 MB）', 'err');
            redirect(url('import.php'));
        }
        $text = (string)file_get_contents($_FILES['adif']['tmp_name']);
    } else {
        $text = (string)($_POST['adif_text'] ?? '');
    }

    if (trim($text) === '') {
        flash('没有可导入的内容', 'err');
        redirect(url('import.php'));
    }

    $forceStation = !empty($_POST['force_station']);
    $records      = adif_parse($text);

    $stat = ['total' => count($records), 'new' => 0, 'dup' => 0, 'verified' => 0, 'bad' => 0];
    $errList = [];

    foreach ($records as $rec) {
        $call = norm_call((string)($rec['CALL'] ?? ''));
        $date = adif_date($rec['QSO_DATE'] ?? null);
        $time = adif_time($rec['TIME_ON'] ?? $rec['TIME_OFF'] ?? null);
        $band = canon_band((string)($rec['BAND'] ?? ''));
        $freq = (float)($rec['FREQ'] ?? 0);
        if ($band === '' && $freq > 0) {
            $band = band_from_freq($freq);
        }
        $mode = canon_mode((string)($rec['MODE'] ?? $rec['SUBMODE'] ?? ''));
        $station = $forceStation
            ? (string)$u['callsign']
            : (norm_call((string)($rec['STATION_CALLSIGN'] ?? $rec['OPERATOR'] ?? '')) ?: (string)$u['callsign']);

        $in = [
            'station_callsign' => $station,
            'call'             => $call,
            'qso_date'         => $date ?? '',
            'time_on'          => $time ?? '',
            'band'             => $band,
            'mode'             => $mode,
            'submode'          => mb_substr((string)($rec['SUBMODE'] ?? ''), 0, 20),
            'freq'             => $freq > 0 ? $freq : null,
            'rst_sent'         => mb_substr((string)($rec['RST_SENT'] ?? ''), 0, 12),
            'rst_rcvd'         => mb_substr((string)($rec['RST_RCVD'] ?? ''), 0, 12),
            'tx_pwr'           => ($rec['TX_PWR'] ?? '') === '' ? null : (float)$rec['TX_PWR'],
            'gridsquare'       => mb_substr((string)($rec['GRIDSQUARE'] ?? ''), 0, 12),
            'qth'              => mb_substr((string)($rec['QTH'] ?? ''), 0, 120),
            'name'             => mb_substr((string)($rec['NAME'] ?? ''), 0, 80),
            'comment'          => mb_substr((string)($rec['COMMENT'] ?? $rec['NOTES'] ?? ''), 0, 255),
        ];

        $res = qso_create($uid, $in, (string)$u['callsign']);
        if ($res['ok']) {
            $stat['new']++;
            if ($res['verified']) {
                $stat['verified']++;
            }
        } elseif ($res['status'] === 'duplicate') {
            $stat['dup']++;
        } else {
            $stat['bad']++;
            if (count($errList) < 8) {
                $errList[] = ($call ?: '(空呼号)') . '：' . (string)$res['error'];
            }
        }
    }

    $report = ['stat' => $stat, 'errors' => $errList];
}

app_header('ADIF 导入');
?>
<div class="page-head">
  <div><h1>导入 ADIF 日志</h1>
  <p class="subtitle">支持 .adi / .adif 文件，也可直接粘贴导出文本。所有时间按 UTC 处理。</p></div>
</div>

<?php if ($report): ?>
  <div class="card">
    <h2>导入结果</h2>
    <div class="grid g4">
      <div class="stat"><div class="n"><?= (int)$report['stat']['total'] ?></div><div class="k">解析记录</div></div>
      <div class="stat brand"><div class="n"><?= (int)$report['stat']['new'] ?></div><div class="k">新增</div></div>
      <div class="stat ok"><div class="n"><?= (int)$report['stat']['verified'] ?></div><div class="k">当场自动验证</div></div>
      <div class="stat warn"><div class="n"><?= (int)$report['stat']['dup'] ?></div><div class="k">重复跳过</div></div>
    </div>
    <?php if ($report['stat']['bad'] > 0): ?>
      <hr class="hr">
      <div class="flash err"><b><?= (int)$report['stat']['bad'] ?></b> 条记录无法导入（缺少必要字段）：
        <ul style="margin:8px 0 0;padding-left:20px">
          <?php foreach ($report['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($report['stat']['new'] > 0): ?>
      <div class="btn-row" style="margin-top:14px">
        <a class="btn primary" href="<?= e(url('matches.php')) ?>">查看待确认匹配</a>
        <a class="btn" href="<?= e(url('qsos.php')) ?>">查看我的 QSO</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <form method="post" action="<?= e(url('import.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="field">
      <label>选择 ADIF 文件</label>
      <input type="file" name="adif" accept=".adi,.adif,.txt">
      <div class="hint">从 HRD / Log4OM / N1MM / WSJT-X / fldigi 等导出的 ADIF 均可</div>
    </div>
    <div class="field">
      <label>或直接粘贴 ADIF 文本</label>
      <textarea name="adif_text" class="mono" placeholder="<CALL:6>JA1ABC <QSO_DATE:8>20260101 ..."></textarea>
    </div>
    <div class="field">
      <label class="checkline">
        <input type="checkbox" name="force_station" value="1" checked>
        忽略文件里的 STATION_CALLSIGN，统一以 <b class="mono"><?= e($u['callsign']) ?></b> 作为本方呼号
      </label>
      <div class="hint">若你的日志里含多个操作呼号，取消勾选可保留原值（这些呼号建议先在资料页登记为附加呼号）</div>
    </div>
    <button class="btn primary" type="submit">开始导入</button>
  </form>
</div>

<div class="card">
  <h2>导入后会发生什么</h2>
  <ol class="small" style="margin:0;padding-left:22px;color:var(--ink-soft)">
    <li>每条记录按「呼号对 + UTC 时间(分) + 波段 + 模式」生成指纹；</li>
    <li>同一条通联在同一账号内不会重复记录；</li>
    <li>若对方已在本站上传同一通联，指纹一致 → 双方记录<b>立即双向验证</b>；</li>
    <li>若双方记录时间有出入（≤<?= (int)cfg('match_tolerance_min', 15) ?> 分钟），会出现在「待确认」页面供人工核对。</li>
  </ol>
</div>
<?php app_footer();
