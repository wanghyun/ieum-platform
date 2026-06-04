<?php
$sub_menu = '950190';
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/fitness.php';

$g5['title'] = '아이이음 체력 입력';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_fitness_ensure_table();

function ieum_admin_fitness_metric_defaults()
{
    return array(
        'jump_rope' => array('label' => '줄넘기', 'unit' => '회', 'step' => 10, 'hint' => '정해진 시간 안에 성공한 횟수'),
        'shuttle_run' => array('label' => '왕복오래달리기(셔틀런)', 'unit' => '회', 'step' => 1, 'hint' => '신호에 맞춰 성공한 왕복 횟수'),
        'push_up' => array('label' => '팔굽혀펴기', 'unit' => '회', 'step' => 1, 'hint' => '자세가 유지된 성공 횟수'),
        'sit_up' => array('label' => '윗몸일으키기', 'unit' => '회', 'step' => 1, 'hint' => '정해진 시간 안의 정확한 횟수'),
        'long_jump' => array('label' => '제자리멀리뛰기', 'unit' => 'cm', 'step' => 5, 'hint' => '출발선에서 가장 가까운 착지 지점'),
        'flexibility' => array('label' => '유연성', 'unit' => 'cm', 'step' => 1, 'hint' => '반동 없이 천천히 뻗은 최종 위치'),
    );
}

function ieum_admin_fitness_label_item($key, $item = array())
{
    $defaults = ieum_admin_fitness_metric_defaults();
    if (isset($defaults[$key])) {
        return array_merge($item, $defaults[$key]);
    }
    $item['label'] = isset($item['label']) && trim($item['label']) !== '' ? $item['label'] : $key;
    $item['unit'] = isset($item['unit']) ? $item['unit'] : '';
    $item['step'] = isset($item['step']) ? $item['step'] : 1;
    $item['hint'] = isset($item['hint']) ? $item['hint'] : '현장 기준에 맞춘 실제 측정값';
    return $item;
}

