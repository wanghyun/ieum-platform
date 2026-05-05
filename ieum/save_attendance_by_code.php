<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/attendance.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, '허용되지 않은 요청입니다.', array(), 405);
}

ieum_require_login_json();

$csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
if (!ieum_verify_csrf_token($csrf_token)) {
    ieum_json_response(false, '보안 토큰이 올바르지 않습니다.', array(), 403);
}

$student_code = isset($_POST['student_code']) ? $_POST['student_code'] : '';
$student_code = ieum_normalize_student_code($student_code);

if ($student_code === '') {
    ieum_json_response(false, '학생번호를 입력하세요.', array(), 422);
}

$result = ieum_save_attendance_by_code($student_code, 'kiosk');

if ($result['status'] === 'forbidden') {
    ieum_json_response(false, $result['message'], array('status' => 'forbidden'), 403);
}

if ($result['status'] === 'not_found') {
    ieum_json_response(false, $result['message'], array('status' => 'not_found'), 404);
}

if ($result['status'] === 'duplicate') {
    ieum_json_response(true, $result['message'], array(
        'status' => 'duplicate',
        'student_name' => $result['student']['student_name'],
        'checked_at' => $result['attendance']['checked_at'],
    ));
}

ieum_json_response(true, $result['message'], array(
    'status' => 'created',
    'student_name' => $result['student']['student_name'],
    'attendance_id' => $result['attendance_id'],
    'sms_queue_ids' => $result['sms_queue_ids'],
    'sms_queue_count' => count($result['sms_queue_ids']),
));
