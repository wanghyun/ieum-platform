<?php
$sub_menu = '950185';
require_once './_common.php';
require_once IEUM_PATH . '/lib/hq_billing.php';
require_once IEUM_PATH . '/lib/paymint.php';

if ($is_admin !== 'super') {
    alert('본사 관리자만 접근할 수 있습니다.');
}

$g5['title'] = '아이이음 본사 결제 운영';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'refresh_paymint_balance') {
            $result = ieum_paymint_read_partner_balance();
            if (!empty($result['ok'])) {
                $message = '결제선생 쌤포인트 잔액을 확인했습니다. 현재 잔액 ' . number_format((int) $result['balance']) . 'P';
            } else {
                $error = '결제선생 잔액 확인에 실패했습니다. ' . (isset($result['message']) ? $result['message'] : '');
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$paymint_settings = ieum_paymint_get_settings();
$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month_sql = sql_escape_string($month);
$selected_academy_id = isset($_GET['academy_id']) ? (int) $_GET['academy_id'] : 0;
$detail_status = isset($_GET['detail_status']) ? preg_replace('/[^a-z_]/', '', $_GET['detail_status']) : 'all';
$detail_status_options = array(
    'all' => '전체',
    'waiting' => '결제대기',
    'paid' => '납부 완료',
    'failed' => '실패/폐기',
);
if (!isset($detail_status_options[$detail_status])) {
    $detail_status = 'all';
}

$academy_detail_status_where = '';
if ($detail_status === 'paid') {
    $academy_detail_status_where = " and (b.appr_state = 'F' or b.status = 'paid') ";
} elseif ($detail_status === 'waiting') {
    $academy_detail_status_where = " and b.appr_state = 'W' and b.status not in ('failed','destroyed','paid') ";
} elseif ($detail_status === 'failed') {
    $academy_detail_status_where = " and b.status in ('failed','destroyed') ";
}

$selected_academy = null;
if ($selected_academy_id > 0) {
    $selected_academy = sql_fetch("
        select academy_id, academy_name, academy_code
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '{$selected_academy_id}'
         limit 1
    ", false);
    if (!isset($selected_academy['academy_id'])) {
        $selected_academy_id = 0;
        $selected_academy = null;
    }
}

$monthly_fee_summary = sql_fetch("
    select count(*) as log_count,
           coalesce(sum(abs(amount)), 0) as send_fee
      from " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . "
     where log_type = 'bill_send'
       and left(created_at, 7) = '{$month_sql}'
", false);

$monthly_bill_summary = sql_fetch("
    select count(*) as bill_count,
           coalesce(sum(bill_amount), 0) as bill_amount,
           sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
           sum(case when status = 'failed' then 1 else 0 end) as failed_count,
           sum(case when status = 'destroyed' then 1 else 0 end) as destroyed_count,
           sum(case when appr_state = 'W' and status not in ('failed','destroyed','paid') then 1 else 0 end) as waiting_count
      from " . IEUM_PAYMINT_BILL_TABLE . "
     where left(created_at, 7) = '{$month_sql}'
", false);

$send_count = (int) $monthly_bill_summary['bill_count'];
$bill_amount = (int) $monthly_bill_summary['bill_amount'];
$send_fee = (int) $monthly_fee_summary['send_fee'];
$paid_count = (int) $monthly_bill_summary['paid_count'];
$waiting_count = (int) $monthly_bill_summary['waiting_count'];
$failed_count = (int) $monthly_bill_summary['failed_count'];
$destroyed_count = (int) $monthly_bill_summary['destroyed_count'];
$paid_rate = $send_count > 0 ? round(($paid_count / $send_count) * 100) : 0;

$academy_usage = sql_query("
    select a.academy_id,
           a.academy_name,
           coalesce(logs.log_count, 0) as point_log_count,
           coalesce(logs.send_fee, 0) as send_fee,
           coalesce(bills.bill_count, 0) as bill_count,
           coalesce(bills.bill_amount, 0) as bill_amount,
           coalesce(bills.paid_count, 0) as paid_count,
           coalesce(bills.waiting_count, 0) as waiting_count,
           coalesce(bills.failed_count, 0) as failed_count,
           coalesce(bills.destroyed_count, 0) as destroyed_count
      from " . IEUM_ACADEMY_TABLE . " a
 left join (
        select academy_id,
               count(*) as log_count,
               coalesce(sum(abs(amount)), 0) as send_fee
          from " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . "
         where log_type = 'bill_send'
           and left(created_at, 7) = '{$month_sql}'
      group by academy_id
    ) logs on logs.academy_id = a.academy_id
 left join (
        select academy_id,
               count(*) as bill_count,
               coalesce(sum(bill_amount), 0) as bill_amount,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when appr_state = 'W' and status not in ('failed','destroyed','paid') then 1 else 0 end) as waiting_count,
               sum(case when status = 'failed' then 1 else 0 end) as failed_count,
               sum(case when status = 'destroyed' then 1 else 0 end) as destroyed_count
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where left(created_at, 7) = '{$month_sql}'
      group by academy_id
    ) bills on bills.academy_id = a.academy_id
     where coalesce(bills.bill_count, 0) > 0
        or coalesce(logs.log_count, 0) > 0
        or coalesce(bills.paid_count, 0) > 0
        or coalesce(bills.waiting_count, 0) > 0
        or coalesce(bills.failed_count, 0) > 0
  order by bill_count desc, bill_amount desc, a.academy_name asc
", false);

$recent_bills = sql_query("
    select b.*, a.academy_name, s.student_name, s.student_code
      from " . IEUM_PAYMINT_BILL_TABLE . " b
 left join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = b.academy_id
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = b.student_id and s.academy_id = b.academy_id
  order by b.paymint_bill_id desc
     limit 80
", false);

$academy_detail_summary = null;
$academy_detail_bills = null;
if ($selected_academy_id > 0) {
    $academy_detail_summary = sql_fetch("
        select count(*) as bill_count,
               coalesce(sum(bill_amount), 0) as bill_amount,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when appr_state = 'W' and status not in ('failed','destroyed','paid') then 1 else 0 end) as waiting_count,
               sum(case when status = 'failed' then 1 else 0 end) as failed_count,
               sum(case when status = 'destroyed' then 1 else 0 end) as destroyed_count
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$selected_academy_id}'
           and left(created_at, 7) = '{$month_sql}'
    ", false);

    $academy_detail_bills = sql_query("
        select b.*, s.student_name, s.student_code, s.grade_group, c.class_name, c.start_time
          from " . IEUM_PAYMINT_BILL_TABLE . " b
     left join " . IEUM_STUDENT_TABLE . " s on s.student_id = b.student_id and s.academy_id = b.academy_id
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where b.academy_id = '{$selected_academy_id}'
           and left(b.created_at, 7) = '{$month_sql}'
           {$academy_detail_status_where}
      order by b.paymint_bill_id desc
    ", false);
}

if ($selected_academy_id > 0 && $selected_academy && isset($_GET['export']) && $_GET['export'] === 'academy_bills') {
    $filename = 'ieum_bills_' . $selected_academy['academy_code'] . '_' . $month . '_' . $detail_status . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('일시', '상태', '도장', '학생', '학생번호', '부', '수신번호', '청구월', '이번달', '이전 미납', '총 청구', '청구서 ID'));

    $export_rows = sql_query("
        select b.*, s.student_name, s.student_code, c.class_name, c.start_time
          from " . IEUM_PAYMINT_BILL_TABLE . " b
     left join " . IEUM_STUDENT_TABLE . " s on s.student_id = b.student_id and s.academy_id = b.academy_id
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where b.academy_id = '{$selected_academy_id}'
           and left(b.created_at, 7) = '{$month_sql}'
           {$academy_detail_status_where}
      order by b.paymint_bill_id desc
    ", false);

    while ($row = sql_fetch_array($export_rows)) {
        list($status_label) = ieum_billing_bill_status_label($row);
        fputcsv($out, array(
            $row['created_at'],
            $status_label,
            $selected_academy['academy_name'],
            $row['student_name'] ?: $row['recipient_name'],
            $row['student_code'],
            trim((string) $row['class_name'] . ' ' . (string) $row['start_time']),
            $row['recipient_phone'],
            $row['billing_month'],
            (int) $row['current_amount'],
            (int) $row['arrears_amount'],
            (int) $row['bill_amount'],
            $row['bill_id'],
        ));
    }
    fclose($out);
    exit;
}

$logs = sql_query("
    select w.*, a.academy_name
      from " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . " w
 left join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = w.academy_id
     where w.log_type = 'bill_send'
  order by w.log_id desc
     limit 80
", false);

function ieum_billing_bill_status_label($row)
{
    if (isset($row['appr_state']) && $row['appr_state'] === 'F') {
        return array('납부 완료', 'paid');
    }
    if (isset($row['status']) && $row['status'] === 'failed') {
        return array('발송실패', 'failed');
    }
    if (isset($row['status']) && $row['status'] === 'destroyed') {
        return array('폐기', 'muted');
    }
    if (isset($row['status']) && $row['status'] === 'mock_sent') {
        return array('발송준비', 'waiting');
    }
    return array('결제대기', 'waiting');
}

function ieum_billing_mask_phone($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) < 8) {
        return $phone;
    }

    return substr($digits, 0, 3) . '****' . substr($digits, -4);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}h1{margin:0;font-size:30px}h2{margin:0 0 14px;font-size:20px}.meta,.label{color:#667085;font-size:13px;line-height:1.55}.actions,.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0}.detail-cards{grid-template-columns:repeat(4,minmax(0,1fr));margin-top:12px}.card,.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.panel{margin-top:18px}.num{font-size:30px;font-weight:1000;margin-top:6px}.sub{font-size:12px;color:#667085;margin-top:4px}.notice{padding:12px 14px;border-radius:8px;margin:12px 0}.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}input{border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.dark{background:#111827;border-color:#111827;color:#fff}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;background:#fff;min-width:980px}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:middle}th{background:#71829f;color:#fff}.left{text-align:left}.right{text-align:right}.plus{color:#176b2c;font-weight:900}.minus{color:#a4262c;font-weight:900}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:900;background:#eef2f7;color:#344054}.badge.paid{background:#e8f7ee;color:#087f5b}.badge.waiting{background:#fff4e6;color:#9a5b00}.badge.failed{background:#fdecec;color:#a4262c}.badge.muted{background:#eef2f7;color:#667085}.ops-check{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.ops-check article{border:1px solid #d9e5f8;border-radius:10px;background:#fbfdff;padding:14px}.ops-check strong{display:block;font-size:15px}.ops-check span{display:block;margin-top:6px;color:#667085;font-size:12px;line-height:1.45}.stat-line{display:grid;grid-template-columns:120px 1fr auto;gap:10px;align-items:center;margin-top:8px}.bar{height:10px;border-radius:999px;background:#eef2f7;overflow:hidden}.fill{height:100%;border-radius:999px;background:#1769c2}.academy-link{font-weight:1000;color:#1769c2;text-decoration:none}.academy-link:hover{text-decoration:underline}.selected-panel{border-color:#9bb7df;background:#f8fbff}@media(max-width:1100px){.cards,.detail-cards,.ops-check{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.cards,.detail-cards,.ops-check{grid-template-columns:1fr}.filters input,.filters .btn{width:100%}.hero{align-items:flex-start}}
</style>
</head>
<body>
<?php echo ieum_admin_header('billing_wallet'); ?>
<?php echo ieum_admin_subnav('billing_wallet'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>본사 결제 운영</h1>
            <div class="meta">결제선생 청구서 발송, 쌤포인트 잔액, 도장별 사용량을 본사에서 확인합니다. 도장 관리자에게는 발송 단가와 본사 잔액이 노출되지 않습니다.</div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_settings.php">결제선생 설정</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">도장 결제연동</a>
        </div>
    </section>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="cards billing-summary-cards">
        <article class="card"><div class="label">결제선생 쌤포인트</div><div class="num"><?php echo (int) $paymint_settings['remote_balance'] >= 0 ? number_format((int) $paymint_settings['remote_balance']) . 'P' : '확인 필요'; ?></div><div class="sub">충전은 결제선생 매니저사이트에서 진행</div></article>
        <article class="card"><div class="label">청구서 발송 단가</div><div class="num"><?php echo number_format(IEUM_BILLING_SEND_FEE); ?>P</div><div class="sub">가맹점에는 노출하지 않는 본사 부담 비용</div></article>
        <article class="card"><div class="label"><?php echo get_text($month); ?> 발송</div><div class="num"><?php echo number_format($send_count); ?>건</div><div class="sub">청구서 생성/발송 기준</div></article>
        <article class="card"><div class="label"><?php echo get_text($month); ?> 청구금액</div><div class="num"><?php echo number_format($bill_amount); ?>원</div><div class="sub">도장별 수련비 청구 합계</div></article>
        <article class="card"><div class="label">납부 완료율</div><div class="num"><?php echo number_format($paid_rate); ?>%</div><div class="sub"><?php echo number_format($paid_count); ?>건 완료 · <?php echo number_format($waiting_count); ?>건 대기</div></article>
        <article class="card"><div class="label">쌤포인트 사용</div><div class="num"><?php echo number_format($send_fee); ?>P</div><div class="sub">도장 화면에는 노출하지 않음</div></article>
    </section>

    <section class="panel">
        <div class="hero">
            <div>
                <h2>결제선생 연결 상태</h2>
                <div class="meta">환경: <?php echo get_text($paymint_settings['environment'] === 'production' ? '운영' : '샌드박스'); ?> · 기본 발송: <?php echo get_text($paymint_settings['default_send_type'] === 'URL' ? 'URL 생성' : '카카오 청구서'); ?> · 재발송 제한: <?php echo (int) $paymint_settings['resend_cooldown_hours']; ?>시간</div>
            </div>
            <form method="get" class="filters">
                <input type="month" name="month" value="<?php echo get_text($month); ?>">
                <button type="submit" class="btn primary">조회</button>
            </form>
        </div>
        <div class="cards detail-cards">
            <article class="card">
                <div class="label">결제선생 쌤포인트</div>
                <div class="num"><?php echo (int) $paymint_settings['remote_balance'] >= 0 ? number_format((int) $paymint_settings['remote_balance']) . 'P' : '미확인'; ?></div>
                <div class="sub"><?php echo !empty($paymint_settings['balance_checked_at']) ? '마지막 확인 ' . get_text(substr($paymint_settings['balance_checked_at'], 0, 16)) : '아직 API로 확인하지 않았습니다.'; ?></div>
            </article>
            <article class="card">
                <div class="label">결제선생 매니저사이트</div>
                <div class="sub" style="margin-top:8px">
                    <?php if (!empty($paymint_settings['charge_url'])) { ?>
                    <a class="btn" href="<?php echo get_text($paymint_settings['charge_url']); ?>" target="_blank" rel="noopener">매니저사이트 열기</a>
                    <?php } else { ?>
                    쌤포인트 충전과 실제 입금 확인은 결제선생 매니저사이트에서 진행합니다.
                    <?php } ?>
                </div>
            </article>
            <article class="card">
                <div class="label">API 확인</div>
                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="refresh_paymint_balance">
                    <button type="submit" class="btn primary">쌤포인트 잔액 확인</button>
                </form>
                <?php if (!empty($paymint_settings['last_api_error'])) { ?><div class="sub">최근 오류: <?php echo get_text($paymint_settings['last_api_error']); ?></div><?php } ?>
            </article>
        </div>
        <div class="stat-line">
            <strong>납부 완료</strong>
            <div class="bar"><div class="fill" style="width:<?php echo min(100, max(0, $paid_rate)); ?>%"></div></div>
            <span><?php echo number_format($paid_count); ?>/<?php echo number_format($send_count); ?>건</span>
        </div>
        <div class="meta" style="margin-top:10px">발송실패 <?php echo number_format($failed_count); ?>건 · 폐기 <?php echo number_format($destroyed_count); ?>건</div>
    </section>

    <section class="panel">
        <h2>본사 운영 체크 5단계</h2>
        <div class="ops-check">
            <article>
                <strong>1. 발송 전 최종 확인</strong>
                <span>도장 관리자는 미리보기에서 대상, 미납 포함, 완료자 제외를 확인하고 마지막 발송 버튼을 누릅니다.</span>
            </article>
            <article>
                <strong>2. 발송 후 상태 반영</strong>
                <span>청구서 생성/발송 이력은 이 화면에서 도장별로 추적하고, 도장 화면에는 필요한 상태만 보여줍니다.</span>
            </article>
            <article>
                <strong>3. 결제 완료 콜백</strong>
                <span>결제선생 콜백 URL로 납부 완료가 들어오면 수련비 납부 상태를 자동 갱신하는 구조입니다.</span>
            </article>
            <article>
                <strong>4. 본사 관리자 관리</strong>
                <span>본사는 쌤포인트 잔액, 도장별 청구 사용량, 실패/폐기 건, 결제연동 상태를 관리합니다.</span>
            </article>
            <article>
                <strong>5. 도장 관리자 관리</strong>
                <span>도장은 청구서 발송과 수납 확인만 사용합니다. 본사 부담 쌤포인트 사용량은 도장 화면에 노출하지 않습니다.</span>
            </article>
        </div>
    </section>

    <section class="panel">
        <h2>도장별 월 사용량</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>도장</th>
                    <th>발송</th>
                    <th>납부 완료</th>
                    <th>결제대기</th>
                    <th>실패/폐기</th>
                    <th>청구금액</th>
                    <th>쌤포인트 사용</th>
                    <th>완료율</th>
                </tr>
                </thead>
                <tbody>
                <?php $has = false; while ($row = sql_fetch_array($academy_usage)) { $has = true; $row_send = (int) $row['bill_count']; $row_paid = (int) $row['paid_count']; $row_rate = $row_send > 0 ? round(($row_paid / $row_send) * 100) : 0; ?>
                    <tr>
                        <td class="left"><a class="academy-link" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?month=<?php echo urlencode($month); ?>&academy_id=<?php echo (int) $row['academy_id']; ?>#academyDetail"><?php echo get_text($row['academy_name'] ?: '미지정'); ?></a></td>
                        <td><?php echo number_format($row_send); ?>건</td>
                        <td><span class="badge paid"><?php echo number_format($row_paid); ?>건</span></td>
                        <td><span class="badge waiting"><?php echo number_format((int) $row['waiting_count']); ?>건</span></td>
                        <td><?php echo number_format((int) $row['failed_count'] + (int) $row['destroyed_count']); ?>건</td>
                        <td class="right"><?php echo number_format((int) $row['bill_amount']); ?>원</td>
                        <td class="right"><?php echo number_format((int) $row['send_fee']); ?>P</td>
                        <td><?php echo number_format($row_rate); ?>%</td>
                    </tr>
                <?php } ?>
                <?php if (!$has) { ?><tr><td colspan="8">선택한 월의 사용 이력이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($selected_academy_id > 0 && $selected_academy) {
        $detail_bill_count = (int) $academy_detail_summary['bill_count'];
        $detail_paid_count = (int) $academy_detail_summary['paid_count'];
        $detail_waiting_count = (int) $academy_detail_summary['waiting_count'];
        $detail_failed_total = (int) $academy_detail_summary['failed_count'] + (int) $academy_detail_summary['destroyed_count'];
        $detail_paid_rate = $detail_bill_count > 0 ? round(($detail_paid_count / $detail_bill_count) * 100) : 0;
    ?>
    <section class="panel selected-panel" id="academyDetail">
        <div class="hero">
            <div>
                <h2><?php echo get_text($selected_academy['academy_name']); ?> 상세</h2>
                <div class="meta"><?php echo get_text($month); ?> 청구서 상세 · 도장코드 <?php echo get_text($selected_academy['academy_code']); ?></div>
            </div>
            <div class="actions">
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?month=<?php echo urlencode($month); ?>">전체 사용량</a>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php?q=<?php echo urlencode($selected_academy['academy_name']); ?>">결제연동 확인</a>
                <a class="btn dark" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?month=<?php echo urlencode($month); ?>&academy_id=<?php echo (int) $selected_academy_id; ?>&detail_status=<?php echo urlencode($detail_status); ?>&export=academy_bills">엑셀 다운로드</a>
            </div>
        </div>
        <div class="actions" style="margin:6px 0 14px">
            <?php foreach ($detail_status_options as $status_key => $status_label_text) { ?>
                <a class="btn <?php echo $detail_status === $status_key ? 'primary' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php?month=<?php echo urlencode($month); ?>&academy_id=<?php echo (int) $selected_academy_id; ?>&detail_status=<?php echo urlencode($status_key); ?>#academyDetail"><?php echo get_text($status_label_text); ?></a>
            <?php } ?>
        </div>
        <section class="cards detail-cards">
            <article class="card"><div class="label">청구서</div><div class="num"><?php echo number_format($detail_bill_count); ?>건</div><div class="sub">선택 월 기준</div></article>
            <article class="card"><div class="label">청구금액</div><div class="num"><?php echo number_format((int) $academy_detail_summary['bill_amount']); ?>원</div><div class="sub">수련비 청구 합계</div></article>
            <article class="card"><div class="label">납부 완료</div><div class="num"><?php echo number_format($detail_paid_count); ?>건</div><div class="sub">완료율 <?php echo number_format($detail_paid_rate); ?>%</div></article>
            <article class="card"><div class="label">확인 필요</div><div class="num"><?php echo number_format($detail_waiting_count + $detail_failed_total); ?>건</div><div class="sub">대기 <?php echo number_format($detail_waiting_count); ?> · 실패/폐기 <?php echo number_format($detail_failed_total); ?></div></article>
        </section>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>일시</th><th>상태</th><th>학생</th><th>부</th><th>수신번호</th><th>청구월</th><th>이번달</th><th>이전 미납</th><th>총 청구</th><th>청구서 ID</th></tr>
                </thead>
                <tbody>
                <?php $has = false; while ($row = sql_fetch_array($academy_detail_bills)) { $has = true; list($status_label, $status_class) = ieum_billing_bill_status_label($row); ?>
                    <tr>
                        <td><?php echo get_text(substr($row['created_at'], 0, 16)); ?></td>
                        <td><span class="badge <?php echo get_text($status_class); ?>"><?php echo get_text($status_label); ?></span></td>
                        <td><?php echo get_text(($row['student_name'] ?: $row['recipient_name']) . ($row['student_code'] ? ' (' . $row['student_code'] . ')' : '')); ?></td>
                        <td><?php echo get_text(trim((string) $row['class_name'] . ' ' . (string) $row['start_time']) ?: '-'); ?></td>
                        <td><?php echo get_text(ieum_billing_mask_phone($row['recipient_phone'])); ?></td>
                        <td><?php echo get_text($row['billing_month']); ?></td>
                        <td class="right"><?php echo number_format((int) $row['current_amount']); ?>원</td>
                        <td class="right"><?php echo number_format((int) $row['arrears_amount']); ?>원</td>
                        <td class="right"><?php echo number_format((int) $row['bill_amount']); ?>원</td>
                        <td><?php echo get_text($row['bill_id']); ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$has) { ?><tr><td colspan="10">선택한 월의 청구서 이력이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php } ?>

    <section class="panel">
        <h2>최근 청구서</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>일시</th><th>상태</th><th>도장</th><th>학생</th><th>수신번호</th><th>청구월</th><th>청구금액</th><th>청구서 ID</th></tr>
                </thead>
                <tbody>
                <?php $has = false; while ($row = sql_fetch_array($recent_bills)) { $has = true; list($status_label, $status_class) = ieum_billing_bill_status_label($row); ?>
                    <tr>
                        <td><?php echo get_text(substr($row['created_at'], 0, 16)); ?></td>
                        <td><span class="badge <?php echo get_text($status_class); ?>"><?php echo get_text($status_label); ?></span></td>
                        <td class="left"><?php echo get_text($row['academy_name'] ?: '-'); ?></td>
                        <td><?php echo get_text(($row['student_name'] ?: $row['recipient_name']) . ($row['student_code'] ? ' (' . $row['student_code'] . ')' : '')); ?></td>
                        <td><?php echo get_text(ieum_billing_mask_phone($row['recipient_phone'])); ?></td>
                        <td><?php echo get_text($row['billing_month']); ?></td>
                        <td class="right"><?php echo number_format((int) $row['bill_amount']); ?>원</td>
                        <td><?php echo get_text($row['bill_id']); ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$has) { ?><tr><td colspan="8">청구서 이력이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>최근 쌤포인트 사용 이력</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>일시</th><th>구분</th><th>도장</th><th>사용 포인트</th><th>기록 잔액</th><th>메모</th></tr></thead>
                <tbody>
                <?php $has = false; while ($row = sql_fetch_array($logs)) { $has = true; $amount = (int) $row['amount']; ?>
                    <tr>
                        <td><?php echo get_text($row['created_at']); ?></td>
                        <td>청구서 발송</td>
                        <td class="left"><?php echo get_text($row['academy_name'] ?: '-'); ?></td>
                        <td class="right minus"><?php echo number_format(abs($amount)); ?>P</td>
                        <td class="right"><?php echo number_format((int) $row['balance_after']); ?>P</td>
                        <td class="left"><?php echo get_text($row['description']); ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$has) { ?><tr><td colspan="6">아직 쌤포인트 사용 이력이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
