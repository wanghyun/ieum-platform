<?php
$sub_menu = '950149';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급 미션 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

$message = '';
$error = '';
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : 'taekwondo';
if ($program_code === '') {
    $program_code = 'taekwondo';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $program_code = isset($_POST['program_code']) ? ieum_program_code($_POST['program_code']) : $program_code;
        if ($program_code === '') {
            $program_code = 'taekwondo';
        }
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'reset_defaults') {
            ieum_promotion_seed_default_missions($academy_id, $program_code, true);
            $message = '기본 승급 미션을 다시 불러왔습니다.';
        } elseif ($action === 'save_missions') {
            $missions = isset($_POST['missions']) && is_array($_POST['missions']) ? $_POST['missions'] : array();
            sql_query("
                delete from " . IEUM_PROMOTION_MISSION_TABLE . "
                 where academy_id = '{$academy_id}'
                   and program_code = '" . sql_escape_string($program_code) . "'
            ", false);
            $saved = 0;
            foreach ($missions as $belt_name => $categories) {
                $belt_name = trim((string) $belt_name);
                if ($belt_name === '' || !is_array($categories)) {
                    continue;
                }
                $sort_order = 10;
                foreach ($categories as $category => $data) {
                    $category = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $category);
                    if ($category === '') {
                        continue;
                    }
                    $label = isset($data['label']) ? trim($data['label']) : '';
                    $items = isset($data['items']) ? trim($data['items']) : '';
                    $active = isset($data['active']) ? 1 : 0;
                    if ($label === '' || $items === '') {
                        continue;
                    }
                    sql_query("
                        insert into " . IEUM_PROMOTION_MISSION_TABLE . "
                           set academy_id = '{$academy_id}',
                               program_code = '" . sql_escape_string($program_code) . "',
                               belt_name = '" . sql_escape_string($belt_name) . "',
                               category = '" . sql_escape_string($category) . "',
                               category_label = '" . sql_escape_string($label) . "',
                               mission_items = '" . sql_escape_string($items) . "',
                               sort_order = '{$sort_order}',
                               is_active = '{$active}',
                               updated_at = '" . G5_TIME_YMDHIS . "'
                    ", false);
                    $sort_order += 10;
                    $saved++;
                }
            }
            $message = '승급 미션 ' . number_format($saved) . '개 항목을 저장했습니다.';
        }
    }
}

ieum_promotion_seed_default_missions($academy_id, $program_code, false);
$csrf_token = ieum_new_csrf_token();
$programs = ieum_program_options($academy_id, true);

