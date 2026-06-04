<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/paymint.php';

header('Content-Type: application/json; charset=utf-8');

$settings = ieum_paymint_get_settings();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($settings['callback_token'] !== '' && !hash_equals($settings['callback_token'], $token)) {
    echo json_encode(array('code' => '9999', 'message' => 'invalid token'), JSON_UNESCAPED_UNICODE);
    exit;
}

list($raw, $payload) = ieum_paymint_decode_payload();
$member_id = isset($payload['memberId']) ? trim($payload['memberId']) : (isset($payload['member_id']) ? trim($payload['member_id']) : '');
$merchant_id = isset($payload['merchantId']) ? trim($payload['merchantId']) : (isset($payload['merchant_id']) ? trim($payload['merchant_id']) : '');
$status = isset($payload['status']) ? trim($payload['status']) : (isset($payload['mappingStatus']) ? trim($payload['mappingStatus']) : 'callback_received');
$member_id_sql = sql_escape_string($member_id);
$merchant_id_sql = sql_escape_string($merchant_id);
$status_sql = sql_escape_string($status);

$map = array();
if ($member_id !== '' || $merchant_id !== '') {
    $map = sql_fetch("
        select *
          from " . IEUM_PAYMINT_MERCHANT_TABLE . "
         where (member_id = '{$member_id_sql}' and '{$member_id_sql}' <> '')
            or (merchant_id = '{$merchant_id_sql}' and '{$merchant_id_sql}' <> '')
      order by merchant_map_id desc
         limit 1
    ", false);
}

$academy_id = isset($map['academy_id']) ? (int) $map['academy_id'] : 0;

sql_query("
    insert into " . IEUM_PAYMINT_CALLBACK_TABLE . "
        set callback_type = 'merchant',
            bill_id = '',
            academy_id = '{$academy_id}',
            payment_id = 0,
            appr_state = '',
            payload = '" . sql_escape_string($raw) . "',
            result_code = '0000',
            result_message = 'received',
            received_at = '" . G5_TIME_YMDHIS . "'
");

if (isset($map['merchant_map_id'])) {
    sql_query("
        update " . IEUM_PAYMINT_MERCHANT_TABLE . "
           set mapping_status = '{$status_sql}',
               callback_payload = '" . sql_escape_string($raw) . "',
               mapped_at = coalesce(mapped_at, '" . G5_TIME_YMDHIS . "'),
               updated_at = '" . G5_TIME_YMDHIS . "'
         where merchant_map_id = '" . (int) $map['merchant_map_id'] . "'
    ");
}

echo json_encode(array('code' => '0000', 'message' => 'received'), JSON_UNESCAPED_UNICODE);

