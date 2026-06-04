<?php
$sub_menu = '950145';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 프로그램 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

ieum_seed_default_programs($academy_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $programs = isset($_POST['programs']) && is_array($_POST['programs']) ? $_POST['programs'] : array();
        $default_code = isset($_POST['default_program']) ? ieum_program_code($_POST['default_program']) : '';
        $sort = 0;

        foreach ($programs as $code => $row) {
            $code = ieum_program_code($code);
            if ($code === '') {
                continue;
            }

            $name = isset($row['program_name']) ? trim($row['program_name']) : '';
            $is_active = isset($row['is_active']) ? 1 : 0;
            $sort++;
            if ($name === '') {
                $is_active = 0;
                $name = $code;
            }

            $code_sql = sql_escape_string($code);
            $name_sql = sql_escape_string($name);
            sql_query("
                insert into " . IEUM_ACADEMY_PROGRAM_TABLE . "
                    set academy_id = '{$academy_id}',
                        program_code = '{$code_sql}',
                        program_name = '{$name_sql}',
                        is_default = 0,
                        is_active = '{$is_active}',
                        sort_order = '{$sort}',
                        created_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        program_name = values(program_name),
                        is_active = values(is_active),
                        sort_order = values(sort_order),
                        updated_at = '" . G5_TIME_YMDHIS . "'
            ");
        }

        $new_name = isset($_POST['new_program_name']) ? trim($_POST['new_program_name']) : '';
        if ($new_name !== '') {
            $base_code = strtolower(preg_replace('/[^0-9A-Za-z_]+/', '_', $new_name));
            if ($base_code === '' || preg_match('/^_+$/', $base_code)) {
                $base_code = 'program';
            }
            $base_code = trim($base_code, '_');
            $new_code = $base_code;
            $suffix = 2;
            while (true) {
                $new_code_sql = sql_escape_string($new_code);
                $exists = sql_fetch("
                    select program_id
                      from " . IEUM_ACADEMY_PROGRAM_TABLE . "
                     where academy_id = '{$academy_id}'
                       and program_code = '{$new_code_sql}'
                     limit 1
                ", false);
                if (!isset($exists['program_id'])) {
                    break;
                }
                $new_code = $base_code . '_' . $suffix;
                $suffix++;
            }

            $new_name_sql = sql_escape_string($new_name);
            $new_code_sql = sql_escape_string($new_code);
            sql_query("
                insert into " . IEUM_ACADEMY_PROGRAM_TABLE . "
                    set academy_id = '{$academy_id}',
                        program_code = '{$new_code_sql}',
                        program_name = '{$new_name_sql}',
                        is_default = 0,
                        is_active = 1,
                        sort_order = '{$sort}' + 1,
                        created_at = '" . G5_TIME_YMDHIS . "'
            ");
        }

        ieum_normalize_default_program($academy_id, $default_code);
        $message = '프로그램 설정을 저장했습니다.';
    }
}

$csrf_token = ieum_new_csrf_token();
$programs = ieum_program_options($academy_id, false);
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
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}
input[type=text]{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.help{color:#667085;font-size:13px;line-height:1.6}.add-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:14px}
@media(max-width:720px){table{display:block;overflow-x:auto;white-space:nowrap}.add-row{grid-template-columns:1fr}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.program-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune .meta{
    margin:8px 0 18px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune .panel{
    max-width:980px!important;
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune input[type=text]{
    min-height:40px!important;
    border-color:#cfd8e3!important;
    border-radius:6px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune .add-row{
    grid-template-columns:minmax(0,1fr) 92px!important;
    align-items:center!important;
}
body.ieum-side-layout.ieum-dashboard-page.program-page-tune .btn{
    min-height:40px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.program-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page program-page-tune">
<?php echo ieum_admin_header('programs', 'side'); ?>
<main class="wrap">
    <h1>프로그램 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 원생 등록에서 먼저 보이는 수업 종목을 정합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <table>
            <thead><tr><th>사용</th><th>대표</th><th>프로그램명</th><th>코드</th></tr></thead>
            <tbody>
            <?php foreach ($programs as $program) { ?>
            <tr>
                <td><input type="checkbox" name="programs[<?php echo get_text($program['program_code']); ?>][is_active]" value="1" <?php echo $program['is_active'] ? 'checked' : ''; ?>></td>
                <td><input type="radio" name="default_program" value="<?php echo get_text($program['program_code']); ?>" <?php echo $program['is_default'] ? 'checked' : ''; ?>></td>
                <td><input type="text" name="programs[<?php echo get_text($program['program_code']); ?>][program_name]" value="<?php echo get_text($program['program_name']); ?>"></td>
                <td><?php echo get_text($program['program_code']); ?></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
        <div class="add-row">
            <input type="text" name="new_program_name" placeholder="새 프로그램 추가 예: 영어, 수학, 축구">
            <button type="submit" class="btn primary">저장</button>
        </div>
        <p class="help">대표 프로그램은 원생 등록 화면에서 기본 선택됩니다. 사용 체크를 끄면 신규 원생 등록 선택지에서 숨겨집니다.</p>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.program-page-tune.ieum-dashboard-page';
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
