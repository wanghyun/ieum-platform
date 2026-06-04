<?php
$sub_menu = '950160';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 부별 명단';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$grade_group = isset($_GET['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_GET['grade_group'])) : '';

function ieum_group_grade_options()
{
    return array(
        '' => '전체 학년',
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
    );
}

function ieum_group_grade_label($value)
{
    $options = ieum_group_grade_options();
    return isset($options[$value]) ? $options[$value] : $value;
}

$class_times = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($program_code !== '') {
    $program_sql = sql_escape_string($program_code);
    $where .= " and s.program_code = '{$program_sql}' ";
}
if ($class_time_id) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}
if ($grade_group !== '') {
    $grade_sql = sql_escape_string($grade_group);
    $where .= " and s.grade_group = '{$grade_sql}' ";
}

$students = sql_query("
    select s.*, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.grade_group asc, s.student_name asc
", false);
$student_rows = array();
$class_counts = array();
while ($row = sql_fetch_array($students)) {
    $student_rows[] = $row;
    $class_label = $row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '부 미지정';
    if (!isset($class_counts[$class_label])) {
        $class_counts[$class_label] = 0;
    }
    $class_counts[$class_label]++;
}
$total_count = count($student_rows);
$current_program_label = $program_code !== '' ? ieum_program_label($academy_id, $program_code) : '전체 프로그램';
$current_class_label = '전체 부';
$class_times_for_label = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($class_times_for_label)) {
    if ((int) $class['class_time_id'] === $class_time_id) {
        $current_class_label = $class['class_name'] . ' ' . $class['start_time'];
        break;
    }
}
$current_grade_label = $grade_group !== '' ? ieum_group_grade_label($grade_group) : '전체 학년';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.bar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:6px}.actions{display:flex;gap:8px;flex-wrap:wrap}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}select{height:40px;border:1px solid #cfd6df;border-radius:8px;padding:0 10px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:800;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.dark{background:#111827;border-color:#111827;color:#fff}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.summary-card{border:1px solid #d9dee7;border-radius:12px;background:#fff;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:24px}
.class-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.class-tab{border:1px solid #d9dee7;border-radius:999px;background:#f8fafc;padding:7px 12px;font-weight:900;color:#344054}
.roster{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}.student{border:1px solid #d8dee9;border-radius:12px;padding:15px;background:#fff;display:grid;gap:8px}.name{font-size:19px;font-weight:1000}.sub{color:#667085;font-size:13px}.badge{display:inline-flex;width:max-content;border-radius:999px;background:#eef2ff;color:#15204a;padding:4px 9px;font-size:12px;font-weight:900}.empty{padding:24px;text-align:center;color:#667085;border:1px dashed #cfd6df;border-radius:12px;background:#fafbfc}
.wrap.is-loading{opacity:.55;pointer-events:none}.filters select{min-width:150px}@media(max-width:900px){.summary{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.summary,.roster{grid-template-columns:1fr}.actions,.filters{display:grid}.actions .btn,.filters select,.filters .btn{width:100%}}
@media print{.ieum-top,.ieum-subnav-wrap,.ieum-side,.ieum-shell-top,.ieum-right-rail,.actions,.panel.filter-panel,.btn{display:none!important}body{background:#fff}.wrap,body.ieum-side-layout .wrap{max-width:none;margin:0;padding:12mm}h1{font-size:22px}.summary{grid-template-columns:repeat(4,1fr);gap:6px}.summary-card{box-shadow:none;padding:10px}.roster{grid-template-columns:repeat(3,1fr);gap:6px}.student{box-shadow:none;border-radius:6px;padding:9px}.name{font-size:15px}.sub{font-size:11px}}
/* Dashboard shell alignment: keep the roster page in the same frame as dashboard. */
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune{
    --ieum-side-width:260px!important;
    --ieum-rail-width:0px!important;
    --ieum-top-height:64px!important;
    --ieum-shell-top:#fff!important;
    --ieum-side-bg:#fff!important;
    background:#f4f6f9!important;
    color:#1f2937!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-side{
    width:260px!important;
    background:var(--ieum-side-bg)!important;
    border-right:1px solid #e7ebf0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-brand{
    height:144px!important;
    min-height:144px!important;
    background:var(--ieum-side-bg)!important;
    color:#111827!important;
    padding:0 26px!important;
    font-size:29px!important;
    letter-spacing:0!important;
    border-bottom:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-menu>summary{
    min-height:42px!important;
    padding:0 12px!important;
    border-left:0!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:700!important;
    color:#243142!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-menu>summary:hover{
    background:#f4f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-nav-label{
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-nav-icon{
    width:17px!important;
    height:17px!important;
    flex:0 0 17px!important;
    color:#334155!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-sub{
    background:#fff!important;
    border:0!important;
    padding:2px 0 8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-sub a{
    min-height:32px!important;
    padding:0 12px 0 34px!important;
    font-size:13px!important;
    font-weight:600!important;
    color:#4b5563!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .side-sub a.active{
    background:#f4f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:var(--ieum-side-bg)!important;
    color:#1f2937!important;
    border-bottom:0!important;
    box-shadow:none!important;
    padding:0 38px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-link{
    min-height:34px!important;
    border:0!important;
    background:transparent!important;
    color:#1f2937!important;
    padding:0 10px!important;
    border-radius:4px!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-link:hover{
    background:#f4f7fb!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-meta{
    color:#374151!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-meta-inner{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-help-dot{
    color:#c3cad5!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-clock{
    font-weight:900!important;
    color:#243142!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-support-link{
    display:inline-flex!important;
    align-items:center!important;
    min-height:26px!important;
    color:#1f2937!important;
    text-decoration:none!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-support-link:hover{
    color:#1583e9!important;
    text-decoration:underline!important;
    text-underline-offset:3px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:6px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .bar{
    align-items:flex-end!important;
    margin-bottom:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .bar h1{
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .meta{
    margin-top:8px!important;
    color:#667085!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .actions{
    align-items:center!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:8px!important;
    margin-bottom:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary-card,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .student{
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
    border-color:#dfe5ee!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary-card{
    min-height:76px!important;
    display:grid!important;
    align-content:center!important;
    padding:12px 14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary-card span{
    color:#667085!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary-card strong{
    font-size:22px!important;
    line-height:1.18!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .filter-panel{
    padding:14px 16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .filters{
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .filters select{
    min-width:170px!important;
    height:40px!important;
    border-radius:6px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .class-tabs{
    gap:7px!important;
    margin-top:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .class-tab{
    border-radius:999px!important;
    background:#f8fafc!important;
    font-size:13px!important;
    padding:7px 10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .roster{
    grid-template-columns:repeat(auto-fill,minmax(210px,1fr))!important;
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .student{
    min-height:112px!important;
    padding:12px!important;
    gap:6px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .badge{
    background:#eef5ff!important;
    color:#1d4f91!important;
    border-radius:999px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .name{
    font-size:17px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .sub{
    color:#667085!important;
    line-height:1.35!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .student .sub:nth-of-type(n+3){
    display:-webkit-box!important;
    -webkit-line-clamp:1!important;
    -webkit-box-orient:vertical!important;
    overflow:hidden!important;
}
@media(max-width:900px){
    body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
@media(max-width:680px){
    body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .summary,
    body.ieum-side-layout.ieum-dashboard-page.groups-page-tune .roster{
        grid-template-columns:1fr!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page groups-page-tune">
<?php echo ieum_admin_header('groups', 'side'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>부별 명단</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?></div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php">원생 관리</a>
            <button class="btn primary" type="button" onclick="window.print()">명단 인쇄</button>
        </div>
    </div>

    <section class="summary" aria-label="현재 명단 요약">
        <article class="summary-card"><span>현재 보기</span><strong><?php echo number_format($total_count); ?>명</strong></article>
        <article class="summary-card"><span>프로그램</span><strong><?php echo get_text($current_program_label); ?></strong></article>
        <article class="summary-card"><span>수업 부</span><strong><?php echo get_text($current_class_label); ?></strong></article>
        <article class="summary-card"><span>학년/부</span><strong><?php echo get_text($current_grade_label); ?></strong></article>
    </section>

    <section class="panel filter-panel">
        <form method="get" class="filters">
            <select name="program_code">
                <option value="">전체 프로그램</option>
                <?php foreach ($program_options as $program) { ?>
                <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                <?php } ?>
            </select>
            <select name="class_time_id">
                <option value="0">전체 부</option>
                <?php while ($class = sql_fetch_array($class_times)) { ?>
                <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                    <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
                </option>
                <?php } ?>
            </select>
            <select name="grade_group">
                <?php foreach (ieum_group_grade_options() as $value => $label) { ?>
                <option value="<?php echo get_text($value); ?>" <?php echo get_selected($grade_group, $value); ?>><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <button class="btn primary" type="submit">보기</button>
        </form>
        <div class="class-tabs" aria-label="부별 인원 요약">
            <?php foreach ($class_counts as $label => $count) { ?>
            <span class="class-tab"><?php echo get_text($label); ?> <?php echo number_format($count); ?>명</span>
            <?php } ?>
        </div>
    </section>

    <section class="roster">
        <?php foreach ($student_rows as $row) { ?>
        <article class="student">
            <span class="badge"><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '부 미지정'); ?></span>
            <div class="name"><?php echo get_text($row['student_name']); ?></div>
            <div class="sub">원생번호 <?php echo get_text($row['student_code']); ?> · <?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?></div>
            <div class="sub"><?php echo get_text(ieum_group_grade_label($row['grade_group'])); ?></div>
            <?php if (!empty($row['memo'])) { ?><div class="sub"><?php echo get_text($row['memo']); ?></div><?php } ?>
        </article>
        <?php } ?>
        <?php if ($total_count === 0) { ?><article class="empty">조건에 맞는 원생이 없습니다.</article><?php } ?>
    </section>
</main>
<script>
(function() {
    var academyName = <?php echo json_encode(isset($academy['academy_name']) ? $academy['academy_name'] : '아이이음', JSON_UNESCAPED_UNICODE); ?>;
    var rootSelector = '.groups-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = academyName;
    }
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }
    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (!meta) {
        return;
    }
    meta.textContent = '';
    var supportBaseUrl = <?php echo json_encode(IEUM_URL . '/admin/support.php', JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.createElement('span');
    metaInner.className = 'dashboard-shell-meta-inner';
    var makeSupportLink = function(text, topic) {
        var link = document.createElement('a');
        link.className = 'dashboard-shell-support-link';
        link.href = supportBaseUrl + '?topic=' + encodeURIComponent(topic);
        link.textContent = text;
        return link;
    };
    var makeDivider = function() {
        var divider = document.createElement('span');
        divider.className = 'dashboard-shell-divider';
        divider.textContent = '|';
        return divider;
    };
    var helpGroup = document.createElement('span');
    helpGroup.className = 'dashboard-shell-help-group';
    [
        ['Q&A', 'qna'],
        ['자주하는 질문', 'faq'],
        ['문의하기', 'contact'],
        ['AI 챗봇', 'ai']
    ].forEach(function(item, index) {
        if (index > 0) {
            var dot = document.createElement('span');
            dot.className = 'dashboard-shell-help-dot';
            dot.textContent = '·';
            helpGroup.appendChild(dot);
        }
        helpGroup.appendChild(makeSupportLink(item[0], item[1]));
    });
    var academyText = document.createElement('span');
    academyText.textContent = academyName;
    var clockText = document.createElement('span');
    clockText.className = 'dashboard-shell-clock';
    var renderClock = function() {
        var now = new Date();
        clockText.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    };
    metaInner.appendChild(makeSupportLink('개발지원센터', 'qna'));
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(helpGroup);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(academyText);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(clockText);
    meta.appendChild(metaInner);
    renderClock();
    window.setInterval(renderClock, 30000);
})();
</script>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;

    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            });
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
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });

    main.addEventListener('change', (event) => {
        const select = event.target.closest('form.filters select');
        if (!select) return;
        const form = select.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });

    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
