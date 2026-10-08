<?php
/**
 * 页面骨架与公共组件
 */
declare(strict_types=1);

function app_header(string $title = '', array $opt = []): void
{
    $u       = current_user();
    $site    = (string)cfg('site_name', 'eQSL 交换验证系统');
    $page    = $title !== '' ? $title . ' · ' . $site : $site;
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $flashes = flash_take();

    $nav = [
        'index.php'  => '总览',
        'qsos.php'   => '我的 QSO',
        'import.php' => 'ADIF 导入',
        'matches.php'=> '待确认',
        'stats.php'  => '统计',
    ];

    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page) ?></title>
<meta name="description" content="业余无线电电子 QSL 交换与双向验证系统">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="wrap bar-inner">
    <a class="brand" href="<?= e(url('index.php')) ?>">
      <span class="brand-mark">QSL</span>
      <span class="brand-text">
        <strong><?= e($site) ?></strong>
        <small><?= e(cfg('site_slogan', '')) ?></small>
      </span>
    </a>
    <nav class="nav" id="nav">
      <?php if ($u): ?>
        <?php foreach ($nav as $file => $label): ?>
          <a href="<?= e(url($file)) ?>" class="<?= $current === $file ? 'on' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('card.php?u=' . urlencode($u['callsign']))) ?>" class="<?= $current === 'card.php' ? 'on' : '' ?>">我的卡片</a>
        <a href="<?= e(url('profile.php')) ?>" class="<?= $current === 'profile.php' ? 'on' : '' ?>">资料</a>
        <?php if ((int)$u['is_admin']): ?>
          <a href="<?= e(url('admin.php')) ?>" class="<?= $current === 'admin.php' ? 'on' : '' ?>">管理</a>
        <?php endif; ?>
        <a class="ghost" href="<?= e(url('logout.php')) ?>">退出</a>
      <?php else: ?>
        <a href="<?= e(url('verify.php')) ?>">验证查询</a>
        <a href="<?= e(url('login.php')) ?>">登录</a>
        <?php if (cfg('allow_register', true)): ?>
          <a class="primary" href="<?= e(url('register.php')) ?>">注册台站</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>
    <button class="nav-toggle" onclick="document.getElementById('nav').classList.toggle('open')" aria-label="菜单">☰</button>
  </div>
</header>

<main class="wrap main">
  <?php if ($flashes): ?>
    <div class="flashes">
      <?php foreach ($flashes as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php
}

function app_footer(): void
{
    $u = current_user();
    ?>
</main>

<footer class="foot">
  <div class="wrap foot-inner">
    <div>
      <strong><?= e(cfg('site_name', 'eQSL')) ?></strong>
      <span class="muted"> · 所有 QSO 时间均为 UTC</span>
    </div>
    <div class="utc-clock">
      当前 UTC：<b id="utcNow">--:--:--</b>
      <?php if ($u): ?><span class="muted"> · 73 de <?= e($u['callsign']) ?></span><?php endif; ?>
    </div>
  </div>
</footer>

<script>
(function () {
  var el = document.getElementById('utcNow');
  function tick() {
    if (!el) return;
    var d = new Date();
    var p = function (n) { return String(n).padStart(2, '0'); };
    el.textContent = p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
  }
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
<?php
}

/** 状态徽章 */
function badge_verified(int $v): string
{
    return $v
        ? '<span class="badge ok">已双向验证</span>'
        : '<span class="badge wait">待对方确认</span>';
}

/** 分页链接 HTML */
function pager_links(array $p, string $baseUrl, array $extra = []): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $qs = $extra;
    $out = '<div class="pager">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $qs['page'] = $i;
        $href = $baseUrl . '?' . http_build_query($qs);
        $out .= '<a href="' . e($href) . '" class="' . ($i === $p['page'] ? 'on' : '') . '">' . $i . '</a>';
    }
    return $out . '</div>';
}
