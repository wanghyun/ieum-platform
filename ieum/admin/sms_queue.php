<?php
$sub_menu = '950130';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/dashboard.php';

$g5['title'] = '아이이음 문자 발송현황';
$current_academy = ieum_require_academy_page();
$academy_id = (int) $current_academy['academy_id'];
$message = '';
$error = '';

ieum_dashboard_ensure_auto_check_table();
if (function_exists('ieum_sms_queue_ensure_schedule_columns')) {
    ieum_sms_queue_ensure_schedule_columns();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'resolve_failed_sms') {
            $sms_id = isset($_POST['sms_id']) ? (int) $_POST['sms_id'] : 0;
            $sms = sql_fetch("
                select sms_id, created_at
                  from " . IEUM_SMS_QUEUE_TABLE . "
                 where academy_id = '{$academy_id}'
                   and sms_id = '{$sms_id}'
                   and status = 'failed'
            ", false);
            if (!empty($sms['sms_id'])) {
                $target_date = substr($sms['created_at'], 0, 10);
                ieum_dashboard_resolve_auto_check($academy_id, 'sms_failed', (string) $sms_id, $target_date, isset($member['mb_id']) ? $member['mb_id'] : '');
                $message = '문자 실패 건을 확인완료 처리했습니다.';
            } else {
                $error = '확인완료 처리할 문자 실패 건을 찾을 수 없습니다.';
            }
        } else {
            $error = '잘못된 요청입니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$sms_shortcut_catalog = function_exists('ieum_dashboard_shortcut_catalog') ? ieum_dashboard_shortcut_catalog() : array();
$sms_shortcut_keys = function_exists('ieum_dashboard_get_shortcut_keys') ? ieum_dashboard_get_shortcut_keys($academy_id) : array();
$sms_default_shortcut_keys = function_exists('ieum_dashboard_default_shortcut_keys') ? ieum_dashboard_default_shortcut_keys() : array();

$status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', trim($_GET['status'])) : '';
$date = isset($_GET['date']) ? preg_replace('/[^0-9-]/', '', $_GET['date']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

function ieum_sms_status_label($status)
{
    $labels = array(
        'pending' => '발송 대기',
        'processing' => '발송 중',
        'sent' => '발송 완료',
        'failed' => '발송 실패',
        'canceled' => '발송 취소',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}

function ieum_sms_type_label($type)
{
    $labels = array(
        'checkin' => '등원 문자',
        'checkout' => '하원 문자',
        'absent_alert' => '미등원 알림',
        'vehicle_alert' => '차량 알림',
        'calendar_memo_alert' => '메모 알림',
        'tuition_due' => '수련비 안내',
        'tuition_overdue' => '미납 안내',
        'birthday_care' => '생일 안내',
        'absent_care' => '안부 확인',
        'student_care' => '원생 안내',
        'character_report' => '인성리포트',
    );

    return isset($labels[$type]) ? $labels[$type] : $type;
}

$where = " where q.academy_id = '{$academy_id}' ";
if ($status !== '') {
    $status_sql = sql_escape_string($status);
    $where .= " and q.status = '{$status_sql}' ";
}
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date_sql = sql_escape_string($date);
    $where .= " and left(q.created_at, 10) = '{$date_sql}' ";
}
if ($q !== '') {
    $q_sql = sql_escape_string($q);
    $where .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or q.recipient_phone like '%{$q_sql}%' or q.message like '%{$q_sql}%') ";
}

$summary = array();
$summary_result = sql_query("
    select status, count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
  group by status
", false);
while ($row = sql_fetch_array($summary_result)) {
    $summary[$row['status']] = (int) $row['cnt'];
}

$failed_open = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_AUTO_CHECK_RESOLVE_TABLE . " acr on acr.academy_id = q.academy_id
       and acr.check_type = 'sms_failed'
       and acr.target_key = cast(q.sms_id as char)
       and acr.target_date = left(q.created_at, 10)
     where q.academy_id = '{$academy_id}'
       and q.status = 'failed'
       and acr.resolved_at is null
", false);
$failed_total = isset($summary['failed']) ? (int) $summary['failed'] : 0;
$failed_open_count = (int) (isset($failed_open['cnt']) ? $failed_open['cnt'] : 0);

$total = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
      {$where}
", false);

$rows = sql_query("
    select q.*, s.student_code, s.student_name, s.parent_name,
           acr.resolved_at as auto_resolved_at
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
 left join " . IEUM_AUTO_CHECK_RESOLVE_TABLE . " acr on acr.academy_id = q.academy_id
       and acr.check_type = 'sms_failed'
       and acr.target_key = cast(q.sms_id as char)
       and acr.target_date = left(q.created_at, 10)
      {$where}
  order by q.sms_id desc
     limit 200
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:4px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:18px;padding:22px;box-shadow:0 12px 28px rgba(15,23,42,.06)}.chips{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:8px 0 16px}.chip{background:#f5f7fb;border:1px solid #e4e9f2;border-radius:16px;padding:14px 16px;color:#344054;font-weight:900}.chip.pending{background:#fff8eb;color:#915c00;border-color:#f3cf8c}.chip.processing{background:#eef5ff;color:#175cd3;border-color:#b8d4ff}.chip.sent{background:#ecfdf3;color:#176b2c;border-color:#a8e6bb}.chip.failed{background:#fff1f1;color:#a4262c;border-color:#f0b2b2}.chip.canceled{background:#f8fafc;color:#475467}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}input,select{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:800;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.resolve{min-height:32px;padding:5px 9px;background:#0f766e;border-color:#0f766e;color:#fff;font-size:12px}.notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-weight:800}.notice.ok{background:#eef9f1;color:#176b2c}.notice.err{background:#fdecec;color:#a4262c}.queue-table-wrap{overflow-x:auto;border:1px solid #d8dee9;border-radius:8px}table{width:100%;min-width:1280px;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:top;line-height:1.35}th{background:#72829d;color:#fff}td.left{text-align:left}.sms-table th:first-child,.sms-table td:first-child{width:52px}.sms-table th:nth-child(2),.sms-table td:nth-child(2){width:104px;white-space:nowrap}.sms-table th:nth-child(3),.sms-table td:nth-child(3){width:118px;white-space:nowrap}.sms-table th:nth-child(4),.sms-table td:nth-child(4){width:150px;white-space:normal;word-break:keep-all}.sms-table th:nth-child(5),.sms-table td:nth-child(5){width:124px;white-space:nowrap}.sms-table th:nth-child(6),.sms-table td:nth-child(6){min-width:420px;word-break:keep-all}.sms-table th:nth-child(7),.sms-table td:nth-child(7),.sms-table th:nth-child(8),.sms-table td:nth-child(8){width:132px}.sms-table th:nth-child(9),.sms-table td:nth-child(9){width:112px}.sms-table th:nth-child(10),.sms-table td:nth-child(10){width:180px}.sms-table th:nth-child(11),.sms-table td:nth-child(11){width:96px}.status-pending{color:#9a5b00;font-weight:900}.status-processing{color:#175cd3;font-weight:900}.status-sent{color:#176b2c;font-weight:900}.status-failed{color:#a4262c;font-weight:900}.status-canceled{color:#64748b;font-weight:900}.confirmed{color:#0f766e;font-weight:900}.token{margin-top:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#475467}code{background:#eef2f7;border-radius:4px;padding:2px 6px}
@media (max-width:900px){table{white-space:normal}.filters{align-items:stretch}.filters input{width:100%}}
body.sms-queue-page-tune .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
body.sms-queue-page-tune .bar{background:#fff;border:1px solid #d9dee7;border-radius:18px;padding:22px 24px;box-shadow:0 12px 28px rgba(15,23,42,.06)}
body.sms-queue-page-tune .bar h1{font-size:32px;letter-spacing:-.01em}
body.sms-queue-page-tune .filters{margin-top:8px}
body.sms-queue-page-tune .token{border-radius:14px}
body.sms-queue-page-tune .queue-table-wrap{border-radius:14px}
body.sms-queue-page-tune th,body.sms-queue-page-tune td{padding:11px 10px}
body.sms-queue-page-tune .chips{grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
body.sms-queue-page-tune .chip{display:flex;align-items:center;min-height:54px;border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.35}
body.sms-queue-page-tune .sms-message-preview{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.45;max-height:42px;word-break:keep-all}
body.sms-queue-page-tune .sms-table{table-layout:fixed;min-width:1460px}
body.sms-queue-page-tune .sms-table th:nth-child(2),body.sms-queue-page-tune .sms-table td:nth-child(2){width:92px}
body.sms-queue-page-tune .sms-table th:nth-child(3),body.sms-queue-page-tune .sms-table td:nth-child(3){width:104px}
body.sms-queue-page-tune .sms-table th:nth-child(4),body.sms-queue-page-tune .sms-table td:nth-child(4){width:120px}
body.sms-queue-page-tune .sms-table th:nth-child(5),body.sms-queue-page-tune .sms-table td:nth-child(5){width:106px}
body.sms-queue-page-tune .sms-table th:nth-child(6),body.sms-queue-page-tune .sms-table td:nth-child(6){width:360px;min-width:0}
body.sms-queue-page-tune .sms-table th:nth-child(7),body.sms-queue-page-tune .sms-table td:nth-child(7),body.sms-queue-page-tune .sms-table th:nth-child(8),body.sms-queue-page-tune .sms-table td:nth-child(8),body.sms-queue-page-tune .sms-table th:nth-child(9),body.sms-queue-page-tune .sms-table td:nth-child(9){width:96px}
body.sms-queue-page-tune .sms-table th:nth-child(10),body.sms-queue-page-tune .sms-table td:nth-child(10){width:92px}
body.sms-queue-page-tune .sms-table th:nth-child(11),body.sms-queue-page-tune .sms-table td:nth-child(11){width:140px}
body.sms-queue-page-tune .sms-table th:nth-child(12),body.sms-queue-page-tune .sms-table td:nth-child(12){width:74px}
body.sms-queue-page-tune .sms-message-preview{overflow-wrap:anywhere;word-break:break-word}
@media(max-width:1180px){body.sms-queue-page-tune .chips{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .bar{
    padding:0!important;
    border:0!important;
    background:transparent!important;
    border-radius:0!important;
    box-shadow:none!important;
    margin-bottom:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .bar h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .panel{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:none!important;
    padding:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
    padding:8px!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune td{
    border-color:#e5ebf3!important;
    padding:8px!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .token{
    margin-top:12px!important;
    padding:9px 11px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .token summary{
    cursor:pointer!important;
    font-weight:900!important;
    color:#334155!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-queue-page-tune .token code{
    display:inline-block!important;
    margin-top:8px!important;
    word-break:break-all!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark{
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .panel,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .bar,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .token,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .queue-table-wrap{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .meta{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark input,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark select,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip.pending{
    background:#2a2416!important;
    border-color:#6d5421!important;
    color:#ffd58a!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip.processing{
    background:#10283d!important;
    border-color:#1c5b88!important;
    color:#8fd0ff!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip.sent{
    background:#183425!important;
    border-color:#24593a!important;
    color:#8ee0a8!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip.failed{
    background:#3a1f27!important;
    border-color:#6f2d3b!important;
    color:#ffb4c0!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .chip.canceled{
    background:#172132!important;
    border-color:#334155!important;
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .token summary{
    color:#e5edf7!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .token code{
    background:#111827!important;
    border:1px solid #334155!important;
    color:#d9e2ef!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .status-pending{color:#ffd58a!important}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .status-processing{color:#8fd0ff!important}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .status-sent,
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .confirmed{color:#8ee0a8!important}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .status-failed{color:#ffb4c0!important}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .status-canceled{color:#9aa8bb!important}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark .btn.resolve{
    background:#0f766e!important;
    border-color:#0f766e!important;
    color:#fff!important;
}
body.ieum-dashboard-page.sms-queue-page-tune.ieum-dark input::placeholder{
    color:#9aa8bb!important;
    opacity:1!important;
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page sms-queue-page-tune">
<?php echo ieum_admin_header('sms_queue', 'side'); ?>
<main class="wrap">
    <?php if ($message) { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>
    <div class="bar">
        <div>
            <h1>문자 발송현황</h1>
            <div class="meta"><?php echo get_text($current_academy['academy_name']); ?> · 발송 대기/완료/실패를 최근 200건까지 확인합니다.</div>
        </div>
    </div>

    <section class="panel">
        <div class="chips">
            <span class="chip pending">발송 대기 <?php echo number_format(isset($summary['pending']) ? $summary['pending'] : 0); ?>건</span>
            <span class="chip processing">발송 중 <?php echo number_format(isset($summary['processing']) ? $summary['processing'] : 0); ?>건</span>
            <span class="chip sent">발송 완료 <?php echo number_format(isset($summary['sent']) ? $summary['sent'] : 0); ?>건</span>
            <span class="chip failed">실패 확인 <?php echo number_format($failed_open_count); ?><?php echo $failed_total !== $failed_open_count ? '/' . number_format($failed_total) : ''; ?>건</span>
            <span class="chip canceled">발송 취소 <?php echo number_format(isset($summary['canceled']) ? $summary['canceled'] : 0); ?>건</span>
        </div>

        <form method="get" class="filters">
            <select name="status">
                <option value="">전체 상태</option>
                <option value="pending" <?php echo get_selected($status, 'pending'); ?>>발송 대기</option>
                <option value="processing" <?php echo get_selected($status, 'processing'); ?>>발송 중</option>
                <option value="sent" <?php echo get_selected($status, 'sent'); ?>>발송 완료</option>
                <option value="failed" <?php echo get_selected($status, 'failed'); ?>>발송 실패</option>
                <option value="canceled" <?php echo get_selected($status, 'canceled'); ?>>발송 취소</option>
            </select>
            <input type="date" name="date" value="<?php echo get_text($date); ?>">
            <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="원생, 연락처, 메시지 검색">
            <button type="submit" class="btn primary">조회</button>
            <a href="<?php echo IEUM_URL; ?>/admin/sms_queue.php" class="btn">초기화</a>
        </form>

        <details class="token">
            <summary>문자앱 연결 코드</summary>
            <code><?php echo get_text(IEUM_SMS_GATEWAY_TOKEN); ?></code>
        </details>
    </section>

    <section class="panel" style="margin-top:18px">
        <div class="queue-table-wrap">
        <table class="sms-table">
            <thead>
            <tr>
                <th scope="col">번호</th>
                <th scope="col">상태</th>
                <th scope="col">종류</th>
                <th scope="col">원생</th>
                <th scope="col">수신번호</th>
                <th scope="col">메시지</th>
                <th scope="col">등록 시간</th>
                <th scope="col">예약 시간</th>
                <th scope="col">발송 시간</th>
                <th scope="col">발송폰</th>
                <th scope="col">확인 내용</th>
                <th scope="col">확인</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $i = 0;
            while ($row = sql_fetch_array($rows)) {
                $i++;
                $status_class = 'status-' . $row['status'];
                $type = isset($row['message_type']) ? $row['message_type'] : 'checkin';
            ?>
            <tr>
                <td><?php echo (int) $row['sms_id']; ?></td>
                <td class="<?php echo get_text($status_class); ?>"><?php echo get_text(ieum_sms_status_label($row['status'])); ?></td>
                <td><?php echo get_text(ieum_sms_type_label($type)); ?></td>
                <td><?php echo get_text(trim($row['student_code'] . ' ' . $row['student_name'])); ?></td>
                <td><?php echo get_text(ieum_mask_phone($row['recipient_phone'])); ?></td>
                <td class="left"><div class="sms-message-preview" title="<?php echo get_text($row['message']); ?>"><?php echo get_text($row['message']); ?></div></td>
                <td><?php echo get_text($row['created_at']); ?></td>
                <td><?php echo get_text(!empty($row['scheduled_at']) ? $row['scheduled_at'] : '-'); ?></td>
                <td><?php echo get_text($row['sent_at']); ?></td>
                <td><?php echo get_text($row['gateway_device']); ?></td>
                <td class="left"><div class="sms-message-preview" title="<?php echo get_text($row['error_message']); ?>"><?php echo get_text($row['error_message']); ?></div></td>
                <td>
                    <?php if ($row['status'] === 'failed' && empty($row['auto_resolved_at'])) { ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                        <input type="hidden" name="action" value="resolve_failed_sms">
                        <input type="hidden" name="sms_id" value="<?php echo (int) $row['sms_id']; ?>">
                        <button type="submit" class="btn resolve">확인완료</button>
                    </form>
                    <?php } elseif ($row['status'] === 'failed') { ?>
                    <span class="confirmed">확인됨</span>
                    <?php } else { ?>
                    -
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr><td colspan="12">표시할 문자 발송 내역이 없습니다.</td></tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </section>
</main>
<?php
$sms_shortcut_js_catalog = array();
foreach ($sms_shortcut_catalog as $shortcut_key => $shortcut_item) {
    $sms_shortcut_js_catalog[$shortcut_key] = array(
        'label' => isset($shortcut_item['label']) ? $shortcut_item['label'] : $shortcut_key,
        'desc' => isset($shortcut_item['desc']) ? $shortcut_item['desc'] : '',
        'url' => isset($shortcut_item['url']) ? $shortcut_item['url'] : '#',
    );
}
?>
<script type="text/plain" data-deprecated-shell-sync="common-ui-owned">
(function(){
    var rootSelector = '.sms-queue-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($current_academy['academy_name']); ?>;
    }

    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }

    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($current_academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
<script>
(function(){
    var rootSelector = '.sms-queue-page-tune.ieum-dashboard-page';
    var shortcutCatalog = <?php echo json_encode($sms_shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($sms_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(array_values($sms_default_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutSaveUrl = <?php echo json_encode(IEUM_URL . '/dashboard.php', JSON_UNESCAPED_UNICODE); ?>;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.querySelector(rootSelector + ' .dashboard-shell-meta-inner');
    /*
     * The shared top theme toggle is owned by lib/ui.php.
     * Keep this old page-local block inert so favorite-star logic below still runs cleanly.
     *
    var themeIconPaths = {
        dark: '<path d="M20 14.2A7.5 7.5 0 0 1 9.8 4a8 8 0 1 0 10.2 10.2Z"/>',
        light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.9 4.9l1.4 1.4"/><path d="M17.7 17.7l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.9 19.1l1.4-1.4"/><path d="M17.7 6.3l1.4-1.4"/>'
    };
    var setThemeButton = function(button, theme) {
        var isDark = theme === 'dark';
        document.body.classList.toggle('ieum-dark', isDark);
        try {
            localStorage.setItem('ieumDashboardTheme', isDark ? 'dark' : 'light');
        } catch (error) {}
        button.setAttribute('aria-label', isDark ? '라이트 모드로 변경' : '다크 모드로 변경');
        button.setAttribute('title', isDark ? '라이트 모드로 변경' : '다크 모드로 변경');
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (isDark ? themeIconPaths.light : themeIconPaths.dark) + '</svg>';
    };
    if (metaInner && !metaInner.querySelector('.dashboard-theme-toggle')) {
        var aiHelpLink = metaInner.querySelector('a[href*="#chatbot"], a[href*="topic=ai"]');
        if (aiHelpLink) {
            aiHelpLink.textContent = 'AI 챗봇';
        }
        var themeButton = document.createElement('button');
        themeButton.type = 'button';
        themeButton.className = 'dashboard-theme-toggle';
        metaInner.appendChild(themeButton);
        var savedTheme = '';
        try {
            savedTheme = localStorage.getItem('ieumDashboardTheme') || '';
        } catch (error) {}
        setThemeButton(themeButton, savedTheme === 'dark' || document.body.classList.contains('ieum-dark') ? 'dark' : 'light');
        themeButton.addEventListener('click', function() {
            setThemeButton(themeButton, document.body.classList.contains('ieum-dark') ? 'light' : 'dark');
        });
    }
    */
    var shortcutButtons = [];
    var shortcutToast = document.createElement('span');
    var shortcutToastTimer = null;
    var shortcutToastDefault = '<span>최대 6개까지 선택할 수 있습니다.</span><span>보조정보- 업무바로가기에서 확인하세요.</span>';
    shortcutToast.className = 'side-favorite-toast';
    shortcutToast.innerHTML = shortcutToastDefault;
    document.body.appendChild(shortcutToast);
    var normalizeShortcutKeys = function(keys) {
        var seen = {};
        var normalized = [];
        (keys || []).forEach(function(key) {
            key = String(key || '');
            if (!shortcutCatalog[key] || seen[key]) {
                return;
            }
            seen[key] = true;
            normalized.push(key);
        });
        return normalized.slice(0, shortcutMax);
    };
    currentShortcutKeys = normalizeShortcutKeys(currentShortcutKeys);
    if (!currentShortcutKeys.length) {
        currentShortcutKeys = normalizeShortcutKeys(defaultShortcutKeys);
    }
    var shortcutPath = function(url) {
        try {
            var parsed = new URL(url, window.location.href);
            return parsed.pathname.replace(/\/+$/, '');
        } catch (error) {
            return String(url || '').split('?')[0].split('#')[0].replace(/\/+$/, '');
        }
    };
    var shortcutByPath = {};
    Object.keys(shortcutCatalog || {}).forEach(function(key) {
        var path = shortcutPath(shortcutCatalog[key].url);
        if (path && !shortcutByPath[path]) {
            shortcutByPath[path] = key;
        }
    });
    var showShortcutToast = function(button, message) {
        shortcutToast.innerHTML = message || shortcutToastDefault;
        var left = 214;
        var top = 160;
        if (button && button.getBoundingClientRect) {
            var rect = button.getBoundingClientRect();
            left = Math.max(12, rect.left - 166);
            top = Math.max(72, rect.top + rect.height / 2 - 15);
        }
        shortcutToast.style.left = left + 'px';
        shortcutToast.style.top = top + 'px';
        shortcutToast.classList.add('is-show');
        window.clearTimeout(shortcutToastTimer);
        shortcutToastTimer = window.setTimeout(function() {
            shortcutToast.classList.remove('is-show');
        }, 1900);
    };
    var updateShortcutButtons = function() {
        shortcutButtons.forEach(function(button) {
            var key = button.getAttribute('data-shortcut-key') || '';
            var item = shortcutCatalog[key] || {};
            var active = currentShortcutKeys.indexOf(key) !== -1;
            button.classList.toggle('is-active', active);
            button.textContent = active ? '★' : '☆';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('title', active ? '업무 바로가기에서 제거' : '업무 바로가기에 추가');
            button.setAttribute('aria-label', (item.label || '메뉴') + (active ? ' 즐겨찾기 제거' : ' 즐겨찾기 추가'));
        });
    };
    var saveShortcutKeys = function(keys) {
        if (!window.fetch || !window.FormData || !shortcutSaveUrl) {
            return;
        }
        var formData = new FormData();
        formData.append('csrf_token', shortcutCsrfToken);
        formData.append('action', 'save_dashboard_shortcuts');
        keys.forEach(function(key) {
            formData.append('shortcut_keys[]', key);
        });
        fetch(shortcutSaveUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).catch(function() {});
    };
    var toggleShortcut = function(key, button) {
        if (!shortcutCatalog[key]) {
            return;
        }
        var nextKeys = currentShortcutKeys.slice();
        var index = nextKeys.indexOf(key);
        if (index === -1) {
            if (nextKeys.length >= shortcutMax) {
                showShortcutToast(button, '');
                return;
            }
            nextKeys.push(key);
        } else {
            nextKeys.splice(index, 1);
        }
        if (!nextKeys.length) {
            nextKeys = normalizeShortcutKeys(defaultShortcutKeys);
        }
        currentShortcutKeys = normalizeShortcutKeys(nextKeys);
        updateShortcutButtons();
        if (button) {
            button.classList.add('is-pulse');
            window.setTimeout(function() {
                button.classList.remove('is-pulse');
            }, 170);
        }
        saveShortcutKeys(currentShortcutKeys);
    };
    document.querySelectorAll(rootSelector + ' .side-sub a').forEach(function(link) {
        var key = shortcutByPath[shortcutPath(link.href)];
        if (!key || link.closest('.side-favorite-row')) {
            return;
        }
        var row = document.createElement('span');
        row.className = 'side-favorite-row';
        row.setAttribute('data-shortcut-key', key);
        link.parentNode.insertBefore(row, link);
        row.appendChild(link);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'side-favorite-toggle';
        button.setAttribute('data-shortcut-key', key);
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleShortcut(key, button);
        });
        row.appendChild(button);
        shortcutButtons.push(button);
    });
    updateShortcutButtons();
})();
</script>
</body>
</html>