function ieum_admin_fitness_grade_label($value)
{
    $labels = array(
        '' => '기본',
        'age_6' => '6세',
        'age_7' => '7세',
        'kindergarten' => '유치부',
        'elementary_1' => '초등/1학년',
        'elementary_2' => '초등/2학년',
        'elementary_3' => '초등/3학년',
        'elementary_4' => '초등/4학년',
        'elementary_5' => '초등/5학년',
        'elementary_6' => '초등/6학년',
        'middle_1' => '중등/1학년',
        'middle_2' => '중등/2학년',
        'middle_3' => '중등/3학년',
        'high_1' => '고등/1학년',
        'high_2' => '고등/2학년',
        'high_3' => '고등/3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기반',
    );
    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_admin_fitness_gender_label($value)
{
    if ($value === 'male') {
        return '남자';
    }
    if ($value === 'female') {
        return '여자';
    }
    return '공통';
}

function ieum_admin_fitness_value($row, $key)
{
    if (!$row || !array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return '';
    }
    $number = (float) $row[$key];
    if (abs($number - round($number)) < 0.00001) {
        return (string) (int) round($number);
    }
    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function ieum_admin_fitness_level_label($level)
{
    $level_no = is_array($level) && isset($level['level_no']) ? (int) $level['level_no'] : 0;
    if ($level_no === 1) {
        return '우수';
    }
    if ($level_no === 2) {
        return '양호';
    }
    if ($level_no === 3) {
        return '보통';
    }
    if ($level_no === 4) {
        return '관찰';
    }
    if ($level_no >= 5) {
        return '보강';
    }
    return '기록 대기';
}

function ieum_admin_fitness_bmi_label($label)
{
    $label = (string) $label;
    if (strpos($label, '저') !== false) {
        return '저체중';
    }
    if (strpos($label, '과') !== false) {
        return '과체중';
    }
    if (strpos($label, '비') !== false) {
        return '비만';
    }
    if ($label === '' || strpos($label, '미') !== false) {
        return '미입력';
    }
    return '정상체중';
}

function ieum_admin_fitness_display_label($label)
{
    return str_replace(array('남학생', '여학생', '학생'), array('남자', '여자', '원생'), (string) $label);
}

function ieum_admin_fitness_context_label($student, $month)
{
    $grade = ieum_fitness_context_grade_group($student, $month);
    return ieum_admin_fitness_grade_label($grade) . ' · ' . ieum_admin_fitness_gender_label(isset($student['gender']) ? $student['gender'] : 'all') . ' 기준';
}

$month = isset($_REQUEST['month']) ? preg_replace('/[^0-9\-]/', '', trim($_REQUEST['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_REQUEST['program_code']) ? ieum_program_code($_REQUEST['program_code']) : '';
$class_time_id = isset($_REQUEST['class_time_id']) ? (int) $_REQUEST['class_time_id'] : 0;

$items = array();
foreach (ieum_fitness_active_items($academy_id) as $key => $item) {
    $items[$key] = ieum_admin_fitness_label_item($key, $item);
}
$metric_options = array(
    'all' => array('label' => '전체 항목', 'unit' => '', 'step' => 1),
    'body' => array('label' => '키/몸무게/BMI', 'unit' => '', 'step' => 1),
);
$metric_options = array_merge($metric_options, $items);
$metric_key = isset($_REQUEST['metric_key']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_REQUEST['metric_key'])) : 'all';
if (!isset($metric_options[$metric_key])) {
    $metric_key = 'all';
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['ieum_token']) ? trim($_POST['ieum_token']) : '';
    if (!ieum_verify_csrf_token($token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 저장해 주세요.';
    } else {
        $fitness_rows = isset($_POST['fitness']) && is_array($_POST['fitness']) ? $_POST['fitness'] : array();
        foreach ($fitness_rows as $sid => $row) {
            ieum_fitness_save($academy_id, (int) $sid, $month, $row, isset($member['mb_id']) ? $member['mb_id'] : '');
        }
        $message = '체력 측정값을 저장했습니다.';
    }
}

$program_filter_sql = '';
if ($program_code !== '') {
    $program_filter_sql = " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : '';

$class_options = array();
$class_result = sql_query("
    select class_time_id, class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($class_result)) {
    $class_options[] = $class;
}

$students = array();
$student_result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.birth_date, s.gender, s.grade_group, s.program_code,
           c.class_name, c.start_time
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
    $students[] = $row;
}

$fitness_map = array();
$fitness_result = sql_query("
    select *
      from " . IEUM_REPORT_FITNESS_TABLE . "
     where academy_id = '{$academy_id}'
       and report_month = '" . sql_escape_string($month) . "'
", false);
while ($row = sql_fetch_array($fitness_result)) {
    $fitness_map[(int) $row['student_id']] = $row;
}
$student_ids = array_map(function ($student) {
    return (int) $student['student_id'];
}, $students);
ieum_fitness_merge_metric_values($fitness_map, ieum_fitness_metric_values($academy_id, $month, $student_ids), $student_ids);

$fitness_calc_map = array();
$saved_count = 0;
$total_sum = 0;
$bmi_count = 0;
foreach ($students as $student) {
    $sid = (int) $student['student_id'];
    $row = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
    $calc = ieum_fitness_calculate($row, $student, $academy_id, $month);
    $fitness_calc_map[$sid] = $calc;
    $has_score_metric = false;
    foreach (array_keys($items) as $item_key) {
        if (isset($row[$item_key]) && $row[$item_key] !== null && $row[$item_key] !== '') {
            $has_score_metric = true;
            break;
        }
    }
    if ($has_score_metric) {
        $saved_count++;
        $total_sum += (float) $calc['total'];
    }
    if ($calc['bmi'] !== null) {
        $bmi_count++;
    }
}
$avg_total = $saved_count ? round($total_sum / $saved_count, 1) : 0;
$selected_metric = $metric_options[$metric_key];
$body_averages = ieum_fitness_body_averages();
$token = ieum_new_csrf_token();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#101828;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:0 auto;padding:28px 24px 42px}.page-head{display:grid;gap:18px;align-items:start}.title-row{display:flex;gap:14px;align-items:center;justify-content:space-between}.title-row h1{font-size:34px;margin:0;letter-spacing:-.01em}.meta{margin-top:8px;color:#667085;line-height:1.45}.head-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;min-width:max-content;border:1px solid #cfd6df;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:8px 14px;font-weight:900;cursor:pointer;white-space:nowrap}.primary{background:#1d6fc9;border-color:#1d6fc9;color:#fff}.dark{background:#101828;border-color:#101828;color:#fff}.notice{padding:12px 14px;border-radius:12px;margin-top:14px;font-weight:800}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{background:#fff;border:1px solid #d9dee7;border-radius:16px;padding:17px;box-shadow:0 10px 24px rgba(15,23,42,.05)}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:900}.summary-card strong{display:block;margin-top:6px;font-size:28px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:18px;padding:18px;box-shadow:0 10px 26px rgba(15,23,42,.06)}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}input,select{border:1px solid #cfd6df;border-radius:10px;padding:10px 11px;font-size:14px;background:#fff}select{min-height:42px}.mode-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.mode-tabs a{border:1px solid #d9dee7;border-radius:999px;background:#f8fafc;color:#344054;text-decoration:none;padding:9px 13px;font-weight:900}.mode-tabs a.active{background:#1d6fc9;border-color:#1d6fc9;color:#fff}.guide-grid{display:grid;grid-template-columns:minmax(0,520px) minmax(520px,1fr);justify-content:start;gap:14px;margin-top:18px;align-items:start}.guide-card{background:#fff;border:1px solid #d9dee7;border-radius:18px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.05);overflow:hidden}.guide-card h2{font-size:20px;margin:0 0 12px}.guide-card p{margin:8px 0;color:#344054;line-height:1.6}.average-card{padding-bottom:16px;overflow-x:auto}.average-list{display:grid;grid-template-columns:repeat(6,minmax(108px,1fr));gap:8px;min-width:696px}.average-item{border:1px solid #d8dee9;border-radius:14px;background:#f8fbff;overflow:hidden}.average-item .avg-head{display:flex;align-items:center;justify-content:space-between;gap:6px;background:#e9eff8;color:#25324a;padding:7px 9px;font-size:12px;font-weight:1000}.average-item dl{display:grid;grid-template-columns:64px 1fr;margin:0}.average-item dt,.average-item dd{border-top:1px solid #d8dee9;margin:0;padding:5px 7px;font-size:12px;line-height:1.15;white-space:nowrap}.average-item dt{color:#667085;font-weight:900;background:#fff}.average-item dd{font-weight:900;text-align:right;background:#fff}.input-panel{margin-top:18px}.sticky-save{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-18px -18px 18px;padding:14px 18px;background:rgba(255,255,255,.96);border-bottom:1px solid #d9dee7;border-radius:18px 18px 0 0;backdrop-filter:blur(8px)}.sticky-save strong{font-size:17px}.input-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1360px}th,td{border:1px solid #d8dee9;padding:8px;text-align:center;font-size:14px;vertical-align:middle}th{background:#74839c;color:#fff}.left{text-align:left}.student-name{font-weight:1000}.sub{display:block;color:#667085;font-size:12px;margin-top:3px}.metric{width:92px}.body-metric{width:82px}.memo{min-width:150px;width:100%}.total{font-size:20px;font-weight:1000;color:#1d6fc9}.score-mini{display:block;margin-top:4px;color:#667085;font-size:11px}.level-chip{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef4ff;color:#1d6fc9;font-size:11px;font-weight:900}.card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fitness-card{border:1px solid #d9dee7;border-radius:16px;background:#fff;padding:14px;display:grid;gap:10px}.fitness-card .name{font-size:21px;font-weight:1000}.metric-title{color:#1d6fc9;font-weight:1000}.body-pair{display:grid;grid-template-columns:1fr 1fr;gap:8px}.body-pair label{display:grid;gap:5px;color:#667085;font-size:12px;font-weight:900}.stepper{display:grid;grid-template-columns:44px 1fr 44px;gap:8px;align-items:center}.stepper button{height:44px;border:1px solid #cfd6df;border-radius:12px;background:#f3f6fa;font-size:23px;font-weight:1000}.stepper input{width:100%;height:44px;text-align:center;font-size:19px;font-weight:900}.hint{color:#667085;font-size:12px;line-height:1.45}.auto-result{display:grid;grid-template-columns:1fr 1fr;gap:8px}.auto-result span{border:1px solid #dbe7f6;background:#f8fbff;border-radius:12px;padding:8px;color:#344054;font-size:12px;font-weight:900}.auto-result b{display:block;color:#1d6fc9;font-size:15px;margin-top:2px}.empty{padding:34px;text-align:center;color:#667085}.fitness-settings-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.52);opacity:0;pointer-events:none;transition:.18s opacity;z-index:880}.fitness-settings-backdrop.open{opacity:1;pointer-events:auto}.fitness-settings-modal{position:fixed;right:32px;top:82px;width:min(560px,calc(100vw - 36px));max-height:calc(100vh - 120px);overflow:auto;background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.28);padding:20px;opacity:0;pointer-events:none;transform:translateY(-8px);transition:.18s opacity,.18s transform;z-index:881}.fitness-settings-modal.open{opacity:1;pointer-events:auto;transform:none}.settings-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.settings-head h2{margin:0;font-size:22px}.settings-close{width:36px;height:36px;border:0;border-radius:999px;background:#101828;color:#fff;font-size:20px;cursor:pointer}.settings-quick{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}.settings-quick a{display:grid;gap:5px;border:1px solid #d9dee7;border-radius:14px;background:#f8fbff;padding:13px;text-decoration:none;color:#101828;font-weight:1000}.settings-quick span{font-size:12px;color:#667085;font-weight:700;line-height:1.4}.settings-items{display:grid;gap:8px;margin-top:10px}.settings-item{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #e3e8f0;border-radius:12px;padding:9px 11px;background:#fbfcff}.settings-item strong{font-size:14px}.settings-item span{color:#667085;font-size:12px;font-weight:900}.report-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.58);opacity:0;pointer-events:none;transition:.18s opacity;z-index:900}.report-modal-backdrop.open{opacity:1;pointer-events:auto}.report-modal{position:fixed;inset:32px 42px;background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.35);display:flex;flex-direction:column;opacity:0;transform:translateY(14px) scale(.98);pointer-events:none;transition:.18s opacity,.18s transform;z-index:901;overflow:hidden}.report-modal.open{opacity:1;transform:none;pointer-events:auto}.report-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #d9dee7;background:#f8fafc}.report-modal-title{font-size:18px;font-weight:1000}.report-modal-title span{display:block;margin-top:3px;font-size:13px;font-weight:700;color:#667085}.report-modal-actions{display:flex;align-items:center;gap:8px}.report-modal-close{width:38px;height:38px;border:0;border-radius:999px;background:#101828;color:#fff;font-size:22px;line-height:1;cursor:pointer}.report-modal-frame{width:100%;height:100%;border:0;flex:1;background:#eef2f7}@media(max-width:1300px){.average-list{grid-template-columns:repeat(6,minmax(108px,1fr))}}@media(max-width:1100px){.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.guide-grid{grid-template-columns:1fr}.average-list{grid-template-columns:repeat(3,minmax(108px,1fr));min-width:0}}@media(max-width:760px){.wrap{padding:18px 12px 32px}.title-row{align-items:flex-start;flex-direction:column}.title-row h1{font-size:28px}.summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.filters{display:grid}.filters>*{width:100%}.average-list{grid-template-columns:repeat(2,minmax(0,1fr))}.card-grid{grid-template-columns:1fr}.sticky-save{align-items:stretch;flex-direction:column}.input-actions{justify-content:stretch}.sticky-save .btn{width:100%}.table-wrap{display:none}.settings-quick{grid-template-columns:1fr}.fitness-settings-modal{right:12px;left:12px;top:64px;width:auto}.report-modal{inset:10px}.report-modal-head{align-items:flex-start;flex-direction:column}.report-modal-actions{width:100%;justify-content:flex-end}}@page{size:A4 landscape;margin:8mm}@media print{body{background:#fff}.ieum-top,.ieum-subnav-wrap,.filters,.mode-tabs,.btn,.guide-grid,.summary,.report-modal,.report-modal-backdrop,.fitness-settings-backdrop,.fitness-settings-modal{display:none!important}.wrap{max-width:none;margin:0;padding:0}.page-head{display:block}.title-row h1{font-size:18pt}.meta{font-size:9pt}.panel{box-shadow:none;border:0;padding:0;margin:0}.input-panel{display:block}.sticky-save{display:none}.table-wrap{display:block;overflow:visible}table{min-width:0;width:100%;table-layout:fixed}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}th,td{padding:2.2mm 1.1mm;font-size:7.2pt;line-height:1.25;border-color:#9aa4b2}th{background:#e9eff8!important;color:#111827!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.student-name{font-size:8pt}.sub,.score-mini{font-size:6.2pt}.metric,.body-metric{width:100%;max-width:16mm}.memo{min-width:0}.total{font-size:9pt}.input-panel th:last-child,.input-panel td:last-child{display:none}.input-panel th:nth-child(1),.input-panel td:nth-child(1){width:30mm}.input-panel th:nth-child(4),.input-panel td:nth-child(4){width:15mm}.input-panel th:nth-last-child(2),.input-panel td:nth-last-child(2){width:15mm}}
.average-card .average-list{grid-template-columns:repeat(6,minmax(108px,1fr));min-width:696px}
@media(max-width:760px){.average-card .average-list{grid-template-columns:repeat(6,minmax(108px,1fr));min-width:696px}}
.fitness-settings-modal{left:50%!important;right:auto!important;top:50%!important;width:min(680px,calc(100vw - 36px))!important;max-height:calc(100vh - 80px)!important;transform:translate(-50%,-46%) scale(.98)!important}
.fitness-settings-modal.open{transform:translate(-50%,-50%) scale(1)!important}
.settings-quick a{padding:14px!important}
.settings-quick a:before{content:'⚙';display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#eaf4ff;color:#1769c2}
.settings-items{grid-template-columns:repeat(2,minmax(0,1fr))}
.settings-item span{text-align:right}
.sticky-save{position:static!important;margin:-18px -18px 16px!important;background:#fff!important}
.input-title{display:grid;gap:4px}
.input-title strong{font-size:20px!important}
.input-title span{color:#667085;font-size:13px;font-weight:800}
.input-actions .settings-btn{background:#f8fbff;border-color:#b7d4f6;color:#145da8}
@media(max-width:760px){.fitness-settings-modal{width:calc(100vw - 24px)!important;max-height:calc(100vh - 48px)!important}.settings-quick,.settings-items{grid-template-columns:1fr}}
.fitness-page-tune .page-head{gap:14px}
.fitness-page-tune .title-row h1{font-size:30px}
.fitness-page-tune .summary{grid-template-columns:repeat(4,minmax(0,1fr))}
.fitness-page-tune .summary-card,.fitness-page-tune .panel,.fitness-page-tune .guide-card{border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.fitness-page-tune .guide-grid{grid-template-columns:minmax(300px,420px) minmax(0,1fr)}
.fitness-page-tune .average-card{min-width:0}
.fitness-page-tune .average-card .average-list{grid-template-columns:repeat(6,minmax(108px,1fr));min-width:696px}
.fitness-page-tune .average-item .avg-head{padding:7px 8px}
.fitness-page-tune .average-item .avg-head span{white-space:nowrap}
.fitness-page-tune .average-item dl{grid-template-columns:50px 1fr}
.fitness-page-tune .average-item dt,.fitness-page-tune .average-item dd{padding:5px 6px}
.fitness-page-tune .input-panel{margin-top:18px}
.fitness-page-tune .sticky-save{border-radius:16px 16px 0 0}
.fitness-page-tune .btn{border-radius:10px}
.fitness-page-tune .table-wrap{position:relative;border:1px solid #d8dee9;border-radius:0 0 16px 16px;background:#fff;overflow-x:auto;overflow-y:visible;max-width:100%;padding-bottom:10px;scrollbar-gutter:stable}
.fitness-page-tune .table-wrap:before{content:'좌우로 밀어 전체 항목을 확인할 수 있습니다';position:sticky;left:14px;top:0;display:inline-flex;margin:10px 0 8px 10px;padding:6px 10px;border-radius:999px;background:#eef4ff;color:#1d5ea8;font-size:12px;font-weight:900;z-index:2}
.fitness-page-tune .table-wrap table{min-width:1540px;table-layout:fixed}
.fitness-page-tune .table-wrap th,.fitness-page-tune .table-wrap td{padding:9px 8px}
.fitness-page-tune .table-wrap th:first-child{width:230px}
.fitness-page-tune .table-wrap td.left{width:230px;min-width:230px;line-height:1.35;word-break:keep-all}
.fitness-page-tune .student-name{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fitness-page-tune .table-wrap td.left .sub{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fitness-page-tune .metric{width:74px}
.fitness-page-tune .body-metric{width:72px}
.fitness-page-tune .memo{min-width:150px}
.fitness-page-tune .score-mini{line-height:1.25;word-break:keep-all}
@media(max-width:1180px){.fitness-page-tune .summary{grid-template-columns:repeat(2,minmax(0,1fr))}.fitness-page-tune .guide-grid{grid-template-columns:1fr}}
@media(max-width:700px){.fitness-page-tune .summary{grid-template-columns:1fr}}
/* 2026-05-30 calm pass: keep reference data compact so the input table stays dominant. */
.fitness-page-tune .guide-grid{
    grid-template-columns:minmax(340px,520px) minmax(0,1fr)!important;
    gap:14px!important;
}
.fitness-page-tune .guide-card{
    box-shadow:none!important;
    border-color:#dfe5ee!important;
}
.fitness-page-tune .average-card{
    max-width:100%!important;
    overflow:hidden!important;
}
.fitness-page-tune .average-card h2{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px!important;
    word-break:keep-all;
}
.fitness-page-tune .average-card h2:after{
    content:'성장 참고용';
    border-radius:999px;
    background:#eef4ff;
    color:#1d5ea8;
    padding:4px 9px;
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
}
.fitness-page-tune .average-card .average-list{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(138px,1fr))!important;
    min-width:0!important;
    max-height:154px;
    overflow:auto;
    padding-right:4px;
}
.fitness-page-tune .average-item{
    border-radius:11px!important;
}
.fitness-page-tune .average-item .avg-head{
    min-height:32px;
    padding:6px 8px!important;
}
.fitness-page-tune .average-item dl{
    grid-template-columns:48px 1fr!important;
}
.fitness-page-tune .average-item dt,
.fitness-page-tune .average-item dd{
    padding:4px 6px!important;
    font-size:12px!important;
}
.fitness-page-tune .input-panel{
    border-color:#cfd8e6!important;
}
.fitness-page-tune .sticky-save{
    top:58px!important;
    z-index:30!important;
    border-radius:16px 16px 0 0!important;
    margin:-18px -18px 12px!important;
    padding:13px 18px!important;
}
.fitness-page-tune .input-actions .settings-btn{
    order:-1;
}
@media(max-width:1180px){
    .fitness-page-tune .guide-grid{grid-template-columns:1fr!important}
    .fitness-page-tune .average-card .average-list{max-height:190px}
}
@media(max-width:1500px){
    .fitness-page-tune .guide-grid{grid-template-columns:1fr!important}
    .fitness-page-tune .average-card .average-list{max-height:154px}
}
@media(max-width:700px){
    .fitness-page-tune .average-card .average-list{grid-template-columns:repeat(2,minmax(0,1fr))!important;max-height:240px}
}
.fitness-page-tune .fitness-guide-details{
    margin-top:14px;
    background:#fff;
    border:1px solid #d9dee7;
    border-radius:16px;
    box-shadow:0 8px 20px rgba(15,23,42,.045);
    overflow:hidden;
}
.fitness-page-tune .fitness-guide-details>summary{
    list-style:none;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:13px 18px;
    cursor:pointer;
    color:#101828;
    font-weight:1000;
}
.fitness-page-tune .fitness-guide-details>summary::-webkit-details-marker{display:none}
.fitness-page-tune .fitness-guide-details>summary:before{
    content:'▸';
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:24px;
    height:24px;
    border-radius:999px;
    background:#eef4ff;
    color:#1d6fc9;
    font-size:13px;
    flex:0 0 auto;
}
.fitness-page-tune .fitness-guide-details[open]>summary:before{content:'▾'}
.fitness-page-tune .fitness-guide-details>summary span{
    display:flex;
    align-items:center;
    gap:8px;
    margin-right:auto;
}
.fitness-page-tune .fitness-guide-details>summary strong{
    border-radius:999px;
    background:#f3f6fb;
    color:#667085;
    padding:5px 10px;
    font-size:12px;
    white-space:nowrap;
}
.fitness-page-tune .fitness-guide-details .guide-grid{
    margin-top:0!important;
    padding:0 16px 16px;
}
.fitness-page-tune .fitness-guide-details:not([open])+.input-panel{
    margin-top:14px!important;
}
/* 2026-05-31 easy-mode pass: 체력은 측정 입력표와 지금 저장 버튼을 먼저 보이게 */
.fitness-page-tune .page-head{
    grid-template-columns:minmax(0,1fr) minmax(420px,.62fr)!important;
    gap:12px!important;
}
.fitness-page-tune .title-row{
    margin-bottom:10px!important;
}
.fitness-page-tune .summary{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:8px!important;
}
.fitness-page-tune .summary-card{
    padding:12px 14px!important;
    box-shadow:none!important;
}
.fitness-page-tune .summary-card strong{
    font-size:26px!important;
}
.fitness-page-tune .filters{
    gap:8px!important;
}
.fitness-page-tune .mode-tabs{
    gap:7px!important;
}
.fitness-page-tune .mode-tab{
    min-height:36px!important;
    padding:7px 12px!important;
}
.fitness-page-tune .fitness-guide-details{
    border-style:dashed!important;
    box-shadow:none!important;
    background:#fbfcfe!important;
}
.fitness-page-tune .fitness-guide-details>summary{
    min-height:44px!important;
    padding:10px 14px!important;
}
.fitness-page-tune .fitness-guide-details .guide-grid{
    padding:0 14px 14px!important;
}
.fitness-page-tune .input-panel{
    margin-top:14px!important;
    border-radius:20px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
.fitness-page-tune .sticky-save{
    margin:-18px -18px 10px!important;
    padding:12px 16px!important;
}
.fitness-page-tune .input-actions .settings-btn{
    border-color:#d5dee9!important;
    background:#fff!important;
}
.fitness-page-tune .table-wrap th{
    background:#f3f6fb!important;
    color:#334155!important;
}
/* 2026-05-31 input-table pass: full-table is PC focused, metric tabs are the easy/mobile path. */
.fitness-page-tune .table-wrap{
    border-radius:0 0 18px 18px!important;
    overflow:auto!important;
}
.fitness-page-tune .table-wrap:before{
    content:'PC 전체 입력표입니다. 모바일이나 현장 입력은 위 항목 버튼을 눌러 카드형으로 입력하세요'!important;
    background:#eef8f2!important;
    color:#176b2c!important;
}
.fitness-page-tune .table-wrap table{
    min-width:1280px!important;
}
.fitness-page-tune .table-wrap th,
.fitness-page-tune .table-wrap td{
    padding:7px 6px!important;
    font-size:13px!important;
}
.fitness-page-tune .table-wrap th:first-child,
.fitness-page-tune .table-wrap td.left{
    width:190px!important;
    min-width:190px!important;
    position:sticky;
    left:0;
    z-index:3;
    background:#fff;
    box-shadow:1px 0 0 #d8dee9;
}
.fitness-page-tune .table-wrap th:first-child{
    background:#f3f6fb!important;
    z-index:4;
}
.fitness-page-tune .student-name{
    font-size:14px!important;
}
.fitness-page-tune .metric,
.fitness-page-tune .body-metric{
    width:68px!important;
    padding:7px 5px!important;
}
.fitness-page-tune .memo{
    min-width:116px!important;
}
.fitness-page-tune .score-mini{
    font-size:10px!important;
}
.fitness-page-tune .card-grid{
    grid-template-columns:repeat(3,minmax(0,1fr));
}
.fitness-page-tune .fitness-card{
    box-shadow:0 8px 18px rgba(15,23,42,.05);
}
@media(max-width:1280px){
    .fitness-page-tune .page-head{grid-template-columns:1fr!important}
    .fitness-page-tune .card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:760px){
    .fitness-page-tune .table-wrap{display:block!important;border:1px dashed #b6c7dd!important;background:#f8fbff!important;padding:12px!important}
    .fitness-page-tune .table-wrap table{display:none!important}
    .fitness-page-tune .table-wrap:before{position:static!important;margin:0!important;display:flex!important;white-space:normal!important;line-height:1.45}
    .fitness-page-tune .card-grid{grid-template-columns:1fr!important}
}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.fitness-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
.fitness-page-tune .side-brand-mark,
.fitness-page-tune .side-profile,
.fitness-page-tune .side-search,
.fitness-page-tune .ieum-right-rail{display:none!important}
.fitness-page-tune .side-nav{padding:0 14px 22px!important}
.fitness-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.fitness-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.fitness-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.fitness-page-tune .ieum-shell-link,
.fitness-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.fitness-page-tune .ieum-shell-link::before{display:none!important}
.fitness-page-tune .ieum-shell-meta{color:#0f172a!important}
.fitness-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fitness-page-tune .dashboard-shell-divider,
.fitness-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune{--ieum-side-width:0px}
    .fitness-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
@media print{
    .fitness-page-tune .ieum-shell-top,
    .fitness-page-tune .ieum-side{display:none!important}
    body.ieum-side-layout.ieum-dashboard-page.fitness-page-tune .wrap{margin:0!important;padding:0!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page fitness-page-tune">
<?php echo ieum_admin_header('fitness', 'side'); ?>
<main class="wrap">
    <section class="page-head">
        <div>
            <div class="title-row">
                <div>
                    <h1>체력 입력</h1>
                    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> · 실제 측정값을 입력하면 학년/성별 기준으로 자동 환산됩니다.</div>
                </div>
                <div class="head-actions">
                    <button type="button" class="btn dark" onclick="window.print()">인쇄</button>
                </div>
            </div>
            <?php if ($message !== '') { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
            <?php if ($error !== '') { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>
            <div class="summary">
                <article class="summary-card"><span>조회 원생</span><strong><?php echo number_format(count($students)); ?>명</strong></article>
                <article class="summary-card"><span>입력 완료</span><strong><?php echo number_format($saved_count); ?>명</strong></article>
                <article class="summary-card"><span>BMI 입력</span><strong><?php echo number_format($bmi_count); ?>명</strong></article>
                <article class="summary-card"><span>평균 체력점수</span><strong><?php echo number_format($avg_total, 1); ?>점</strong></article>
            </div>
        </div>
        <aside class="panel">
            <form method="get" class="filters">
                <input type="month" name="month" value="<?php echo get_text($month); ?>">
                <select name="program_code">
                    <option value="">전체 프로그램</option>
                    <?php foreach ($program_options as $program) { ?>
                    <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                    <?php } ?>
                </select>
                <select name="class_time_id">
                    <option value="0">전체 부</option>
                    <?php foreach ($class_options as $class) { ?>
                    <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></option>
                    <?php } ?>
                </select>
                <select name="metric_key">
                    <?php foreach ($metric_options as $key => $option) { ?>
                    <option value="<?php echo get_text($key); ?>" <?php echo get_selected($metric_key, $key); ?>><?php echo get_text($option['label']); ?></option>
                    <?php } ?>
                </select>
                <button type="submit" class="btn primary">조회</button>
            </form>
            <div class="mode-tabs">
                <?php foreach ($metric_options as $key => $option) {
                    $url = IEUM_URL . '/admin/fitness.php?month=' . rawurlencode($month) . '&program_code=' . rawurlencode($program_code) . '&class_time_id=' . (int) $class_time_id . '&metric_key=' . rawurlencode($key);
                ?>
                <a class="<?php echo $metric_key === $key ? 'active' : ''; ?>" href="<?php echo $url; ?>"><?php echo get_text($option['label']); ?></a>
                <?php } ?>
            </div>
        </aside>
    </section>

    <details class="fitness-guide-details">
        <summary>
            <span>측정 안내와 평균 키/몸무게 참고</span>
            <strong>필요할 때 펼쳐 보기</strong>
        </summary>
    <section class="guide-grid">
        <article class="guide-card">
            <h2><?php echo get_text($selected_metric['label']); ?> 측정 안내</h2>
            <p><strong>입력 방식:</strong> PC에서는 전체 항목 표로 빠르게 입력하고, 모바일에서는 항목 버튼을 눌러 카드형으로 입력합니다.</p>
            <p><strong>기준:</strong> 지도진은 실제 측정값만 입력합니다. 점수와 성장 해석은 원생의 학년/성별 기준표에 맞춰 자동 계산됩니다.</p>
            <?php if ($metric_key !== 'all' && $metric_key !== 'body') { ?>
            <p><strong>이번 항목:</strong> <?php echo get_text($selected_metric['hint']); ?></p>
            <?php } else { ?>
            <p><strong>BMI:</strong> 키와 몸무게는 체력 점수에 포함하지 않고 성장 참고 정보로만 사용합니다.</p>
            <?php } ?>
        </article>
        <article class="guide-card average-card">
            <h2>초등 평균 키/몸무게 참고</h2>
            <div class="average-list">
                <?php foreach ($body_averages as $group) { ?>
                    <?php foreach ($group['rows'] as $avg) { ?>
                    <article class="average-item">
                        <div class="avg-head"><span><?php echo get_text(ieum_admin_fitness_display_label($group['label'])); ?></span><strong><?php echo get_text($avg['grade']); ?></strong></div>
                        <dl>
                            <dt>평균 키</dt><dd><?php echo number_format((float) $avg['height'], 1); ?>cm</dd>
                            <dt>몸무게</dt><dd><?php echo number_format((float) $avg['weight'], 1); ?>kg</dd>
                        </dl>
                    </article>
                    <?php } ?>
                <?php } ?>
            </div>
        </article>
    </section>
    </details>

    <form method="post" class="panel input-panel">
        <div class="sticky-save">
            <div class="input-title">
                <strong><?php echo get_text($selected_metric['label']); ?> 입력표</strong>
                <span>측정값을 입력하다가 항목이나 기준을 바꿔야 하면 여기에서 바로 설정을 엽니다.</span>
            </div>
            <div class="input-actions">
                <button type="button" class="btn settings-btn" id="fitnessSettingsOpen">⚙ 항목 설정</button>
                <button type="submit" class="btn primary">지금 저장</button>
            </div>
        </div>
        <input type="hidden" name="ieum_token" value="<?php echo get_text($token); ?>">
        <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
        <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
        <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
        <input type="hidden" name="metric_key" value="<?php echo get_text($metric_key); ?>">

        <?php if ($metric_key !== 'all') { ?>
        <section class="card-grid">
            <?php foreach ($students as $student) {
                $sid = (int) $student['student_id'];
                $row = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
                $calc = isset($fitness_calc_map[$sid]) ? $fitness_calc_map[$sid] : ieum_fitness_calculate($row, $student, $academy_id, $month);
                $class_label = trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: ''));
            ?>
            <article class="fitness-card" id="student-<?php echo $sid; ?>">
                <div>
                    <div class="name"><?php echo get_text($student['student_name']); ?></div>
                    <div class="sub"><?php echo get_text($student['student_code'] . ' · ' . ieum_admin_fitness_grade_label($student['grade_group']) . ' · ' . $class_label); ?></div>
                    <div class="sub"><?php echo get_text(ieum_admin_fitness_context_label($student, $month)); ?></div>
                </div>
                <?php if ($metric_key === 'body') { ?>
                <div class="metric-title">키/몸무게</div>
                <div class="body-pair">
                    <label>키 cm<input type="number" step="0.1" name="fitness[<?php echo $sid; ?>][height_cm]" value="<?php echo get_text(ieum_admin_fitness_value($row, 'height_cm')); ?>"></label>
                    <label>몸무게 kg<input type="number" step="0.1" name="fitness[<?php echo $sid; ?>][weight_kg]" value="<?php echo get_text(ieum_admin_fitness_value($row, 'weight_kg')); ?>"></label>
                </div>
                <span class="level-chip">BMI <?php echo $calc['bmi'] === null ? '미입력' : number_format((float) $calc['bmi'], 1) . ' · ' . get_text(ieum_admin_fitness_bmi_label($calc['bmi_label'])); ?></span>
                <?php } else {
                    $option = $metric_options[$metric_key];
                    $step = isset($option['step']) ? (float) $option['step'] : 1;
                    $item_score = isset($calc['item_scores'][$metric_key]) ? (float) $calc['item_scores'][$metric_key] : 0;
                    $item_level = isset($calc['item_levels'][$metric_key]) ? $calc['item_levels'][$metric_key] : array();
                ?>
                <div class="metric-title"><?php echo get_text($option['label']); ?> <span class="sub"><?php echo get_text($option['hint']); ?></span></div>
                <div class="stepper" data-step="<?php echo get_text($step); ?>">
                    <button type="button" class="minus">-</button>
                    <input type="number" step="<?php echo get_text($step); ?>" min="<?php echo $metric_key === 'flexibility' ? '-50' : '0'; ?>" name="fitness[<?php echo $sid; ?>][<?php echo get_text($metric_key); ?>]" value="<?php echo get_text(ieum_admin_fitness_value($row, $metric_key)); ?>" aria-label="<?php echo get_text($option['label']); ?> 측정값">
                    <button type="button" class="plus">+</button>
                </div>
                <div class="auto-result">
                    <span>자동점수<b><?php echo number_format($item_score, 1); ?>점</b></span>
                    <span>성장해석<b><?php echo get_text(ieum_admin_fitness_level_label($item_level)); ?></b></span>
                </div>
                <?php } ?>
                <input type="text" name="fitness[<?php echo $sid; ?>][memo]" value="<?php echo get_text(isset($row['memo']) ? $row['memo'] : ''); ?>" placeholder="메모">
                <?php if ($row) { ?>
                <a class="btn fitness-report-modal-open" href="<?php echo IEUM_URL; ?>/admin/fitness_parent_report.php?month=<?php echo rawurlencode($month); ?>&amp;student_id=<?php echo $sid; ?>" data-student-name="<?php echo get_text($student['student_name']); ?>" data-student-code="<?php echo get_text($student['student_code']); ?>">리포트 보기</a>
                <?php } ?>
            </article>
            <?php } ?>
        </section>
        <?php } else { ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>원생</th>
                        <th>키<span class="score-mini">cm</span></th>
                        <th>몸무게<span class="score-mini">kg</span></th>
                        <th>BMI</th>
                        <?php foreach ($items as $item) { ?><th><?php echo get_text($item['label']); ?><span class="score-mini"><?php echo get_text($item['unit']); ?></span></th><?php } ?>
                        <th>메모</th>
                        <th>환산</th>
                        <th>최종</th>
                        <th>리포트</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student) {
                    $sid = (int) $student['student_id'];
                    $row = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
                    $calc = isset($fitness_calc_map[$sid]) ? $fitness_calc_map[$sid] : ieum_fitness_calculate($row, $student, $academy_id, $month);
                    $class_label = trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: ''));
                ?>
                    <tr id="student-<?php echo $sid; ?>">
                        <td class="left">
                            <span class="student-name"><?php echo get_text($student['student_name']); ?></span>
                            <span class="sub"><?php echo get_text($student['student_code'] . ' · ' . ieum_admin_fitness_grade_label($student['grade_group'])); ?></span>
                            <span class="sub"><?php echo get_text(ieum_admin_fitness_context_label($student, $month)); ?></span>
                            <span class="sub"><?php echo get_text($class_label); ?></span>
                        </td>
                        <td><input class="body-metric" type="number" step="0.1" name="fitness[<?php echo $sid; ?>][height_cm]" value="<?php echo get_text(ieum_admin_fitness_value($row, 'height_cm')); ?>"></td>
                        <td><input class="body-metric" type="number" step="0.1" name="fitness[<?php echo $sid; ?>][weight_kg]" value="<?php echo get_text(ieum_admin_fitness_value($row, 'weight_kg')); ?>"></td>
                        <td><?php echo $calc['bmi'] === null ? '-' : number_format((float) $calc['bmi'], 1); ?><span class="score-mini"><?php echo get_text(ieum_admin_fitness_bmi_label($calc['bmi_label'])); ?></span></td>
                        <?php foreach ($items as $key => $item) {
                            $item_step = isset($item['step']) ? (float) $item['step'] : 1;
                            $item_score = isset($calc['item_scores'][$key]) ? (float) $calc['item_scores'][$key] : 0;
                            $item_level = isset($calc['item_levels'][$key]) ? $calc['item_levels'][$key] : array();
                        ?>
                        <td>
                            <input class="metric" type="number" step="<?php echo get_text($item_step); ?>" min="<?php echo $key === 'flexibility' ? '-50' : '0'; ?>" name="fitness[<?php echo $sid; ?>][<?php echo get_text($key); ?>]" value="<?php echo get_text(ieum_admin_fitness_value($row, $key)); ?>">
                            <span class="score-mini"><?php echo number_format($item_score, 1); ?>점 · <?php echo get_text(ieum_admin_fitness_level_label($item_level)); ?></span>
                        </td>
                        <?php } ?>
                        <td><input class="memo" type="text" name="fitness[<?php echo $sid; ?>][memo]" value="<?php echo get_text(isset($row['memo']) ? $row['memo'] : ''); ?>" placeholder="특이사항"></td>
                        <td><?php echo number_format((float) $calc['item_total'], 1); ?>/60</td>
                        <td class="total"><?php echo number_format((float) $calc['total'], 1); ?></td>
                        <td><?php if ($row) { ?><a class="btn fitness-report-modal-open" href="<?php echo IEUM_URL; ?>/admin/fitness_parent_report.php?month=<?php echo rawurlencode($month); ?>&amp;student_id=<?php echo $sid; ?>" data-student-name="<?php echo get_text($student['student_name']); ?>" data-student-code="<?php echo get_text($student['student_code']); ?>">보기</a><?php } else { ?><span class="sub">측정 전</span><?php } ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$students) { ?><tr><td colspan="<?php echo count($items) + 8; ?>" class="empty">조건에 맞는 원생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.fitness-page-tune.ieum-dashboard-page';
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
<div class="fitness-settings-backdrop" id="fitnessSettingsBackdrop"></div>
<section class="fitness-settings-modal" id="fitnessSettingsModal" role="dialog" aria-modal="true" aria-labelledby="fitnessSettingsTitle">
    <div class="settings-head">
        <div>
            <h2 id="fitnessSettingsTitle">체력 항목 설정</h2>
            <p class="meta">현재 입력 화면에서 쓰는 항목, 단위, 기준표를 바로 확인하고 수정합니다.</p>
        </div>
        <button type="button" class="settings-close" id="fitnessSettingsClose" aria-label="닫기">×</button>
    </div>
    <div class="settings-quick">
        <a href="<?php echo IEUM_URL; ?>/admin/fitness_standards.php">
            항목/단위 설정
            <span>줄넘기, 셔틀런, cm 같은 표시와 사용 여부를 관리합니다.</span>
        </a>
        <a href="<?php echo IEUM_URL; ?>/admin/fitness_standards.php?metric_key=<?php echo rawurlencode($metric_key === 'all' || $metric_key === 'body' ? 'jump_rope' : $metric_key); ?>">
            기준표 수정
            <span>학년/성별 환산 기준과 현장 맞춤 기준을 조정합니다.</span>
        </a>
    </div>
    <strong>현재 사용 항목</strong>
    <div class="settings-items">
        <article class="settings-item"><strong>키/몸무게/BMI</strong><span>cm · kg · 자동 BMI</span></article>
        <?php foreach ($items as $key => $item) { ?>
        <article class="settings-item">
            <strong><?php echo get_text($item['label']); ?></strong>
            <span><?php echo get_text($item['unit'] !== '' ? $item['unit'] : '단위 없음'); ?> · <?php echo get_text(isset($item['hint']) ? $item['hint'] : '측정값 입력'); ?></span>
        </article>
        <?php } ?>
    </div>
</section>
<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('.plus, .minus');
    if (!button) return;
    const stepper = button.closest('.stepper');
    const input = stepper ? stepper.querySelector('input') : null;
    if (!input) return;
    const step = Number(stepper.dataset.step || input.step || 1) || 1;
    const current = input.value === '' ? 0 : Number(input.value);
    const next = current + (button.classList.contains('plus') ? step : -step);
    const min = input.min === '' ? -Infinity : Number(input.min);
    input.value = String(Math.max(min, Math.round(next * 10) / 10));
});
</script>
<script>
(function () {
    const open = document.getElementById('fitnessSettingsOpen');
    const close = document.getElementById('fitnessSettingsClose');
    const backdrop = document.getElementById('fitnessSettingsBackdrop');
    const modal = document.getElementById('fitnessSettingsModal');
    if (!open || !close || !backdrop || !modal) return;

    function showSettings() {
        backdrop.classList.add('open');
        modal.classList.add('open');
    }

    function hideSettings() {
        backdrop.classList.remove('open');
        modal.classList.remove('open');
        open.focus();
    }

    open.addEventListener('click', showSettings);
    close.addEventListener('click', hideSettings);
    backdrop.addEventListener('click', hideSettings);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            hideSettings();
        }
    });
})();
</script>
<script>
(function () {
    let modal = null;
    let backdrop = null;
    let frame = null;
    let openLink = null;

    function ensureReportModal() {
        if (modal) return;
        backdrop = document.createElement('div');
        backdrop.className = 'report-modal-backdrop';
        modal = document.createElement('section');
        modal.className = 'report-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = '<header class="report-modal-head"><div class="report-modal-title">체력 리포트 <span id="fitnessReportSub">원생 체력 리포트를 확인합니다.</span></div><div class="report-modal-actions"><a class="btn" id="fitnessReportNewWindow" href="#" target="_blank" rel="noopener">새 창</a><button type="button" class="btn primary" id="fitnessReportPrint">인쇄</button><button type="button" class="report-modal-close" id="fitnessReportClose" aria-label="닫기">×</button></div></header><iframe class="report-modal-frame" id="fitnessReportFrame" title="체력 리포트"></iframe>';
        document.body.append(backdrop, modal);
        frame = modal.querySelector('#fitnessReportFrame');
        backdrop.addEventListener('click', closeReportModal);
        modal.querySelector('#fitnessReportClose').addEventListener('click', closeReportModal);
        modal.querySelector('#fitnessReportPrint').addEventListener('click', function () {
            if (frame && frame.contentWindow) {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            }
        });
    }

    function openReportModal(link) {
        ensureReportModal();
        openLink = link;
        const studentName = link.dataset.studentName || '원생';
        const studentCode = link.dataset.studentCode ? ' · ' + link.dataset.studentCode : '';
        modal.querySelector('#fitnessReportSub').textContent = studentName + studentCode;
        modal.querySelector('#fitnessReportNewWindow').href = link.href;
        frame.src = link.href;
        document.body.classList.add('report-modal-opened');
        requestAnimationFrame(function () {
            backdrop.classList.add('open');
            modal.classList.add('open');
        });
    }

    function closeReportModal() {
        if (!modal) return;
        backdrop.classList.remove('open');
        modal.classList.remove('open');
        document.body.classList.remove('report-modal-opened');
        setTimeout(function () {
            if (frame && !modal.classList.contains('open')) frame.src = 'about:blank';
        }, 180);
        if (openLink) openLink.focus();
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('.fitness-report-modal-open');
        if (!link) return;
        event.preventDefault();
        openReportModal(link);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && modal.classList.contains('open')) closeReportModal();
    });
})();
</script>
</body>
</html>


