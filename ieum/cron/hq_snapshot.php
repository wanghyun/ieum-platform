<?php
require_once dirname(__DIR__) . '/_common.php';
require_once IEUM_PATH . '/lib/paymint.php';
require_once IEUM_PATH . '/lib/hq_snapshot.php';

header('Content-Type: application/json; charset=utf-8');

$cron_token = defined('IEUM_CRON_TOKEN') ? IEUM_CRON_TOKEN : (defined('IEUM_SMS_GATEWAY_TOKEN') ? IEUM_SMS_GATEWAY_TOKEN : '');
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($cron_token === '' || !hash_equals($cron_token, $token)) {
    echo json_encode(array('success' => false, 'message' => 'invalid token'), JSON_UNESCAPED_UNICODE);
    exit;
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$snapshot_date = isset($_GET['date']) ? preg_replace('/[^0-9-]/', '', trim($_GET['date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot_date)) {
    $snapshot_date = G5_TIME_YMD;
}

$result = ieum_hq_snapshot_refresh_all($month, $snapshot_date, 'cron');

echo json_encode($result, JSON_UNESCAPED_UNICODE);
