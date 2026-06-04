<?php
if (!defined('_GNUBOARD_')) {
    require_once dirname(__DIR__) . '/common.php';
}

define('IEUM_PATH', __DIR__);
define('IEUM_URL', G5_URL . '/ieum');

define('IEUM_ACADEMY_TABLE', 'ieum_academies');
define('IEUM_ACADEMY_PROGRAM_TABLE', 'ieum_academy_programs');
define('IEUM_STUDENT_TABLE', 'ieum_students');
define('IEUM_ATTENDANCE_TABLE', 'ieum_attendance');
define('IEUM_SMS_QUEUE_TABLE', 'ieum_sms_queue');
define('IEUM_SMS_GATEWAY_DEVICE_TABLE', 'ieum_sms_gateway_devices');
define('IEUM_CLASS_TIME_TABLE', 'ieum_class_times');
define('IEUM_STUDENT_GUARDIAN_TABLE', 'ieum_student_guardians');
define('IEUM_ACADEMY_CONTACT_TABLE', 'ieum_academy_contacts');
define('IEUM_ABSENT_ALERT_LOG_TABLE', 'ieum_absent_alert_log');
define('IEUM_PROJECT_TASK_TABLE', 'ieum_project_tasks');
define('IEUM_STUDENT_STATUS_LOG_TABLE', 'ieum_student_status_logs');
define('IEUM_TUITION_PLAN_TABLE', 'ieum_tuition_plans');
define('IEUM_TUITION_PAYMENT_TABLE', 'ieum_tuition_payments');
define('IEUM_SMS_TEMPLATE_TABLE', 'ieum_sms_templates');
define('IEUM_TUITION_SETTING_TABLE', 'ieum_tuition_settings');
define('IEUM_REPORT_CHARACTER_TABLE', 'ieum_report_character');
define('IEUM_REPORT_FITNESS_TABLE', 'ieum_report_fitness');
define('IEUM_FITNESS_STANDARD_TABLE', 'ieum_fitness_standards');
define('IEUM_FITNESS_SETTING_TABLE', 'ieum_fitness_settings');
define('IEUM_FITNESS_METRIC_TABLE', 'ieum_fitness_metrics');
define('IEUM_FITNESS_VALUE_TABLE', 'ieum_fitness_metric_values');
define('IEUM_CHARACTER_MISSION_TABLE', 'ieum_character_missions');
define('IEUM_CHARACTER_MISSION_STUDENT_TABLE', 'ieum_character_mission_students');
define('IEUM_CHARACTER_LEVEL_TABLE', 'ieum_character_level_snapshots');
define('IEUM_CHARACTER_SPECIAL_TABLE', 'ieum_character_special_logs');
define('IEUM_VEHICLE_ROUTE_TABLE', 'ieum_vehicle_routes');
define('IEUM_VEHICLE_STOP_TABLE', 'ieum_vehicle_stops');
define('IEUM_STUDENT_VEHICLE_TABLE', 'ieum_student_vehicles');
define('IEUM_VEHICLE_BOARDING_TABLE', 'ieum_vehicle_boarding_logs');
define('IEUM_VEHICLE_RUN_TABLE', 'ieum_vehicle_runs');
define('IEUM_VEHICLE_LOCATION_TABLE', 'ieum_vehicle_locations');
define('IEUM_ACADEMY_CALENDAR_TABLE', 'ieum_academy_calendar');
define('IEUM_TABLET_DEVICE_TABLE', 'ieum_tablet_devices');
define('IEUM_HQ_BILLING_WALLET_TABLE', 'ieum_hq_billing_wallet');
define('IEUM_HQ_BILLING_WALLET_LOG_TABLE', 'ieum_hq_billing_wallet_logs');
define('IEUM_BILLING_SEND_LOG_TABLE', 'ieum_billing_send_logs');
define('IEUM_PAYMINT_SETTING_TABLE', 'ieum_paymint_settings');
define('IEUM_PAYMINT_MERCHANT_TABLE', 'ieum_paymint_merchants');
define('IEUM_PAYMINT_BILL_TABLE', 'ieum_paymint_bills');
define('IEUM_PAYMINT_BILL_LOG_TABLE', 'ieum_paymint_bill_logs');
define('IEUM_PAYMINT_CALLBACK_TABLE', 'ieum_paymint_callbacks');
define('IEUM_PAYMINT_USAGE_MONTHLY_TABLE', 'ieum_paymint_usage_monthly');
define('IEUM_MAP_SETTING_TABLE', 'ieum_map_settings');
define('IEUM_DASHBOARD_SHORTCUT_TABLE', 'ieum_dashboard_shortcuts');
define('IEUM_DASHBOARD_TODAY_TASK_TABLE', 'ieum_dashboard_today_tasks');
define('IEUM_AUTO_CHECK_RESOLVE_TABLE', 'ieum_auto_check_resolves');
define('IEUM_IMPORT_MAPPING_TABLE', 'ieum_import_mappings');
define('IEUM_IMPORT_LOG_TABLE', 'ieum_import_logs');
define('IEUM_HQ_DAILY_SNAPSHOT_TABLE', 'ieum_hq_daily_snapshots');
define('IEUM_PROMOTION_LOG_TABLE', 'ieum_promotion_logs');
define('IEUM_PROMOTION_MISSION_TABLE', 'ieum_promotion_missions');
define('IEUM_PROMOTION_EXAM_FEE_TABLE', 'ieum_promotion_exam_fees');
define('IEUM_PROMOTION_NOTICE_LOG_TABLE', 'ieum_promotion_notice_logs');

if (is_file(IEUM_PATH . '/config.php')) {
    require_once IEUM_PATH . '/config.php';
}
