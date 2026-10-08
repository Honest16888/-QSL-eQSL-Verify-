<?php
/**
 * 认证与会话
 */
declare(strict_types=1);

function current_user(): ?array
{
    start_session();
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $user = q_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user) {
        unset($_SESSION['uid']);
        return null;
    }
    return $user;
}

function current_user_id(): int
{
    $u = current_user();
    return $u ? (int)$u['id'] : 0;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        start_session();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('请先登录', 'warn');
        redirect(url('login.php'));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!(int)$u['is_admin']) {
        http_response_code(403);
        exit('无权限');
    }
    return $u;
}

function login_user(int $userId): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['login_attempts']);
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/** 登录失败限速 */
function login_throttle_check(): bool
{
    start_session();
    $n   = (int)($_SESSION['login_attempts']['n'] ?? 0);
    $t   = (int)($_SESSION['login_attempts']['t'] ?? 0);
    $win = (int)cfg('login.window_sec', 900);
    if ($t > 0 && time() - $t > $win) {
        return true;   // 窗口已过，重置
    }
    return $n < (int)cfg('login.max_attempts', 8);
}

function login_throttle_fail(): void
{
    start_session();
    $t = (int)($_SESSION['login_attempts']['t'] ?? 0);
    $n = (int)($_SESSION['login_attempts']['n'] ?? 0);
    if (time() - $t > (int)cfg('login.window_sec', 900)) {
        $n = 0;
    }
    $_SESSION['login_attempts'] = ['n' => $n + 1, 't' => time()];
}

/**
 * 登录校验（呼号或邮箱 + 密码）
 */
function attempt_login(string $identity, string $password): ?array
{
    $identity = trim($identity);
    if ($identity === '' || $password === '') {
        return null;
    }
    if (str_contains($identity, '@')) {
        $u = q_one('SELECT * FROM users WHERE email = ?', [mb_strtolower($identity)]);
    } else {
        $u = q_one('SELECT * FROM users WHERE callsign = ?', [norm_call($identity)]);
    }
    if (!$u) {
        return null;
    }
    if (!password_verify($password, (string)$u['password_hash'])) {
        return null;
    }
    // 哈希需要升级则顺带重写
    if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
    }
    return $u;
}

/**
 * 注册
 * @return array{0: array<string,string>, 1: int} [错误, 新用户 id]
 */
function register_user(array $in): array
{
    $errs = [];

    $callsign = norm_call((string)($in['callsign'] ?? ''));
    if ($callsign === '' || !valid_call($callsign)) {
        $errs['callsign'] = '呼号格式无效（如 BH1ABC、BD7XYZ）';
    } elseif (q_val('SELECT 1 FROM users WHERE callsign = ?', [$callsign])) {
        $errs['callsign'] = '该呼号已注册';
    } elseif (q_val('SELECT 1 FROM user_callsigns WHERE callsign = ?', [$callsign])) {
        $errs['callsign'] = '该呼号已被登记为附加呼号';
    }

    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errs['email'] = '邮箱格式无效';
    } elseif (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        $errs['email'] = '该邮箱已注册';
    }

    $pass  = (string)($in['password'] ?? '');
    $pass2 = (string)($in['password2'] ?? '');
    if (mb_strlen($pass) < 8) {
        $errs['password'] = '密码至少 8 位';
    } elseif ($pass !== $pass2) {
        $errs['password2'] = '两次输入的密码不一致';
    }

    if ($errs) {
        return [$errs, 0];
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = db()->prepare(
        'INSERT INTO users (callsign, email, password_hash, display_name, qth, locator, country, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $callsign,
        $email,
        $hash,
        mb_substr(trim((string)($in['display_name'] ?? '')), 0, 80) ?: null,
        mb_substr(trim((string)($in['qth'] ?? '')), 0, 120) ?: null,
        strtoupper(mb_substr(trim((string)($in['locator'] ?? '')), 0, 12)) ?: null,
        mb_substr(trim((string)($in['country'] ?? '')), 0, 80) ?: dxcc_entity($callsign),
        now_utc(),
    ]);
    return [[], (int)db()->lastInsertId()];
}

/** 该呼号归属的用户（主呼号或附加呼号）；单次请求内做内存缓存 */
function user_by_callsign(string $call): ?array
{
    static $cache = [];
    $c = norm_call($call);
    if ($c === '') {
        return null;
    }
    if (array_key_exists($c, $cache)) {
        return $cache[$c];
    }

    $u = q_one('SELECT * FROM users WHERE callsign = ?', [$c]);
    if (!$u) {
        $row = q_one('SELECT user_id FROM user_callsigns WHERE callsign = ?', [$c]);
        $u = $row ? q_one('SELECT * FROM users WHERE id = ?', [(int)$row['user_id']]) : null;
    }
    return $cache[$c] = $u ?: null;
}
