<?php
/**
 * 统一入口：页面只需 require 本文件
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/adif.php';
require_once __DIR__ . '/dxcc.php';
require_once __DIR__ . '/qsl.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

date_default_timezone_set((string)cfg('timezone', 'UTC'));
ini_set('display_errors', cfg('debug', false) ? '1' : '0');
ini_set('log_errors', '1');
