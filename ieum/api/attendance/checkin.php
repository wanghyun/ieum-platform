<?php
require_once dirname(dirname(__DIR__)) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/gateway.php';
require_once IEUM_PATH . '/lib/attendance.php';
require_once IEUM_PATH . '/lib/character_level.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, '허용되지 않는 요청입니다.', array(), 405);
}

$academy = ieum_require_gateway_token();
$student_code = isset($_POST['student_code']) ? ieum_normalize_student_code($_POST['student_code']) : '';
$selected_student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
$device = isset($_POST['device']) ? trim($_POST['device']) : 'tablet-attendance';

if ($student_code === '') {
    ieum_json_response(false, '학생번호를 입력하세요.', array(), 422);
}

$result = ieum_save_attendance_by_code($student_code, 'tablet_app', $selected_student_id, $academy, $device);

if ($result['status'] === 'forbidden') {
    ieum_json_response(false, $result['message'], array('status' => 'forbidden'), 403);
}

if ($result['status'] === 'not_found') {
    ieum_json_response(false, $result['message'], array('status' => 'not_found'), 404);
}

if ($result['status'] === 'needs_selection') {
    ieum_json_response(true, $result['message'], array(
        'status' => 'needs_selection',
        'academy_id' => (int) $academy['academy_id'],
        'academy_name' => $academy['academy_name'],
        'students' => isset($result['students']) ? $result['students'] : array(),
    ));
}

$student = isset($result['student']) ? $result['student'] : array();
$photo_url = '';
if (isset($student['student_photo']) && $student['student_photo'] !== '') {
    $photo_url = ieum_attendance_public_file_url($student['student_photo']);
}

$character_level = null;
if (isset($student['student_id'])) {
    $character_summary = ieum_character_level_sync_snapshot((int) $academy['academy_id'], $student, date('Y-m', strtotime(G5_TIME_YMD)));
    $character_level = isset($character_summary['current']['level']) ? $character_summary['current']['level'] : null;
}

$data = array(
    'status' => $result['status'] === 'duplicate' ? 'duplicate' : 'created',
    'academy_id' => (int) $academy['academy_id'],
    'academy_name' => $academy['academy_name'],
    'student_id' => isset($student['student_id']) ? (int) $student['student_id'] : 0,
    'student_code' => isset($student['student_code']) ? $student['student_code'] : '',
    'student_name' => isset($student['student_name']) ? $student['student_name'] : '',
    'photo_url' => $photo_url,
    'progress' => isset($result['progress']) ? $result['progress'] : null,
    'character_level' => $character_level,
);

if ($result['status'] === 'duplicate') {
    $data['checked_at'] = isset($result['attendance']['checked_at']) ? $result['attendance']['checked_at'] : '';
} else {
    $data['attendance_id'] = isset($result['attendance_id']) ? (int) $result['attendance_id'] : 0;
    $data['sms_queue_ids'] = isset($result['sms_queue_ids']) ? $result['sms_queue_ids'] : array();
    $data['sms_queue_count'] = count($data['sms_queue_ids']);
}

ieum_json_response(true, $result['message'], $data);
