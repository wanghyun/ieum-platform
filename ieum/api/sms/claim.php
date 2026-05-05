<?php
require_once dirname(dirname(__DIR__)) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/gateway.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, '허용되지 않은 요청입니다.', array(), 405);
}

$academy = ieum_require_gateway_token();
$academy_id = (int) $academy['academy_id'];

$device = isset($_POST['device']) ? trim($_POST['device']) : 'android-gateway';
$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 10;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 20) {
    $limit = 20;
}

$device_sql = sql_escape_string($device);

$result = sql_query("
     select sms_id
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'pending'
  order by sms_id asc
     limit {$limit}
", false);

$ids = array();
while ($row = sql_fetch_array($result)) {
    $ids[] = (int) $row['sms_id'];
}

if (!$ids) {
    ieum_json_response(true, '처리할 문자 큐가 없습니다.', array(
        'items' => array(),
        'count' => 0,
    ));
}

$id_csv = implode(',', $ids);
sql_query("
    update " . IEUM_SMS_QUEUE_TABLE . "
       set status = 'processing',
           gateway_device = '{$device_sql}',
           error_message = ''
     where sms_id in ({$id_csv})
       and status = 'pending'
       and academy_id = '{$academy_id}'
");

$claimed = sql_query("
    select q.sms_id, q.student_id, q.attendance_id, q.recipient_phone, q.message, q.created_at,
           s.student_code, s.student_name
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
     where q.sms_id in ({$id_csv})
       and q.academy_id = '{$academy_id}'
       and q.status = 'processing'
       and q.gateway_device = '{$device_sql}'
  order by q.sms_id asc
", false);

$items = array();
while ($row = sql_fetch_array($claimed)) {
    $items[] = array(
        'sms_id' => (int) $row['sms_id'],
        'student_id' => (int) $row['student_id'],
        'attendance_id' => (int) $row['attendance_id'],
        'recipient_phone' => $row['recipient_phone'],
        'message' => $row['message'],
        'created_at' => $row['created_at'],
        'student_code' => $row['student_code'],
        'student_name' => $row['student_name'],
    );
}

ieum_json_response(true, '문자 큐를 확보했습니다.', array(
    'academy_id' => $academy_id,
    'academy_code' => $academy['academy_code'],
    'academy_name' => $academy['academy_name'],
    'items' => $items,
    'count' => count($items),
));