$belt_names = array();
$result = sql_query("
    select distinct belt_name
      from " . IEUM_PROMOTION_MISSION_TABLE . "
     where academy_id in (0, '{$academy_id}')
       and program_code = '" . sql_escape_string($program_code) . "'
  order by field(belt_name, '흰띠','노란띠','주황띠','초록띠','보라띠','파랑띠','파란띠','밤띠','빨강띠','빨간띠','품띠','1품','2품','3품','4품'), belt_name
", false);
while ($row = sql_fetch_array($result)) {
    if (!in_array($row['belt_name'], $belt_names, true)) {
        $belt_names[] = $row['belt_name'];
    }
}
if (!$belt_names) {
    foreach (ieum_promotion_mission_default_rows() as $row) {
        if (!in_array($row['belt_name'], $belt_names, true)) {
            $belt_names[] = $row['belt_name'];
        }
    }
}

$missions_by_belt = array();
foreach ($belt_names as $belt_name) {
    $missions_by_belt[$belt_name] = ieum_promotion_missions_for_belt($academy_id, $program_code, $belt_name, 0);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 8px 22px rgba(15,23,42,.05)}h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;margin-bottom:18px}.notice{padding:12px;border-radius:10px;margin:12px 0}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}.toolbar select{border:1px solid #cfd6df;border-radius:10px;padding:10px 12px;min-height:40px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:8px 14px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{border-color:#fca5a5;color:#b91c1c}.hint{color:#667085;line-height:1.6}.mission-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.belt-card{border:1px solid #d9e2f1;border-radius:14px;background:#fff;overflow:hidden}.belt-head{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#eef6ff;padding:14px 16px}.belt-head h2{margin:0;font-size:20px}.belt-head span{color:#1d4ed8;font-weight:900}.mission-row{display:grid;grid-template-columns:170px minmax(0,1fr) 80px;gap:10px;align-items:start;padding:14px 16px;border-top:1px solid #e5e7eb}.mission-row input[type=text],.mission-row textarea{width:100%;border:1px solid #cfd6df;border-radius:10px;padding:10px;font:inherit}.mission-row textarea{min-height:116px;resize:vertical;line-height:1.55}.mission-active{display:flex;align-items:center;gap:6px;justify-content:center;min-height:42px;font-weight:900;color:#475569}.form-foot{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}.print-link{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.small{font-size:13px;color:#667085}@media(max-width:1100px){.mission-grid{grid-template-columns:1fr}.mission-row{grid-template-columns:1fr}}@media print{.toolbar,.form-foot,.print-link,.ieum-top,.ieum-subnav-wrap{display:none!important}.wrap{margin:0;padding:0}.panel{box-shadow:none;border:0}}
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .ieum-right-rail{display:none!important}
.promotion-missions-page-tune .side-nav{padding:0 14px 22px!important}.promotion-missions-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.promotion-missions-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.promotion-missions-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.promotion-missions-page-tune .ieum-shell-link,.promotion-missions-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.promotion-missions-page-tune .ieum-shell-link::before{display:none!important}.promotion-missions-page-tune .ieum-shell-meta{color:#0f172a!important}.promotion-missions-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.promotion-missions-page-tune .dashboard-shell-divider,.promotion-missions-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
.promotion-missions-page-tune .mission-row textarea{min-height:104px}
@media(max-width:1320px){.promotion-missions-page-tune .mission-row{grid-template-columns:minmax(92px,.7fr) minmax(0,1.6fr) 54px;gap:8px;padding:12px}.promotion-missions-page-tune .mission-row textarea{min-height:96px}.promotion-missions-page-tune .mission-active{font-size:12px;min-height:38px}}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune{--ieum-side-width:0px}.promotion-missions-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
@media print{body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .ieum-side,body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .ieum-shell-top{display:none!important}body.ieum-side-layout.ieum-dashboard-page.promotion-missions-page-tune .wrap{margin:0!important;padding:0!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-missions-page-tune">
<?php echo ieum_admin_header('promotion_missions', 'side'); ?>
<main class="wrap">
    <h1>승급 미션 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 띠별 승급심사 미션을 도장 방식에 맞게 수정합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <div class="toolbar">
            <form method="get" class="toolbar">
                <select name="program_code" onchange="this.form.submit()">
                    <?php foreach ($programs as $program) { ?>
                    <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                    <?php } ?>
                </select>
                <noscript><button class="btn primary" type="submit">조회</button></noscript>
            </form>
            <div class="print-link">
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php">승급 대상</a>
                <a class="btn primary" target="_blank" href="<?php echo IEUM_URL; ?>/admin/promotion_missions_print.php?<?php echo http_build_query(array('program_code' => $program_code, 'status' => 'due', 'print_mode' => 'detail')); ?>">미션표 인쇄</a>
            </div>
        </div>
        <p class="hint">본사는 기본 미션을 제공하고, 각 도장은 자기 수업 방식에 맞게 항목을 줄이거나 추가할 수 있습니다. 한 줄에 하나씩 적으면 인쇄표에서 체크 칸으로 나옵니다.</p>
    </section>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="save_missions">
        <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
        <section class="mission-grid">
            <?php foreach ($missions_by_belt as $belt_name => $missions) { ?>
            <article class="belt-card">
                <div class="belt-head">
                    <h2><?php echo get_text($belt_name); ?></h2>
                    <span><?php echo number_format(count($missions)); ?>개 영역</span>
                </div>
                <?php foreach ($missions as $mission) {
                    $category = isset($mission['category']) ? $mission['category'] : '';
                    if ($category === '') {
                        continue;
                    }
                ?>
                <div class="mission-row">
                    <input type="text" name="missions[<?php echo get_text($belt_name); ?>][<?php echo get_text($category); ?>][label]" value="<?php echo get_text($mission['category_label']); ?>" aria-label="영역명">
                    <textarea name="missions[<?php echo get_text($belt_name); ?>][<?php echo get_text($category); ?>][items]" aria-label="미션 항목"><?php echo get_text(implode("\n", $mission['items'])); ?></textarea>
                    <label class="mission-active"><input type="checkbox" name="missions[<?php echo get_text($belt_name); ?>][<?php echo get_text($category); ?>][active]" value="1" <?php echo !isset($mission['is_active']) || (int) $mission['is_active'] ? 'checked' : ''; ?>> 사용</label>
                </div>
                <?php } ?>
            </article>
            <?php } ?>
        </section>
        <div class="form-foot">
            <button class="btn primary" type="submit">전체 미션 저장</button>
        </div>
    </form>

    <form method="post" class="panel" onsubmit="return confirm('도장별로 수정한 내용을 기본 미션으로 다시 바꿀까요?');">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="reset_defaults">
        <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
        <strong>기본값 다시 불러오기</strong>
        <p class="small">본사가 제공한 기본 서기/발차기/격파 미션으로 다시 세팅합니다. 이미 수정한 내용은 덮어씁니다.</p>
        <button class="btn danger" type="submit">기본 미션으로 초기화</button>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.promotion-missions-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) homeLink.textContent = '아이이음 교육페이지';
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
