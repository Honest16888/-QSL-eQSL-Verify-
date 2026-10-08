<?php
require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}
logout_user();
flash('已退出登录，73！');
redirect(url('login.php'));
