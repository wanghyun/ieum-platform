<?php
$sub_menu = '950180';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 인성 입력';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

ieum_character_ensure_table();

$week_start = isset($_GET['week_start']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['week_start'])) : date('Y-m-d', strtotime('monday this week', strtotime(G5_TIME_YMD)));
if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $week_start)) {
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime(G5_TIME_YMD)));
}
$month = substr($week_start, 0, 7);
$week_sql = sql_escape_string($week_start);
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$class_scope = isset($_GET['class_scope']) ? preg_replace('/[^a-z_]/', '', trim($_GET['class_scope'])) : 'all';
if ($class_time_id > 0) {
    $class_scope = 'class';
} elseif ($class_scope !== 'unassigned') {
    $class_scope = 'all';
}
$focus_student_id = isset($_GET['focus_student_id']) ? (int) $_GET['focus_student_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $week_start = isset($_POST['week_start']) ? preg_replace('/[^0-9\-]/', '', trim($_POST['week_start'])) : $week_start;
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $week_start)) {
            $week_start = date('Y-m-d', strtotime('monday this week', strtotime(G5_TIME_YMD)));
        }
        $month = substr($week_start, 0, 7);
        $week_sql = sql_escape_string($week_start);
        $program_code = isset($_POST['program_code']) ? ieum_program_code($_POST['program_code']) : '';
        $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : 0;
        $class_scope = isset($_POST['class_scope']) ? preg_replace('/[^a-z_]/', '', trim($_POST['class_scope'])) : 'all';
        if ($class_time_id > 0) {
            $class_scope = 'class';
        } elseif ($class_scope !== 'unassigned') {
            $class_scope = 'all';
        }
        $focus_student_id = isset($_POST['focus_student_id']) ? (int) $_POST['focus_student_id'] : 0;
        $created_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'save_scores';
        if ($action === 'quick_special') {
            $quick_student_id = isset($_POST['special_student_id']) ? (int) $_POST['special_student_id'] : 0;
            $special_types = isset($_POST['special_type']) && is_array($_POST['special_type']) ? $_POST['special_type'] : array();
            $special_memos = isset($_POST['special_memo']) && is_array($_POST['special_memo']) ? $_POST['special_memo'] : array();
            $student = sql_fetch("
                select student_id, student_name
                  from " . IEUM_STUDENT_TABLE . "
                 where academy_id = '{$academy_id}'
                   and student_id = '{$quick_student_id}'
                   and is_active = 1
                   and coalesce(character_report_enabled, 1) = 1
                 limit 1
            ", false);
            if (!isset($student['student_id'])) {
                $error = '특별 칭찬을 저장할 원생을 찾지 못했습니다.';
            } else {
                $special_type = isset($special_types[$quick_student_id]) ? trim((string) $special_types[$quick_student_id]) : 'attitude';
                $special_memo = isset($special_memos[$quick_student_id]) ? trim((string) $special_memos[$quick_student_id]) : '';
                ieum_character_special_add($academy_id, $quick_student_id, G5_TIME_YMD, $special_type, 1, $special_memo, isset($member['mb_id']) ? $member['mb_id'] : '');
                $message = get_text($student['student_name']) . ' 원생에게 오늘의 특별 칭찬 +1점을 저장했습니다.';
            }
        } else {
        $scores = isset($_POST['scores']) && is_array($_POST['scores']) ? $_POST['scores'] : array();
        $saved = 0;
        foreach ($scores as $student_id => $row) {
            $student_id = (int) $student_id;
            if ($student_id <= 0) {
                continue;
            }
            $student = sql_fetch("
                select student_id
                  from " . IEUM_STUDENT_TABLE . "
                 where academy_id = '{$academy_id}'
                   and student_id = '{$student_id}'
                   and is_active = 1
                   and coalesce(character_report_enabled, 1) = 1
                 limit 1
            ", false);
            if (!isset($student['student_id'])) {
                continue;
            }
            $courtesy = max(1, min(5, isset($row['courtesy']) ? (int) $row['courtesy'] : 3));
            $focus = max(1, min(5, isset($row['focus']) ? (int) $row['focus'] : 3));
            $confidence = max(1, min(5, isset($row['confidence']) ? (int) $row['confidence'] : 3));
            $consideration = max(1, min(5, isset($row['consideration']) ? (int) $row['consideration'] : 3));
            $memo = isset($row['memo']) ? trim($row['memo']) : '';
            sql_query("
                insert into " . IEUM_REPORT_CHARACTER_TABLE . "
                    set academy_id = '{$academy_id}',
                        student_id = '{$student_id}',
                        week_start = '{$week_sql}',
                        courtesy = '{$courtesy}',
                        focus = '{$focus}',
                        confidence = '{$confidence}',
                        consideration = '{$consideration}',
                        special_score = 0,
                        special_reason = '',
                        memo = '" . sql_escape_string($memo) . "',
                        created_by = '{$created_by}',
                        created_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        courtesy = values(courtesy),
                        focus = values(focus),
                        confidence = values(confidence),
                        consideration = values(consideration),
                        special_score = 0,
                        special_reason = '',
                        memo = values(memo),
                        updated_at = '" . G5_TIME_YMDHIS . "'
            ");
            $saved++;
        }
        $message = '인성 점수 ' . number_format($saved) . '명을 저장했습니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$class_options = array();
$classes = sql_query("
    select class_time_id, class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($classes)) {
    $class_options[] = $class;
}
$program_filter_sql = '';
if ($program_code !== '') {
    $program_sql = sql_escape_string($program_code);
    $program_filter_sql = " and s.program_code = '{$program_sql}' ";
}
if ($focus_student_id > 0 && $class_time_id <= 0 && $class_scope === 'all') {
    $focus_student = sql_fetch("
        select class_time_id
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$focus_student_id}'
           and is_active = 1
         limit 1
    ", false);
    if (isset($focus_student['class_time_id']) && (int) $focus_student['class_time_id'] > 0) {
        $class_time_id = (int) $focus_student['class_time_id'];
        $class_scope = 'class';
    } else {
        $class_scope = 'unassigned';
    }
}
$class_filter_sql = '';
if ($class_time_id > 0) {
    $class_filter_sql = " and s.class_time_id = '{$class_time_id}' ";
} elseif ($class_scope === 'unassigned') {
    $class_filter_sql = " and coalesce(s.class_time_id, 0) = 0 ";
}
$selected_class_label = '전체 부';
foreach ($class_options as $class) {
    if ((int) $class['class_time_id'] === $class_time_id) {
        $selected_class_label = trim($class['class_name'] . ' ' . $class['start_time']);
        break;
    }
}
if ($class_scope === 'unassigned') {
    $selected_class_label = '미지정';
}
$students_result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.program_code, s.grade_group, s.class_time_id, c.class_name, c.start_time,
           coalesce(r.courtesy, 3) as courtesy,
           coalesce(r.focus, 3) as focus,
           coalesce(r.confidence, 3) as confidence,
           coalesce(r.consideration, 3) as consideration,
           coalesce(r.memo, '') as character_memo
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_REPORT_CHARACTER_TABLE . " r on r.student_id = s.student_id and r.academy_id = s.academy_id and r.week_start = '{$week_sql}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
$student_rows = array();
while ($row = sql_fetch_array($students_result)) {
    $student_rows[] = $row;
}

$student_count = count($student_rows);
$week_saved_count = 0;
$today_special_count = 0;
$month_special_count = 0;
$disabled_count = 0;
$student_ids = array();
foreach ($student_rows as $row) {
    $student_ids[] = (int) $row['student_id'];
    if ((int) $row['courtesy'] !== 3 || (int) $row['focus'] !== 3 || (int) $row['confidence'] !== 3 || (int) $row['consideration'] !== 3 || trim((string) $row['character_memo']) !== '') {
        $week_saved_count++;
    }
}
if ($student_ids) {
    $student_ids_sql = implode(',', $student_ids);
    $special_counts = sql_fetch("
        select
            sum(case when praise_date = '" . sql_escape_string(G5_TIME_YMD) . "' then 1 else 0 end) as today_count,
            count(*) as month_count
          from " . IEUM_CHARACTER_SPECIAL_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id in ({$student_ids_sql})
           and praise_date between '" . sql_escape_string($month . '-01') . "' and '" . sql_escape_string(date('Y-m-t', strtotime($month . '-01'))) . "'
    ", false);
    $today_special_count = isset($special_counts['today_count']) ? (int) $special_counts['today_count'] : 0;
    $month_special_count = isset($special_counts['month_count']) ? (int) $special_counts['month_count'] : 0;
}
$disabled = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 0
       {$program_filter_sql}
       {$class_filter_sql}
", false);
$disabled_count = isset($disabled['cnt']) ? (int) $disabled['cnt'] : 0;

$class_input_counts = array();
foreach ($class_options as $class) {
    $class_input_counts[(int) $class['class_time_id']] = array(
        'class' => $class,
        'enabled' => 0,
        'saved' => 0,
    );
}
$class_count_result = sql_query("
    select s.student_id, coalesce(s.class_time_id, 0) as class_time_id,
           c.class_name, c.start_time,
           coalesce(r.courtesy, 3) as courtesy,
           coalesce(r.focus, 3) as focus,
           coalesce(r.confidence, 3) as confidence,
           coalesce(r.consideration, 3) as consideration,
           coalesce(r.memo, '') as character_memo
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_REPORT_CHARACTER_TABLE . " r on r.student_id = s.student_id and r.academy_id = s.academy_id and r.week_start = '{$week_sql}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
       {$program_filter_sql}
  order by c.sort_order asc, c.start_time asc
", false);
while ($row = sql_fetch_array($class_count_result)) {
    $cid = isset($row['class_time_id']) ? (int) $row['class_time_id'] : 0;
    if (!isset($class_input_counts[$cid])) {
        $class_input_counts[$cid] = array(
            'class' => array('class_time_id' => $cid, 'class_name' => '미지정', 'start_time' => ''),
            'enabled' => 0,
            'saved' => 0,
        );
    }
    $class_input_counts[$cid]['enabled']++;
    if ((int) $row['courtesy'] !== 3 || (int) $row['focus'] !== 3 || (int) $row['confidence'] !== 3 || (int) $row['consideration'] !== 3 || trim((string) $row['character_memo']) !== '') {
        $class_input_counts[$cid]['saved']++;
    }
}
$show_compact_all_input = !$class_time_id && $student_count > 60;

$report_students = sql_query("
    select s.student_id, s.student_code, s.student_name, s.admission_date, s.attendance_days, s.program_code, s.grade_group, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.wrap.is-loading{opacity:.55;pointer-events:none}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}h1{margin:0;font-size:28px}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:800;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.print{background:#111827;border-color:#111827;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.score{width:86px}.memo{min-width:180px;width:100%}.quick-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#344054;margin-top:12px}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.badge.first{background:#fff4e6;color:#9a5b00}.total{font-size:20px;font-weight:900;color:#1769c2}.class-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.class-tab{display:inline-flex;border:1px solid #d8dee9;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:7px 12px;font-weight:900}.class-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.compact-report summary{cursor:pointer;font-weight:900;color:#1769c2}.compact-report table{margin-top:12px}@media(max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}
.character-summary{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:10px;margin-top:16px}.summary-tile{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:14px;box-shadow:0 8px 18px rgba(15,23,42,.05)}.summary-tile span{display:block;color:#667085;font-size:12px;font-weight:900}.summary-tile strong{display:block;margin-top:6px;font-size:26px;line-height:1}.summary-tile.good{border-color:#bbebc7;background:#f4fbf5}.summary-tile.warn{border-color:#ffd59a;background:#fff9ef}.summary-tile.off{border-color:#d8dee9;background:#f8fafc}.class-input-board{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:16px}.class-input-card{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #d9dee7;border-radius:12px;background:#fff;padding:14px;text-decoration:none;color:#111827;box-shadow:0 8px 18px rgba(15,23,42,.04)}.class-input-card:hover{border-color:#1769c2;background:#f7fbff}.class-input-card.active{border-color:#1769c2;background:#eaf4ff;box-shadow:inset 0 0 0 2px #1769c2,0 10px 22px rgba(23,105,194,.12)}.class-input-card strong{display:block;font-size:16px}.class-input-card span{display:block;margin-top:4px;color:#667085;font-size:12px}.class-input-card.active span{color:#174b85}.class-input-card em{font-style:normal;border-radius:999px;background:#eef2f7;color:#344054;padding:6px 9px;font-size:12px;font-weight:900}.class-input-card.active em{background:#1769c2;color:#fff}.class-input-card.done em{background:#e8f7ee;color:#087f5b}.class-input-card.done.active em{background:#1769c2;color:#fff}.all-input-warning{margin:12px 0;border:1px solid #ffd7a8;border-radius:10px;background:#fffaf0;color:#8a5200;padding:12px;line-height:1.55}.all-input-details{margin-top:12px}.all-input-details>summary{cursor:pointer;border:1px solid #cfd6df;border-radius:10px;background:#fff;padding:13px 14px;font-weight:900}.input-toolbar{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:12px 0}.tool-group{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.mini-btn{min-height:30px;border:1px solid #cfd6df;border-radius:999px;background:#fff;color:#344054;padding:5px 9px;font-size:12px;font-weight:900;cursor:pointer}.mini-btn:hover{border-color:#1769c2;color:#1769c2;background:#edf6ff}.student-name-line{font-weight:900}.student-row-tools{display:flex;gap:4px;flex-wrap:wrap;margin-top:7px}.score-cell{min-width:92px}.special-cell{min-width:230px}.quick-special summary{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border:1px solid #1769c2;border-radius:999px;background:#edf6ff;color:#1769c2;padding:5px 10px;font-size:12px;font-weight:900;cursor:pointer}.quick-special-fields{display:grid;grid-template-columns:104px minmax(120px,1fr) auto;gap:6px;margin-top:7px;align-items:center}.quick-special input{min-width:120px}.quick-special .btn{min-height:34px;padding:6px 9px;font-size:12px}.today-count{display:inline-flex;margin-top:6px;border-radius:999px;background:#eef9f1;color:#176b2c;padding:4px 8px;font-size:12px;font-weight:900}.recent-special{display:block;margin-top:5px;color:#475467;font-size:12px;line-height:1.35}.save-bar{position:sticky;bottom:0;z-index:4;display:flex;justify-content:space-between;align-items:center;gap:10px;margin:14px -18px -18px;padding:12px 18px;background:rgba(255,255,255,.94);border-top:1px solid #e5e7eb;backdrop-filter:blur(8px)}.save-bar strong{font-size:14px}.save-bar span{color:#667085;font-size:12px}@media(max-width:1100px){.character-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.character-summary,.class-input-board{grid-template-columns:1fr}.quick-special-fields{grid-template-columns:1fr}.save-bar{align-items:flex-start;flex-direction:column}.save-bar .btn{width:100%}}
</style>
<style>
.focus-row{background:#eef6ff;box-shadow:inset 4px 0 #1769c2}
.focus-row td{border-top-color:#9cc7ef;border-bottom-color:#9cc7ef}
form.character-input-form{padding-bottom:18px}
.character-page-tune form.character-input-form{overflow-x:auto;max-width:100%}
.character-page-tune form.character-input-form table{min-width:820px;table-layout:fixed}
.character-page-tune form.character-input-form th,
.character-page-tune form.character-input-form td{padding:8px}
.character-page-tune form.character-input-form th:nth-child(1),
.character-page-tune form.character-input-form td:nth-child(1){width:104px}
.character-page-tune form.character-input-form th:nth-child(2),
.character-page-tune form.character-input-form td:nth-child(2){width:60px}
.character-page-tune .score-cell{width:86px;min-width:72px}
.character-page-tune .score{width:100%;min-width:0}
.character-page-tune .special-cell{width:166px;min-width:150px}
.character-page-tune .memo{min-width:120px;width:100%}
.character-page-tune .quick-special summary{min-height:30px;padding:4px 8px}
.character-page-tune .today-count,.character-page-tune .recent-special{font-size:11px}
.character-page-tune .hero h1{font-size:30px;letter-spacing:-.01em}
.character-page-tune .panel,.character-page-tune .summary-tile,.character-page-tune .class-input-card{border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.character-page-tune .filters{background:#fff;border:1px solid #d9dee7;border-radius:16px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.character-page-tune .btn,.character-page-tune input,.character-page-tune select{border-radius:10px}
.character-page-tune .class-tabs{margin-top:14px}
.character-page-tune .class-tab{padding:8px 14px}
.character-page-tune .quick-note{border-radius:14px;background:#fff;border-color:#d9dee7}
.character-page-tune .character-summary{grid-template-columns:repeat(5,minmax(0,1fr))}
.character-page-tune .class-input-board{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.character-page-tune .save-bar{border-radius:0 0 16px 16px}
@media(max-width:1200px){.character-page-tune .character-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
/* 2026-05-31 easy-mode pass: 인성 입력은 부 선택과 입력표가 중심 */
.character-page-tune .hero{
    margin-bottom:10px!important;
}
.character-page-tune .hero .meta{
    font-size:14px!important;
}
.character-page-tune .filters{
    margin-top:10px!important;
    margin-bottom:10px!important;
    padding:10px 12px!important;
    box-shadow:none!important;
}
.character-page-tune .class-tabs{
    gap:7px!important;
    margin-top:10px!important;
}
.character-page-tune .class-tab{
    padding:7px 12px!important;
    font-size:14px!important;
}
.character-page-tune .character-summary{
    grid-template-columns:repeat(5,minmax(0,1fr))!important;
    gap:8px!important;
    margin-top:10px!important;
}
.character-page-tune .summary-tile{
    padding:11px 12px!important;
    box-shadow:none!important;
}
.character-page-tune .summary-tile strong{
    font-size:22px!important;
}
.character-page-tune .quick-note{
    margin:10px 0!important;
    padding:10px 12px!important;
    font-size:13px!important;
}
.character-page-tune .class-input-board{
    display:flex!important;
    gap:8px!important;
    overflow-x:auto!important;
    margin:10px 0 14px!important;
    padding-bottom:3px!important;
}
.character-page-tune .class-input-card{
    flex:0 0 214px!important;
    min-height:62px!important;
    padding:10px 12px!important;
    box-shadow:none!important;
}
.character-page-tune .panel{
    padding:16px!important;
}
.character-page-tune .input-toolbar{
    position:sticky!important;
    top:64px!important;
    z-index:5!important;
    background:#fff!important;
    border:1px solid #e2e8f0!important;
    border-radius:14px!important;
    padding:10px 12px!important;
}
.character-page-tune .compact-report{
    margin-top:14px!important;
}
.character-page-tune .compact-report>h2{
    font-size:18px!important;
}
/* 2026-05-31 table focus pass: keep the input table compact and readable. */
.character-page-tune form.character-input-form table{min-width:860px!important}
.character-page-tune form.character-input-form th{height:42px!important;font-size:13px!important}
.character-page-tune form.character-input-form td{background:#fff}
.character-page-tune form.character-input-form tbody tr:hover td{background:#fbfdff}
.character-page-tune form.character-input-form th:nth-child(1),
.character-page-tune form.character-input-form td:nth-child(1){width:136px!important}
.character-page-tune form.character-input-form th:nth-child(2),
.character-page-tune form.character-input-form td:nth-child(2){width:78px!important}
.character-page-tune form.character-input-form th:nth-child(3),
.character-page-tune form.character-input-form th:nth-child(4),
.character-page-tune form.character-input-form th:nth-child(5),
.character-page-tune form.character-input-form th:nth-child(6),
.character-page-tune form.character-input-form td:nth-child(3),
.character-page-tune form.character-input-form td:nth-child(4),
.character-page-tune form.character-input-form td:nth-child(5),
.character-page-tune form.character-input-form td:nth-child(6){width:66px!important}
.character-page-tune form.character-input-form th:nth-child(7),
.character-page-tune form.character-input-form td:nth-child(7){width:188px!important}
.character-page-tune form.character-input-form th:nth-child(8),
.character-page-tune form.character-input-form td:nth-child(8){width:180px!important}
.character-page-tune .score-cell{min-width:0!important}
.character-page-tune .score-cell select{min-height:38px!important;padding:8px 7px!important;text-align:center;font-weight:900}
.character-page-tune .quick-special summary{width:100%;min-height:34px!important}
.character-page-tune .quick-special-fields{grid-template-columns:1fr!important}
.character-page-tune .quick-special-fields select,
.character-page-tune .quick-special-fields input,
.character-page-tune .quick-special-fields .btn{width:100%}
.character-page-tune .memo{min-width:0!important;min-height:38px!important}
@media(max-width:760px){
    .character-page-tune form.character-input-form table,
    .character-page-tune form.character-input-form thead,
    .character-page-tune form.character-input-form tbody,
    .character-page-tune form.character-input-form tr,
    .character-page-tune form.character-input-form th,
    .character-page-tune form.character-input-form td{display:block;width:100%!important;min-width:0!important}
    .character-page-tune form.character-input-form thead{display:none}
    .character-page-tune form.character-input-form tr{border:1px solid #d9dee7;border-radius:16px;padding:12px;margin:10px 0;background:#fff;box-shadow:0 8px 18px rgba(15,23,42,.05)}
    .character-page-tune form.character-input-form td{border:0;padding:5px 0}
    .character-page-tune form.character-input-form td:nth-child(3),
    .character-page-tune form.character-input-form td:nth-child(4),
    .character-page-tune form.character-input-form td:nth-child(5),
    .character-page-tune form.character-input-form td:nth-child(6){display:inline-block;width:24%!important;vertical-align:top;padding-right:4px}
    .character-page-tune form.character-input-form td:nth-child(3)::before{content:'예절'}
    .character-page-tune form.character-input-form td:nth-child(4)::before{content:'집중'}
    .character-page-tune form.character-input-form td:nth-child(5)::before{content:'자신'}
    .character-page-tune form.character-input-form td:nth-child(6)::before{content:'배려'}
    .character-page-tune form.character-input-form td:nth-child(3)::before,
    .character-page-tune form.character-input-form td:nth-child(4)::before,
    .character-page-tune form.character-input-form td:nth-child(5)::before,
    .character-page-tune form.character-input-form td:nth-child(6)::before{display:block;margin-bottom:4px;color:#667085;font-size:11px;font-weight:900}
}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.character-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.character-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.character-page-tune .side-brand{
    height:144px!important;
    padding:0 28px!important;
    align-items:center!important;
    font-size:30px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
.character-page-tune .side-brand-mark,
.character-page-tune .side-profile,
.character-page-tune .side-search,
.character-page-tune .ieum-right-rail{display:none!important}
.character-page-tune .side-nav{padding:0 14px 22px!important}
.character-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.character-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.character-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:#fff!important;
    border-bottom:1px solid #eef2f7!important;
    color:#0f172a!important;
    box-shadow:none!important;
}
.character-page-tune .ieum-shell-link,
.character-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.character-page-tune .ieum-shell-link::before{display:none!important}
.character-page-tune .ieum-shell-meta{color:#0f172a!important}
.character-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.character-page-tune .dashboard-shell-divider,
.character-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.character-page-tune .wrap{
    max-width:none!important;
    width:auto!important;
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
}
.character-page-tune .hero h1{font-size:30px!important;line-height:1.2!important}
.character-page-tune .panel,
.character-page-tune .summary-tile,
.character-page-tune .class-input-card,
.character-page-tune .filters{border-radius:8px!important;box-shadow:none!important}
.character-page-tune th{background:#f8fafc!important;color:#475569!important;border-color:#e5e7eb!important}
.character-page-tune td{border-color:#eef2f7!important}
.character-page-tune .input-toolbar{top:64px!important;border-radius:8px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.character-page-tune{--ieum-side-width:0px}
    .character-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.character-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page character-page-tune">
<?php echo ieum_admin_header('character', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>인성 입력</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 기본값 3점 · 예절/집중력/자신감/배려심 · 지도진 특별 칭찬</div>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <form method="get" class="filters">
        <input type="date" name="week_start" value="<?php echo get_text($week_start); ?>">
        <select name="program_code">
            <option value="">전체 프로그램</option>
            <?php foreach ($program_options as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id">
            <option value="0">전체 부</option>
            <?php foreach ($class_options as $class) { ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
            </option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">주간 조회</button>
        <a class="btn print" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_sheet.php?week_start=<?php echo get_text($week_start); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">체크표 인쇄</a>
    </form>
    <div class="class-tabs">
        <a class="class-tab <?php echo $class_scope === 'all' && !$class_time_id ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($week_start); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=0">전체 부</a>
        <?php if (isset($class_input_counts[0]) && (int) $class_input_counts[0]['enabled'] > 0) { ?>
        <a class="class-tab <?php echo $class_scope === 'unassigned' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($week_start); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=0&amp;class_scope=unassigned">미지정</a>
        <?php } ?>
        <?php foreach ($class_options as $class) { ?>
        <a class="class-tab <?php echo $class_time_id === (int) $class['class_time_id'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($week_start); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class['class_time_id']; ?>">
            <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
        </a>
        <?php } ?>
    </div>
    <section class="character-summary" aria-label="인성 입력 요약">
        <div class="summary-tile">
            <span>입력 대상</span>
            <strong><?php echo number_format($student_count); ?>명</strong>
        </div>
        <div class="summary-tile good">
            <span>이번 주 저장</span>
            <strong><?php echo number_format($week_saved_count); ?>명</strong>
        </div>
        <div class="summary-tile warn">
            <span>오늘 칭찬</span>
            <strong><?php echo number_format($today_special_count); ?>회</strong>
        </div>
        <div class="summary-tile">
            <span>이번 달 칭찬</span>
            <strong><?php echo number_format($month_special_count); ?>회</strong>
        </div>
        <div class="summary-tile off">
            <span>리포트 제외</span>
            <strong><?php echo number_format($disabled_count); ?>명</strong>
        </div>
    </section>
    <div class="quick-note">기본 3점은 정상 수업 참여 기준입니다. 이번 주에 눈에 띈 원생만 4~5점 또는 지도진 특별 칭찬을 추가하면 됩니다. 성실 점수는 입관일 이후 정상 수업일 출석률로 자동 반영됩니다.</div>
    <section class="class-input-board" aria-label="부별 인성 입력 바로가기">
        <?php foreach ($class_input_counts as $cid => $info) {
            $class = $info['class'];
            $enabled = (int) $info['enabled'];
            if ($enabled <= 0 && $cid !== 0) {
                continue;
            }
            $saved = (int) $info['saved'];
            $class_url = IEUM_URL . '/admin/character.php?' . http_build_query(array(
                'week_start' => $week_start,
                'program_code' => $program_code,
                'class_time_id' => $cid,
                'class_scope' => $cid === 0 ? 'unassigned' : 'class',
            ));
            $is_done = $enabled > 0 && $saved >= $enabled;
            $is_active_card = ($cid === 0 && $class_scope === 'unassigned') || ($cid > 0 && $class_time_id === $cid);
        ?>
        <a class="class-input-card <?php echo $is_done ? 'done' : ''; ?> <?php echo $is_active_card ? 'active' : ''; ?>" href="<?php echo get_text($class_url); ?>">
            <div>
                <strong><?php echo get_text(trim($class['class_name'] . ' ' . $class['start_time'])); ?></strong>
                <span>입력 <?php echo number_format($saved); ?>/<?php echo number_format($enabled); ?>명</span>
            </div>
            <em><?php echo $is_done ? '완료' : '입력'; ?></em>
        </a>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="section-head">
            <h2><?php echo get_text($selected_class_label); ?> 주간 인성 입력</h2>
            <a class="btn print" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_sheet.php?week_start=<?php echo get_text($week_start); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">이 부 체크표 인쇄</a>
        </div>
        <?php if ($show_compact_all_input) { ?>
        <div class="all-input-warning">전체 부는 입력 대상이 많아 화면이 무거워질 수 있습니다. 실제 수업 후 입력은 위의 부별 카드나 부 버튼을 눌러 진행하는 흐름을 권장합니다.</div>
        <details class="all-input-details" <?php echo $focus_student_id > 0 ? 'open' : ''; ?>>
            <summary>전체 원생 입력표 열기 · <?php echo number_format($student_count); ?>명</summary>
        <?php } ?>
        <form method="post" id="specialPraiseForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="quick_special">
            <input type="hidden" name="week_start" value="<?php echo get_text($week_start); ?>">
            <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
            <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
            <input type="hidden" name="class_scope" value="<?php echo get_text($class_scope); ?>">
            <input type="hidden" name="focus_student_id" value="<?php echo (int) $focus_student_id; ?>">
        </form>
        <form method="post" class="character-input-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_scores">
            <input type="hidden" name="week_start" value="<?php echo get_text($week_start); ?>">
            <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
            <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
            <input type="hidden" name="class_scope" value="<?php echo get_text($class_scope); ?>">
            <input type="hidden" name="focus_student_id" value="<?php echo (int) $focus_student_id; ?>">
            <div class="input-toolbar">
                <div class="tool-group">
                    <strong>빠른 입력</strong>
                    <button type="button" class="mini-btn js-set-all" data-score="3">전체 기본 3점</button>
                    <button type="button" class="mini-btn js-set-all" data-score="4">전체 4점</button>
                </div>
                <div class="tool-group">
                    <span class="badge">저장 전 변경사항은 아래 저장 버튼을 눌러야 반영됩니다.</span>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>원생</th><th>수업부</th><th>예절</th><th>집중력</th><th>자신감</th><th>배려심</th><th>지도진 칭찬</th><th>메모</th></tr>
                </thead>
                <tbody>
                <?php $i = 0; foreach ($student_rows as $row) { $i++; $sid = (int) $row['student_id']; $special_month = ieum_character_special_month_summary($academy_id, $sid, $month); $latest_special = !empty($special_month['logs']) ? $special_month['logs'][count($special_month['logs']) - 1] : null; ?>
                <tr class="<?php echo $focus_student_id === $sid ? 'focus-row' : ''; ?>" data-student-row="<?php echo $sid; ?>">
                    <td class="left">
                        <div class="student-name-line"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></div>
                    </td>
                    <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                    <td class="score-cell"><select class="score js-score" name="scores[<?php echo $sid; ?>][courtesy]"><?php echo ieum_character_score_options($row['courtesy']); ?></select></td>
                    <td class="score-cell"><select class="score js-score" name="scores[<?php echo $sid; ?>][focus]"><?php echo ieum_character_score_options($row['focus']); ?></select></td>
                    <td class="score-cell"><select class="score js-score" name="scores[<?php echo $sid; ?>][confidence]"><?php echo ieum_character_score_options($row['confidence']); ?></select></td>
                    <td class="score-cell"><select class="score js-score" name="scores[<?php echo $sid; ?>][consideration]"><?php echo ieum_character_score_options($row['consideration']); ?></select></td>
                    <td class="special-cell">
                        <details class="quick-special">
                            <summary>오늘 +1</summary>
                            <div class="quick-special-fields">
                                <select form="specialPraiseForm" name="special_type[<?php echo $sid; ?>]"><?php echo ieum_character_special_reason_options('attitude'); ?></select>
                                <input form="specialPraiseForm" type="text" name="special_memo[<?php echo $sid; ?>]" maxlength="80" placeholder="칭찬 메모">
                                <button form="specialPraiseForm" type="submit" class="btn primary" name="special_student_id" value="<?php echo $sid; ?>">저장</button>
                            </div>
                        </details>
                        <?php if (!empty($special_month['count'])) { ?><span class="today-count">이번 달 칭찬 <?php echo number_format((int) $special_month['count']); ?>회 · +<?php echo number_format((int) $special_month['score']); ?>점 반영</span><?php } ?>
                        <?php if ($latest_special) { ?><span class="recent-special">최근: <?php echo get_text($latest_special['date'] . ' · ' . $latest_special['label'] . ($latest_special['memo'] !== '' ? ' · ' . $latest_special['memo'] : '')); ?></span><?php } ?>
                    </td>
                    <td><input class="memo" type="text" name="scores[<?php echo $sid; ?>][memo]" value="<?php echo get_text($row['character_memo']); ?>" placeholder="이번 주 특이사항만 간단히"></td>
                </tr>
                <?php } ?>
                <?php if ($i === 0) { ?><tr><td colspan="8">사용 중인 원생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
            <div class="save-bar">
                <div>
                    <strong><?php echo get_text($selected_class_label); ?> 입력 저장</strong>
                    <span>대상 <?php echo number_format($student_count); ?>명 · 리포트 제외 <?php echo number_format($disabled_count); ?>명</span>
                </div>
                <button type="submit" class="btn primary">이번 주 인성 점수 저장</button>
            </div>
        </form>
        <?php if ($show_compact_all_input) { ?>
        </details>
        <?php } ?>
    </section>

    <section class="panel compact-report">
        <h2><?php echo get_text($month); ?> <?php echo get_text($selected_class_label); ?> 인성 점수 자동 계산</h2>
        <details>
        <summary>월간 자동 계산표 열기</summary>
        <table>
            <thead>
                <tr><th>원생</th><th>평가 주차</th><th>예절</th><th>집중력</th><th>자신감</th><th>배려심</th><th>성실</th><th>특별</th><th>총점</th><th>기준</th></tr>
            </thead>
            <tbody>
            <?php $ri = 0; while ($row = sql_fetch_array($report_students)) { $ri++; $score = ieum_character_month_score($academy_id, $row, $month); ?>
            <tr>
                <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                <td><?php echo number_format((int) $score['evaluated_weeks']); ?>주</td>
                <td><?php echo number_format((int) $score['components']['courtesy']['score']); ?>점<br><span class="badge">평균 <?php echo get_text($score['components']['courtesy']['average']); ?></span></td>
                <td><?php echo number_format((int) $score['components']['focus']['score']); ?>점<br><span class="badge">평균 <?php echo get_text($score['components']['focus']['average']); ?></span></td>
                <td><?php echo number_format((int) $score['components']['confidence']['score']); ?>점<br><span class="badge">평균 <?php echo get_text($score['components']['confidence']['average']); ?></span></td>
                <td><?php echo number_format((int) $score['components']['consideration']['score']); ?>점<br><span class="badge">평균 <?php echo get_text($score['components']['consideration']['average']); ?></span></td>
                <td><?php echo number_format((int) $score['attendance']['score']); ?>점<br><span class="badge"><?php echo number_format((int) $score['attendance']['rate']); ?>% <?php echo number_format((int) $score['attendance']['attended_days']); ?>/<?php echo number_format((int) $score['attendance']['scheduled_days']); ?>일</span></td>
                <td><?php echo !empty($score['special']['score']) ? '+' . number_format((int) $score['special']['score']) . '점' : '-'; ?><br><?php if (!empty($score['special']['reasons'])) { ?><span class="badge"><?php echo get_text(implode(', ', $score['special']['reasons'])); ?></span><?php } ?></td>
                <td class="total"><?php echo number_format((int) $score['total_score']); ?>점</td>
                <td><?php if ($score['is_first_month']) { ?><span class="badge first">입관 첫 달</span><br><?php } ?><span class="badge"><?php echo get_text($score['base_date']); ?>부터</span></td>
            </tr>
            <?php } ?>
            <?php if ($ri === 0) { ?><tr><td colspan="10">사용 중인 원생이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
        </details>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.character-page-tune.ieum-dashboard-page';
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
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const scrollFocusRow = () => {
        const row = main.querySelector('.focus-row');
        if (row) {
            const rect = row.getBoundingClientRect();
            const topLimit = 140;
            const bottomLimit = window.innerHeight - 90;
            if (rect.top < topLimit || rect.bottom > bottomLimit) {
                row.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }
        }
    };
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
            scrollFocusRow();
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

    scrollFocusRow();

    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });

    main.addEventListener('change', (event) => {
        const select = event.target.closest('form.filters select, form.filters input[type="date"]');
        if (!select) return;
        const form = select.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });

    main.addEventListener('click', (event) => {
        const allButton = event.target.closest('.js-set-all');
        if (allButton) {
            event.preventDefault();
            const score = allButton.dataset.score || '3';
            main.querySelectorAll('.js-score').forEach((select) => {
                select.value = score;
            });
            return;
        }
        const link = event.target.closest('a.class-tab, a.class-input-card');
        if (!link || link.target) return;
        event.preventDefault();
        loadView(link.href, true);
    });

    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
