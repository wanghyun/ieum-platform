<?php
$sub_menu = '950175';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/hq_billing.php';

$g5['title'] = '아이이음 수련비 납부';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$billing_month = isset($_GET['billing_month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['billing_month'])) : ieum_tuition_billing_month();
if (!preg_match('/^\d{4}\-\d{2}$/', $billing_month)) {
    $billing_month = ieum_tuition_billing_month();
}
$preview_filter = isset($_GET['preview_filter']) ? preg_replace('/[^0-9a-z_]/', '', trim($_GET['preview_filter'])) : 'all';
if (!in_array($preview_filter, array('all', 'arrears', 'no_recipient'), true)) {
    $preview_filter = 'all';
}
$preview_per_page = isset($_GET['preview_per_page']) ? (int) $_GET['preview_per_page'] : 25;
if (!in_array($preview_per_page, array(10, 25, 50), true)) {
    $preview_per_page = 25;
}
$preview_page = isset($_GET['preview_page']) ? max(1, (int) $_GET['preview_page']) : 1;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'update';
        $payment_id = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;
        if ($action === 'billing_settings') {
            $bill_auto_send_enabled = isset($_POST['bill_auto_send_enabled']) ? 1 : 0;
            $bill_auto_send_day = isset($_POST['bill_auto_send_day']) ? (int) $_POST['bill_auto_send_day'] : 5;
            $bill_auto_send_day = max(1, min(31, $bill_auto_send_day));
            $bill_auto_send_scope = isset($_POST['bill_auto_send_scope']) && $_POST['bill_auto_send_scope'] === 'selected' ? 'selected' : 'all';
            $bill_auto_include_arrears = isset($_POST['bill_auto_include_arrears']) ? 1 : 0;
            sql_query("
                insert into " . IEUM_TUITION_SETTING_TABLE . "
                    set academy_id = '{$academy_id}',
                        bill_auto_send_enabled = '{$bill_auto_send_enabled}',
                        bill_auto_send_day = '{$bill_auto_send_day}',
                        bill_auto_send_scope = '" . sql_escape_string($bill_auto_send_scope) . "',
                        bill_auto_include_arrears = '{$bill_auto_include_arrears}',
                        updated_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        bill_auto_send_enabled = values(bill_auto_send_enabled),
                        bill_auto_send_day = values(bill_auto_send_day),
                        bill_auto_send_scope = values(bill_auto_send_scope),
                        bill_auto_include_arrears = values(bill_auto_include_arrears),
                        updated_at = values(updated_at)
            ");
            $message = '청구서 자동 발송 설정을 저장했습니다.';
        } elseif ($action === 'send_month_bills') {
            $preview = ieum_hq_tuition_bill_preview($academy_id, $billing_month);
            if (empty($preview['details'])) {
                $error = '이번 달 청구서 발송 대상이 없습니다.';
            } else {
                $created = 0;
                $fee = 0;
                $failed = 0;
                foreach ($preview['details'] as $detail) {
                    $result = ieum_hq_send_tuition_bill((int) $detail['payment_id'], isset($member['mb_id']) ? $member['mb_id'] : '');
                    if ((int) $result['created'] > 0) {
                        $created += (int) $result['created'];
                        $fee += (int) $result['fee'];
                    } else {
                        $failed++;
                    }
                }
                if ($created > 0) {
                    $message = '이번 달 청구서 ' . number_format($created) . '건을 발송 처리했습니다.';
                    if ($failed > 0) {
                        $message .= ' 확인 필요 ' . number_format($failed) . '건이 있습니다.';
                    }
                    if ($is_admin === 'super') {
                        $message .= ' 본사 충전금에서 ' . number_format($fee) . '원이 차감되었습니다.';
                    }
                } else {
                    $error = '청구서 발송 처리된 건이 없습니다. 보호자 연락처, 결제 상태, 본사 충전금을 확인하세요.';
                }
            }
        } elseif ($action === 'bulk_mark_paid') {
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
        } elseif ($action === 'bulk_send_bill') {
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
                $error = '청구서를 발송할 학생을 선택하세요.';
            } else {
                $created = 0;
                $fee = 0;
                $failed = 0;
                foreach ($ids as $id) {
                    $row = sql_fetch("
                        select payment_id
                          from " . IEUM_TUITION_PAYMENT_TABLE . "
                         where academy_id = '{$academy_id}'
                           and payment_id = '{$id}'
                         limit 1
                    ", false);
                    if (!isset($row['payment_id'])) {
                        $failed++;
                        continue;
                    }
                    $result = ieum_hq_send_tuition_bill($id, isset($member['mb_id']) ? $member['mb_id'] : '');
                    if ((int) $result['created'] > 0) {
                        $created += (int) $result['created'];
                        $fee += (int) $result['fee'];
                    } else {
                        $failed++;
                    }
                }
                if ($created > 0) {
                    $message = '선택한 청구서 ' . number_format($created) . '건을 발송 처리했습니다.';
                    if ($failed > 0) {
                        $message .= ' 확인 필요 ' . number_format($failed) . '건이 있습니다.';
                    }
                    if ($is_admin === 'super') {
                        $message .= ' 본사 충전금에서 ' . number_format($fee) . '원이 차감되었습니다.';
                    }
                } else {
                    $error = '청구서 발송 처리된 건이 없습니다. 보호자 연락처, 결제 상태, 본사 충전금을 확인하세요.';
                }
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
        } elseif ($action === 'send_bill') {
            $result = ieum_hq_send_tuition_bill($payment_id, isset($member['mb_id']) ? $member['mb_id'] : '');
            if ($result['created'] > 0) {
                $message = '청구서 ' . number_format((int) $result['created']) . '건을 발송 처리했습니다.';
                if ($is_admin === 'super') {
                    $message .= ' 본사 충전금에서 ' . number_format((int) $result['fee']) . '원이 차감되었습니다.';
                }
            } elseif ($result['message'] === 'insufficient_balance') {
                $error = $is_admin === 'super' ? '본사 청구서 발송 충전금이 부족합니다.' : '청구서 발송 준비금 확인이 필요합니다. 본사에 문의해 주세요.';
            } elseif ($result['message'] === 'no_recipient') {
                $error = '청구서를 받을 보호자 연락처가 없습니다.';
            } elseif ($result['message'] === 'already_paid') {
                $error = '이미 결제완료된 수련비입니다.';
            } else {
                $error = '청구서 발송 처리를 완료하지 못했습니다.';
            }
        } else {
            $amount_due = isset($_POST['amount_due']) ? max(0, (int) $_POST['amount_due']) : 0;
            $amount_paid = isset($_POST['amount_paid']) ? max(0, (int) $_POST['amount_paid']) : 0;
            $bill_auto_send_enabled = isset($_POST['bill_auto_send_enabled']) ? 1 : 0;
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
            $status = ieum_tuition_payment_auto_status($amount_due, $amount_paid);

            $paid_at_sql = $status === 'paid' ? "'" . G5_TIME_YMDHIS . "'" : 'null';
            sql_query("
                update " . IEUM_TUITION_PAYMENT_TABLE . "
                   set amount_due = '{$amount_due}',
                       status = '" . sql_escape_string($status) . "',
                       amount_paid = '{$amount_paid}',
                       bill_auto_send_enabled = '{$bill_auto_send_enabled}',
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
$hq_wallet_balance = ieum_hq_wallet_balance();
$settings = ieum_tuition_get_settings($academy_id);
$bill_preview = ieum_hq_tuition_bill_preview($academy_id, $billing_month);
$preview_rows = $bill_preview['details'];
if ($preview_filter === 'arrears') {
    $preview_rows = array_values(array_filter($bill_preview['details'], function ($row) {
        return (int) $row['arrears_amount'] > 0;
    }));
} elseif ($preview_filter === 'no_recipient') {
    $preview_rows = isset($bill_preview['excluded_details']) ? $bill_preview['excluded_details'] : array();
}
$preview_total = count($preview_rows);
$preview_total_pages = max(1, (int) ceil($preview_total / $preview_per_page));
if ($preview_page > $preview_total_pages) {
    $preview_page = $preview_total_pages;
}
$preview_offset = ($preview_page - 1) * $preview_per_page;
$preview_page_rows = array_slice($preview_rows, $preview_offset, $preview_per_page);
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1320px;margin:28px auto;padding:0 20px}.wrap.is-loading{opacity:.55;pointer-events:none}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:13px;color:#667085}.num{font-size:26px;font-weight:900;margin-top:4px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.soft{background:#eef2f7}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.right{text-align:right}.status-paid{color:#176b2c;font-weight:900}.status-partial{color:#9a5b00;font-weight:900}.status-unpaid{color:#a4262c;font-weight:900}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.help{color:#667085;font-size:13px;margin:8px 0 0}.balance{font-weight:900;color:#a4262c}.sent{color:#176b2c;font-size:12px;font-weight:800}@media(max-width:1100px){.cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
</style>
<style>
.status-partial{color:#a4262c}.billing-settings{display:grid;grid-template-columns:1.4fr .8fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:18px;padding:14px;border:1px solid #d9dee7;border-radius:8px;background:#fbfcff}.billing-settings label{font-weight:800;color:#344054}.billing-settings .field{display:grid;gap:6px}.billing-settings .check{display:flex;align-items:center;gap:7px;min-height:38px}.billing-settings input[type=checkbox]{width:auto}.preview-box{border:1px solid #c7d8f2;border-radius:8px;background:#f7fbff;margin-bottom:18px;padding:16px}.preview-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px}.preview-title{font-size:18px;font-weight:900}.preview-meta{color:#667085;font-size:13px;margin-top:4px}.preview-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.preview-item{background:#fff;border:1px solid #d9e5f8;border-radius:8px;padding:12px}.preview-item strong{display:block;font-size:22px;margin-top:4px}.preview-item .label{font-size:12px;color:#667085}.preview-warn{margin-top:12px;color:#8a5200;background:#fff8e6;border:1px solid #f5d48a;border-radius:8px;padding:10px;font-size:13px}.preview-actions{display:flex;gap:8px;flex-wrap:wrap}.preview-detail{margin-top:14px;border:1px solid #d9e5f8;border-radius:8px;overflow:hidden;background:#fff}.preview-detail summary{cursor:pointer;font-weight:900;padding:12px 14px;background:#eef5ff}.preview-tools{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #d9e5f8}.preview-tools form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.preview-pages{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.preview-page{padding:6px 9px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;font-weight:800}.preview-page.active{background:#1769c2;color:#fff;border-color:#1769c2}.preview-detail table{margin:0}.preview-detail th{background:#5f7393}.preview-detail td,.preview-detail th{font-size:13px;padding:8px}.bulk-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}.bulk-bar label{font-weight:800;color:#344054}.table-scroll{overflow-x:auto;border:1px solid #d8dee9;border-radius:8px}.payment-table{min-width:1260px;border:0}.payment-table th,.payment-table td{line-height:1.35;padding:8px 7px}.payment-table th:first-child,.payment-table td:first-child{width:48px}.payment-table th:nth-child(2),.payment-table td:nth-child(2){width:94px}.payment-table th:nth-child(3),.payment-table td:nth-child(3){width:140px;white-space:nowrap}.payment-table th:nth-child(4),.payment-table td:nth-child(4){width:92px}.payment-table th:nth-child(5),.payment-table td:nth-child(5),.payment-table th:nth-child(6),.payment-table td:nth-child(6){width:128px}.payment-table th:nth-child(7),.payment-table td:nth-child(7){width:92px;white-space:nowrap}.payment-table th:nth-child(8),.payment-table td:nth-child(8){width:82px;white-space:nowrap}.payment-table th:nth-child(9),.payment-table td:nth-child(9){width:86px;white-space:nowrap}.payment-table th:nth-child(10),.payment-table td:nth-child(10){width:84px}.payment-table th:nth-child(11),.payment-table td:nth-child(11){width:220px}.payment-table th:nth-child(12),.payment-table td:nth-child(12){width:132px}.payment-table input[type=number]{width:100%;text-align:right}.payment-table input[name=memo]{width:100%}.payment-table .actions{min-width:112px}.payment-table .btn{min-height:34px;padding:6px 10px}.payment-table .sent{white-space:normal;line-height:1.25}.auto-bill{display:inline-flex;align-items:center;gap:4px;font-weight:800;color:#344054}@media(max-width:1100px){.preview-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:980px){.billing-settings{grid-template-columns:1fr 1fr}.billing-settings button{grid-column:1/-1}}@media(max-width:620px){.billing-settings,.preview-grid{grid-template-columns:1fr}}
</style>
<style>
.preview-groups{margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
.preview-group{background:#fff;border:1px solid #d9e5f8;border-radius:8px;padding:12px}
.preview-group strong{display:block;font-size:15px}
.preview-group .group-line{margin-top:5px;color:#667085;font-size:12px}
.preview-group .group-amount{margin-top:8px;font-size:18px;font-weight:900;color:#174a8b}
.detail-sub{display:block;color:#667085;font-size:12px;margin-top:3px}
</style>
</head>
<body>
<?php echo ieum_admin_header('tuition_payments'); ?>
<?php echo ieum_admin_subnav('tuition_payments'); ?>
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
        <?php if ($is_admin === 'super') { ?><article class="card"><div class="label">본사 발송 충전금</div><div class="num"><?php echo number_format($hq_wallet_balance); ?>원</div></article><?php } ?>
    </section>

    <section class="panel">
        <form method="post" class="billing-settings">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="billing_settings">
            <label class="check"><input type="checkbox" name="bill_auto_send_enabled" value="1" <?php echo !empty($settings['bill_auto_send_enabled']) ? 'checked' : ''; ?>> 청구서 자동발송</label>
            <div class="field">
                <label>자동 발송일</label>
                <select name="bill_auto_send_day">
                    <?php for ($day = 1; $day <= 31; $day++) { ?>
                    <option value="<?php echo $day; ?>" <?php echo (int) (isset($settings['bill_auto_send_day']) ? $settings['bill_auto_send_day'] : 5) === $day ? 'selected' : ''; ?>>매월 <?php echo $day; ?>일</option>
                    <?php } ?>
                </select>
            </div>
            <div class="field">
                <label>발송 대상</label>
                <select name="bill_auto_send_scope">
                    <option value="all" <?php echo isset($settings['bill_auto_send_scope']) && $settings['bill_auto_send_scope'] === 'all' ? 'selected' : ''; ?>>전체 미결제 학생</option>
                    <option value="selected" <?php echo isset($settings['bill_auto_send_scope']) && $settings['bill_auto_send_scope'] === 'selected' ? 'selected' : ''; ?>>자동청구 체크 학생만</option>
                </select>
            </div>
            <label class="check"><input type="checkbox" name="bill_auto_include_arrears" value="1" <?php echo !isset($settings['bill_auto_include_arrears']) || !empty($settings['bill_auto_include_arrears']) ? 'checked' : ''; ?>> 미납 포함</label>
            <button type="submit" class="btn primary">자동발송 설정 저장</button>
        </form>
        <section class="preview-box">
            <div class="preview-head">
                <div>
                    <div class="preview-title">자동발송 미리보기</div>
                    <div class="preview-meta">
                        <?php echo get_text($billing_month); ?> ·
                        <?php echo !empty($bill_preview['auto_enabled']) ? '자동발송 사용' : '자동발송 꺼짐'; ?> ·
                        매월 <?php echo number_format((int) $bill_preview['auto_send_day']); ?>일 ·
                        <?php echo $bill_preview['scope'] === 'selected' ? '자동청구 체크 학생만' : '전체 미결제 학생'; ?> ·
                        <?php echo !empty($bill_preview['include_arrears']) ? '미납 포함' : '이번 달만'; ?>
                    </div>
                </div>
                <div class="preview-actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="send_month_bills">
                        <button type="submit" class="btn primary" <?php echo empty($bill_preview['details']) ? 'disabled' : ''; ?> onclick="return confirm('미리보기의 발송 예정 학생에게 이번 달 청구서를 발송 처리할까요?');">이번 달 청구서 발송</button>
                    </form>
                    <?php if ($is_admin === 'super') { ?><a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">본사 발송비 확인</a><?php } ?>
                </div>
            </div>
            <div class="preview-grid">
                <article class="preview-item"><span class="label">발송 예정</span><strong><?php echo number_format((int) $bill_preview['target_count']); ?>명</strong></article>
                <article class="preview-item"><span class="label">결제완료 제외</span><strong><?php echo number_format((int) $bill_preview['paid_excluded_count']); ?>명</strong></article>
                <article class="preview-item"><span class="label">미납 포함</span><strong><?php echo number_format((int) $bill_preview['arrears_student_count']); ?>명</strong></article>
                <article class="preview-item"><span class="label">총 청구 예정</span><strong><?php echo number_format((int) $bill_preview['bill_amount']); ?>원</strong></article>
                <?php if ($is_admin === 'super') { ?><article class="preview-item"><span class="label">본사 예상 발송비</span><strong><?php echo number_format((int) $bill_preview['send_fee']); ?>원</strong></article><?php } ?>
            </div>
            <?php if (!empty($bill_preview['group_summary'])) { ?>
            <div class="preview-groups" aria-label="학년별 자동발송 요약">
                <?php foreach ($bill_preview['group_summary'] as $group) { ?>
                <article class="preview-group">
                    <strong><?php echo get_text($group['grade_label']); ?></strong>
                    <div class="group-line">
                        발송 <?php echo number_format((int) $group['student_count']); ?>명 ·
                        수신 <?php echo number_format((int) $group['recipient_count']); ?>건 ·
                        미납포함 <?php echo number_format((int) $group['arrears_student_count']); ?>명
                    </div>
                    <div class="group-amount"><?php echo number_format((int) $group['bill_amount']); ?>원</div>
                </article>
                <?php } ?>
            </div>
            <?php } ?>
            <?php
            $preview_warnings = array();
            if ((int) $bill_preview['no_recipient_count'] > 0) {
                $preview_warnings[] = '수련비 문자 수신 보호자가 없는 학생 ' . number_format((int) $bill_preview['no_recipient_count']) . '명';
            }
            if ((int) $bill_preview['already_sent_excluded_count'] > 0) {
                $preview_warnings[] = '이미 청구서가 발송되어 제외된 학생 ' . number_format((int) $bill_preview['already_sent_excluded_count']) . '명';
            }
            if ((int) $bill_preview['selected_excluded_count'] > 0) {
                $preview_warnings[] = '자동청구 체크가 꺼져 제외된 학생 ' . number_format((int) $bill_preview['selected_excluded_count']) . '명';
            }
            if ((int) $bill_preview['zero_amount_excluded_count'] > 0) {
                $preview_warnings[] = '잔액 0원으로 제외된 학생 ' . number_format((int) $bill_preview['zero_amount_excluded_count']) . '명';
            }
            ?>
            <?php if ($preview_warnings) { ?><div class="preview-warn"><?php echo get_text(implode(' · ', $preview_warnings)); ?></div><?php } ?>
            <?php if (!empty($bill_preview['details']) || !empty($bill_preview['excluded_details'])) { ?>
            <details class="preview-detail">
                <summary>상세 목록 <?php echo number_format($preview_total); ?>명 보기</summary>
                <div class="preview-tools">
                    <form method="get">
                        <input type="hidden" name="billing_month" value="<?php echo get_text($billing_month); ?>">
                        <label>목록
                            <select name="preview_filter">
                                <option value="all" <?php echo $preview_filter === 'all' ? 'selected' : ''; ?>>전체 발송 예정</option>
                                <option value="arrears" <?php echo $preview_filter === 'arrears' ? 'selected' : ''; ?>>미납 포함만</option>
                                <option value="no_recipient" <?php echo $preview_filter === 'no_recipient' ? 'selected' : ''; ?>>연락처 없음</option>
                            </select>
                        </label>
                        <label>보기
                            <select name="preview_per_page">
                                <option value="10" <?php echo $preview_per_page === 10 ? 'selected' : ''; ?>>10명</option>
                                <option value="25" <?php echo $preview_per_page === 25 ? 'selected' : ''; ?>>25명</option>
                                <option value="50" <?php echo $preview_per_page === 50 ? 'selected' : ''; ?>>50명</option>
                            </select>
                        </label>
                        <button type="submit" class="btn soft">적용</button>
                    </form>
                    <div class="preview-pages">
                        <?php for ($page = 1; $page <= $preview_total_pages; $page++) { ?>
                        <a class="preview-page <?php echo $page === $preview_page ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo urlencode($billing_month); ?>&preview_filter=<?php echo urlencode($preview_filter); ?>&preview_per_page=<?php echo (int) $preview_per_page; ?>&preview_page=<?php echo $page; ?>"><?php echo $page; ?></a>
                        <?php } ?>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr><th>학생</th><th>학년/부</th><th>이번 달</th><th>이전 미납</th><th>청구 예정</th><th>수신/상태</th><th>미납월</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview_page_rows as $detail) { ?>
                        <tr>
                            <td><?php echo get_text($detail['student_name'] . ' (' . $detail['student_code'] . ')'); ?></td>
                            <td>
                                <?php echo get_text(isset($detail['grade_label']) ? $detail['grade_label'] : '학년 미지정'); ?>
                                <span class="detail-sub"><?php echo get_text(isset($detail['class_label']) ? $detail['class_label'] : '부 미지정'); ?></span>
                            </td>
                            <td class="right"><?php echo number_format((int) $detail['current_amount']); ?>원</td>
                            <td class="right"><?php echo number_format((int) $detail['arrears_amount']); ?>원</td>
                            <td class="right balance"><?php echo number_format((int) $detail['bill_amount']); ?>원</td>
                            <td><?php echo isset($detail['reason']) ? get_text($detail['reason']) : number_format((int) $detail['recipient_count']) . '명'; ?></td>
                            <td><?php echo $detail['arrears_months'] ? get_text(implode(', ', $detail['arrears_months'])) : '-'; ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$preview_page_rows) { ?>
                        <tr><td colspan="7">선택한 조건의 상세 목록이 없습니다.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </details>
            <?php } ?>
        </section>
        <form method="post" id="bulkActionForm" class="bulk-bar">
            <div>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <label><input type="checkbox" id="checkAllPayments"> 전체 선택</label>
            </div>
            <div class="actions">
                <button type="submit" name="action" value="bulk_mark_paid" class="btn primary" onclick="return confirm('선택한 학생을 결제완료 처리할까요?');">선택 완납 처리</button>
                <button type="submit" name="action" value="bulk_send_bill" class="btn soft" onclick="return confirm('선택한 학생에게 청구서를 발송 처리할까요?');">선택 청구서 발송</button>
            </div>
        </form>
        <div class="table-scroll">
        <table class="payment-table">
            <thead>
                <tr><th>선택</th><th>납부일</th><th>학생</th><th>수업부</th><th>청구액</th><th>입금액</th><th>잔액</th><th>상태</th><th>자동청구</th><th>문자</th><th>메모</th><th>관리</th></tr>
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
                        <td><input type="checkbox" form="bulkActionForm" name="payment_ids[]" value="<?php echo (int) $row['payment_id']; ?>" <?php echo $row['status'] === 'paid' ? 'disabled' : ''; ?>></td>
                        <td><?php echo get_text($row['due_date']); ?></td>
                        <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                        <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                        <td><input type="number" name="amount_due" value="<?php echo (int) $row['amount_due']; ?>" min="0" style="width:110px;text-align:right"></td>
                        <td><input type="number" name="amount_paid" value="<?php echo (int) $row['amount_paid']; ?>" min="0" style="width:110px"></td>
                        <td class="right balance"><?php echo number_format($balance); ?></td>
                        <td>
                            <span class="status-<?php echo get_text($row['status']); ?>"><?php echo get_text(ieum_tuition_status_label($row['status'])); ?></span>
                        </td>
                        <td><label class="auto-bill"><input type="checkbox" name="bill_auto_send_enabled" value="1" <?php echo !empty($row['bill_auto_send_enabled']) ? 'checked' : ''; ?>> 발송</label></td>
                        <td>
                            <?php if (!empty($row['notice_sent_at'])) { ?><div class="sent"><?php echo get_text(substr($row['notice_sent_at'], 5, 11)); ?> 발송</div><?php } ?>
                            <div><?php echo number_format((int) (isset($row['notice_count']) ? $row['notice_count'] : 0)); ?>회</div>
                            <?php if (!empty($row['bill_sent_at'])) { ?><div class="sent">청구서 <?php echo number_format((int) $row['bill_send_count']); ?>건</div><?php } ?>
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
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="send_bill">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn soft" onclick="return confirm('청구서를 발송 처리할까요?');">청구서</button>
                                </form>
                            </div>
                        </td>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="12">이번 달 수련비 대상이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
        </div>
        <p class="help">입금액이 청구액 이상이면 결제완료, 부족하면 미결제로 자동 정리됩니다. 자동발송은 이 화면에서 설정한 날짜에 실행되고, 미납 포함을 켜면 이전 달 미납 잔액을 이번 달 청구서에 합산합니다.</p>
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
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('main.wrap');
            if (!next) {
                window.location.href = url;
                return;
            }
            main.innerHTML = next.innerHTML;
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };
    const buildFormUrl = (form) => {
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url.toString();
    };
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters, .preview-tools form');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });
    main.addEventListener('change', (event) => {
        if (event.target && event.target.id === 'checkAllPayments') {
            document.querySelectorAll('input[name="payment_ids[]"]:not(:disabled)').forEach((item) => {
                item.checked = event.target.checked;
            });
            return;
        }
        const control = event.target.closest('form.filters input, form.filters select, .preview-tools form select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });
    main.addEventListener('click', (event) => {
        const link = event.target.closest('.preview-page');
        if (!link) return;
        event.preventDefault();
        loadView(link.href, true);
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
