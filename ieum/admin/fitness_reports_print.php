<?php
$sub_menu = '950193';
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/fitness.php';

$g5['title'] = '아이이음 체력 리포트 전체 인쇄';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_fitness_ensure_table();

function ieum_print_fitness_month_diff($from_date, $to_month)
{
    $from_date = trim((string) $from_date);
    if ($from_date === '' || !preg_match('/^\d{4}\-\d{2}/', $from_date)) {
        return 0;
    }
    return (((int) substr($to_month, 0, 4) - (int) substr($from_date, 0, 4)) * 12)
        + ((int) substr($to_month, 5, 2) - (int) substr($from_date, 5, 2));
}

function ieum_print_fitness_cycle_due($student, $month, $settings)
{
    $cycle_months = isset($settings['cycle_months']) ? (int) $settings['cycle_months'] : 1;
    if ($cycle_months <= 1) {
        return true;
    }
    $base_date = !empty($student['admission_date']) ? $student['admission_date'] : (isset($student['created_at']) ? $student['created_at'] : '');
    $diff = ieum_print_fitness_month_diff($base_date, $month);
    return $diff >= 0 && ($diff % $cycle_months) === 0;
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$scope = isset($_GET['scope']) ? preg_replace('/[^a-z_]/', '', trim($_GET['scope'])) : 'ready';
if (!in_array($scope, array('ready', 'completed'), true)) {
    $scope = 'ready';
}

$program_filter_sql = $program_code !== '' ? " and s.program_code = '" . sql_escape_string($program_code) . "' " : '';
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : '';

$students = array();
$student_result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.admission_date, s.created_at, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.fitness_report_enabled, 1) = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_result)) {
    $students[(int) $row['student_id']] = $row;
}

