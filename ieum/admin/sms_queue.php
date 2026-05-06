<?php
$sub_menu = '950130';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

$g5['title'] = '아이이음 문자 큐';
$current_academy = ieum_require_academy_page();
$academy_id = (int) $current_academy['academy_id'];

$status = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', trim($_GET['status'])) : '';
$date = isset($_GET['date']) ? preg_replace('/[^0-9-]/', '', $_GET['date']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

function ieum_sms_status_label($status)
{
    $labels = array(
        'pending' => '전송 대기',
        'processing' => '전송 처리중',
        'sent' => '전송 완료',
        'failed' => '전송 실패',
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

$total = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
      {$where}
", false);

$rows = sql_query("
    select q.*, s.student_code, s.student_name, s.parent_name
      from " . IEUM_SMS_QUEUE_TABLE . " q
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = q.student_id
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
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a,.nav-group-title{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover,.nav-group:hover .nav-group-title{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.nav-group{position:relative}.nav-group-title{display:inline-flex;color:#d8e2ff;cursor:default}.nav-sub{display:none;position:absolute;left:0;top:100%;z-index:20;min-width:170px;background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:6px;box-shadow:0 12px 26px rgba(15,23,42,.18)}.nav-group:hover .nav-sub{display:grid;gap:4px}.nav-sub a{color:#111827;white-space:nowrap}.nav-sub a:hover,.nav-sub a.active{background:#eef2ff;color:#15204a}
.wrap{max-width:1280px;margin:28px auto;padding:0 20px}.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}h1{margin:0;font-size:26px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.chips{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px}.chip{background:#eef2f7;border-radius:999px;padding:6px 10px;color:#344054;font-weight:700}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}input,select{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:top}th{background:#72829d;color:#fff}td.left{text-align:left}.status-pending{color:#9a5b00;font-weight:700}.status-processing{color:#175cd3;font-weight:700}.status-sent{color:#176b2c;font-weight:700}.status-failed{color:#a4262c;font-weight:700}.token{margin-top:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#475467}code{background:#eef2f7;border-radius:4px;padding:2px 6px}@media (max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.filters{align-items:stretch}.filters input{width:100%}.nav-sub{position:static}.nav-group:hover .nav-sub{display:grid}}
</style>
</head>
<body>
<?php echo ieum_admin_header('sms'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>문자 큐</h1>
            <div><?php echo get_text($current_academy['academy_name']); ?></div>
            <div>조회 결과 <?php echo number_format((int) $total['cnt']); ?>건, 최근 200건 표시</div>
        </div>
    </div>

    <section class="panel">
        <div class="chips">
            <span class="chip">전송 대기 <?php echo number_format(isset($summary['pending']) ? $summary['pending'] : 0); ?>건</span>
            <span class="chip">전송 처리중 <?php echo number_format(isset($summary['processing']) ? $summary['processing'] : 0); ?>건</span>
            <span class="chip">전송 완료 <?php echo number_format(isset($summary['sent']) ? $summary['sent'] : 0); ?>건</span>
            <span class="chip">전송 실패 <?php echo number_format(isset($summary['failed']) ? $summary['failed'] : 0); ?>건</span>
        </div>

        <form method="get" class="filters">
            <select name="status">
                <option value="">전체 상태</option>
                <option value="pending" <?php echo get_selected($status, 'pending'); ?>>전송 대기</option>
                <option value="processing" <?php echo get_selected($status, 'processing'); ?>>전송 처리중</option>
                <option value="sent" <?php echo get_selected($status, 'sent'); ?>>전송 완료</option>
                <option value="failed" <?php echo get_selected($status, 'failed'); ?>>전송 실패</option>
            </select>
            <input type="date" name="date" value="<?php echo get_text($date); ?>">
            <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="학생, 연락처, 메시지 검색">
            <button type="submit" class="btn primary">조회</button>
            <a href="<?php echo IEUM_URL; ?>/admin/sms_queue.php" class="btn">초기화</a>
        </form>

        <div class="token">
            안드로이드 API 토큰: <code><?php echo get_text(IEUM_SMS_GATEWAY_TOKEN); ?></code>
        </div>
    </section>

    <section class="panel" style="margin-top:18px">
        <table>
            <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">상태</th>
                <th scope="col">종류</th>
                <th scope="col">학생</th>
                <th scope="col">수신번호</th>
                <th scope="col">메시지</th>
                <th scope="col">생성</th>
                <th scope="col">발송</th>
                <th scope="col">기기</th>
                <th scope="col">오류</th>
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
                <td class="left"><?php echo get_text($row['message']); ?></td>
                <td><?php echo get_text($row['created_at']); ?></td>
                <td><?php echo get_text($row['sent_at']); ?></td>
                <td><?php echo get_text($row['gateway_device']); ?></td>
                <td class="left"><?php echo get_text($row['error_message']); ?></td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr><td colspan="10">문자 큐가 없습니다.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
