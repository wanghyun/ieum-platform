<?php
$sub_menu = '950101';
require_once './_common.php';
require_once IEUM_PATH . '/lib/hq_billing.php';
require_once IEUM_PATH . '/lib/paymint.php';

ieum_require_head_admin_page();
ieum_paymint_ensure_tables();

$g5['title'] = '가맹점 월별 사용 추이';

$academy_id = isset($_GET['academy_id']) ? (int) $_GET['academy_id'] : 0;
$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$academy = $academy_id ? sql_fetch("
    select *
      from " . IEUM_ACADEMY_TABLE . "
     where academy_id = '{$academy_id}'
     limit 1
", false) : null;

if (!$academy || !isset($academy['academy_id'])) {
    alert('가맹점 정보를 찾을 수 없습니다.', IEUM_URL . '/admin/hq_overview.php');
}

function ieum_hq_detail_table_exists($table)
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

function ieum_hq_detail_money($value)
{
    return number_format((int) $value) . '원';
}

function ieum_hq_detail_rate($part, $total)
{
    $part = (int) $part;
    $total = (int) $total;
    return $total > 0 ? round(($part / $total) * 100) : 0;
}

$has_status_logs = ieum_hq_detail_table_exists(IEUM_STUDENT_STATUS_LOG_TABLE);
$has_boarding = ieum_hq_detail_table_exists(IEUM_VEHICLE_BOARDING_TABLE);
$has_sms_devices = ieum_hq_detail_table_exists(IEUM_SMS_GATEWAY_DEVICE_TABLE);
$has_tablet_devices = ieum_hq_detail_table_exists(IEUM_TABLET_DEVICE_TABLE);

$base_ts = strtotime($month . '-01');
$months = array();
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime('-' . $i . ' month', $base_ts));
}

$rows = array();
$totals = array(
    'new_count' => 0,
    'paused_count' => 0,
    'returned_count' => 0,
    'withdrawn_count' => 0,
    'character_entered' => 0,
    'fitness_entered' => 0,
    'boarding_count' => 0,
    'bill_count' => 0,
    'paid_count' => 0,
    'failed_count' => 0,
    'bill_amount' => 0,
);

