<?php
/**
 * 数据库与配置访问层（PDO + MySQL，零依赖）
 */
declare(strict_types=1);

/** 载入配置（config/config.php 不存在时回落到模板）
 *  可用环境变量 QSL_CONFIG 指定配置文件绝对路径（自测用）。
 */
function load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = getenv('QSL_CONFIG') ?: (__DIR__ . '/../config/config.php');
    if (!is_file($file)) {
        $file = __DIR__ . '/../config/config.sample.php';
    }
    $cfg = require $file;
    return $cfg;
}

/** 点号路径读取配置，如 cfg('db.host') */
function cfg(string $path, mixed $default = null): mixed
{
    $cfg  = load_config();
    $node = $cfg;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) {
            return $default;
        }
        $node = $node[$seg];
    }
    return $node;
}

/** PDO 单例 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $d      = cfg('db');
    $driver = (string)($d['driver'] ?? 'mysql');

    if ($driver === 'sqlite') {
        // 仅用于离线自测（php cli/selftest.php），生产请使用 mysql
        $dsn = 'sqlite:' . ($d['path'] ?? ':memory:');
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);

    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        $msg = cfg('debug', false)
            ? '数据库连接失败：' . $e->getMessage()
            : '数据库连接失败，请检查 config/config.php。';
        exit('<!doctype html><meta charset="utf-8"><title>系统错误</title>'
            . '<div style="font:15px/1.7 system-ui;padding:40px;max-width:640px;margin:40px auto;'
            . 'border:1px solid #e2e8f0;border-radius:12px;background:#fff">'
            . '<h2 style="margin:0 0 12px">⚠ 系统暂时不可用</h2><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#64748b">若尚未安装，请访问 <code>/install.php</code> 完成初始化。</p></div>');
    }
    return $pdo;
}

/** 带参数的查询助手 */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [], mixed $default = null): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
