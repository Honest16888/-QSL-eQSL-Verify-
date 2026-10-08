<?php
/**
 * 台站资料 / 电子卡片 / 附加呼号 / 修改密码
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');

    /* ---- 基本资料 ---- */
    if ($act === 'profile') {
        $locator = strtoupper(trim((string)($_POST['locator'] ?? '')));
        if ($locator !== '' && !valid_locator($locator)) {
            flash('网格格式无效（如 OM89ev）', 'err');
        } else {
            db()->prepare(
                'UPDATE users SET display_name=?, qth=?, locator=?, country=?, latitude=?, longitude=?,
                 rig=?, antenna=?, bio=?, is_public=? WHERE id=?'
            )->execute([
                mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 80) ?: null,
                mb_substr(trim((string)($_POST['qth'] ?? '')), 0, 120) ?: null,
                $locator ?: null,
                mb_substr(trim((string)($_POST['country'] ?? '')), 0, 80) ?: null,
                ($_POST['latitude'] ?? '') === '' ? null : (float)$_POST['latitude'],
                ($_POST['longitude'] ?? '') === '' ? null : (float)$_POST['longitude'],
                mb_substr(trim((string)($_POST['rig'] ?? '')), 0, 160) ?: null,
                mb_substr(trim((string)($_POST['antenna'] ?? '')), 0, 160) ?: null,
                mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 600) ?: null,
                !empty($_POST['is_public']) ? 1 : 0,
                $uid,
            ]);
            flash('资料已保存');
        }
        redirect(url('profile.php'));
    }

    /* ---- 附加呼号 ---- */
    if ($act === 'alias_add') {
        $c = norm_call((string)($_POST['alias'] ?? ''));
        if (!valid_call($c)) {
            flash('呼号格式无效', 'err');
        } elseif (q_val('SELECT 1 FROM users WHERE callsign = ?', [$c])) {
            flash('该呼号已被他人作为主呼号使用', 'err');
        } elseif (q_val('SELECT 1 FROM user_callsigns WHERE callsign = ?', [$c])) {
            flash('该呼号已登记', 'err');
        } else {
            q('INSERT INTO user_callsigns (user_id, callsign, note) VALUES (?,?,?)',
                [$uid, $c, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120) ?: null]);
            flash('已添加附加呼号 ' . $c);
        }
        redirect(url('profile.php'));
    }

    if ($act === 'alias_del') {
        q('DELETE FROM user_callsigns WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
        flash('已删除附加呼号');
        redirect(url('profile.php'));
    }

    /* ---- 上传电子卡片背景 ---- */
    if ($act === 'card_image') {
        $f = $_FILES['card_image'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            flash('请选择图片文件', 'err');
        } else {
            $maxKb = (int)cfg('upload.max_kb', 2048);
            if ((int)$f['size'] > $maxKb * 1024) {
                flash('图片超过 ' . $maxKb . ' KB', 'err');
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = (string)$finfo->file($f['tmp_name']);
                $ext   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
                if (!$ext) {
                    flash('仅支持 JPG / PNG / WebP / GIF', 'err');
                } else {
                    $dir = (string)cfg('upload.dir');
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $name = bin2hex(random_bytes(8)) . '.' . $ext;
                    if (@move_uploaded_file($f['tmp_name'], rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name)) {
                        // 删除旧图
                        if (!empty($u['card_image'])) {
                            @unlink(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename((string)$u['card_image']));
                        }
                        q('UPDATE users SET card_image = ? WHERE id = ?', [$name, $uid]);
                        flash('电子卡片背景已更新');
                    } else {
                        flash('上传失败，请检查 uploads 目录是否可写', 'err');
                    }
                }
            }
        }
        redirect(url('profile.php'));
    }

    /* ---- 修改密码 ---- */
    if ($act === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $np  = (string)($_POST['new_password'] ?? '');
        $np2 = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($old, (string)$u['password_hash'])) {
            flash('当前密码不正确', 'err');
        } elseif (mb_strlen($np) < 8) {
            flash('新密码至少 8 位', 'err');
        } elseif ($np !== $np2) {
            flash('两次输入的新密码不一致', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($np, PASSWORD_DEFAULT), $uid]);
            flash('密码已更新');
        }
        redirect(url('profile.php'));
    }
}

$aliases = q_all('SELECT * FROM user_callsigns WHERE user_id = ? ORDER BY callsign', [$uid]);
$imgUrl  = $u['card_image'] ? url(cfg('upload.url_path', '/uploads') . '/' . $u['card_image']) : '';

