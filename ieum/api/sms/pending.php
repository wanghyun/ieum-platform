<?php
require_once dirname(dirname(__DIR__)) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/gateway.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ieum_json_response(false, '허용되지 않은 요청입니다.', array(), 405);
}

$academy = ieum_require_gateway_token();
$academy_id = (int) $academy['academy_id'];
ieum_sms_queue_ensure_schedule_columns();

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 50) {
    $limit = 50;
}

$result = sql_query("
    select q.sms_id, q.student_id, q.attendance_id, q.recipient_phone, q.message, q.created_at,
           s.student_code, s.student_name
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
     where q.academy_id = '{$academy_id}'
       and q.status = 'pending'
       and (q.scheduled_at is null or q.scheduled_at <= '" . G5_TIME_YMDHIS . "')
  order by q.sms_id asc
     limit {$limit}
", false);

$items = array();
while ($row = sql_fetch_array($result)) {
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

ieum_json_response(true, '문자 대기열 조회 완료', array(
    'academy_id' => $academy_id,
    'academy_code' => $academy['academy_code'],
    'academy_name' => $academy['academy_name'],
    'items' => $items,
    'count' => count($items),
    'note' => '실제 발송 기기는 중복 발송 방지를 위해 claim.php를 사용하세요.',
));
