<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/hq_billing.php';

$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$expected = defined('IEUM_BILLING_CRON_TOKEN') ? IEUM_BILLING_CRON_TOKEN : (defined('IEUM_PROJECT_STATUS_TOKEN') ? IEUM_PROJECT_STATUS_TOKEN : '');
if ($expected === '' || !hash_equals($expected, $token)) {
    ieum_json_response(false, '자동 발송 인증에 실패했습니다.', array(), 401);
}

$academy_id = isset($_REQUEST['academy_id']) ? (int) $_REQUEST['academy_id'] : 0;
$target_date = isset($_REQUEST['date']) ? trim($_REQUEST['date']) : G5_TIME_YMD;
$dry_run = isset($_REQUEST['dry_run']) && $_REQUEST['dry_run'] === '1';

$result = ieum_hq_send_due_tuition_bills($academy_id, $target_date, $dry_run, 'billing-cron');
ieum_json_response(true, '청구서 자동 발송 작업을 실행했습니다.', $result);
