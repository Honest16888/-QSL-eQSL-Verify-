<?php
/**
 * 注册台站
 */
require __DIR__ . '/../includes/bootstrap.php';

if (!cfg('allow_register', true)) {
    http_response_code(403);
    exit('当前站点已关闭公开注册，请联系管理员。');
}
if (current_user()) {
    redirect(url('index.php'));
}

$errs = [];
$old  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST;
    [$errs, $newId] = register_user($_POST);
    if (!$errs) {
        login_user($newId);
        flash('注册成功，欢迎加入！建议先完善台站资料并导入日志。');
        redirect(url('profile.php'));
    }
}

app_header('注册台站');
?>
<div class="page-head"><div><h1>注册台站</h1><p class="subtitle">一个呼号对应一个账号；额外的便携/俱乐部呼号可在资料页追加。</p></div></div>

<div class="card" style="max-width:720px">
  <?php if ($errs): ?>
    <div class="flash err">请检查表单中标红的项目。</div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('register.php')) ?>" autocomplete="on">
    <?= csrf_field() ?>
    <div class="form-row c2">
      <div class="field <?= isset($errs['callsign']) ? 'errs' : '' ?>">
        <label>呼号 *</label>
        <input type="text" name="callsign" required class="mono" placeholder="BH1ABC"
               value="<?= e($old['callsign'] ?? '') ?>" autocomplete="off">
        <div class="hint">将作为登录账号与 QSL 卡标识，自动转大写</div>
        <?php if (isset($errs['callsign'])): ?><div class="err"><?= e($errs['callsign']) ?></div><?php endif; ?>
      </div>
      <div class="field <?= isset($errs['email']) ? 'errs' : '' ?>">
        <label>邮箱 *</label>
        <input type="email" name="email" required value="<?= e($old['email'] ?? '') ?>" autocomplete="email">
        <?php if (isset($errs['email'])): ?><div class="err"><?= e($errs['email']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="form-row c2">
      <div class="field <?= isset($errs['password']) ? 'errs' : '' ?>">
        <label>密码 *</label>
        <input type="password" name="password" required autocomplete="new-password">
        <div class="hint">至少 8 位</div>
        <?php if (isset($errs['password'])): ?><div class="err"><?= e($errs['password']) ?></div><?php endif; ?>
      </div>
      <div class="field <?= isset($errs['password2']) ? 'errs' : '' ?>">
        <label>确认密码 *</label>
        <input type="password" name="password2" required autocomplete="new-password">
        <?php if (isset($errs['password2'])): ?><div class="err"><?= e($errs['password2']) ?></div><?php endif; ?>
      </div>
    </div>

    <hr class="hr">
    <div class="form-row c3">
      <div class="field">
        <label>OP 姓名</label>
        <input type="text" name="display_name" value="<?= e($old['display_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label>QTH</label>
        <input type="text" name="qth" value="<?= e($old['qth'] ?? '') ?>" placeholder="Beijing">
      </div>
      <div class="field">
        <label>网格 Maidenhead</label>
        <input type="text" name="locator" class="mono" value="<?= e($old['locator'] ?? '') ?>" placeholder="OM89ev">
      </div>
    </div>

    <button class="btn primary" type="submit">创建账号</button>
    <a class="btn" href="<?= e(url('login.php')) ?>" style="margin-left:8px">已有账号，去登录</a>
  </form>
</div>
<?php app_footer();
