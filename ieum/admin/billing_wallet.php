<?php
$sub_menu = '950185';
require_once './_common.php';
require_once IEUM_PATH . '/lib/hq_billing.php';

if ($is_admin !== 'super') {
    alert('본사 관리자만 접근할 수 있습니다.');
}

$g5['title'] = '아이이음 청구 발송비 관리';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $amount = isset($_POST['amount']) ? (int) $_POST['amount'] : 0;
        $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
        if ($amount <= 0) {
            $error = '충전 금액을 입력해 주세요.';
        } elseif (ieum_hq_wallet_charge($amount, $memo !== '' ? $memo : '본사 충전', isset($member['mb_id']) ? $member['mb_id'] : '')) {
            $message = number_format($amount) . '원을 충전했습니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$wallet = ieum_hq_wallet_ensure();
$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month_sql = sql_escape_string($month);

$academy_usage = sql_query("
    select a.academy_name,
           l.academy_id,
           count(*) as send_count,
           coalesce(sum(l.send_fee), 0) as send_amount
      from " . IEUM_BILLING_SEND_LOG_TABLE . " l
 left join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = l.academy_id
     where left(l.created_at, 7) = '{$month_sql}'
  group by l.academy_id, a.academy_name
  order by send_amount desc, send_count desc
", false);

$logs = sql_query("
    select w.*, a.academy_name
      from " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . " w
 left join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = w.academy_id
  order by w.log_id desc
     limit 100
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1180px;margin:28px auto;padding:0 20px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0}.card,.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{color:#667085;font-size:13px}.num{font-size:30px;font-weight:1000;margin-top:6px}.panel{margin-top:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.filters,form.charge{display:flex;gap:8px;align-items:center;flex-wrap:wrap}input{border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px}.btn{border:1px solid #cfd6df;border-radius:8px;background:#fff;padding:10px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#71829f;color:#fff}.left{text-align:left}.right{text-align:right}.plus{color:#176b2c;font-weight:900}.minus{color:#a4262c;font-weight:900}@media(max-width:760px){.cards{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
</head>
<body>
<?php echo ieum_admin_header('billing_wallet'); ?>
<main class="wrap">
    <h1>청구 발송비 관리</h1>
    <p class="label">결제선생 B2B 연동 전까지 본사 충전금과 도장별 청구서 발송비 흐름을 관리합니다.</p>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <section class="cards">
        <article class="card"><div class="label">현재 충전금</div><div class="num"><?php echo number_format((int) $wallet['balance_amount']); ?>원</div></article>
        <article class="card"><div class="label">총 충전</div><div class="num"><?php echo number_format((int) $wallet['total_charged']); ?>원</div></article>
        <article class="card"><div class="label">총 사용</div><div class="num"><?php echo number_format((int) $wallet['total_used']); ?>원</div></article>
    </section>
    <section class="panel">
        <h2>충전</h2>
        <form method="post" class="charge">
            <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
            <input type="number" name="amount" placeholder="충전 금액" min="1">
            <input type="text" name="memo" placeholder="메모">
            <button type="submit" class="btn primary">충전 등록</button>
        </form>
    </section>
    <section class="panel">
        <h2>도장별 사용량</h2>
        <form method="get" class="filters">
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button type="submit" class="btn">조회</button>
        </form>
        <table>
            <thead><tr><th>도장</th><th>발송 건수</th><th>사용 금액</th></tr></thead>
            <tbody>
            <?php $has = false; while ($row = sql_fetch_array($academy_usage)) { $has = true; ?>
                <tr>
                    <td class="left"><?php echo get_text($row['academy_name'] ?: '미지정'); ?></td>
                    <td><?php echo number_format((int) $row['send_count']); ?>건</td>
                    <td class="right"><?php echo number_format((int) $row['send_amount']); ?>원</td>
                </tr>
            <?php } ?>
            <?php if (!$has) { ?><tr><td colspan="3">사용 내역이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
    <section class="panel">
        <h2>최근 충전/차감 내역</h2>
        <table>
            <thead><tr><th>일시</th><th>구분</th><th>도장</th><th>금액</th><th>잔액</th><th>메모</th></tr></thead>
            <tbody>
            <?php $has = false; while ($row = sql_fetch_array($logs)) { $has = true; $amount = (int) $row['amount']; ?>
                <tr>
                    <td><?php echo get_text($row['created_at']); ?></td>
                    <td><?php echo get_text($row['log_type']); ?></td>
                    <td class="left"><?php echo get_text($row['academy_name'] ?: '-'); ?></td>
                    <td class="right <?php echo $amount >= 0 ? 'plus' : 'minus'; ?>"><?php echo number_format($amount); ?>원</td>
                    <td class="right"><?php echo number_format((int) $row['balance_after']); ?>원</td>
                    <td class="left"><?php echo get_text($row['description']); ?></td>
                </tr>
            <?php } ?>
            <?php if (!$has) { ?><tr><td colspan="6">내역이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
