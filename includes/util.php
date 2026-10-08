<?php
/**
 * 通用工具：输出转义、会话、CSRF、闪存消息、呼号归一化、分页
 */
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 启动安全会话 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_name('qslsess');
    session_start();
}

/* ------------------------------- CSRF ------------------------------- */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('<!doctype html><meta charset="utf-8"><title>419</title>'
            . '<div style="font:15px system-ui;padding:40px">表单已过期（CSRF 校验失败），请返回重新提交。</div>');
    }
}

/* ------------------------------ 闪存消息 ------------------------------ */

function flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_take(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function url(string $path = ''): string
{
    return rtrim(cfg('base_url', ''), '/') . '/' . ltrim($path, '/');
}

/* ------------------------------ 呼号处理 ------------------------------ */

/** 归一化呼号：去空白、大写、剥离操作性质后缀 */
function norm_call(string $call): string
{
    $c = strtoupper(trim($call));
    $c = preg_replace('/\s+/', '', $c);
    // 剥离 /P /M /MM /AM /QRP /QRPP /T /R /LH 等操作后缀（保留区域数字后缀如 /7）
    $c = preg_replace('#/(P|M|MM|AM|QRP|QRPP|T|R|LH|D|Q)$#', '', $c);
    return $c;
}

/** 呼号合法性粗检 */
function valid_call(string $call): bool
{
    return (bool)preg_match('/^[A-Z0-9]{2,8}(\/[A-Z0-9]{1,5})?$/', $call);
}

/** 网格（Maidenhead）合法性 */
function valid_locator(string $g): bool
{
    return (bool)preg_match('/^[A-R]{2}[0-9]{2}([A-X]{2}([0-9]{2})?)?$/i', strtoupper(trim($g)));
}

/* ------------------------------ 时间处理 ------------------------------ */

/** 把 "Y-m-d" + "H:i" 合成 UTC 时间戳 */
function qso_ts(string $date, string $time): int
{
    return (int)gmmktime(
        (int)substr($time, 0, 2),
        (int)substr($time, 3, 2),
        0,
        (int)substr($date, 5, 2),
        (int)substr($date, 8, 2),
        (int)substr($date, 0, 4)
    );
}

/** 校验日期 YYYY-MM-DD */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y) && $y >= 1900 && $y <= 2100;
}

/** 校验时间 HH:MM 或 HHMM */
function valid_time(string $t): bool
{
    if (preg_match('/^\d{4}$/', $t)) {
        $t = substr($t, 0, 2) . ':' . substr($t, 2, 2);
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
        return false;
    }
    return true;
}

/** 规范化时间为 HH:MM:00 */
function norm_time(string $t): string
{
    if (preg_match('/^(\d{2})(\d{2})(\d{2})?$/', $t, $m)) {
        $t = $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }
    if (preg_match('/^(\d{2}):(\d{2})$/', $t)) {
        $t .= ':00';
    }
    return $t;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/* ------------------------------ 其他 ------------------------------ */

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim(explode(',', (string)$_SERVER[$k])[0]);
            if ($v !== '') {
                return $v;
            }
        }
    }
    return '0.0.0.0';
}

/** 简单分页计算 */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page  = min(max(1, $page), $pages);
    return [
        'page'   => $page,
        'pages'  => $pages,
        'total'  => $total,
        'limit'  => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

/** 取字符串参数 */
function str_param(string $key, string $default = '', int $maxLen = 255): string
{
    $v = $_GET[$key] ?? $_POST[$key] ?? $default;
    if (is_array($v)) {
        return $default;
    }
    $v = trim((string)$v);
    return mb_substr($v, 0, $maxLen);
}