$fitness_map = array();
if ($students) {
    $fitness_result = sql_query("
        select *
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '" . sql_escape_string($month) . "'
    ", false);
    while ($row = sql_fetch_array($fitness_result)) {
        $sid = (int) $row['student_id'];
        if (isset($students[$sid])) {
            $fitness_map[$sid] = $row;
        }
    }
    $student_ids = array_keys($students);
    ieum_fitness_merge_metric_values($fitness_map, ieum_fitness_metric_values($academy_id, $month, $student_ids), $student_ids);
}

$items = ieum_fitness_active_items($academy_id);
$settings = ieum_fitness_settings($academy_id);
$print_students = array();
foreach ($students as $sid => $student) {
    $fitness = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
    $completed = false;
    foreach (array_keys($items) as $key) {
        if (isset($fitness[$key]) && $fitness[$key] !== null && $fitness[$key] !== '') {
            $completed = true;
            break;
        }
    }
    if (!$completed) {
        continue;
    }
    if ($scope === 'ready' && !ieum_print_fitness_cycle_due($student, $month, $settings)) {
        continue;
    }
    $print_students[] = $student;
}

$back_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array(
    'month' => $month,
    'program_code' => $program_code,
    'class_time_id' => $class_time_id,
    'report_status' => $scope === 'ready' ? 'send_ready' : 'done',
));
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.toolbar{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid #d8dee9;padding:14px 18px;display:flex;justify-content:space-between;gap:12px;align-items:center;box-shadow:0 6px 20px rgba(15,23,42,.06)}h1{margin:0;font-size:20px}.meta{color:#667085;margin-top:4px;font-size:13px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{border:1px solid #cfd6df;border-radius:9px;background:#fff;color:#111827;text-decoration:none;padding:10px 13px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.empty{max-width:720px;margin:70px auto;background:#fff;border:1px solid #d8dee9;border-radius:16px;padding:30px;text-align:center;color:#667085}.bundle{display:grid;gap:18px;padding:22px}.preview-label{width:calc(210mm * .76);margin:0 auto 8px;color:#667085;font-size:13px;font-weight:900}.sheet-preview{--preview-scale:.76;position:relative;width:calc(210mm * var(--preview-scale));height:calc(297mm * var(--preview-scale));margin:0 auto 10px;background:#fff;border:1px solid #d8dee9;border-radius:16px;box-shadow:0 18px 44px rgba(15,23,42,.16);overflow:hidden}.sheet-preview::before{content:"리포트 미리보기 준비 중";position:absolute;inset:0;display:grid;place-items:center;color:#667085;font-size:14px;font-weight:900;background:linear-gradient(135deg,#fff,#f8fbff);z-index:2}.sheet-preview.is-loaded::before{display:none}.sheet-frame{width:210mm;height:297mm;border:0;background:#fff;display:block;transform:scale(var(--preview-scale));transform-origin:top left;opacity:0;transition:opacity .18s ease}.sheet-preview.is-loaded .sheet-frame{opacity:1}.loading{color:#667085;font-size:13px;font-weight:800}@media(max-width:900px){.toolbar{position:static;align-items:flex-start;flex-direction:column}.bundle{padding:12px}.preview-label{width:calc(210mm * .54)}.sheet-preview{--preview-scale:.54}}@media(max-width:560px){.preview-label{width:calc(210mm * .42)}.sheet-preview{--preview-scale:.42}}@media print{@page{size:A4;margin:0}html,body{background:#fff}.toolbar,.empty,.preview-label{display:none}.bundle{display:block;padding:0}.sheet-preview{width:210mm;height:297mm;margin:0;border:0;border-radius:0;box-shadow:none;page-break-after:always;break-after:page;overflow:hidden}.sheet-preview::before{display:none}.sheet-preview:last-child{page-break-after:auto;break-after:auto}.sheet-frame{width:210mm;height:297mm;transform:none;box-shadow:none;opacity:1}}
</style>
</head>
<body>
<header class="toolbar">
    <div>
        <h1>체력 리포트 전체 인쇄</h1>
        <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> · <?php echo $scope === 'ready' ? '이번 측정 주기 준비 원생' : '측정 완료 원생'; ?> <?php echo number_format(count($print_students)); ?>명</div>
    </div>
    <div class="actions">
        <span class="loading" id="loadState">리포트 불러오는 중</span>
        <a class="btn" href="<?php echo get_text($back_url); ?>">목록으로</a>
        <button type="button" class="btn primary" id="printBtn" disabled>전체 인쇄</button>
    </div>
</header>
<?php if (!$print_students) { ?>
<section class="empty">인쇄할 체력 리포트가 없습니다. 측정 완료 상태와 조회 조건을 확인해 주세요.</section>
<?php } else { ?>
<main class="bundle">
    <?php foreach ($print_students as $index => $student) {
        $url = IEUM_URL . '/admin/fitness_parent_report.php?' . http_build_query(array(
            'month' => $month,
            'student_id' => (int) $student['student_id'],
            'print_bundle' => 1,
        ));
    ?>
    <div class="preview-label"><?php echo number_format($index + 1); ?> / <?php echo number_format(count($print_students)); ?> · <?php echo get_text($student['student_name']); ?></div>
    <section class="sheet-preview">
        <iframe class="sheet-frame" src="<?php echo get_text($url); ?>" title="<?php echo get_text($student['student_name']); ?> 체력 리포트" scrolling="no"></iframe>
    </section>
    <?php } ?>
</main>
<?php } ?>
<script>
(function () {
    const frames = Array.from(document.querySelectorAll('.sheet-frame'));
    const btn = document.getElementById('printBtn');
    const state = document.getElementById('loadState');
    if (!frames.length) {
        if (state) state.textContent = '인쇄 대상 없음';
        return;
    }
    let loaded = 0;
    const refresh = () => {
        if (state) state.textContent = loaded + ' / ' + frames.length + '장 준비';
        if (loaded >= frames.length && btn) {
            btn.disabled = false;
            if (state) state.textContent = '인쇄 준비 완료';
        }
    };
    frames.forEach((frame) => {
        frame.addEventListener('load', () => {
            if (frame.parentElement) frame.parentElement.classList.add('is-loaded');
            loaded += 1;
            refresh();
        }, { once: true });
    });
    refresh();
    if (btn) {
        btn.addEventListener('click', () => window.print());
    }
})();
</script>
</body>
</html>