foreach ($months as $target_month) {
    $start = $target_month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $start_sql = sql_escape_string($start);
    $end_sql = sql_escape_string($end);
    $month_sql = sql_escape_string($target_month);
    $end_datetime_sql = sql_escape_string($end . ' 23:59:59');

    $student = sql_fetch("
        select count(*) as active_count,
               sum(case when admission_date between '{$start_sql}' and '{$end_sql}' then 1 else 0 end) as new_count,
               sum(case when is_active = 1 and character_report_enabled = 1 then 1 else 0 end) as character_enabled,
               sum(case when is_active = 1 and fitness_report_enabled = 1 then 1 else 0 end) as fitness_enabled,
               sum(case when vehicle_pickup_enabled = 1 or vehicle_dropoff_enabled = 1 then 1 else 0 end) as vehicle_assigned
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and created_at <= '{$end_datetime_sql}'
           and is_active = 1
    ", false);

    $flow = array('paused_count' => 0, 'returned_count' => 0, 'withdrawn_count' => 0);
    if ($has_status_logs) {
        $paused = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'paused'
               and changed_date between '{$start_sql}' and '{$end_sql}'
        ", false);
        $returned = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and before_status = 'paused'
               and after_status in ('returned','enrolled')
               and changed_date between '{$start_sql}' and '{$end_sql}'
        ", false);
        $withdrawn = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'withdrawn'
               and changed_date between '{$start_sql}' and '{$end_sql}'
        ", false);
        $flow['paused_count'] = (int) $paused['cnt'];
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
    $bill = sql_fetch("
        select count(*) as bill_count,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when status in ('failed','destroyed') then 1 else 0 end) as failed_count,
               coalesce(sum(bill_amount), 0) as bill_amount
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$academy_id}'
           and billing_month = '{$month_sql}'
    ", false);

    $boarding_count = 0;
    if ($has_boarding) {
        $boarding = sql_fetch("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and journal_date between '{$start_sql}' and '{$end_sql}'
        ", false);
        $boarding_count = (int) $boarding['cnt'];
    }

    $row = array(
        'month' => $target_month,
        'active_count' => (int) $student['active_count'],
        'new_count' => (int) $student['new_count'],
        'paused_count' => (int) $flow['paused_count'],
        'returned_count' => (int) $flow['returned_count'],
        'withdrawn_count' => (int) $flow['withdrawn_count'],
        'character_enabled' => (int) $student['character_enabled'],
        'character_entered' => (int) $character['entered_count'],
        'fitness_enabled' => (int) $student['fitness_enabled'],
        'fitness_entered' => (int) $fitness['entered_count'],
        'vehicle_assigned' => (int) $student['vehicle_assigned'],
        'boarding_count' => $boarding_count,
        'bill_count' => (int) $bill['bill_count'],
        'paid_count' => (int) $bill['paid_count'],
        'failed_count' => (int) $bill['failed_count'],
        'bill_amount' => (int) $bill['bill_amount'],
    );
    $rows[] = $row;

    foreach ($totals as $key => $value) {
        if (isset($row[$key])) {
            $totals[$key] += (int) $row[$key];
        }
    }
}

$latest = $rows ? $rows[count($rows) - 1] : array();
$sms_device_count = 0;
if ($has_sms_devices) {
    $sms_device = sql_fetch("
        select count(*) as cnt
          from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
         where academy_id = '{$academy_id}'
           and device_status = 'active'
    ", false);
    $sms_device_count = (int) $sms_device['cnt'];
}
$tablet_device_count = 0;
if ($has_tablet_devices) {
    $tablet_device = sql_fetch("
        select count(*) as cnt
          from " . IEUM_TABLET_DEVICE_TABLE . "
         where academy_id = '{$academy_id}'
           and status = 'active'
    ", false);
    $tablet_device_count = (int) $tablet_device['cnt'];
}

$merchant = sql_fetch("
    select *
      from " . IEUM_PAYMINT_MERCHANT_TABLE . "
     where academy_id = '{$academy_id}'
     limit 1
", false);
$merchant_connected = !empty($merchant['merchant_map_id']) && in_array($merchant['mapping_status'], array('mapped','active','connected'), true);
$latest_character_rate = ieum_hq_detail_rate($latest['character_entered'], $latest['character_enabled']);
$latest_fitness_rate = ieum_hq_detail_rate($latest['fitness_entered'], $latest['fitness_enabled']);
$detail_signals = array();
if ((int) $academy['is_active'] !== 1 || $academy['service_status'] !== 'active') {
    $detail_signals[] = '서비스 상태 확인';
}
if ($sms_device_count === 0) {
    $detail_signals[] = '문자폰 미연결';
}
if ($tablet_device_count === 0) {
    $detail_signals[] = '출석기 미연결';
}
if (!$merchant_connected) {
    $detail_signals[] = '결제선생 연동 필요';
}
if (!empty($latest['character_enabled']) && $latest_character_rate < 80) {
    $detail_signals[] = '인성 입력률 낮음';
}
if (!empty($latest['fitness_enabled']) && $latest_fitness_rate < 80) {
    $detail_signals[] = '체력 입력률 낮음';
}
if (!empty($latest['vehicle_assigned']) && empty($latest['boarding_count'])) {
    $detail_signals[] = '이번 달 차량 기록 없음';
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#0f172a;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 22px}.head{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}h1{margin:0;font-size:30px}.muted{color:#64748b}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;text-decoration:none;padding:8px 13px;font-weight:900}.primary{background:#1769c2!important;border-color:#1769c2!important;color:#fff!important}.cards{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:16px}.card,.panel{background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 8px 22px rgba(15,23,42,.05)}.card{padding:16px}.card span{display:block;color:#64748b;font-size:13px;font-weight:900}.card strong{display:block;margin-top:7px;font-size:26px}.card em{display:block;margin-top:6px;color:#64748b;font-size:12px;font-style:normal}.panel{padding:18px;margin-bottom:16px}.panel h2{margin:0 0 14px;font-size:22px}.table-wrap{overflow-x:auto;border:1px solid #dbe3ef;border-radius:12px}table{width:100%;min-width:1200px;border-collapse:collapse}th,td{border-bottom:1px solid #e2e8f0;padding:12px 10px;text-align:center;font-size:13px}th{background:#74839c;color:#fff;white-space:nowrap}td.left{text-align:left}.good{color:#047857;font-weight:900}.warn{color:#b45309;font-weight:900}.bad{color:#b91c1c;font-weight:900}.bar{height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:6px}.bar i{display:block;height:100%;background:#1769c2}.bar.good i{background:#059669}.bar.warn i{background:#d97706}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tag{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef2ff;color:#1e40af}.tag.good{background:#ecfdf3;color:#047857}.tag.warn{background:#fff7ed;color:#b45309}.tag.bad{background:#fef2f2;color:#b91c1c}.signal-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.insight-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.insight{border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;padding:12px}.insight b{display:block;font-size:13px;color:#475569}.insight strong{display:block;font-size:22px;margin-top:6px}@media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)}.two,.insight-grid{grid-template-columns:1fr}}@media(max-width:640px){.cards{grid-template-columns:1fr}.wrap{padding:0 14px}.actions .btn{width:100%}}
</style>
</head>
<body>
<?php echo ieum_admin_header('hq_overview'); ?>
<?php echo ieum_admin_subnav('hq_overview'); ?>
<main class="wrap">
    <div class="head">
        <div>
            <h1><?php echo get_text($academy['academy_name']); ?> 월별 사용 추이</h1>
            <div class="muted"><?php echo get_text($academy['academy_code']); ?> · 최근 6개월 기준 · 본사 관제용</div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/hq_overview.php?month=<?php echo urlencode($month); ?>&q=<?php echo urlencode($academy['academy_code']); ?>">본사 현황</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/academies.php?q=<?php echo urlencode($academy['academy_code']); ?>">가맹점 관리</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php?q=<?php echo urlencode($academy['academy_code']); ?>">결제선생 연동</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?academy_id=<?php echo (int) $academy_id; ?>&month=<?php echo urlencode($month); ?>">청구 운영</a>
        </div>
    </div>

    <section class="cards">
        <article class="card"><span>현재 재원</span><strong><?php echo number_format((int) $latest['active_count']); ?>명</strong><em><?php echo get_text($month); ?> 기준</em></article>
        <article class="card"><span>인성 입력률</span><strong><?php echo ieum_hq_detail_rate($latest['character_entered'], $latest['character_enabled']); ?>%</strong><em><?php echo number_format((int) $latest['character_entered']); ?>/<?php echo number_format((int) $latest['character_enabled']); ?>명</em></article>
        <article class="card"><span>체력 입력률</span><strong><?php echo ieum_hq_detail_rate($latest['fitness_entered'], $latest['fitness_enabled']); ?>%</strong><em><?php echo number_format((int) $latest['fitness_entered']); ?>/<?php echo number_format((int) $latest['fitness_enabled']); ?>명</em></article>
        <article class="card"><span>6개월 청구</span><strong><?php echo number_format($totals['bill_count']); ?>건</strong><em><?php echo ieum_hq_detail_money($totals['bill_amount']); ?></em></article>
        <article class="card"><span>기기 연결</span><strong><?php echo number_format($sms_device_count); ?>/<?php echo number_format($tablet_device_count); ?>대</strong><em>문자폰 / 앱 출석기</em></article>
    </section>

    <section class="panel">
        <h2>본사 확인 포인트</h2>
        <div class="insight-grid">
            <article class="insight"><b>이번 달 인성 입력</b><strong><?php echo number_format($latest_character_rate); ?>%</strong><div class="bar <?php echo $latest_character_rate >= 80 ? 'good' : 'warn'; ?>"><i style="width:<?php echo min(100, $latest_character_rate); ?>%"></i></div></article>
            <article class="insight"><b>이번 달 체력 입력</b><strong><?php echo number_format($latest_fitness_rate); ?>%</strong><div class="bar <?php echo $latest_fitness_rate >= 80 ? 'good' : 'warn'; ?>"><i style="width:<?php echo min(100, $latest_fitness_rate); ?>%"></i></div></article>
            <article class="insight"><b>결제선생</b><strong><?php echo $merchant_connected ? '연동 완료' : '연동 필요'; ?></strong></article>
            <article class="insight"><b>기기 상태</b><strong><?php echo number_format($sms_device_count); ?> / <?php echo number_format($tablet_device_count); ?></strong></article>
        </div>
        <div class="signal-strip">
            <?php if (!$detail_signals) { ?>
                <span class="tag good">현재 조치 신호 없음</span>
            <?php } else { ?>
                <?php foreach ($detail_signals as $signal) { ?>
                    <span class="tag warn"><?php echo get_text($signal); ?></span>
                <?php } ?>
            <?php } ?>
        </div>
    </section>

    <section class="two">
        <article class="panel">
            <h2>운영 상태</h2>
            <p><span class="tag <?php echo (int) $academy['is_active'] === 1 ? 'good' : 'warn'; ?>"><?php echo (int) $academy['is_active'] === 1 ? '사용중' : '비활성'; ?></span>
            <span class="tag"><?php echo get_text($academy['service_status']); ?></span>
            <span class="tag <?php echo $merchant_connected ? 'good' : 'warn'; ?>"><?php echo $merchant_connected ? '결제선생 연동 완료' : '결제선생 연동 필요'; ?></span></p>
            <p class="muted">본사에서는 이 화면으로 도장별 사용 흐름을 보고, 입력률이 떨어지는 도장이나 기기 연결이 끊긴 도장을 빠르게 찾습니다.</p>
        </article>
        <article class="panel">
            <h2>최근 6개월 핵심 합계</h2>
            <p>신규 <?php echo number_format($totals['new_count']); ?>명 · 복귀 <?php echo number_format($totals['returned_count']); ?>명 · 휴관 <?php echo number_format($totals['paused_count']); ?>명 · 퇴관 <?php echo number_format($totals['withdrawn_count']); ?>명</p>
            <p>차량 탑승기록 <?php echo number_format($totals['boarding_count']); ?>건 · 결제완료 <?php echo number_format($totals['paid_count']); ?>건 · 청구실패 <?php echo number_format($totals['failed_count']); ?>건</p>
        </article>
    </section>

    <section class="panel">
        <h2>월별 흐름</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>월</th>
                    <th>재원</th>
                    <th>신규</th>
                    <th>복귀</th>
                    <th>휴관</th>
                    <th>퇴관</th>
                    <th>인성 입력</th>
                    <th>체력 입력</th>
                    <th>차량 기록</th>
                    <th>청구</th>
                    <th>결제 완료</th>
                    <th>실패</th>
                    <th>청구금액</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $character_rate = ieum_hq_detail_rate($row['character_entered'], $row['character_enabled']);
                    $fitness_rate = ieum_hq_detail_rate($row['fitness_entered'], $row['fitness_enabled']);
                ?>
                <tr>
                    <td><b><?php echo get_text($row['month']); ?></b></td>
                    <td><?php echo number_format($row['active_count']); ?>명</td>
                    <td class="good"><?php echo number_format($row['new_count']); ?></td>
                    <td class="good"><?php echo number_format($row['returned_count']); ?></td>
                    <td class="warn"><?php echo number_format($row['paused_count']); ?></td>
                    <td class="bad"><?php echo number_format($row['withdrawn_count']); ?></td>
                    <td>
                        <?php echo number_format($row['character_entered']); ?>/<?php echo number_format($row['character_enabled']); ?>명
                        <div class="bar"><i style="width:<?php echo $character_rate; ?>%"></i></div>
                    </td>
                    <td>
                        <?php echo number_format($row['fitness_entered']); ?>/<?php echo number_format($row['fitness_enabled']); ?>명
                        <div class="bar"><i style="width:<?php echo $fitness_rate; ?>%"></i></div>
                    </td>
                    <td><?php echo number_format($row['boarding_count']); ?>건</td>
                    <td><?php echo number_format($row['bill_count']); ?>건</td>
                    <td class="good"><?php echo number_format($row['paid_count']); ?>건</td>
                    <td class="bad"><?php echo number_format($row['failed_count']); ?>건</td>
                    <td><?php echo ieum_hq_detail_money($row['bill_amount']); ?></td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
