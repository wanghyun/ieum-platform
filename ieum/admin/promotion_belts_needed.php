<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 준비 띠';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_belts_needed_grade_label($value)
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
$download = isset($_GET['download']) ? preg_replace('/[^a-z]/', '', $_GET['download']) : '';
$belt_order_enabled = false;

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

$belts = ieum_promotion_belts($academy);
$belt_order_map = array();
foreach ($belts as $belt_index => $belt_name) {
    $belt_order_map[trim((string) $belt_name)] = (int) $belt_index;
}

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 and coalesce(s.promotion_enabled, 1) = 1 ";
if ($program_code !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
if ($class_time_id > 0) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}

$belt_groups = array();
$total_count = 0;
$overdue_count = 0;
$student_result = sql_query("
    select s.*, c.class_name, c.start_time, c.sort_order
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
", false);
while ($student = sql_fetch_array($student_result)) {
    $status = ieum_promotion_status($academy, $student, $month);
    if (!$status['enabled'] || !empty($status['is_poomdan_exam']) || (!$status['due_this_month'] && !$status['overdue'])) {
        continue;
    }
    $next_rank = isset($status['next_rank']) ? $status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
    $need_belt = isset($next_rank['belt']) ? trim((string) $next_rank['belt']) : '';
    if ($need_belt === '') {
        $need_belt = '띠 미지정';
    }
    if (!isset($belt_groups[$need_belt])) {
        $belt_groups[$need_belt] = array(
            'belt' => $need_belt,
            'count' => 0,
            'overdue' => 0,
            'students' => array(),
            'order' => isset($belt_order_map[$need_belt]) ? (int) $belt_order_map[$need_belt] : 999,
        );
    }
    $class_label = trim((string) $student['class_name'] . ' ' . (string) $student['start_time']);
    $current_label = trim(($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_rank_label($status['poom_dan'], $status['grade_level']));
    $next_label = trim(($need_belt !== '' ? $need_belt . ' ' : '') . ieum_promotion_rank_label($next_rank['poom_dan'], $next_rank['grade_level']));
    $belt_groups[$need_belt]['count']++;
    $total_count++;
    if (!empty($status['overdue'])) {
        $belt_groups[$need_belt]['overdue']++;
        $overdue_count++;
    }
    $belt_groups[$need_belt]['students'][] = array(
        'name' => $student['student_name'],
        'code' => $student['student_code'],
        'grade' => ieum_promotion_belts_needed_grade_label(isset($student['grade_group']) ? $student['grade_group'] : ''),
        'class' => $class_label !== '' ? $class_label : '부 미지정',
        'current' => $current_label !== '' ? $current_label : '미지정',
        'next' => $next_label !== '' ? $next_label : '미지정',
        'next_date' => isset($status['next_date']) ? $status['next_date'] : '',
        'overdue' => !empty($status['overdue']),
    );
}
uasort($belt_groups, function ($a, $b) {
    if ((int) $a['order'] === (int) $b['order']) {
        return strcmp((string) $a['belt'], (string) $b['belt']);
    }
    return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
});

if ($download === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ieum-promotion-belts-' . $month . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('준비 띠', '원생명', '원생번호', '학년/부', '수업 부', '현재', '승급 후', '예정일', '상태'));
    foreach ($belt_groups as $group) {
        foreach ($group['students'] as $student) {
            fputcsv($out, array(
                $group['belt'],
                $student['name'],
                $student['code'],
                $student['grade'],
                $student['class'],
                $student['current'],
                $student['next'],
                $student['next_date'],
                $student['overdue'] ? '기간 지남' : '이번 달',
            ));
        }
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#101828;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.head{display:flex;gap:16px;align-items:flex-end;justify-content:space-between;margin-bottom:18px}.head h1{margin:0;font-size:32px;letter-spacing:0}.meta{margin-top:8px;color:#667085}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #cfd6df;border-radius:9px;background:#fff;color:#101828;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.dark{background:#101828;border-color:#101828;color:#fff}.btn.disabled{opacity:.55;cursor:not-allowed}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-bottom:18px}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.filters input,.filters select{border:1px solid #cfd6df;border-radius:9px;padding:10px 12px;font-size:14px;background:#fff}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{border:1px solid #d9e2ef;border-radius:14px;background:linear-gradient(135deg,#fff,#f8fbff);padding:16px}.summary-card.warn{border-color:#f4c27a;background:#fffaf0}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:30px}
.belt-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.belt-card{border:1px solid #d9e2ef;border-radius:14px;background:#fff;padding:16px}.belt-card strong{display:block;font-size:20px}.belt-card b{display:block;margin-top:8px;font-size:30px;color:#1769c2}.belt-card p{margin:8px 0 0;color:#667085;font-size:13px}.order-box{display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #d9e2ef;border-radius:14px;background:#f8fbff;padding:18px}.order-box strong{display:block;font-size:20px}.order-box p{margin:6px 0 0;color:#667085;line-height:1.5}
.group{break-inside:avoid}.group-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.group-head h2{margin:0;font-size:24px}.group-count{display:inline-flex;align-items:center;gap:8px;border-radius:999px;background:#eef6ff;color:#1769c2;padding:8px 12px;font-weight:1000}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.muted{color:#667085}.state{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef6ff;color:#1769c2}.state.overdue{background:#fff1f2;color:#be123c}.empty{padding:40px;text-align:center;color:#667085;border:1px dashed #cfd6df;border-radius:14px;background:#fff}
@media(max-width:1100px){.summary,.belt-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.head{align-items:flex-start;flex-direction:column}}@media(max-width:640px){.summary,.belt-grid{grid-template-columns:1fr}.wrap{padding:0 12px}.panel{padding:14px}table{display:block;overflow-x:auto;white-space:nowrap}}
@media print{body{background:#fff}.ieum-top,.ieum-subnav-wrap,.filters,.actions,.order-box{display:none!important}.wrap{max-width:none;margin:0;padding:0}.panel{box-shadow:none;border:0;border-radius:0;margin:0 0 14px;padding:0}.summary,.belt-grid{grid-template-columns:repeat(4,1fr)}.summary-card,.belt-card{box-shadow:none}.group{page-break-inside:avoid;margin-top:16px}th,td{padding:7px;font-size:12px}@page{size:A4;margin:12mm}}
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .ieum-right-rail{display:none!important}
.promotion-belts-needed-page-tune .side-nav{padding:0 14px 22px!important}.promotion-belts-needed-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.promotion-belts-needed-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.promotion-belts-needed-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.promotion-belts-needed-page-tune .ieum-shell-link,.promotion-belts-needed-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.promotion-belts-needed-page-tune .ieum-shell-link::before{display:none!important}.promotion-belts-needed-page-tune .ieum-shell-meta{color:#0f172a!important}.promotion-belts-needed-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.promotion-belts-needed-page-tune .dashboard-shell-divider,.promotion-belts-needed-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune{--ieum-side-width:0px}.promotion-belts-needed-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
@media print{body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .ieum-side,body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .ieum-shell-top{display:none!important}body.ieum-side-layout.ieum-dashboard-page.promotion-belts-needed-page-tune .wrap{margin:0!important;padding:0!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-belts-needed-page-tune">
<?php echo ieum_admin_header('promotion_belts_needed', 'side'); ?>
<main class="wrap">
    <div class="head">
        <div>
            <h1>이번 달 준비해야 할 띠</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> 승급 대상 기준</div>
        </div>
        <div class="actions">
            <button class="btn dark" type="button" onclick="window.print()">인쇄</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_belts_needed.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'download' => 'csv')); ?>">엑셀용 다운로드</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => 'due')); ?>">승급 대상 보기</a>
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
        <button class="btn primary" type="submit">조회</button>
    </form>

    <section class="summary panel">
        <article class="summary-card warn"><span>준비할 띠 총수량</span><strong><?php echo number_format($total_count); ?>개</strong></article>
        <article class="summary-card"><span>띠 종류</span><strong><?php echo number_format(count($belt_groups)); ?>종</strong></article>
        <article class="summary-card"><span>기간 지난 대상</span><strong><?php echo number_format($overdue_count); ?>명</strong></article>
        <article class="summary-card"><span>운영 상태</span><strong>수량 확인</strong></article>
    </section>

    <section class="panel">
        <div class="belt-grid">
            <?php foreach ($belt_groups as $group) { ?>
            <article class="belt-card">
                <strong><?php echo get_text($group['belt']); ?></strong>
                <b><?php echo number_format((int) $group['count']); ?>개</b>
                <p><?php echo (int) $group['overdue'] > 0 ? '기간 지난 대상 ' . number_format((int) $group['overdue']) . '명 포함' : '이번 달 승급 대상 기준'; ?></p>
            </article>
            <?php } ?>
            <?php if (!$belt_groups) { ?>
            <div class="empty">이번 달 준비할 띠가 없습니다.</div>
            <?php } ?>
        </div>
    </section>

    <?php if ($belt_order_enabled) { ?>
    <section class="order-box">
        <div>
            <strong>필요수량 주문하기</strong>
            <p>차후 본사와 띠 공급처 연동 후, 이 화면에서 바로 주문할 수 있게 연결합니다. 지금은 수량 확인과 인쇄/다운로드만 사용합니다.</p>
        </div>
        <button class="btn disabled" type="button" disabled>주문 기능 준비 중</button>
    </section>
    <?php } ?>

    <?php foreach ($belt_groups as $group) { ?>
    <section class="panel group">
        <div class="group-head">
            <h2><?php echo get_text($group['belt']); ?></h2>
            <span class="group-count"><?php echo number_format((int) $group['count']); ?>개</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>원생</th>
                    <th>학년/부</th>
                    <th>수업 부</th>
                    <th>현재</th>
                    <th>승급 후</th>
                    <th>예정일</th>
                    <th>상태</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($group['students'] as $student) { ?>
                <tr>
                    <td class="left"><strong><?php echo get_text($student['name']); ?></strong> <span class="muted"><?php echo get_text($student['code']); ?></span></td>
                    <td><?php echo get_text($student['grade']); ?></td>
                    <td><?php echo get_text($student['class']); ?></td>
                    <td><?php echo get_text($student['current']); ?></td>
                    <td><?php echo get_text($student['next']); ?></td>
                    <td><?php echo get_text($student['next_date']); ?></td>
                    <td><span class="state <?php echo $student['overdue'] ? 'overdue' : ''; ?>"><?php echo $student['overdue'] ? '기간 지남' : '이번 달'; ?></span></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </section>
    <?php } ?>
</main>
<script>
(function(){
    var rootSelector = '.promotion-belts-needed-page-tune.ieum-dashboard-page';
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
