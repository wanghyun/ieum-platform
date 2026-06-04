<?php
$sub_menu = '950100';
require_once './_common.php';
require_once IEUM_PATH . '/lib/hq_billing.php';
require_once IEUM_PATH . '/lib/paymint.php';
require_once IEUM_PATH . '/lib/hq_snapshot.php';

ieum_require_head_admin_page();
ieum_paymint_ensure_tables();
ieum_hq_snapshot_ensure_tables();

$g5['title'] = '아이이음 본사 현황';

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month_sql = sql_escape_string($month);
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$month_start_sql = sql_escape_string($month_start . ' 00:00:00');
$month_end_sql = sql_escape_string($month_end . ' 23:59:59');
$month_start_date_sql = sql_escape_string($month_start);
$month_end_date_sql = sql_escape_string($month_end);
$today_sql = sql_escape_string(G5_TIME_YMD);
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$status_filter = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', trim($_GET['status'])) : 'all';
if (!in_array($status_filter, array('all', 'active', 'pending', 'suspended', 'inactive', 'signal'), true)) {
    $status_filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['snapshot_action']) && $_POST['snapshot_action'] === 'refresh') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        alert('잘못된 요청입니다.', IEUM_URL . '/admin/hq_overview.php');
    }

    $result = ieum_hq_snapshot_refresh_all($month, G5_TIME_YMD, 'manual');
    $redirect = IEUM_URL . '/admin/hq_overview.php?month=' . rawurlencode($month) . '&status=' . rawurlencode($status_filter);
    if ($q !== '') {
        $redirect .= '&q=' . rawurlencode($q);
    }
    alert('본사 운영 데이터 ' . number_format($result['count']) . '개 도장을 갱신했습니다.', $redirect);
}

