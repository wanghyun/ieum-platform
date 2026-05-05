<?php
if (!defined('_GNUBOARD_')) {
    require_once dirname(__DIR__) . '/common.php';
}

define('IEUM_PATH', __DIR__);
define('IEUM_URL', G5_URL . '/ieum');

define('IEUM_ACADEMY_TABLE', 'ieum_academies');
define('IEUM_STUDENT_TABLE', 'ieum_students');
define('IEUM_ATTENDANCE_TABLE', 'ieum_attendance');
define('IEUM_SMS_QUEUE_TABLE', 'ieum_sms_queue');
define('IEUM_CLASS_TIME_TABLE', 'ieum_class_times');
define('IEUM_STUDENT_GUARDIAN_TABLE', 'ieum_student_guardians');
define('IEUM_ACADEMY_CONTACT_TABLE', 'ieum_academy_contacts');
define('IEUM_ABSENT_ALERT_LOG_TABLE', 'ieum_absent_alert_log');
define('IEUM_PROJECT_TASK_TABLE', 'ieum_project_tasks');
define('IEUM_TUITION_PLAN_TABLE', 'ieum_tuition_plans');
define('IEUM_TUITION_PAYMENT_TABLE', 'ieum_tuition_payments');

if (is_file(IEUM_PATH . '/config.php')) {
    require_once IEUM_PATH . '/config.php';
}
