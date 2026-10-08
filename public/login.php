<?php
/**
 * 登录
 */
require __DIR__ . '/../includes/bootstrap.php';

if (current_user()) {
    redirect(url('index.php'));
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identity = str_param('identity', '', 190);
    $pass     = (string)($_POST['password'] ?? '');

    if (!login_throttle_check()) {
        $err = '失败次数过多，请稍后再试（15 分钟）。';
    } else {
        $u = attempt_login($identity, $pass);
        if ($u) {
            login_user((int)$u['id']);
            $to = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            flash('登录成功，73！');
            redirect($to && str_starts_with($to, '/') ? $to : url('index.php'));
        }
        login_throttle_fail();
        $err = '呼号/邮箱或密码不正确。';
    }
}

app_header('登录');
?>
<div class="page-head"><div><h1>登录</h1><p class="subtitle">使用注册呼号或邮箱登录。</p></div></div>

<div class="card" style="max-width:460px">
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('login.php')) ?>">
    <?= csrf_field() ?>
    <div class="field">
      <label>呼号 或 邮箱</label>
      <input type="text" name="identity" required class="mono" autofocus autocomplete="username">
    </div>
    <div class="field">
      <label>密码</label>
      <input type="password" name="password" required autocomplete="current-password">
    </div>
    <button class="btn primary" type="submit">登录</button>
    <?php if (cfg('allow_register', true)): ?>
      <a class="btn" href="<?= e(url('register.php')) ?>" style="margin-left:8px">注册台站</a>
    <?php endif; ?>
  </form>
  <hr class="hr">
  <p class="small muted">忘记密码？请联系管理员 <?= e(cfg('admin_email', '')) ?> 手工重置。</p>
</div>
<?php app_footer();
