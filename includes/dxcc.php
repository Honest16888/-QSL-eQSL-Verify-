<?php
/**
 * DXCC 实体查询：按最长前缀匹配
 */
declare(strict_types=1);

/** @return array<int, array{prefix:string,entity:string,continent:string,cq_zone:?int,itu_zone:?int}> */
function dxcc_table(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    try {
        $rows = q_all('SELECT prefix, entity, continent, cq_zone, itu_zone FROM dxcc');
    } catch (Throwable $e) {
        return $t = [];
    }
    // 长前缀优先
    usort($rows, static fn($a, $b) => strlen($b['prefix']) <=> strlen($a['prefix']));
    return $t = $rows;
}

/**
 * 由呼号推断 DXCC 实体
 * @return array{prefix:string,entity:string,continent:string,cq_zone:?int,itu_zone:?int}|null
 */
function dxcc_lookup(string $call): ?array
{
    $c = norm_call($call);
    if ($c === '') {
        return null;
    }
    foreach (dxcc_table() as $row) {
        if (str_starts_with($c, $row['prefix'])) {
            return $row;
        }
    }
    return null;
}

/** 仅取实体名 */
function dxcc_entity(string $call): string
{
    $r = dxcc_lookup($call);
    return $r ? (string)$r['entity'] : '未知';
}

/** 大陆中文名 */
function continent_cn(string $code): string
{
    return match (strtoupper($code)) {
        'AS' => '亚洲',
        'EU' => '欧洲',
        'NA' => '北美洲',
        'SA' => '南美洲',
        'AF' => '非洲',
        'OC' => '大洋洲',
        'AN' => '南极洲',
        default => '—',
    };
}
