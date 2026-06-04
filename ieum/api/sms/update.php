<?php
require_once dirname(dirname(__DIR__)) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/gateway.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, '허용되지 않은 요청입니다.', array(), 405);
}

$academy = ieum_require_gateway_token();
$academy_id = (int) $academy['academy_id'];
$gateway_device = isset($academy['_sms_gateway_device']) ? $academy['_sms_gateway_device'] : null;

$sms_id = isset($_POST['sms_id']) ? (int) $_POST['sms_id'] : 0;
$status = isset($_POST['status']) ? preg_replace('/[^a-z]/', '', trim($_POST['status'])) : '';
$device = isset($_POST['device']) ? trim($_POST['device']) : '';
if ($gateway_device) {
    $device = $gateway_device['device_name'];
}
$error_message = isset($_POST['error_message']) ? trim($_POST['error_message']) : '';

if (!$sms_id) {
    ieum_json_response(false, 'sms_id가 필요합니다.', array(), 422);
}

if (!in_array($status, array('sent', 'failed', 'pending'), true)) {
    ieum_json_response(false, 'status는 sent, failed, pending만 가능합니다.', array(), 422);
}

$row = sql_fetch("
    select *
     from " . IEUM_SMS_QUEUE_TABLE . "
     where sms_id = '{$sms_id}'
       and academy_id = '{$academy_id}'
     limit 1
", false);

if (!isset($row['sms_id'])) {
    ieum_json_response(false, '문자 큐를 찾을 수 없습니다.', array(), 404);
}

$device_sql = sql_escape_string($device);
$error_sql = sql_escape_string($error_message);
$sent_at_sql = $status === 'sent' ? G5_TIME_YMDHIS : null;
$sent_at_set = $sent_at_sql ? "sent_at = '{$sent_at_sql}'," : "sent_at = null,";

sql_query("
    update " . IEUM_SMS_QUEUE_TABLE . "
       set status = '{$status}',
           {$sent_at_set}
           gateway_device = '{$device_sql}',
           error_message = '{$error_sql}'
     where sms_id = '{$sms_id}'
       and academy_id = '{$academy_id}'
");
if ($gateway_device) {
    ieum_sms_gateway_touch((int) $gateway_device['device_id'], $status === 'sent' ? 'last_sent_at' : 'last_seen_at', $error_message);
}

ieum_json_response(true, '문자 큐 상태가 업데이트되었습니다.', array(
    'sms_id' => $sms_id,
    'academy_id' => $academy_id,
    'status' => $status,
));
