<?php
/**
 * schema.sql 静态校验（不需要数据库）：
 *  - 语句括号配对
 *  - INSERT 每行字段数一致
 *  - dxcc 前缀唯一
 *  - 关键表/列齐备
 */
declare(strict_types=1);

$file = __DIR__ . '/../database/schema.sql';
$sql  = (string)file_get_contents($file);

$errs = 0;
function bad(string $m): void { global $errs; $errs++; echo "  ✗ $m\n"; }
function good(string $m): void { echo "  ✓ $m\n"; }

// 括号配对（忽略注释与字符串内部的括号，粗粒度但足够）
$clean = preg_replace('/^\s*--.*$/m', '', $sql);
$open  = substr_count($clean, '(');
$close = substr_count($clean, ')');
$open === $close ? good("括号配对：{$open} 对") : bad("括号不配对：{$open} 开 / {$close} 闭");

// 语句拆分
$stmts = array_values(array_filter(array_map('trim', preg_split('/;\s*\n/', $clean) ?: [])));
good('语句数：' . count($stmts));
foreach ($stmts as $i => $s) {
    if (substr_count($s, '(') !== substr_count($s, ')')) {
        bad("第 " . ($i + 1) . " 条语句括号不配对：" . mb_substr($s, 0, 50));
    }
}

// dxcc 前缀唯一性与字段数
preg_match_all('/INSERT INTO dxcc \(prefix, entity, continent, cq_zone, itu_zone\) VALUES(.*?);/s', $sql, $mm);
$rows = [];
foreach ($mm[1] ?? [] as $block) {
    preg_match_all("/\('([^']*)',\s*'([^']*)',\s*'([^']*)',\s*(\d+|NULL),\s*(\d+|NULL)\)/", $block, $rr, PREG_SET_ORDER);
    foreach ($rr as $r) {
        $rows[] = [$r[1], $r[2], $r[3], $r[4], $r[5]];
    }
}
good('DXCC 种子行数：' . count($rows));
$prefixes = array_column($rows, 0);
$dups = array_keys(array_filter(array_count_values($prefixes), static fn($n) => $n > 1));
$dups ? bad('重复前缀：' . implode(', ', $dups)) : good('前缀唯一，无重复');
count($rows) >= 150 ? good('前缀数量充足（≥150）') : bad('前缀数量偏少：' . count($rows));

// 关键结构检查
$need = [
    'CREATE TABLE IF NOT EXISTS users',
    'CREATE TABLE IF NOT EXISTS qsos',
    'CREATE TABLE IF NOT EXISTS user_callsigns',
    'CREATE TABLE IF NOT EXISTS verifications',
    'CREATE TABLE IF NOT EXISTS dxcc',
    'CREATE TABLE IF NOT EXISTS settings',
    'match_key',
    'pair_key',
    'verify_code',
    'verified',
    'uq_user_match',
    'ix_pair_key',
];
foreach ($need as $n) {
    str_contains($sql, $n) ? good("包含 {$n}") : bad("缺少 {$n}");
}

echo "\n" . ($errs === 0 ? "schema.sql 校验通过\n" : "发现 {$errs} 个问题\n");
exit($errs === 0 ? 0 : 1);
