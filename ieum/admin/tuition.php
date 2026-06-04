<?php
$sub_menu = '950170';
require_once './_common.php';

$g5['title'] = '아이이음 수련비 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_tuition_week_options()
{
    return array('2' => '주 2회', '3' => '주 3회', '4' => '주 4회', '5' => '주 5회', 'custom' => '직접 설정');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $plan_id = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
        $week_type = isset($_POST['week_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['week_type'])) : '5';
        $plan_name = isset($_POST['plan_name']) ? trim($_POST['plan_name']) : '';
        $monthly_fee = isset($_POST['monthly_fee']) ? max(0, (int) $_POST['monthly_fee']) : 0;
        $sibling_discount_amount = isset($_POST['sibling_discount_amount']) ? max(0, (int) $_POST['sibling_discount_amount']) : 0;
        $default_due_day = isset($_POST['default_due_day']) ? (int) $_POST['default_due_day'] : 5;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($default_due_day < 1) {
            $default_due_day = 1;
        } elseif ($default_due_day > 31) {
            $default_due_day = 31;
        }

        if (!isset(ieum_tuition_week_options()[$week_type])) {
            $week_type = 'custom';
        }
        if ($plan_name === '') {
            $plan_name = isset(ieum_tuition_week_options()[$week_type]) ? ieum_tuition_week_options()[$week_type] : '수련비';
        }

        if ($monthly_fee <= 0) {
            $error = '월 수련비를 입력하세요.';
        } else {
            $week_type_sql = sql_escape_string($week_type);
            $plan_name_sql = sql_escape_string($plan_name);
            if ($plan_id) {
                sql_query("
                    update " . IEUM_TUITION_PLAN_TABLE . "
                       set week_type = '{$week_type_sql}',
                           plan_name = '{$plan_name_sql}',
                           monthly_fee = '{$monthly_fee}',
                           sibling_discount_amount = '{$sibling_discount_amount}',
                           default_due_day = '{$default_due_day}',
                           is_active = '{$is_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where plan_id = '{$plan_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = '수련비 정책이 수정되었습니다.';
            } else {
                sql_query("
                    insert into " . IEUM_TUITION_PLAN_TABLE . "
                        set academy_id = '{$academy_id}',
                            week_type = '{$week_type_sql}',
                            plan_name = '{$plan_name_sql}',
                            monthly_fee = '{$monthly_fee}',
                            sibling_discount_amount = '{$sibling_discount_amount}',
                            default_due_day = '{$default_due_day}',
                            is_active = '{$is_active}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '수련비 정책이 등록되었습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$plans = sql_query("
    select *
      from " . IEUM_TUITION_PLAN_TABLE . "
     where academy_id = '{$academy_id}'
  order by field(week_type, '2', '3', '4', '5', 'custom'), plan_id asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px;overflow-x:auto}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input,select{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:center}.grid .primary{min-width:160px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
table{width:100%;min-width:1120px;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}
@media(max-width:900px){.grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .meta{
    margin:8px 0 18px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .panel{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .grid{
    grid-template-columns:130px minmax(150px,1fr) 130px 120px 140px 86px 74px!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune input,
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune select{
    min-height:40px!important;
    border-color:#cfd8e3!important;
    border-radius:6px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune label{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:6px!important;
    min-height:40px!important;
    font-weight:900!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune label input{
    min-height:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .grid .primary{
    min-width:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark{
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .panel{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .meta,
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .help{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark input,
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark select,
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.tuition-policy-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
@media(max-width:1500px){
    body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .grid{
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.tuition-policy-page-tune .grid{
        grid-template-columns:1fr!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page tuition-policy-page-tune">
<?php echo ieum_admin_header('tuition', 'side'); ?>
<main class="wrap">
    <h1>수련비 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 주 횟수별 기본 금액과 형제 할인 기준입니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <select name="week_type">
                <?php foreach (ieum_tuition_week_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <input type="text" name="plan_name" placeholder="예: 주5회 기본반">
            <input type="number" name="monthly_fee" placeholder="월 수련비" min="0" required>
            <input type="number" name="sibling_discount_amount" placeholder="형제 할인" min="0">
            <select name="default_due_day" title="기본 납부일">
                <?php for ($day = 1; $day <= 31; $day++) { ?>
                <option value="<?php echo $day; ?>" <?php echo get_selected($day, 5); ?>>매월 <?php echo $day; ?>일</option>
                <?php } ?>
            </select>
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>

    <section class="panel">
        <table>
            <thead><tr><th>주 횟수</th><th>정책명</th><th>월 수련비</th><th>형제 할인</th><th>기본 납부일</th><th>상태</th><th>수정</th></tr></thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($plans)) { $i++; ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="plan_id" value="<?php echo (int) $row['plan_id']; ?>">
                    <td>
                        <select name="week_type">
                            <?php foreach (ieum_tuition_week_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected($row['week_type'], $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td><input type="text" name="plan_name" value="<?php echo get_text($row['plan_name']); ?>"></td>
                    <td><input type="number" name="monthly_fee" value="<?php echo (int) $row['monthly_fee']; ?>"></td>
                    <td><input type="number" name="sibling_discount_amount" value="<?php echo (int) $row['sibling_discount_amount']; ?>"></td>
                    <td>
                        <select name="default_due_day">
                            <?php for ($day = 1; $day <= 31; $day++) { ?>
                            <option value="<?php echo $day; ?>" <?php echo get_selected((int) (isset($row['default_due_day']) ? $row['default_due_day'] : 5), $day); ?>>매월 <?php echo $day; ?>일</option>
                            <?php } ?>
                        </select>
                    </td>
                    <td><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td><button type="submit" class="btn">저장</button></td>
                </form>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="7">등록된 수련비 정책이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
<script type="text/plain" data-deprecated-shell-sync="common-ui-owned">
(function(){
    var rootSelector = '.tuition-policy-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
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
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
</body>
</html>