app_header('台站资料');
?>
<div class="page-head">
  <div><h1>台站资料</h1><p class="subtitle">这些信息会出现在你的电子 QSL 卡上。</p></div>
  <div class="spacer"></div>
  <a class="btn" href="<?= e(url('card.php?u=' . urlencode($u['callsign']))) ?>">预览我的卡片</a>
</div>

<div class="grid g2">
  <div class="card">
    <h2>基本信息</h2>
    <form method="post" action="<?= e(url('profile.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="profile">
      <div class="form-row c2">
        <div class="field"><label>呼号</label><input type="text" value="<?= e($u['callsign']) ?>" class="mono" disabled></div>
        <div class="field"><label>邮箱</label><input type="text" value="<?= e($u['email']) ?>" disabled></div>
      </div>
      <div class="form-row c2">
        <div class="field"><label>OP 姓名</label><input type="text" name="display_name" value="<?= e($u['display_name']) ?>"></div>
        <div class="field"><label>QTH</label><input type="text" name="qth" value="<?= e($u['qth']) ?>"></div>
      </div>
      <div class="form-row c3">
        <div class="field"><label>网格</label><input type="text" name="locator" class="mono" value="<?= e($u['locator']) ?>" placeholder="OM89ev">
          <div class="hint">Maidenhead 4~8 位</div></div>
        <div class="field"><label>DXCC / 国家</label><input type="text" name="country" value="<?= e($u['country']) ?>"></div>
        <div class="field"><label>经纬度</label>
          <div style="display:flex;gap:6px">
            <input type="number" step="0.00001" name="latitude" value="<?= e($u['latitude']) ?>" placeholder="纬度">
            <input type="number" step="0.00001" name="longitude" value="<?= e($u['longitude']) ?>" placeholder="经度">
          </div>
        </div>
      </div>
      <div class="form-row c2">
        <div class="field"><label>设备</label><input type="text" name="rig" value="<?= e($u['rig']) ?>" placeholder="IC-7300"></div>
        <div class="field"><label>天线</label><input type="text" name="antenna" value="<?= e($u['antenna']) ?>" placeholder="Dipole 10m up"></div>
      </div>
      <div class="field"><label>卡片附言</label><textarea name="bio" maxlength="600"><?= e($u['bio']) ?></textarea></div>
      <label class="checkline"><input type="checkbox" name="is_public" value="1" <?= $u['is_public'] ? 'checked' : '' ?>> 公开我的电子 QSL 卡页</label>
      <div style="margin-top:14px"><button class="btn primary" type="submit">保存资料</button></div>
    </form>
  </div>

  <div>
    <div class="card">
      <h2>电子卡片背景</h2>
      <p class="small muted">建议 800×500 以上横图，会作为卡片底纹（半透明蒙版保证文字可读）。</p>
      <form method="post" action="<?= e(url('profile.php')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="card_image">
        <div class="field"><input type="file" name="card_image" accept="image/jpeg,image/png,image/webp,image/gif"></div>
        <button class="btn" type="submit">上传</button>
      </form>
      <?php if ($imgUrl): ?>
        <div style="margin-top:12px"><img src="<?= e($imgUrl) ?>" alt="" style="max-width:100%;border-radius:8px;border:1px solid var(--line)"></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>附加呼号</h2>
      <p class="small muted">便携 / 移动 / 俱乐部 / 旧呼号。登记后，导入日志时使用这些呼号也会被认作你。</p>
      <form method="post" action="<?= e(url('profile.php')) ?>" style="margin-bottom:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="alias_add">
        <div style="display:flex;gap:8px">
          <input type="text" name="alias" class="mono" placeholder="BH1ABC/P" required>
          <input type="text" name="note" placeholder="备注（可选）">
          <button class="btn" type="submit">添加</button>
        </div>
      </form>
      <?php if ($aliases): ?>
        <div class="chips">
          <?php foreach ($aliases as $a): ?>
            <span class="chip gray mono"><?= e($a['callsign']) ?>
              <form method="post" action="<?= e(url('profile.php')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="alias_del">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn sm danger" type="submit" style="padding:0 6px">×</button>
              </form>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>修改密码</h2>
      <form method="post" action="<?= e(url('profile.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="password">
        <div class="field"><label>当前密码</label><input type="password" name="old_password" autocomplete="current-password"></div>
        <div class="form-row c2">
          <div class="field"><label>新密码</label><input type="password" name="new_password" autocomplete="new-password"></div>
          <div class="field"><label>确认新密码</label><input type="password" name="new_password2" autocomplete="new-password"></div>
        </div>
        <button class="btn" type="submit">更新密码</button>
      </form>
    </div>
  </div>
</div>
<?php app_footer();
