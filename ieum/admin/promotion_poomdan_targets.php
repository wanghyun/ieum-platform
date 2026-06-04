<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승품/단 심사 대상';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_poomdan_grade_label($value)
{
    $labels = array(
        '' => '미지정',
        'kindergarten' => '유치부',
        'elementary_1' => '초등 1학년',
        'elementary_2' => '초등 2학년',
        'elementary_3' => '초등 3학년',
        'elementary_4' => '초등 4학년',
        'elementary_5' => '초등 5학년',
        'elementary_6' => '초등 6학년',
        'middle_1' => '중등 1학년',
        'middle_2' => '중등 2학년',
        'middle_3' => '중등 3학년',
        'high_1' => '고등 1학년',
        'high_2' => '고등 2학년',
        'high_3' => '고등 3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기부',
    );
    return isset($labels[$value]) ? $labels[$value] : $value;
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$status_filter = isset($_GET['status']) ? preg_replace('/[^0-9a-z_]/i', '', $_GET['status']) : 'due';
if (!in_array($status_filter, array('due', 'overdue', 'all'), true)) {
    $status_filter = 'due';
}

$programs = ieum_program_options($academy_id, true);
$classes = array();
$class_result = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc, class_time_id asc
", false);
while ($class = sql_fetch_array($class_result)) {
    $classes[] = $class;
}

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 and coalesce(s.promotion_enabled, 1) = 1 ";
if ($program_code !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
if ($class_time_id > 0) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}

$rows = array();
$counts = array('target' => 0, 'overdue' => 0, 'month' => 0, 'all' => 0);
$result = sql_query("
    select s.*, c.class_name, c.start_time, c.sort_order
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
", false);
while ($student = sql_fetch_array($result)) {
    $status = ieum_promotion_status($academy, $student, $month);
    if (empty($status['is_poomdan_exam'])) {
        continue;
    }
    $counts['all']++;
    if ($status['due_this_month'] || $status['overdue']) {
        $counts['target']++;
        if ($status['overdue']) {
            $counts['overdue']++;
        } else {
            $counts['month']++;
        }
    }

    $include = false;
    if ($status_filter === 'all') {
        $include = true;
    } elseif ($status_filter === 'overdue') {
        $include = $status['overdue'];
    } else {
        $include = $status['due_this_month'] || $status['overdue'];
    }
    if (!$include) {
        continue;
    }
    $student['_promotion_status'] = $status;
    $rows[] = $student;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#101828;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.head{display:flex;gap:16px;align-items:flex-end;justify-content:space-between;margin-bottom:18px}.head h1{margin:0;font-size:32px;letter-spacing:0}.meta{margin-top:8px;color:#667085}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-bottom:18px}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.filters input,.filters select{border:1px solid #cfd6df;border-radius:9px;padding:10px 12px;font-size:14px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #cfd6df;border-radius:9px;background:#fff;color:#101828;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.dark{background:#101828;border-color:#101828;color:#fff}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{border:1px solid #d9e2ef;border-radius:14px;background:linear-gradient(135deg,#fff,#f8fbff);padding:16px}.summary-card.warn{border-color:#f4c27a;background:#fffaf0}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:30px}.notice{border:1px solid #c7d7fe;border-radius:14px;background:#eef4ff;padding:16px;color:#1e3a8a;line-height:1.55}.notice strong{display:block;font-size:18px;color:#102a56;margin-bottom:6px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.muted{color:#667085}.state{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef6ff;color:#1769c2}.state.overdue{background:#fff1f2;color:#be123c}.empty{padding:40px;text-align:center;color:#667085;border:1px dashed #cfd6df;border-radius:14px;background:#fff}.actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:900px){.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.head{align-items:flex-start;flex-direction:column}table{display:block;overflow-x:auto;white-space:nowrap}}@media(max-width:640px){.summary{grid-template-columns:1fr}.wrap{padding:0 12px}.panel{padding:14px}}
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .ieum-right-rail{display:none!important}
.promotion-poomdan-targets-page-tune .side-nav{padding:0 14px 22px!important}.promotion-poomdan-targets-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.promotion-poomdan-targets-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.promotion-poomdan-targets-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.promotion-poomdan-targets-page-tune .ieum-shell-link,.promotion-poomdan-targets-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.promotion-poomdan-targets-page-tune .ieum-shell-link::before{display:none!important}.promotion-poomdan-targets-page-tune .ieum-shell-meta{color:#0f172a!important}.promotion-poomdan-targets-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.promotion-poomdan-targets-page-tune .dashboard-shell-divider,.promotion-poomdan-targets-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
.promotion-poomdan-targets-page-tune .notice{background:#f8fbff!important;border-color:#d9e2ef!important;color:#334155!important}
.promotion-poomdan-targets-page-tune .notice strong{color:#0f172a!important}
.promotion-poomdan-targets-page-tune .table-wrap{border:1px solid #d8dee9;border-radius:12px;overflow:auto}
.promotion-poomdan-targets-page-tune .table-wrap table{min-width:960px;border:0}
.promotion-poomdan-targets-page-tune .table-wrap th,.promotion-poomdan-targets-page-tune .table-wrap td{white-space:nowrap}
.promotion-poomdan-targets-page-tune .table-wrap td.left{min-width:210px}
.promotion-poomdan-targets-page-tune .table-wrap th:first-child,.promotion-poomdan-targets-page-tune .table-wrap td:first-child{border-left:0}
.promotion-poomdan-targets-page-tune .table-wrap th:last-child,.promotion-poomdan-targets-page-tune .table-wrap td:last-child{border-right:0}
.promotion-poomdan-targets-page-tune .table-wrap thead tr:first-child th{border-top:0}
.promotion-poomdan-targets-page-tune .table-wrap tbody tr:last-child td{border-bottom:0}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune{--ieum-side-width:0px}.promotion-poomdan-targets-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
@media print{body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .ieum-side,body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .ieum-shell-top{display:none!important}body.ieum-side-layout.ieum-dashboard-page.promotion-poomdan-targets-page-tune .wrap{margin:0!important;padding:0!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-poomdan-targets-page-tune">
<?php echo ieum_admin_header('promotion_poomdan_targets', 'side'); ?>
<main class="wrap">
    <div class="head">
        <div>
            <h1>승품/단 심사 대상</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> · 1급에서 품/단 심사로 넘어가는 원생</div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => 'due')); ?>">승급 대상</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => 'poomdan', 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>">심사 안내문</a>
            <button class="btn dark" type="button" onclick="window.print()">인쇄</button>
        </div>
    </div>

    <form class="panel filters" method="get">
        <input type="month" name="month" value="<?php echo get_text($month); ?>">
        <select name="program_code">
            <option value="">전체 프로그램</option>
            <?php foreach ($programs as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id">
            <option value="0">전체 부</option>
            <?php foreach ($classes as $class) {
                $class_label = trim($class['class_name'] . ' ' . $class['start_time']);
            ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text($class_label); ?></option>
            <?php } ?>
        </select>
        <select name="status">
            <option value="due" <?php echo get_selected($status_filter, 'due'); ?>>심사 안내 대상</option>
            <option value="overdue" <?php echo get_selected($status_filter, 'overdue'); ?>>기간 지남</option>
            <option value="all" <?php echo get_selected($status_filter, 'all'); ?>>전체 후보</option>
        </select>
        <button class="btn primary" type="submit">조회</button>
    </form>

    <section class="summary panel">
        <article class="summary-card warn"><span>심사 안내 대상</span><strong><?php echo number_format((int) $counts['target']); ?>명</strong></article>
        <article class="summary-card"><span>이번 달</span><strong><?php echo number_format((int) $counts['month']); ?>명</strong></article>
        <article class="summary-card"><span>기간 지남</span><strong><?php echo number_format((int) $counts['overdue']); ?>명</strong></article>
        <article class="summary-card"><span>전체 후보</span><strong><?php echo number_format((int) $counts['all']); ?>명</strong></article>
    </section>

    <section class="notice">
        <strong>승품/단은 도장 승급과 분리해서 관리합니다.</strong>
        1급 원생은 도장 내부 승급 처리 대상이 아니라 협회 승품/단 심사 안내 대상입니다. 다음 단계에서는 심사비 설정, 개별 안내문 생성, 인쇄/문자 발송 흐름으로 연결합니다.
    </section>

    <section class="panel">
        <?php if (!$rows) { ?>
            <div class="empty">조건에 맞는 승품/단 심사 대상자가 없습니다.</div>
        <?php } else { ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>원생</th>
                    <th>학년/부</th>
                    <th>수업 부</th>
                    <th>현재</th>
                    <th>심사</th>
                    <th>합격 후</th>
                    <th>예정일</th>
                    <th>상태</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    $status = $row['_promotion_status'];
                    $next_rank = $status['next_rank'];
                    $class_label = trim((string) $row['class_name'] . ' ' . (string) $row['start_time']);
                    $current_label = trim(($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_full_rank_label($status['poom_dan'], $status['grade_level']));
                    $exam_label = isset($next_rank['exam_label']) ? $next_rank['exam_label'] : ieum_promotion_full_rank_label($next_rank['poom_dan'], 0);
                    $post_pass_label = isset($next_rank['post_pass_label']) ? $next_rank['post_pass_label'] : ieum_promotion_full_rank_label($next_rank['poom_dan'], $next_rank['grade_level']);
                ?>
                <tr>
                    <td class="left"><strong><?php echo get_text($row['student_name']); ?></strong> <span class="muted"><?php echo get_text($row['student_code']); ?></span></td>
                    <td><?php echo get_text(ieum_poomdan_grade_label(isset($row['grade_group']) ? $row['grade_group'] : '')); ?></td>
                    <td><?php echo get_text($class_label !== '' ? $class_label : '부 미지정'); ?></td>
                    <td><?php echo get_text($current_label); ?></td>
                    <td><strong><?php echo get_text($exam_label); ?></strong></td>
                    <td><?php echo get_text($post_pass_label); ?></td>
                    <td><?php echo get_text($status['next_date']); ?></td>
                    <td><span class="state <?php echo !empty($status['overdue']) ? 'overdue' : ''; ?>"><?php echo !empty($status['overdue']) ? '기간 지남' : '이번 달'; ?></span></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        </div>
        <?php } ?>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.promotion-poomdan-targets-page-tune.ieum-dashboard-page';
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
