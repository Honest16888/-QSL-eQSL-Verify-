<?php
/**
 * 自测用配置（sqlite 内存库），仅供 php cli/selftest.php 使用
 */
declare(strict_types=1);

return [
    'db' => [
        'driver' => 'sqlite',
        'path'   => ':memory:',
    ],
    'base_url'         => 'http://localhost:8080',
    'site_name'        => 'eQSL 自测',
    'site_slogan'      => '',
    'admin_email'      => 'test@example.com',
    'allow_register'   => true,
    'debug'            => true,
    'timezone'         => 'UTC',
    'match_tolerance_min' => 15,
    'upload' => [
        'dir'      => sys_get_temp_dir() . '/qsl_uploads',
        'url_path' => '/uploads',
        'max_kb'   => 2048,
        'types'    => ['image/jpeg', 'image/png'],
    ],
    'login' => ['max_attempts' => 8, 'window_sec' => 900],
];
