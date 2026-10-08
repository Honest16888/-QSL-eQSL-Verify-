<?php
/**
 * 配置文件模板 —— 复制为 config/config.php 后按实际环境修改。
 *
 *   cp config/config.sample.php config/config.php
 */
declare(strict_types=1);

return [
    // ---------------- 数据库 ----------------
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'qsl_db',
        'user'     => 'qsl_user',
        'pass'     => 'change_me_strong_password',
        'charset'  => 'utf8mb4',
    ],

    // ---------------- 站点 ----------------
    // 末尾不要带斜杠
    'base_url'     => 'https://qsl.example.com',
    'site_name'    => 'eQSL 交换验证系统',
    'site_slogan'  => 'QSO 双向确认 · 电子 QSL 卡 · DXCC 统计',
    'admin_email'  => 'admin@example.com',

    // ---------------- 运行开关 ----------------
    'allow_register'  => true,   // 关闭后仅管理员可开号
    'debug'           => false,  // 生产环境务必 false
    'timezone'        => 'UTC',  // 站内所有 QSO 时间以 UTC 存储

    // ---------------- 匹配参数 ----------------
    // 精确指纹匹配之外，允许人工确认的时间容差（分钟）
    'match_tolerance_min' => 15,

    // ---------------- 上传 ----------------
    'upload' => [
        // 必须位于站点文档根之内（public/uploads），否则浏览器访问不到卡片图
        'dir'      => __DIR__ . '/../public/uploads',
        'url_path' => '/uploads',
        'max_kb'   => 2048,
        'types'    => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ],

    // ---------------- 安全 ----------------
    // 登录失败限速：同一 IP 在窗口期内最多失败次数
    'login' => [
        'max_attempts' => 8,
        'window_sec'   => 900,
    ],
];
