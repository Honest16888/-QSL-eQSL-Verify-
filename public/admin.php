<?php
/**
 * 后台管理
 */
require __DIR__ . '/../includes/bootstrap.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'toggle_admin') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== (int)$me['id']) {
            q('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$id]);
            flash('权限已切换');
        }
        redirect(url('admin.php'));
    }

    if ($act === 'del_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash('不能删除自己', 'err');
            redirect(url('admin.php'));
        }
        // 先解除与其相关的双向验证
        foreach (q_all('SELECT id FROM qsos WHERE user_id = ? AND verified = 1', [$id]) as $r) {
            qso_unlink((int)$r['id']);
        }
        q('DELETE FROM users WHERE id = ?', [$id]);   // qsos / user_callsigns 级联删除
        flash('用户及其日志已删除');
        redirect(url('admin.php'));
    }

    if ($act === 'settings') {
        q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            ['site_name', mb_substr(trim((string)($_POST['site_name'] ?? '')), 0, 80) ?: 'eQSL 交换验证系统']);
        q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            ['allow_register', !empty($_POST['allow_register']) ? '1' : '0']);
        flash('设置已保存');
        redirect(url('admin.php'));
    }
}

$kw     = str_param('kw', '', 40);
$params = [];
$w      = '';
if ($kw !== '') {
    $w = 'WHERE callsign LIKE ? OR email LIKE ? OR display_name LIKE ?';
    $like = '%' . $kw . '%';
    $params = [$like, $like, $like];
}
$users = q_all("SELECT u.*, (SELECT COUNT(*) FROM qsos WHERE user_id = u.id) AS qso_n,
                       (SELECT COUNT(*) FROM qsos WHERE user_id = u.id AND verified = 1) AS ver_n
                  FROM users u {$w} ORDER BY u.created_at DESC LIMIT 200", $params);

$sys = [
    'users'    => (int)q_val('SELECT COUNT(*) FROM users', [], 0),
    'qsos'     => (int)q_val('SELECT COUNT(*) FROM qsos', [], 0),
    'verified' => (int)q_val('SELECT COUNT(*) FROM qsos WHERE verified = 1', [], 0),
    'dxcc'     => (int)q_val('SELECT COUNT(*) FROM dxcc', [], 0),
];

$siteName  = (string)(q_val("SELECT v FROM settings WHERE k = 'site_name'", [], '') ?: cfg('site_name', ''));
$allowReg  = (string)(q_val("SELECT v FROM settings WHERE k = 'allow_register'", [], '1'));

app_header('管理后台');
?>
<div class="page-head"><div><h1>管理后台</h1><p class="subtitle">站点总览与用户管理</p></div></div>

<div class="grid g4">
  <div class="stat"><div class="n"><?= $sys['users'] ?></div><div class="k">注册台站</div></div>
  <div class="stat brand"><div class="n"><?= $sys['qsos'] ?></div><div class="k">QSO 记录</div></div>
  <div class="stat ok"><div class="n"><?= $sys['verified'] ?></div><div class="k">已验证</div></div>
  <div class="stat"><div class="n"><?= $sys['dxcc'] ?></div><div class="k">DXCC 前缀</div></div>
</div>

<div class="card">
  <h2>站点设置</h2>
  <form method="post" action="<?= e(url('admin.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="settings">
    <div class="form-row c2">
      <div class="field"><label>站名</label><input type="text" name="site_name" value="<?= e($siteName) ?>"></div>
      <div class="field"><label>公开注册</label>
        <label class="checkline"><input type="checkbox" name="allow_register" value="1" <?= $allowReg === '1' ? 'checked' : '' ?>> 允许新台站注册</label>
      </div>
    </div>
    <button class="btn primary" type="submit">保存</button>
    <span class="small muted" style="margin-left:10px">注：此处保存的是数据库设置，优先级高于 config.php 的 allow_register 之外的同名项。</span>
  </form>
</div>

<div class="card">
  <h2>用户</h2>
  <form class="filters" method="get" action="<?= e(url('admin.php')) ?>">
    <div class="field"><label>搜索呼号 / 邮箱 / 姓名</label><input type="text" name="kw" value="<?= e($kw) ?>"></div>
    <button class="btn" type="submit">搜索</button>
    <a class="btn" href="<?= e(url('admin.php')) ?>">全部</a>
  </form>

  <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>呼号</th><th>姓名</th><th>邮箱</th><th>QTH</th><th>QSO / 已验证</th><th>注册时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($users as $us): ?>
        <tr>
          <td class="mono"><b><?= e($us['callsign']) ?></b>
            <?php if ($us['is_admin']): ?><span class="badge info">管理员</span><?php endif; ?></td>
          <td><?= e($us['display_name']) ?></td>
          <td class="small"><?= e($us['email']) ?></td>
          <td><?= e($us['qth']) ?></td>
          <td><?= (int)$us['qso_n'] ?> / <?= (int)$us['ver_n'] ?></td>
          <td class="small mono"><?= e(substr((string)$us['created_at'], 0, 10)) ?></td>
          <td>
            <a class="btn sm" href="<?= e(url('card.php?u=' . urlencode($us['callsign']))) ?>">卡片</a>
            <?php if ((int)$us['id'] !== (int)$me['id']): ?>
              <form method="post" action="<?= e(url('admin.php')) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="act" value="toggle_admin">
                <input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
                <button class="btn sm" type="submit"><?= $us['is_admin'] ? '取消管理员' : '设为管理员' ?></button>
              </form>
              <form method="post" action="<?= e(url('admin.php')) ?>" style="display:inline"
                    onsubmit="return confirm('删除该用户及其全部 QSO？此操作不可恢复。')">
                <?= csrf_field() ?><input type="hidden" name="act" value="del_user">
                <input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
                <button class="btn sm danger" type="submit">删除</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php app_footer();
