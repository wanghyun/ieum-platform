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
$result = ieum_paymint_handle_payment_callback($raw, $payload);

echo json_encode(array(
    'code' => '0000',
    'message' => 'received',
    'result' => $result,
), JSON_UNESCAPED_UNICODE);
