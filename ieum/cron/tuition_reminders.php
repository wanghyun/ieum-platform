<?php
require_once dirname(__DIR__) . '/_common.php';
require_once IEUM_PATH . '/lib/tuition.php';

header('Content-Type: application/json; charset=utf-8');

$cron_token = defined('IEUM_CRON_TOKEN') ? IEUM_CRON_TOKEN : (defined('IEUM_SMS_GATEWAY_TOKEN') ? IEUM_SMS_GATEWAY_TOKEN : '');
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($cron_token === '' || !hash_equals($cron_token, $token)) {
    echo json_encode(array('success' => false, 'message' => 'invalid token'), JSON_UNESCAPED_UNICODE);
    exit;
}

$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$target_date = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
    $target_date = G5_TIME_YMD;
}

$result = ieum_tuition_send_due_notices(0, $target_date, $dry_run);

echo json_encode(array(
    'success' => true,
    'dry_run' => $dry_run,
    'target_date' => $target_date,
    'checked' => $result['checked'],
    'created_sms' => $result['created_sms'],
    'details' => $result['details'],
), JSON_UNESCAPED_UNICODE);
