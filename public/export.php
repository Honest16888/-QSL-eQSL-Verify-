<?php
/**
 * ADIF 导出
 */
require __DIR__ . '/../includes/bootstrap.php';

$u   = require_login();
$uid = (int)$u['id'];

$onlyVerified = (int)($_GET['verified'] ?? 0) === 1;
$w = $onlyVerified ? ' AND verified = 1' : '';

$rows = q_all(
    "SELECT * FROM qsos WHERE user_id = ?{$w} ORDER BY qso_date ASC, time_on ASC",
    [$uid]
);

$call = preg_replace('/[^A-Za-z0-9]/', '', (string)$u['callsign']) ?: 'STATION';
$file = $call . '-' . gmdate('Ymd') . ($onlyVerified ? '-verified' : '') . '.adi';

$body = adif_export($rows, [
    'program' => 'eQSL Verify',
    'version' => '1.0',
    'extra'   => [
        'STATION_CALLSIGN'  => (string)$u['callsign'],
        'OPERATOR'          => (string)$u['callsign'],
        'APP_EQSL_SITE'     => (string)cfg('site_name', ''),
        'APP_EQSL_OWNER'    => (string)$u['callsign'],
    ],
]);

header('Content-Type: application/octet-stream; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
echo $body;
exit;
