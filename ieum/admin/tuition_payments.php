<?php
$sub_menu = '950175';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';

$g5['title'] = '아이이음 수련비 납부';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$billing_month = isset($_GET['billing_month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['billing_month'])) : ieum_tuition_billing_month();
if (!preg_match('/^\d{4}\-\d{2}$/', $billing_month)) {
    $billing_month = ieum_tuition_billing_month();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'update';
        $payment_id = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;
        if ($action === 'bulk_mark_paid') {
            $payment_ids = isset($_POST['payment_ids']) && is_array($_POST['payment_ids']) ? $_POST['payment_ids'] : array();
            $ids = array();
            foreach ($payment_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                $error = '일괄 완납 처리할 학생을 선택하세요.';
            } else {
                $id_sql = implode(',', $ids);
                sql_query("
                    update " . IEUM_TUITION_PAYMENT_TABLE . "
                       set status = 'paid',
                           amount_paid = amount_due,
                           paid_at = '" . G5_TIME_YMDHIS . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and payment_id in ({$id_sql})
                ");
                $message = '선택한 수련비 ' . number_format(count($ids)) . '건을 결제완료 처리했습니다.';
            }
        } elseif ($action === 'mark_paid') {
            $payment = sql_fetch("
                select amount_due
                  from " . IEUM_TUITION_PAYMENT_TABLE . "
                 where academy_id = '{$academy_id}'
                   and payment_id = '{$payment_id}'
                 limit 1
            ", false);
            if (isset($payment['amount_due'])) {
                sql_query("
                    update " . IEUM_TUITION_PAYMENT_TABLE . "
                       set status = 'paid',
                           amount_paid = '" . (int) $payment['amount_due'] . "',
                           paid_at = '" . G5_TIME_YMDHIS . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and payment_id = '{$payment_id}'
                ");
                $message = '결제완료 처리했습니다.';
            }
        } elseif ($action === 'send_notice') {
            $notice_type = isset($_POST['notice_type']) && $_POST['notice_type'] === 'overdue' ? 'overdue' : 'due';
            $result = ieum_tuition_send_payment_notice($payment_id, $notice_type, true);
            if ($result['created'] > 0) {
                $message = '수련비 안내 문자 ' . number_format((int) $result['created']) . '건을 문자 큐에 등록했습니다.';
            } elseif ($result['message'] === 'no_recipient') {
                $error = '문자를 받을 보호자 연락처가 없습니다.';
            } else {
                $error = '문자 큐를 생성하지 못했습니다.';
            }
        } else {
            $amount_due = isset($_POST['amount_due']) ? max(0, (int) $_POST['amount_due']) : 0;
            $amount_paid = isset($_POST['amount_paid']) ? max(0, (int) $_POST['amount_paid']) : 0;
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
            $status = ieum_tuition_payment_auto_status($amount_due, $amount_paid);

            $paid_at_sql = $status === 'paid' ? "'" . G5_TIME_YMDHIS . "'" : 'null';
            sql_query("
                update " . IEUM_TUITION_PAYMENT_TABLE . "
                   set amount_due = '{$amount_due}',
                       status = '" . sql_escape_string($status) . "',
                       amount_paid = '{$amount_paid}',
                       memo = '" . sql_escape_string($memo) . "',
                       paid_at = {$paid_at_sql},
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and payment_id = '{$payment_id}'
            ");
            $message = '수련비 납부 상태를 저장했습니다.';
        }
    }
}

$created = ieum_tuition_ensure_month($academy_id, $billing_month);
if ($created > 0 && $message === '') {
    $message = $billing_month . ' 수련비 대상 ' . number_format($created) . '명을 생성했습니다.';
}

$csrf_token = ieum_new_csrf_token();
$month_sql = sql_escape_string($billing_month);
$summary = sql_fetch("
    select
        count(*) as total_count,
        sum(case when p.status = 'paid' then 1 else 0 end) as paid_count,
        sum(case when p.status in ('unpaid', 'partial') then 1 else 0 end) as unpaid_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date < '" . G5_TIME_YMD . "' and datediff('" . G5_TIME_YMD . "', p.due_date) <= 5 then 1 else 0 end) as overdue_5_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date < '" . G5_TIME_YMD . "' and datediff('" . G5_TIME_YMD . "', p.due_date) > 5 then 1 else 0 end) as overdue_long_count,
        coalesce(sum(p.amount_due), 0) as due_amount,
        coalesce(sum(p.amount_paid), 0) as paid_amount
      from " . IEUM_TUITION_PAYMENT_TABLE . " p
     where p.academy_id = '{$academy_id}'
       and p.billing_month = '{$month_sql}'
", false);

$payments = sql_query("
    select p.*, s.student_name, s.student_code, s.tuition_note, c.class_name, c.start_time
      from " . IEUM_TUITION_PAYMENT_TABLE . " p
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id and s.academy_id = p.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where p.academy_id = '{$academy_id}'
       and p.billing_month = '{$month_sql}'
  order by p.due_date asc, c.sort_order asc, c.start_time asc, s.student_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1320px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:13px;color:#667085}.num{font-size:26px;font-weight:900;margin-top:4px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.soft{background:#eef2f7}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.right{text-align:right}.status-paid{color:#176b2c;font-weight:900}.status-partial{color:#9a5b00;font-weight:900}.status-unpaid{color:#a4262c;font-weight:900}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.help{color:#667085;font-size:13px;margin:8px 0 0}.balance{font-weight:900;color:#a4262c}.sent{color:#176b2c;font-size:12px;font-weight:800}@media(max-width:1100px){.cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
</style>
<style>
.status-partial{color:#a4262c}.bulk-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}.bulk-bar label{font-weight:800;color:#344054}
</style>
</head>
<body>
<?php echo ieum_admin_header('tuition_payments'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>수련비 납부</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($billing_month); ?></div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition.php">수련비 정책</a>
            <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php">문자 큐 확인</a>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <form method="get" class="filters">
        <input type="month" name="billing_month" value="<?php echo get_text($billing_month); ?>">
        <button type="submit" class="btn primary">조회</button>
    </form>

    <section class="cards">
        <article class="card"><div class="label">청구 대상</div><div class="num"><?php echo number_format((int) $summary['total_count']); ?>명</div></article>
        <article class="card"><div class="label">결제완료</div><div class="num"><?php echo number_format((int) $summary['paid_count']); ?>명</div></article>
        <article class="card"><div class="label">미결제</div><div class="num"><?php echo number_format((int) $summary['unpaid_count']); ?>명</div></article>
        <article class="card"><div class="label">미납 5일 이하</div><div class="num"><?php echo number_format((int) $summary['overdue_5_count']); ?>명</div></article>
        <article class="card"><div class="label">미납 5일 초과</div><div class="num"><?php echo number_format((int) $summary['overdue_long_count']); ?>명</div></article>
        <article class="card"><div class="label">입금 / 청구</div><div class="num"><?php echo number_format((int) $summary['paid_amount']); ?> / <?php echo number_format((int) $summary['due_amount']); ?></div></article>
    </section>

    <section class="panel">
        <form method="post" id="bulkPaidForm" class="bulk-bar" onsubmit="return confirm('선택한 학생을 결제완료 처리할까요?');">
            <div>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="bulk_mark_paid">
                <label><input type="checkbox" id="checkAllPayments"> 전체 선택</label>
            </div>
            <button type="submit" class="btn primary">선택 완납 처리</button>
        </form>
        <table>
            <thead>
                <tr><th>선택</th><th>납부일</th><th>학생</th><th>수업부</th><th>청구액</th><th>입금액</th><th>잔액</th><th>상태</th><th>문자</th><th>메모</th><th>관리</th></tr>
            </thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($payments)) { $i++; ?>
                <tr>
                    <?php
                    $balance = max(0, (int) $row['amount_due'] - (int) $row['amount_paid']);
                    $notice_type = $row['due_date'] < G5_TIME_YMD ? 'overdue' : 'due';
                    ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                        <td><input type="checkbox" form="bulkPaidForm" name="payment_ids[]" value="<?php echo (int) $row['payment_id']; ?>" <?php echo $row['status'] === 'paid' ? 'disabled' : ''; ?>></td>
                        <td><?php echo get_text($row['due_date']); ?></td>
                        <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                        <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                        <td><input type="number" name="amount_due" value="<?php echo (int) $row['amount_due']; ?>" min="0" style="width:110px;text-align:right"></td>
                        <td><input type="number" name="amount_paid" value="<?php echo (int) $row['amount_paid']; ?>" min="0" style="width:110px"></td>
                        <td class="right balance"><?php echo number_format($balance); ?></td>
                        <td>
                            <span class="status-<?php echo get_text($row['status']); ?>"><?php echo get_text(ieum_tuition_status_label($row['status'])); ?></span>
                        </td>
                        <td>
                            <?php if (!empty($row['notice_sent_at'])) { ?><div class="sent"><?php echo get_text(substr($row['notice_sent_at'], 5, 11)); ?> 발송</div><?php } ?>
                            <div><?php echo number_format((int) (isset($row['notice_count']) ? $row['notice_count'] : 0)); ?>회</div>
                        </td>
                        <td><input type="text" name="memo" value="<?php echo get_text($row['memo']); ?>" placeholder="예: 형제할인, 현금, 카드"></td>
                        <td>
                            <div class="actions">
                                <button type="submit" class="btn">저장</button>
                    </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn primary">완납</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="send_notice">
                                    <input type="hidden" name="notice_type" value="<?php echo get_text($notice_type); ?>">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn <?php echo $notice_type === 'overdue' ? 'danger' : 'soft'; ?>">문자</button>
                                </form>
                            </div>
                        </td>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="11">이번 달 수련비 대상이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
        <p class="help">입금액이 청구액 이상이면 결제완료, 부족하면 미결제로 자동 정리됩니다. 문자 버튼은 실제 발송이 아니라 안드로이드 게이트웨이가 읽을 문자 큐를 생성합니다.</p>
    </section>
</main>
<script>
const checkAllPayments = document.getElementById('checkAllPayments');
if (checkAllPayments) {
    checkAllPayments.addEventListener('change', () => {
        document.querySelectorAll('input[name="payment_ids[]"]:not(:disabled)').forEach((item) => {
            item.checked = checkAllPayments.checked;
        });
    });
}
</script>
</body>
</html>
