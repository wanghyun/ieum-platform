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
        $payment_id = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;
        $status = isset($_POST['status']) ? preg_replace('/[^a-z_]/', '', trim($_POST['status'])) : 'unpaid';
        $amount_paid = isset($_POST['amount_paid']) ? max(0, (int) $_POST['amount_paid']) : 0;
        $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
        if (!in_array($status, array('unpaid', 'partial', 'paid'), true)) {
            $status = 'unpaid';
        }

        $paid_at_sql = $status === 'paid' ? "'" . G5_TIME_YMDHIS . "'" : 'null';
        sql_query("
            update " . IEUM_TUITION_PAYMENT_TABLE . "
               set status = '" . sql_escape_string($status) . "',
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:13px;color:#667085}.num{font-size:28px;font-weight:900;margin-top:4px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.status-paid{color:#176b2c;font-weight:900}.status-partial{color:#9a5b00;font-weight:900}.status-unpaid{color:#a4262c;font-weight:900}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}@media(max-width:900px){.cards{grid-template-columns:repeat(2,1fr)}table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
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
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition.php">수련비 정책</a>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <form method="get" class="filters">
        <input type="month" name="billing_month" value="<?php echo get_text($billing_month); ?>">
        <button type="submit" class="btn primary">조회</button>
    </form>

    <section class="cards">
        <article class="card"><div class="label">대상</div><div class="num"><?php echo number_format((int) $summary['total_count']); ?>명</div></article>
        <article class="card"><div class="label">결제완료</div><div class="num"><?php echo number_format((int) $summary['paid_count']); ?>명</div></article>
        <article class="card"><div class="label">미결제/부분</div><div class="num"><?php echo number_format((int) $summary['unpaid_count']); ?>명</div></article>
        <article class="card"><div class="label">입금/청구</div><div class="num"><?php echo number_format((int) $summary['paid_amount']); ?> / <?php echo number_format((int) $summary['due_amount']); ?></div></article>
    </section>

    <section class="panel">
        <table>
            <thead>
                <tr><th>납부일</th><th>학생</th><th>수업부</th><th>청구액</th><th>입금액</th><th>상태</th><th>메모</th><th>저장</th></tr>
            </thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($payments)) { $i++; ?>
                <tr>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                        <td><?php echo get_text($row['due_date']); ?></td>
                        <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                        <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                        <td><?php echo number_format((int) $row['amount_due']); ?></td>
                        <td><input type="number" name="amount_paid" value="<?php echo (int) $row['amount_paid']; ?>" min="0" style="width:110px"></td>
                        <td>
                            <select name="status" class="status-<?php echo get_text($row['status']); ?>">
                                <option value="unpaid" <?php echo get_selected($row['status'], 'unpaid'); ?>>미결제</option>
                                <option value="partial" <?php echo get_selected($row['status'], 'partial'); ?>>부분결제</option>
                                <option value="paid" <?php echo get_selected($row['status'], 'paid'); ?>>결제완료</option>
                            </select>
                        </td>
                        <td><input type="text" name="memo" value="<?php echo get_text($row['memo']); ?>" placeholder="예: 형제할인, 현금, 카드"></td>
                        <td><button type="submit" class="btn">저장</button></td>
                    </form>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="8">이번 달 수련비 대상이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