function ieum_hq_overview_table_exists($table)
{
    $table_sql = sql_escape_string($table);
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $row = sql_fetch("
        select count(*) as cnt
          from information_schema.TABLES
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
    ", false);

    return !empty($row['cnt']);
}

function ieum_hq_overview_count($sql)
{
    $row = sql_fetch($sql, false);
    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_hq_overview_money($value)
{
    return number_format((int) $value) . '원';
}

function ieum_hq_overview_service_label($status, $is_active)
{
    if ((int) $is_active !== 1) {
        return '비활성';
    }

    $labels = array(
        'active' => '사용중',
        'pending' => '승인대기',
        'suspended' => '중지',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}

$has_sms_devices = ieum_hq_overview_table_exists(IEUM_SMS_GATEWAY_DEVICE_TABLE);
$has_tablet_devices = ieum_hq_overview_table_exists(IEUM_TABLET_DEVICE_TABLE);
$has_boarding = ieum_hq_overview_table_exists(IEUM_VEHICLE_BOARDING_TABLE);
$has_status_logs = ieum_hq_overview_table_exists(IEUM_STUDENT_STATUS_LOG_TABLE);

$summary = array(
    'academy_total' => 0,
    'academy_active' => 0,
    'student_active' => 0,
    'student_new' => 0,
    'student_paused' => 0,
    'student_returned' => 0,
    'student_withdrawn' => 0,
    'paymint_mapped' => 0,
    'bill_count' => 0,
    'bill_paid' => 0,
    'bill_waiting' => 0,
    'bill_failed' => 0,
    'bill_amount' => 0,
    'vehicle_assigned' => 0,
    'character_enabled' => 0,
    'character_entered' => 0,
    'fitness_enabled' => 0,
    'fitness_entered' => 0,
    'sms_devices' => 0,
    'tablet_devices' => 0,
    'signal_count' => 0,
);

$overview_rows = array();
$snapshot_meta = ieum_hq_snapshot_latest_generated_at($month, G5_TIME_YMD);
$use_snapshot_rows = $snapshot_meta['count'] > 0;

if ($use_snapshot_rows) {
    $overview_rows = ieum_hq_snapshot_load_rows($month, G5_TIME_YMD);
    if ($q !== '') {
        $q_lower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        $overview_rows = array_values(array_filter($overview_rows, function ($row) use ($q_lower) {
            $academy = $row['academy'];
            $haystack = $academy['academy_name'] . ' ' . $academy['academy_code'] . ' ' . $academy['mb_id'];
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            return strpos($haystack, $q_lower) !== false;
        }));
    }
    if ($status_filter === 'active') {
        $overview_rows = array_values(array_filter($overview_rows, function ($row) {
            return (int) $row['academy']['is_active'] === 1 && $row['academy']['service_status'] === 'active';
        }));
    } elseif ($status_filter === 'pending') {
        $overview_rows = array_values(array_filter($overview_rows, function ($row) {
            return (int) $row['academy']['is_active'] === 1 && $row['academy']['service_status'] === 'pending';
        }));
    } elseif ($status_filter === 'suspended') {
        $overview_rows = array_values(array_filter($overview_rows, function ($row) {
            return (int) $row['academy']['is_active'] === 1 && $row['academy']['service_status'] === 'suspended';
        }));
    } elseif ($status_filter === 'inactive') {
        $overview_rows = array_values(array_filter($overview_rows, function ($row) {
            return (int) $row['academy']['is_active'] !== 1;
        }));
    }
} else {
$academy_where = "1=1";
if ($q !== '') {
    $q_sql = sql_escape_string($q);
    $academy_where .= " and (academy_name like '%{$q_sql}%' or academy_code like '%{$q_sql}%' or mb_id like '%{$q_sql}%') ";
}
if ($status_filter === 'active') {
    $academy_where .= " and is_active = 1 and service_status = 'active' ";
} elseif ($status_filter === 'pending') {
    $academy_where .= " and is_active = 1 and service_status = 'pending' ";
} elseif ($status_filter === 'suspended') {
    $academy_where .= " and is_active = 1 and service_status = 'suspended' ";
} elseif ($status_filter === 'inactive') {
    $academy_where .= " and is_active <> 1 ";
}

$academy_result = sql_query("
    select *
      from " . IEUM_ACADEMY_TABLE . "
     where {$academy_where}
  order by is_active desc, academy_name asc, academy_id asc
", false);

while ($academy = sql_fetch_array($academy_result)) {
    $academy_id = (int) $academy['academy_id'];

    $student = sql_fetch("
        select count(*) as total_count,
               sum(case when is_active = 1 and student_status in ('enrolled','trial','returned','') then 1 else 0 end) as active_count,
               sum(case when is_active = 1 and character_report_enabled = 1 then 1 else 0 end) as character_enabled_count,
               sum(case when is_active = 1 and fitness_report_enabled = 1 then 1 else 0 end) as fitness_enabled_count,
               sum(case when student_status = 'paused' then 1 else 0 end) as paused_count,
               sum(case when student_status = 'withdrawn' then 1 else 0 end) as withdrawn_count,
               sum(case when admission_date between '{$month_start_date_sql}' and '{$month_end_date_sql}' then 1 else 0 end) as new_count,
               sum(case when vehicle_pickup_enabled = 1 or vehicle_dropoff_enabled = 1 then 1 else 0 end) as vehicle_assigned_count
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
    ", false);

    $flow = array('new_count' => (int) $student['new_count'], 'paused_in_count' => 0, 'paused_out_count' => 0, 'returned_count' => 0, 'withdrawn_count' => 0);
    if ($has_status_logs) {
        $paused_in = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'paused'
               and changed_date between '{$month_start_date_sql}' and '{$month_end_date_sql}'
        ", false);
        $paused_out = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and before_status = 'paused'
               and after_status <> 'paused'
               and changed_date between '{$month_start_date_sql}' and '{$month_end_date_sql}'
        ", false);
        $returned = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and before_status = 'paused'
               and after_status in ('returned','enrolled')
               and changed_date between '{$month_start_date_sql}' and '{$month_end_date_sql}'
        ", false);
        $withdrawn = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'withdrawn'
               and changed_date between '{$month_start_date_sql}' and '{$month_end_date_sql}'
        ", false);

        $flow['paused_in_count'] = (int) $paused_in['cnt'];
        $flow['paused_out_count'] = (int) $paused_out['cnt'];
        $flow['returned_count'] = (int) $returned['cnt'];
        $flow['withdrawn_count'] = (int) $withdrawn['cnt'];
    }

    $character = sql_fetch("
        select count(distinct student_id) as entered_count
          from " . IEUM_REPORT_CHARACTER_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$month_sql}'
    ", false);

    $fitness = sql_fetch("
        select count(distinct student_id) as entered_count
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$month_sql}'
    ", false);

    $merchant = sql_fetch("
        select *
          from " . IEUM_PAYMINT_MERCHANT_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    $bill = sql_fetch("
        select count(*) as bill_count,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when appr_state = 'W' and status not in ('failed','destroyed','paid') then 1 else 0 end) as waiting_count,
               sum(case when status in ('failed','destroyed') then 1 else 0 end) as failed_count,
               sum(bill_amount) as bill_amount
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$academy_id}'
           and billing_month = '{$month_sql}'
    ", false);

    $sms_device_count = 0;
    if ($has_sms_devices) {
        $sms_device_count = ieum_hq_overview_count("
            select count(*) as cnt
              from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
             where academy_id = '{$academy_id}'
               and device_status = 'active'
        ");
    }

    $tablet_device_count = 0;
    if ($has_tablet_devices) {
        $tablet_device_count = ieum_hq_overview_count("
            select count(*) as cnt
              from " . IEUM_TABLET_DEVICE_TABLE . "
             where academy_id = '{$academy_id}'
               and status = 'active'
        ");
    }

    $boarding_today = 0;
    if ($has_boarding) {
        $boarding_today = ieum_hq_overview_count("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and journal_date = '{$today_sql}'
        ");
    }

    $active_count = (int) $student['active_count'];
    $character_enabled = (int) $student['character_enabled_count'];
    $fitness_enabled = (int) $student['fitness_enabled_count'];
    $character_entered = (int) $character['entered_count'];
    $fitness_entered = (int) $fitness['entered_count'];
    $vehicle_assigned = (int) $student['vehicle_assigned_count'];
    $bill_count = (int) $bill['bill_count'];
    $paid_count = (int) $bill['paid_count'];
    $waiting_count = (int) $bill['waiting_count'];
    $failed_count = (int) $bill['failed_count'];

    $alerts = array();
    if ((int) $academy['is_active'] !== 1 || $academy['service_status'] !== 'active') {
        $alerts[] = '서비스 확인';
    }
    if ($active_count > 0 && $sms_device_count === 0) {
        $alerts[] = '문자폰 미연결';
    }
    if ($active_count > 0 && $tablet_device_count === 0) {
        $alerts[] = '출석기 미연결';
    }
    if ($vehicle_assigned > 0 && $boarding_today === 0) {
        $alerts[] = '오늘 차량기록 없음';
    }
    if ($failed_count > 0) {
        $alerts[] = '청구 실패';
    }
    if ($active_count > 0 && $character_enabled === 0) {
        $alerts[] = '인성 미사용';
    } elseif ($character_enabled > 0 && $character_entered === 0) {
        $alerts[] = '인성 입력 없음';
    }
    if ($active_count > 0 && $fitness_enabled === 0) {
        $alerts[] = '체력 미사용';
    } elseif ($fitness_enabled > 0 && $fitness_entered === 0) {
        $alerts[] = '체력 입력 없음';
    }

    $overview_rows[] = array(
        'academy' => $academy,
        'active_count' => $active_count,
        'total_count' => (int) $student['total_count'],
        'new_count' => (int) $flow['new_count'],
        'paused_count' => (int) $flow['paused_in_count'],
        'paused_out_count' => (int) $flow['paused_out_count'],
        'returned_count' => (int) $flow['returned_count'],
        'withdrawn_count' => (int) $flow['withdrawn_count'],
        'current_paused_count' => (int) $student['paused_count'],
        'current_withdrawn_count' => (int) $student['withdrawn_count'],
        'character_enabled' => $character_enabled,
        'character_entered' => $character_entered,
        'fitness_enabled' => $fitness_enabled,
        'fitness_entered' => $fitness_entered,
        'vehicle_assigned' => $vehicle_assigned,
        'boarding_today' => $boarding_today,
        'sms_device_count' => $sms_device_count,
        'tablet_device_count' => $tablet_device_count,
        'merchant' => $merchant,
        'bill_count' => $bill_count,
        'paid_count' => $paid_count,
        'waiting_count' => $waiting_count,
        'failed_count' => $failed_count,
        'bill_amount' => (int) $bill['bill_amount'],
        'alerts' => $alerts,
    );

    $summary['academy_total']++;
    if ((int) $academy['is_active'] === 1) {
        $summary['academy_active']++;
    }
    $summary['student_active'] += $active_count;
    $summary['student_new'] += (int) $flow['new_count'];
    $summary['student_paused'] += (int) $flow['paused_in_count'];
    $summary['student_returned'] += (int) $flow['returned_count'];
    $summary['student_withdrawn'] += (int) $flow['withdrawn_count'];
    if (!empty($merchant['merchant_map_id']) && in_array($merchant['mapping_status'], array('mapped','active','connected'), true)) {
        $summary['paymint_mapped']++;
    }
    $summary['bill_count'] += $bill_count;
    $summary['bill_paid'] += $paid_count;
    $summary['bill_waiting'] += $waiting_count;
    $summary['bill_failed'] += $failed_count;
    $summary['bill_amount'] += (int) $bill['bill_amount'];
    $summary['vehicle_assigned'] += $vehicle_assigned;
}
}

if ($status_filter === 'signal') {
    $overview_rows = array_values(array_filter($overview_rows, function ($row) {
        return !empty($row['alerts']);
    }));
}

$summary = array(
    'academy_total' => 0,
    'academy_active' => 0,
    'student_active' => 0,
    'student_new' => 0,
    'student_paused' => 0,
    'student_returned' => 0,
    'student_withdrawn' => 0,
    'paymint_mapped' => 0,
    'bill_count' => 0,
    'bill_paid' => 0,
    'bill_waiting' => 0,
    'bill_failed' => 0,
    'bill_amount' => 0,
    'vehicle_assigned' => 0,
);
foreach ($overview_rows as $row) {
    $summary['academy_total']++;
    if ((int) $row['academy']['is_active'] === 1) {
        $summary['academy_active']++;
    }
    $summary['student_active'] += (int) $row['active_count'];
    $summary['student_new'] += (int) $row['new_count'];
    $summary['student_paused'] += (int) $row['paused_count'];
    $summary['student_returned'] += (int) $row['returned_count'];
    $summary['student_withdrawn'] += (int) $row['withdrawn_count'];
    if (!empty($row['merchant']['merchant_map_id']) && in_array($row['merchant']['mapping_status'], array('mapped','active','connected'), true)) {
        $summary['paymint_mapped']++;
    }
    $summary['bill_count'] += (int) $row['bill_count'];
    $summary['bill_paid'] += (int) $row['paid_count'];
    $summary['bill_waiting'] += (int) $row['waiting_count'];
    $summary['bill_failed'] += (int) $row['failed_count'];
    $summary['bill_amount'] += (int) $row['bill_amount'];
    $summary['vehicle_assigned'] += (int) $row['vehicle_assigned'];
    $summary['character_enabled'] += (int) $row['character_enabled'];
    $summary['character_entered'] += (int) $row['character_entered'];
    $summary['fitness_enabled'] += (int) $row['fitness_enabled'];
    $summary['fitness_entered'] += (int) $row['fitness_entered'];
    $summary['sms_devices'] += (int) $row['sms_device_count'];
    $summary['tablet_devices'] += (int) $row['tablet_device_count'];
    if (!empty($row['alerts'])) {
        $summary['signal_count']++;
    }
}

$wallet = ieum_hq_wallet_ensure();
$csrf_token = ieum_new_csrf_token();
$snapshot_label = !empty($snapshot_meta['generated_at']) ? $snapshot_meta['generated_at'] : '아직 집계 전';
$snapshot_mode_label = $use_snapshot_rows ? '저장된 집계 기준' : '실시간 계산 기준';
$character_rate = $summary['character_enabled'] > 0 ? round(($summary['character_entered'] / $summary['character_enabled']) * 100) : 0;
$fitness_rate = $summary['fitness_enabled'] > 0 ? round(($summary['fitness_entered'] / $summary['fitness_enabled']) * 100) : 0;
$device_ready_count = 0;
foreach ($overview_rows as $row) {
    if ((int) $row['sms_device_count'] > 0 && (int) $row['tablet_device_count'] > 0) {
        $device_ready_count++;
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#0f172a;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 22px}
.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:18px;flex-wrap:wrap}
h1{margin:0;font-size:30px;letter-spacing:0}.muted{color:#64748b}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;text-decoration:none;padding:8px 13px;font-weight:900;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.dark{background:#111827;border-color:#111827;color:#fff}
input[type=month],input[type=text],select{height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;background:#fff}
.hero{display:grid;grid-template-columns:repeat(6,minmax(150px,1fr));gap:12px;margin:18px 0}
.card{background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.05)}
.card strong{display:block;color:#64748b;font-size:13px;margin-bottom:8px}.num{font-size:28px;font-weight:950}.sub{font-size:12px;color:#64748b;margin-top:6px}
.bar{height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:10px}.bar i{display:block;height:100%;background:#1769c2}
.bar.good i{background:#059669}.bar.warn i{background:#d97706}
.layout{display:grid;grid-template-columns:1fr 340px;gap:16px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.05)}.panel h2{margin:0 0 14px;font-size:22px}
.signal-list{display:grid;gap:8px}.signal{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #dbe3ef;border-radius:10px;padding:11px;background:#f8fafc}.signal b{font-size:14px}.tag{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef2ff;color:#1e40af}.tag.warn{background:#fff7ed;color:#b45309}.tag.bad{background:#fef2f2;color:#b91c1c}.tag.good{background:#ecfdf3;color:#047857}
.table-wrap{overflow-x:auto;border:1px solid #dbe3ef;border-radius:12px;background:#fff}table{width:100%;border-collapse:collapse;min-width:1180px}th,td{border-bottom:1px solid #e2e8f0;padding:12px 10px;text-align:left;font-size:13px;vertical-align:top}th{background:#74839c;color:#fff;white-space:nowrap}td.numcell{text-align:right;font-variant-numeric:tabular-nums}.academy-name{font-weight:950}.small{font-size:12px;color:#64748b}.metric-line{white-space:nowrap}.empty{color:#94a3b8}.status-ok{color:#047857;font-weight:900}.status-warn{color:#b45309;font-weight:900}.status-bad{color:#b91c1c;font-weight:900}
.name-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.detail-btn{min-height:28px;border:1px solid #cbd5e1;border-radius:999px;background:#f8fafc;color:#1e3a8a;padding:4px 9px;font-size:12px;font-weight:900;cursor:pointer}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:80;display:none;align-items:center;justify-content:center;padding:20px}.modal-backdrop.is-open{display:flex}.modal{width:min(860px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 24px 80px rgba(15,23,42,.28);border:1px solid #dbe3ef}.modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:20px 22px;border-bottom:1px solid #e2e8f0}.modal-head h2{margin:0;font-size:24px}.modal-close{border:0;background:#f1f5f9;border-radius:999px;width:36px;height:36px;font-size:20px;font-weight:900;cursor:pointer}.modal-body{padding:20px 22px}.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.detail-card{border:1px solid #dbe3ef;border-radius:12px;padding:14px;background:#f8fafc}.detail-card strong{display:block;color:#475569;font-size:13px;margin-bottom:8px}.detail-card .big{font-size:22px;font-weight:950}.detail-card .desc{margin-top:6px;color:#64748b;font-size:12px;line-height:1.5}.detail-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.detail-alerts{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
@media(max-width:1300px){.hero{grid-template-columns:repeat(3,1fr)}.layout{grid-template-columns:1fr}}
@media(max-width:720px){.wrap{padding:0 14px}.hero{grid-template-columns:1fr}.head{align-items:flex-start}.actions{width:100%}.btn,input[type=month],input[type=text],select{width:100%}.detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('hq_overview'); ?>
<?php echo ieum_admin_subnav('hq_overview'); ?>
<main class="wrap">
    <div class="head">
        <div>
            <h1>본사 운영 현황</h1>
            <div class="muted">가맹점별 원생, 리포트, 차량, 결제선생 사용량을 한 화면에서 확인합니다.</div>
        </div>
        <form class="actions" method="get">
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <select name="status" aria-label="가맹점 상태">
                <option value="all" <?php echo get_selected($status_filter, 'all'); ?>>전체 상태</option>
                <option value="active" <?php echo get_selected($status_filter, 'active'); ?>>사용중</option>
                <option value="pending" <?php echo get_selected($status_filter, 'pending'); ?>>승인대기</option>
                <option value="suspended" <?php echo get_selected($status_filter, 'suspended'); ?>>중지</option>
                <option value="inactive" <?php echo get_selected($status_filter, 'inactive'); ?>>비활성</option>
                <option value="signal" <?php echo get_selected($status_filter, 'signal'); ?>>확인 신호 있음</option>
            </select>
            <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="가맹점명, 코드, 아이디 검색">
            <button class="btn primary" type="submit">조회</button>
            <button class="btn dark" type="button" id="snapshotRefreshOpen">수동 갱신</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/academies.php">가맹점 관리</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">결제 운영</a>
        </form>
    </div>
    <div class="card" style="margin-bottom:16px">
        <strong>본사 데이터 기준</strong>
        <div class="sub"><?php echo get_text($snapshot_mode_label); ?> · 마지막 갱신 <?php echo get_text($snapshot_label); ?> · 쌤포인트 잔액은 결제 운영 화면에서 API 기준으로 확인합니다. · 새벽 자동 집계 후 본사 화면에 반영됩니다.</div>
    </div>

    <section class="hero">
        <article class="card"><strong>운영 가맹점</strong><div class="num"><?php echo number_format($summary['academy_active']); ?>/<?php echo number_format($summary['academy_total']); ?></div><div class="sub">사용 중 / 조회 결과</div></article>
        <article class="card"><strong>기기 정상 연결</strong><div class="num"><?php echo number_format($device_ready_count); ?>곳</div><div class="sub">문자폰 <?php echo number_format($summary['sms_devices']); ?>대 · 출석기 <?php echo number_format($summary['tablet_devices']); ?>대</div></article>
        <article class="card"><strong>인성 입력률</strong><div class="num"><?php echo number_format($character_rate); ?>%</div><div class="sub"><?php echo number_format($summary['character_entered']); ?>/<?php echo number_format($summary['character_enabled']); ?>명 입력</div><div class="bar <?php echo $character_rate >= 80 ? 'good' : 'warn'; ?>"><i style="width:<?php echo min(100, $character_rate); ?>%"></i></div></article>
        <article class="card"><strong>체력 입력률</strong><div class="num"><?php echo number_format($fitness_rate); ?>%</div><div class="sub"><?php echo number_format($summary['fitness_entered']); ?>/<?php echo number_format($summary['fitness_enabled']); ?>명 입력</div><div class="bar <?php echo $fitness_rate >= 80 ? 'good' : 'warn'; ?>"><i style="width:<?php echo min(100, $fitness_rate); ?>%"></i></div></article>
        <article class="card"><strong>결제선생 연동</strong><div class="num"><?php echo number_format($summary['paymint_mapped']); ?>곳</div><div class="sub">청구 <?php echo number_format($summary['bill_count']); ?>건 · 실패 <?php echo number_format($summary['bill_failed']); ?>건</div></article>
        <article class="card"><strong>확인 신호</strong><div class="num"><?php echo number_format($summary['signal_count']); ?>곳</div><div class="sub">본사가 확인할 가맹점</div></article>
    </section>

    <section class="layout">
        <article class="panel">
            <h2>가맹점별 사용량</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>가맹점</th>
                        <th>원생 현황</th>
                        <th>인성 리포트</th>
                        <th>체력 리포트</th>
                        <th>차량 운행</th>
                        <th>결제선생/청구서</th>
                        <th>기기 연결</th>
                        <th>확인 신호</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$overview_rows) { ?>
                    <tr><td colspan="8" class="empty">등록된 가맹점이 없습니다.</td></tr>
                    <?php } ?>
                    <?php foreach ($overview_rows as $row) {
                        $academy = $row['academy'];
                        $merchant_connected = !empty($row['merchant']['merchant_map_id']) && in_array($row['merchant']['mapping_status'], array('mapped','active','connected'), true);
                    ?>
                    <tr>
                        <td>
                            <div class="name-row">
                                <div class="academy-name"><?php echo get_text($academy['academy_name']); ?></div>
                                <button type="button"
                                    class="detail-btn js-academy-detail"
                                    data-name="<?php echo get_text($academy['academy_name']); ?>"
                                    data-code="<?php echo get_text($academy['academy_code']); ?>"
                                    data-status="<?php echo get_text(ieum_hq_overview_service_label($academy['service_status'], $academy['is_active'])); ?>"
                                    data-active="<?php echo number_format($row['active_count']); ?>"
                                    data-total="<?php echo number_format($row['total_count']); ?>"
                                    data-new="<?php echo number_format($row['new_count']); ?>"
                                    data-returned="<?php echo number_format($row['returned_count']); ?>"
                                    data-paused="<?php echo number_format($row['paused_count']); ?>"
                                    data-withdrawn="<?php echo number_format($row['withdrawn_count']); ?>"
                                    data-current-paused="<?php echo number_format($row['current_paused_count']); ?>"
                                    data-current-withdrawn="<?php echo number_format($row['current_withdrawn_count']); ?>"
                                    data-character="<?php echo number_format($row['character_entered']); ?>/<?php echo number_format($row['character_enabled']); ?>"
                                    data-fitness="<?php echo number_format($row['fitness_entered']); ?>/<?php echo number_format($row['fitness_enabled']); ?>"
                                    data-vehicle="<?php echo number_format($row['boarding_today']); ?>/<?php echo number_format($row['vehicle_assigned']); ?>"
                                    data-billing="<?php echo number_format($row['paid_count']); ?>/<?php echo number_format($row['waiting_count']); ?>/<?php echo number_format($row['failed_count']); ?>"
                                    data-bill-count="<?php echo number_format($row['bill_count']); ?>"
                                    data-bill-amount="<?php echo get_text(ieum_hq_overview_money($row['bill_amount'])); ?>"
                                    data-sms="<?php echo number_format($row['sms_device_count']); ?>"
                                    data-tablet="<?php echo number_format($row['tablet_device_count']); ?>"
                                    data-alerts="<?php echo get_text($row['alerts'] ? implode(', ', $row['alerts']) : '정상'); ?>"
                                    data-detail-url="<?php echo IEUM_URL; ?>/admin/hq_academy_detail.php?academy_id=<?php echo (int) $academy['academy_id']; ?>&amp;month=<?php echo urlencode($month); ?>"
                                    data-academy-url="<?php echo IEUM_URL; ?>/admin/academies.php?q=<?php echo urlencode($academy['academy_code']); ?>"
                                    data-paymint-url="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php?q=<?php echo urlencode($academy['academy_code']); ?>"
                                    data-billing-url="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?academy_id=<?php echo (int) $academy['academy_id']; ?>&amp;month=<?php echo urlencode($month); ?>"
                                >상세</button>
                            </div>
                            <div class="small"><?php echo get_text($academy['academy_code']); ?> · <?php echo get_text(ieum_hq_overview_service_label($academy['service_status'], $academy['is_active'])); ?></div>
                        </td>
                        <td>
                            <div class="metric-line">재원 <b><?php echo number_format($row['active_count']); ?></b>명</div>
                            <div class="small">신규 <?php echo number_format($row['new_count']); ?> · 복귀 <?php echo number_format($row['returned_count']); ?> · 휴관 <?php echo number_format($row['paused_count']); ?> · 퇴관 <?php echo number_format($row['withdrawn_count']); ?></div>
                            <div class="small">현재 휴관 <?php echo number_format($row['current_paused_count']); ?> · 현재 퇴관 <?php echo number_format($row['current_withdrawn_count']); ?></div>
                        </td>
                        <td>
                            <div class="metric-line">사용 <?php echo number_format($row['character_enabled']); ?>명</div>
                            <div class="small"><?php echo get_text($month); ?> 입력 <?php echo number_format($row['character_entered']); ?>명</div>
                        </td>
                        <td>
                            <div class="metric-line">사용 <?php echo number_format($row['fitness_enabled']); ?>명</div>
                            <div class="small"><?php echo get_text($month); ?> 입력 <?php echo number_format($row['fitness_entered']); ?>명</div>
                        </td>
                        <td>
                            <div class="metric-line">배정 <?php echo number_format($row['vehicle_assigned']); ?>명</div>
                            <div class="small">오늘 탑승기록 <?php echo number_format($row['boarding_today']); ?>건</div>
                        </td>
                        <td>
                            <div class="<?php echo $merchant_connected ? 'status-ok' : 'status-warn'; ?>"><?php echo $merchant_connected ? '연동 완료' : '연동 필요'; ?></div>
                            <div class="small">청구 <?php echo number_format($row['bill_count']); ?> · 완료 <?php echo number_format($row['paid_count']); ?> · 실패 <?php echo number_format($row['failed_count']); ?></div>
                        </td>
                        <td>
                            <div class="metric-line">문자폰 <?php echo number_format($row['sms_device_count']); ?>대</div>
                            <div class="small">출석기 <?php echo number_format($row['tablet_device_count']); ?>대</div>
                        </td>
                        <td>
                            <?php if (!$row['alerts']) { ?>
                                <span class="tag good">정상</span>
                            <?php } else { ?>
                                <?php foreach ($row['alerts'] as $alert) { ?>
                                    <span class="tag <?php echo strpos($alert, '실패') !== false ? 'bad' : 'warn'; ?>"><?php echo get_text($alert); ?></span>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="panel">
            <h2>본사 체크</h2>
            <div class="signal-list">
                <div class="signal"><b>가맹점 등록/승인</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/academies.php">관리</a></div>
                <div class="signal"><b>결제선생 가맹점 연동</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">확인</a></div>
                <div class="signal"><b>API 키/지도 설정</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/map_settings.php">설정</a></div>
                <div class="signal"><b>청구서 사용량/쌤포인트</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">보기</a></div>
                <div class="signal"><b>기기 미연결 가맹점</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/hq_overview.php?month=<?php echo urlencode($month); ?>&status=signal">확인</a></div>
                <div class="signal"><b>입력률 낮은 가맹점</b><a class="tag" href="<?php echo IEUM_URL; ?>/admin/hq_overview.php?month=<?php echo urlencode($month); ?>&status=signal">확인</a></div>
            </div>
            <p class="small" style="margin-top:14px;line-height:1.6">
                본사 화면은 도장 운영 화면이 아니라, 가맹점의 사용 상태를 빠르게 확인하고 조치할 곳을 찾는 관제 화면입니다.
                도장별 상세 버튼에서 원생 흐름, 리포트 입력률, 기기 연결, 결제선생 사용량을 이어서 확인합니다.
            </p>
        </aside>
    </section>
</main>
<div class="modal-backdrop" id="academyDetailModal" aria-hidden="true">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="academyDetailTitle">
        <div class="modal-head">
            <div>
                <h2 id="academyDetailTitle">가맹점 상세</h2>
                <div class="small" id="academyDetailMeta"></div>
            </div>
            <button type="button" class="modal-close" id="academyDetailClose" aria-label="닫기">×</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <article class="detail-card"><strong>원생 현황</strong><div class="big" id="detailStudents"></div><div class="desc" id="detailStudentsSub"></div></article>
                <article class="detail-card"><strong>인성 리포트</strong><div class="big" id="detailCharacter"></div><div class="desc">이번 달 입력 / 사용 대상</div></article>
                <article class="detail-card"><strong>체력 리포트</strong><div class="big" id="detailFitness"></div><div class="desc">이번 달 입력 / 사용 대상</div></article>
                <article class="detail-card"><strong>차량 운행</strong><div class="big" id="detailVehicle"></div><div class="desc">오늘 탑승기록 / 차량 배정</div></article>
                <article class="detail-card"><strong>결제선생 청구</strong><div class="big" id="detailBilling"></div><div class="desc" id="detailBillingSub"></div></article>
                <article class="detail-card"><strong>기기 연결</strong><div class="big" id="detailDevices"></div><div class="desc">문자폰 / 앱 출석기</div></article>
            </div>
            <div class="detail-card" style="margin-top:12px">
                <strong>확인 신호</strong>
                <div class="detail-alerts" id="detailAlerts"></div>
            </div>
            <div class="detail-actions">
                <a class="btn primary" id="detailTrendLink" href="<?php echo IEUM_URL; ?>/admin/hq_academy_detail.php">월별 추이 보기</a>
                <a class="btn" id="detailAcademyLink" href="<?php echo IEUM_URL; ?>/admin/academies.php">가맹점 관리</a>
                <a class="btn" id="detailPaymintLink" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">결제선생 연동</a>
                <a class="btn" id="detailBillingLink" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">청구 운영</a>
            </div>
        </div>
    </section>
</div>
<div class="modal-backdrop" id="snapshotRefreshModal" aria-hidden="true">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="snapshotRefreshTitle">
        <div class="modal-head">
            <div>
                <h2 id="snapshotRefreshTitle">본사 데이터 수동 갱신</h2>
                <div class="small">현재 월 기준으로 전체 가맹점 데이터를 다시 집계합니다.</div>
            </div>
            <button type="button" class="modal-close" id="snapshotRefreshClose" aria-label="닫기">×</button>
        </div>
        <div class="modal-body">
            <div class="detail-card">
                <strong>서버 안정화 안내</strong>
                <div class="desc">
                    오전 10시부터 오후 8시까지는 도장 운영과 문자/출석 사용이 많은 시간입니다.
                    이 시간대의 수동 갱신은 서버 안정화를 위해 되도록 지양해 주세요.
                    긴급 확인이 필요한 경우에만 계속 진행해 주세요.
                </div>
            </div>
            <form method="post" class="detail-actions" id="snapshotRefreshForm">
                <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                <input type="hidden" name="snapshot_action" value="refresh">
                <button class="btn primary" type="submit">계속 진행</button>
                <button class="btn" type="button" id="snapshotRefreshCancel">종료</button>
            </form>
        </div>
    </section>
</div>
<script>
(function () {
    var modal = document.getElementById('academyDetailModal');
    var closeButton = document.getElementById('academyDetailClose');
    function setText(id, value) {
        var node = document.getElementById(id);
        if (node) node.textContent = value || '';
    }
    function formatPair(value, leftUnit, rightUnit) {
        var parts = String(value || '0/0').split('/');
        return (parts[0] || '0') + leftUnit + ' / ' + (parts[1] || '0') + rightUnit;
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('.js-academy-detail').forEach(function (button) {
        button.addEventListener('click', function () {
            var d = button.dataset;
            setText('academyDetailTitle', d.name);
            setText('academyDetailMeta', d.code + ' · ' + d.status);
            setText('detailStudents', d.active + '명');
            setText('detailStudentsSub', '전체 ' + d.total + '명 · 신규 ' + d.new + ' · 복귀 ' + d.returned + ' · 휴관 ' + d.paused + ' · 퇴관 ' + d.withdrawn + ' · 현재 휴관 ' + d.currentPaused + ' · 현재 퇴관 ' + d.currentWithdrawn);
            setText('detailCharacter', formatPair(d.character, '명', '명'));
            setText('detailFitness', formatPair(d.fitness, '명', '명'));
            setText('detailVehicle', formatPair(d.vehicle, '건', '명'));
            setText('detailBilling', String(d.billing || '0/0/0').split('/').join(' / ') + '건');
            setText('detailBillingSub', '완료/대기/실패 · 청구 ' + d.billCount + '건 · ' + d.billAmount);
            setText('detailDevices', d.sms + '대 / ' + d.tablet + '대');
            document.getElementById('detailTrendLink').href = d.detailUrl || '#';
            document.getElementById('detailAcademyLink').href = d.academyUrl || '<?php echo IEUM_URL; ?>/admin/academies.php';
            document.getElementById('detailPaymintLink').href = d.paymintUrl || '<?php echo IEUM_URL; ?>/admin/paymint_merchants.php';
            document.getElementById('detailBillingLink').href = d.billingUrl || '<?php echo IEUM_URL; ?>/admin/billing_wallet.php';
            var alertBox = document.getElementById('detailAlerts');
            alertBox.innerHTML = '';
            (d.alerts || '정상').split(',').forEach(function (item) {
                var text = item.trim();
                if (!text) return;
                var span = document.createElement('span');
                span.className = 'tag ' + (text === '정상' ? 'good' : (text.indexOf('실패') !== -1 ? 'bad' : 'warn'));
                span.textContent = text;
                alertBox.appendChild(span);
            });
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
(function () {
    var modal = document.getElementById('snapshotRefreshModal');
    var openButton = document.getElementById('snapshotRefreshOpen');
    var closeButton = document.getElementById('snapshotRefreshClose');
    var cancelButton = document.getElementById('snapshotRefreshCancel');
    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    if (openButton) openButton.addEventListener('click', openModal);
    if (closeButton) closeButton.addEventListener('click', closeModal);
    if (cancelButton) cancelButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
})();
</script>
</body>
</html>
