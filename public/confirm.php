<?php
/**
 * 人工确认一次通联（双向置为已验证）
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('matches.php'));
}
csrf_check();

$myId   = (int)($_POST['my_id'] ?? 0);
$peerId = (int)($_POST['peer_id'] ?? 0);

$mine = q_one('SELECT id, user_id FROM qsos WHERE id = ?', [$myId]);
if (!$mine || (int)$mine['user_id'] !== $uid) {
    flash('记录不存在或无权操作', 'err');
    redirect(url('matches.php'));
}
$peer = q_one('SELECT id, user_id FROM qsos WHERE id = ?', [$peerId]);
if (!$peer || (int)$peer['user_id'] === $uid) {
    flash('对方记录无效', 'err');
    redirect(url('matches.php'));
}

if (qso_link_pair($myId, $peerId, 'manual')) {
    flash('已确认：双方 QSL 同时标记为已验证 ✅');
} else {
    flash('确认失败，请稍后重试', 'err');
}
redirect(url('matches.php'));
