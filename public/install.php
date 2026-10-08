<?php
/**
 * 安装向导：执行 database/schema.sql 并创建管理员
 * ⚠ 安装完成后请删除本文件（或重命名为 .bak）
 */
require __DIR__ . '/../includes/bootstrap.php';

$log       = [];
$hasConfig = is_file(__DIR__ . '/../config/config.php');
$dbOk      = false;
$userCount = 0;
$dxccCount = 0;

/* 探测现状 */
try {
    db()->query('SELECT 1');
    $dbOk = true;
    try {
        $userCount = (int)q_val('SELECT COUNT(*) FROM users', [], 0);
    } catch (Throwable $e) {
        $userCount = 0;   // 表还不存在
    }
    try {
        $dxccCount = (int)q_val('SELECT COUNT(*) FROM dxcc', [], 0);
    } catch (Throwable $e) {
        $dxccCount = 0;
    }
} catch (Throwable $e) {
    $dbOk = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk) {
    csrf_check();

    $schemaFile = __DIR__ . '/../database/schema.sql';
    $sql = (string)file_get_contents($schemaFile);
    // 去掉整行注释后按 ";\n" 拆分
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $stmts = preg_split('/;\s*\n/', $sql) ?: [];

    $skipSeed = $dxccCount > 0;   // 已有 DXCC 数据则跳过种子插入
    foreach ($stmts as $s) {
        $s = trim($s);
        if ($s === '' || str_starts_with($s, 'SET ')) {
            continue;
        }
        if ($skipSeed && stripos($s, 'INSERT INTO dxcc') === 0) {
            $log[] = ['skip', 'DXCC 种子数据已存在，跳过'];
            continue;
        }
        try {
            db()->exec($s);
            $log[] = ['ok', mb_substr(preg_replace('/\s+/', ' ', $s), 0, 70) . ' …'];
        } catch (Throwable $e) {
            $log[] = ['err', mb_substr(preg_replace('/\s+/', ' ', $s), 0, 50) . ' … → ' . $e->getMessage()];
        }
    }

    // 创建管理员
    $call = norm_call((string)($_POST['admin_call'] ?? ''));
    $mail = mb_strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $pass = (string)($_POST['admin_pass'] ?? '');

    if ($call !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) && mb_strlen($pass) >= 8) {
        $exists = q_one('SELECT id FROM users WHERE callsign = ?', [$call]);
        if ($exists) {
            q('UPDATE users SET is_admin = 1 WHERE id = ?', [(int)$exists['id']]);
            $log[] = ['ok', '已将 ' . $call . ' 设为管理员'];
        } else {
            db()->prepare(
                'INSERT INTO users (callsign, email, password_hash, is_admin, country, created_at)
                 VALUES (?,?,?,1,?,?)'
            )->execute([$call, $mail, password_hash($pass, PASSWORD_DEFAULT), dxcc_entity($call), now_utc()]);
            $log[] = ['ok', '已创建管理员 ' . $call];
        }
    } else {
        $log[] = ['err', '管理员信息不完整，已跳过（呼号有效 / 邮箱合法 / 密码≥8位）'];
    }
}

app_header('安装向导');
?>
<div class="page-head"><div><h1>安装向导</h1><p class="subtitle">初始化数据库表结构并创建管理员账号。</p></div></div>

<?php if (!$hasConfig): ?>
  <div class="flash warn">
    尚未创建 <code>config/config.php</code>。请先复制 <code>config/config.sample.php</code> 为 <code>config/config.php</code>
    并填写数据库连接信息。
  </div>
<?php endif; ?>

<?php if (!$dbOk): ?>
  <div class="flash err">数据库连接失败，请检查 config/config.php 中的主机、库名、账号与密码，并确认 MySQL 已启动。</div>
<?php else: ?>
  <div class="card">
    <h2>当前状态</h2>
    <dl class="kv">
      <dt>配置文件</dt><dd><?= $hasConfig ? '已创建' : '缺失' ?></dd>
      <dt>数据库连接</dt><dd><span class="badge ok">正常</span></dd>
      <dt>已注册用户</dt><dd><?= $userCount ?></dd>
      <dt>DXCC 前缀</dt><dd><?= $dxccCount ?></dd>
    </dl>
  </div>

  <?php if ($log): ?>
    <div class="card">
      <h2>执行结果</h2>
      <?php foreach ($log as [$t, $m]): ?>
        <div class="flash <?= $t === 'err' ? 'err' : ($t === 'skip' ? 'warn' : 'ok') ?>"><?= e($m) ?></div>
      <?php endforeach; ?>
      <div class="note">
        安装完成。请立即 <b>删除 public/install.php</b>，然后登录管理后台。
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= $userCount ? '重新执行 / 创建管理员' : '初始化' ?></h2>
    <form method="post" action="<?= e(url('install.php')) ?>">
      <?= csrf_field() ?>
      <div class="form-row c3">
        <div class="field"><label>管理员呼号</label><input type="text" name="admin_call" class="mono" placeholder="BH1ABC" required></div>
        <div class="field"><label>管理员邮箱</label><input type="email" name="admin_email" required></div>
        <div class="field"><label>管理员密码</label><input type="password" name="admin_pass" required minlength="8"></div>
      </div>
      <label class="checkline"><input type="checkbox" required> 我已备份数据库，理解建表语句使用 CREATE TABLE IF NOT EXISTS</label>
      <div style="margin-top:14px"><button class="btn primary" type="submit">执行安装</button></div>
    </form>
  </div>
<?php endif; ?>
<?php app_footer();
