<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급 대상';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_grade_label_local($value)
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

function ieum_promotion_apply_promote($academy, $academy_id, $student, $promoted_at, $promoted_by = '')
{
    $status = ieum_promotion_status($academy, $student, substr($promoted_at, 0, 7));
    if (!empty($status['is_poomdan_exam'])) {
        return false;
    }
    $student_id = (int) $student['student_id'];
    $next_rank = ieum_promotion_next_rank($academy, $student);
    $next_belt = isset($next_rank['belt']) ? $next_rank['belt'] : '';
    $next_grade = isset($next_rank['grade_level']) ? (int) $next_rank['grade_level'] : 0;
    $next_poom_dan = isset($next_rank['poom_dan']) ? (int) $next_rank['poom_dan'] : 0;

    sql_query("
        update " . IEUM_STUDENT_TABLE . "
           set current_belt = '" . sql_escape_string($next_belt) . "',
               current_poom_dan = '{$next_poom_dan}',
               current_grade_level = '{$next_grade}',
               last_promotion_date = '" . sql_escape_string($promoted_at) . "',
               promotion_enabled = 1,
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
    ");

    ieum_promotion_record_log($academy_id, $student, $next_rank, $promoted_at, $promoted_by);

    return $next_rank;
}

$message = '';
$error = '';
$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$belt_filter = isset($_GET['belt']) ? trim((string) $_GET['belt']) : '';
$belt_filter = preg_replace('/[<>"\']/', '', $belt_filter);
$status_filter = isset($_GET['status']) ? preg_replace('/[^0-9a-z_]/i', '', $_GET['status']) : 'due';
if (!in_array($status_filter, array('due', 'overdue', 'all', 'excluded'), true)) {
    $status_filter = 'due';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
        if ($action === 'mark_promoted' && $student_id > 0) {
            $student = sql_fetch("select * from " . IEUM_STUDENT_TABLE . " where academy_id = '{$academy_id}' and student_id = '{$student_id}'", false);
            if (!isset($student['student_id'])) {
                $error = '승급 처리할 원생을 찾을 수 없습니다.';
            } else {
                $promoted_at = isset($_POST['promoted_at']) ? preg_replace('/[^0-9-]/', '', $_POST['promoted_at']) : date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $promoted_at)) {
                    $promoted_at = date('Y-m-d');
                }
                $promoted = ieum_promotion_apply_promote($academy, $academy_id, $student, $promoted_at, isset($member['mb_id']) ? $member['mb_id'] : '');
                if ($promoted === false) {
                    $error = '승품/단 심사 대상자는 승급 처리에서 제외됩니다.';
                } else {
                    $message = get_text($student['student_name']) . ' 원생을 승급 처리했습니다.';
                }
            }
        } elseif ($action === 'mark_promoted_bulk') {
            $promoted_at = isset($_POST['promoted_at']) ? preg_replace('/[^0-9-]/', '', $_POST['promoted_at']) : date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $promoted_at)) {
                $promoted_at = date('Y-m-d');
            }
            $student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : array();
            $promoted_count = 0;
            foreach ($student_ids as $posted_student_id) {
                $posted_student_id = (int) $posted_student_id;
                if ($posted_student_id <= 0) {
                    continue;
                }
                $student = sql_fetch("select * from " . IEUM_STUDENT_TABLE . " where academy_id = '{$academy_id}' and student_id = '{$posted_student_id}' and is_active = 1", false);
                if (!isset($student['student_id'])) {
                    continue;
                }
                $status = ieum_promotion_status($academy, $student, $month);
                if (!$status['enabled'] || !empty($status['is_poomdan_exam']) || (!$status['due_this_month'] && !$status['overdue'])) {
                    continue;
                }
                ieum_promotion_apply_promote($academy, $academy_id, $student, $promoted_at, isset($member['mb_id']) ? $member['mb_id'] : '');
                $promoted_count++;
            }
            $message = '승급 대상 ' . number_format($promoted_count) . '명을 승급 처리했습니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$programs = ieum_program_options($academy_id, true);
$belts = ieum_promotion_belts($academy);
$belt_order_map = array();
foreach ($belts as $belt_index => $belt_name) {
    $belt_order_map[trim((string) $belt_name)] = (int) $belt_index;
}
if ($belt_filter !== '' && !in_array($belt_filter, $belts, true)) {
    $belt_filter = '';
}
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

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($program_code !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
if ($class_time_id > 0) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}

$rows = array();
$bulk_promotion_rows = array();
$belt_need_counts = array();
$counts = array('all' => 0, 'due' => 0, 'overdue' => 0, 'excluded' => 0);
$result = sql_query("
    select s.*, c.class_name, c.start_time, c.sort_order
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
", false);
while ($student = sql_fetch_array($result)) {
    $status = ieum_promotion_status($academy, $student, $month);
    if (!empty($status['is_poomdan_exam'])) {
        continue;
    }
    $counts['all']++;
    if (!$status['enabled']) {
        $counts['excluded']++;
    }
    if ($status['due_this_month'] || $status['overdue']) {
        $counts['due']++;
    }
    if ($status['overdue']) {
        $counts['overdue']++;
    }

    $include = false;
    if ($status_filter === 'all') {
        $include = $status['enabled'];
    } elseif ($status_filter === 'excluded') {
        $include = !$status['enabled'];
    } elseif ($status_filter === 'overdue') {
        $include = $status['overdue'];
    } else {
        $include = $status['due_this_month'] || $status['overdue'];
    }
    if ($include) {
        $student['_promotion_status'] = $status;
        $student['_belt_order'] = isset($belt_order_map[$status['belt']]) ? (int) $belt_order_map[$status['belt']] : 999;
        if ($belt_filter !== '' && $status['belt'] !== $belt_filter) {
            continue;
        }
        $rows[] = $student;
        if ($status['enabled'] && ($status['due_this_month'] || $status['overdue'])) {
            $bulk_promotion_rows[] = $student;
        }
    }
    if ($status['enabled'] && ($status['due_this_month'] || $status['overdue'])) {
        $need_belt = isset($status['next_rank']['belt']) ? $status['next_rank']['belt'] : '';
        if ($need_belt !== '') {
            if (!isset($belt_need_counts[$need_belt])) {
                $belt_need_counts[$need_belt] = 0;
            }
            $belt_need_counts[$need_belt]++;
        }
    }
}
usort($rows, function ($a, $b) {
    $a_class = isset($a['sort_order']) ? (int) $a['sort_order'] : 999;
    $b_class = isset($b['sort_order']) ? (int) $b['sort_order'] : 999;
    if ($a_class !== $b_class) {
        return $a_class < $b_class ? -1 : 1;
    }
    if ((string) $a['start_time'] !== (string) $b['start_time']) {
        return strcmp((string) $a['start_time'], (string) $b['start_time']);
    }
    if ((int) $a['_belt_order'] !== (int) $b['_belt_order']) {
        return (int) $a['_belt_order'] < (int) $b['_belt_order'] ? -1 : 1;
    }
    if ((int) $a['current_grade_level'] !== (int) $b['current_grade_level']) {
        return (int) $a['current_grade_level'] > (int) $b['current_grade_level'] ? -1 : 1;
    }
    return strcmp((string) $a['student_name'], (string) $b['student_name']);
});
$bulk_promotion_rows = array_values(array_filter($rows, function ($row) {
    $status = $row['_promotion_status'];
    return $status['enabled'] && ($status['due_this_month'] || $status['overdue']);
}));
uksort($belt_need_counts, function ($a, $b) use ($belt_order_map) {
    $ai = isset($belt_order_map[$a]) ? (int) $belt_order_map[$a] : 999;
    $bi = isset($belt_order_map[$b]) ? (int) $belt_order_map[$b] : 999;
    if ($ai === $bi) {
        return strcmp($a, $b);
    }
    return $ai < $bi ? -1 : 1;
});
$bulk_confirm_lines = array();
foreach ($bulk_promotion_rows as $bulk_row) {
    $bulk_status = $bulk_row['_promotion_status'];
    $bulk_next = isset($bulk_status['next_rank']) ? $bulk_status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
    $bulk_current_label = ($bulk_status['belt'] !== '' ? $bulk_status['belt'] . ' ' : '') . ieum_promotion_rank_label($bulk_status['poom_dan'], $bulk_status['grade_level']);
    $bulk_next_label = ($bulk_next['belt'] !== '' ? $bulk_next['belt'] . ' ' : '') . ieum_promotion_rank_label($bulk_next['poom_dan'], $bulk_next['grade_level']);
    $bulk_state_label = !empty($bulk_status['overdue']) ? '기간 지남' : '이번 달 대상';
    $bulk_confirm_lines[] = $bulk_row['student_name'] . ' (' . $bulk_row['student_code'] . ')|' . $bulk_current_label . ' -> ' . $bulk_next_label . '|' . $bulk_state_label;
}
$bulk_belt_lines = array();
foreach ($belt_need_counts as $belt_name => $belt_count) {
    $bulk_belt_lines[] = $belt_name . '|' . number_format((int) $belt_count) . '개';
}
$bulk_summary_lines = array(
    '처리 대상|' . number_format(count($bulk_promotion_rows)) . '명',
    '기간 지남|' . number_format((int) $counts['overdue']) . '명',
    '승급 제외|' . number_format((int) $counts['excluded']) . '명',
);
$certificate_link = IEUM_URL . '/admin/promotion_certificates.php?' . http_build_query(array(
    'month' => $month,
    'program_code' => $program_code,
    'class_time_id' => $class_time_id,
    'print_status' => 'not_printed',
));
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;margin-bottom:18px}.notice{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.filters{display:grid;gap:12px}.filter-fields{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr)) auto;gap:8px;align-items:center}.filter-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-top:1px solid #edf1f7;padding-top:12px}.filters input,.filters select{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:14px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{border-color:#fca5a5;color:#b91c1c}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.card{border:1px solid #d9dee7;border-radius:12px;background:#fff;padding:16px;text-decoration:none;color:#111827}.card.active{border-color:#1769c2;background:#eef6ff}.card span{display:block;color:#667085;font-size:13px}.card b{display:block;font-size:28px;margin-top:6px}
.belt-needs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 18px}.belt-need-title{font-weight:1000;color:#111827;margin-right:4px}.belt-need{display:inline-flex;gap:8px;align-items:center;border:1px solid #f4c27a;border-radius:999px;background:#fffaf0;padding:8px 12px;font-weight:900}.belt-need b{color:#9a5b00}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.muted{color:#667085}.state{display:inline-flex;border-radius:999px;padding:5px 9px;font-weight:900;font-size:12px;background:#eef2ff;color:#1d4ed8}.state.overdue{background:#fff1f2;color:#be123c}.state.excluded{background:#f1f5f9;color:#475569}
.target-table-wrap{overflow-x:auto;border-radius:10px}
.actions{display:flex;gap:6px;justify-content:center;align-items:center}.actions input{width:136px;border:1px solid #cfd6df;border-radius:8px;padding:8px}
.bulk-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between}.bulk-actions .hint{color:#667085;font-size:13px}.bulk-forms{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.date-action{display:inline-flex;align-items:center;gap:7px;border:1px solid #d9e2ef;border-radius:10px;background:#f8fbff;padding:6px 8px;font-size:13px;font-weight:900;color:#334155}.date-action input{width:136px;border:1px solid #cfd6df;border-radius:8px;padding:8px;background:#fff}.select-cell{width:54px}.select-cell input{width:18px;height:18px;accent-color:#1769c2}.confirm-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.52);padding:20px}.confirm-modal.open{display:flex}.confirm-box{width:min(860px,100%);background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.3);overflow:hidden}.confirm-head{padding:18px 20px;border-bottom:1px solid #e5e7eb}.confirm-head h2{margin:0;font-size:22px}.confirm-body{padding:18px 20px}.confirm-note{margin:0 0 14px;color:#475569;line-height:1.55}.confirm-summary{display:none;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.confirm-summary.open{display:grid}.confirm-summary-card{border:1px solid #d9e2f1;border-radius:12px;background:#f8fbff;padding:12px}.confirm-summary-card span{display:block;color:#64748b;font-size:13px}.confirm-summary-card b{display:block;margin-top:4px;font-size:24px;color:#0f172a}.confirm-belts{display:none;gap:8px;flex-wrap:wrap;margin:0 0 14px}.confirm-belts.open{display:flex}.confirm-belt{display:inline-flex;gap:8px;align-items:center;border:1px solid #f4c27a;border-radius:999px;background:#fffaf0;padding:8px 12px;font-weight:900}.confirm-belt b{color:#9a5b00}.confirm-list{max-height:340px;overflow:auto;border:1px solid #d8dee9;border-radius:10px;background:#f8fafc;margin-top:12px}.confirm-list div{display:grid;grid-template-columns:minmax(160px,1fr) minmax(180px,1.4fr) auto;gap:14px;align-items:center;padding:10px 12px;border-bottom:1px solid #e5e7eb}.confirm-list div:last-child{border-bottom:0}.confirm-list strong{white-space:nowrap}.confirm-list span{text-align:right;color:#1d4ed8;font-weight:900}.confirm-list em{font-style:normal;color:#64748b;font-size:12px;white-space:nowrap}.confirm-foot{display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;background:#f8fafc;border-top:1px solid #e5e7eb}
.promotion-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}.flow-step{border:1px solid #d9e2ef;border-radius:12px;background:#fff;padding:14px;display:grid;grid-template-columns:34px 1fr;gap:10px;align-items:start;color:#111827;text-decoration:none;transition:border-color .15s ease,background .15s ease,box-shadow .15s ease}.flow-step:hover{border-color:#9bb7df;background:#f8fbff;box-shadow:0 10px 20px rgba(23,105,194,.08)}.flow-step b{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#eaf2ff;color:#1769c2;font-size:14px}.flow-step strong{display:block;font-size:15px}.flow-step span{display:block;margin-top:4px;color:#667085;font-size:13px;line-height:1.4}.flow-step.next{border-color:#1769c2;background:#f2f8ff}.flow-step.next b{background:#1769c2;color:#fff}.flow-step.done{border-color:#bfe7ca;background:#f3fbf5}.flow-step.done b{background:#176b2c;color:#fff}.flow-action{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #bfd4f2;border-radius:12px;background:linear-gradient(135deg,#fff,#f7fbff);padding:16px;margin-bottom:18px}.flow-action strong{display:block;font-size:18px}.flow-action span{display:block;margin-top:4px;color:#667085;font-size:13px;line-height:1.45}
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .ieum-right-rail{display:none!important}
.promotion-targets-page-tune .side-nav{padding:0 14px 22px!important}
.promotion-targets-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.promotion-targets-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.promotion-targets-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.promotion-targets-page-tune .ieum-shell-link,
.promotion-targets-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.promotion-targets-page-tune .ieum-shell-link::before{display:none!important}
.promotion-targets-page-tune .ieum-shell-meta{color:#0f172a!important}
.promotion-targets-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.promotion-targets-page-tune .dashboard-shell-divider,
.promotion-targets-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune{--ieum-side-width:0px}
    .promotion-targets-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.promotion-targets-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
@media(max-width:1280px){.filter-fields{grid-template-columns:repeat(3,minmax(0,1fr))}.filter-fields .btn{grid-column:auto}}
@media(max-width:900px){.cards,.promotion-flow{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions .btn{flex:1 1 160px}.flow-action{align-items:flex-start;flex-direction:column}table{white-space:nowrap}}@media(max-width:560px){.cards,.promotion-flow,.filter-fields{grid-template-columns:1fr}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-targets-page-tune">
<?php echo ieum_admin_header('promotion_targets', 'side'); ?>
<main class="wrap">
    <h1>승급 대상</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 마지막 승급일과 현재 승급 주기로 이번 달 대상자를 자동 확인합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><span><?php echo get_text($message); ?> 승급증 인쇄 화면에서 발급일을 정해 출력할 수 있습니다.</span><a class="btn primary" href="<?php echo get_text($certificate_link); ?>">승급증 인쇄로 이동</a></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <?php if ($message) { ?>
    <section class="flow-action">
        <div>
            <strong>다음 단계는 승급증 인쇄입니다.</strong>
            <span>승급 완료 원생은 승급증 인쇄 대기 목록으로 이동합니다. 발급일을 확인하고 인쇄 후 완료 표시까지 처리해 주세요.</span>
        </div>
        <a class="btn primary" href="<?php echo get_text($certificate_link); ?>">승급증 인쇄 바로가기</a>
    </section>
    <?php } ?>
    <section class="promotion-flow" aria-label="승급 운영 순서">
        <a class="flow-step done" href="#promotionTargetList"><b>1</b><div><strong>대상 확인</strong><span>수업 부, 띠, 기간 지난 원생을 확인합니다.</span></div></a>
        <a class="flow-step" target="_blank" href="<?php echo IEUM_URL; ?>/admin/promotion_missions_print.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter, 'print_mode' => 'detail')); ?>"><b>2</b><div><strong>미션표 인쇄</strong><span>부별 승급 미션 체크표를 준비합니다.</span></div></a>
        <a class="flow-step" href="#promotionBulkPanel"><b>3</b><div><strong>승급 완료</strong><span>심사일을 정하고 선택 또는 전체 처리합니다.</span></div></a>
        <a class="flow-step next" href="<?php echo get_text($certificate_link); ?>"><b>4</b><div><strong>승급증 인쇄</strong><span>발급일 확인 후 인쇄완료 표시까지 마감합니다.</span></div></a>
    </section>

    <section class="cards">
        <?php
        $base_qs = array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'belt' => $belt_filter);
        foreach (array('due' => '처리 필요', 'overdue' => '기간 지남', 'all' => '전체 사용', 'excluded' => '제외') as $key => $label) {
            $url = IEUM_URL . '/admin/promotion_targets.php?' . http_build_query(array_merge($base_qs, array('status' => $key)));
        ?>
        <a class="card <?php echo $status_filter === $key ? 'active' : ''; ?>" href="<?php echo get_text($url); ?>"><span><?php echo get_text($label); ?></span><b><?php echo number_format((int) $counts[$key]); ?>명</b></a>
        <?php } ?>
    </section>

    <section class="belt-needs" aria-label="이번 달 준비할 띠">
        <strong class="belt-need-title">이번 달 준비할 띠</strong>
        <?php if ($belt_need_counts) { ?>
            <?php foreach ($belt_need_counts as $belt_name => $belt_count) { ?>
            <span class="belt-need"><span><?php echo get_text($belt_name); ?></span><b><?php echo number_format((int) $belt_count); ?>개</b></span>
            <?php } ?>
        <?php } else { ?>
            <span class="belt-need"><span>이번 달 준비할 띠</span><b>없음</b></span>
        <?php } ?>
    </section>

    <form class="panel filters" method="get">
        <div class="filter-fields">
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
                <option value="due" <?php echo get_selected($status_filter, 'due'); ?>>처리 필요</option>
                <option value="overdue" <?php echo get_selected($status_filter, 'overdue'); ?>>기간 지남</option>
                <option value="all" <?php echo get_selected($status_filter, 'all'); ?>>전체 사용</option>
                <option value="excluded" <?php echo get_selected($status_filter, 'excluded'); ?>>제외</option>
            </select>
            <select name="belt">
                <option value="">전체 띠</option>
                <?php foreach ($belts as $belt_name) { ?>
                <option value="<?php echo get_text($belt_name); ?>" <?php echo get_selected($belt_filter, $belt_name); ?>><?php echo get_text($belt_name); ?></option>
                <?php } ?>
            </select>
            <button class="btn primary" type="submit">조회</button>
        </div>
        <div class="filter-actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_settings.php">승급 설정</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_missions.php?<?php echo http_build_query(array('program_code' => $program_code !== '' ? $program_code : 'taekwondo')); ?>">승급 미션 설정</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => 'promotion', 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>">심사 안내문</a>
            <a class="btn" href="<?php echo get_text($certificate_link); ?>">승급증 인쇄</a>
            <a class="btn" target="_blank" href="<?php echo IEUM_URL; ?>/admin/promotion_targets_print.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter)); ?>">승급 명단 인쇄</a>
            <a class="btn primary" target="_blank" href="<?php echo IEUM_URL; ?>/admin/promotion_missions_print.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter, 'print_mode' => 'detail')); ?>">승급 미션표 인쇄</a>
        </div>
    </form>

    <section class="panel bulk-actions" id="promotionBulkPanel">
        <div>
            <strong>현재 목록 전체 승급</strong>
            <div class="hint">화면에 보이는 승급 대상만 처리합니다. 제외 원생이나 아직 대상이 아닌 원생은 포함하지 않습니다.</div>
        </div>
        <div class="bulk-forms">
        <form id="selectedPromotionForm" class="js-promotion-confirm" method="post" data-title="선택 승급 처리 확인" data-lines="">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="mark_promoted_bulk">
            <input type="hidden" class="js-confirm-summary" value="">
            <input type="hidden" class="js-confirm-belts" value="">
            <input type="hidden" class="js-confirm-kind" value="bulk">
            <label class="date-action">심사/승급일<input type="date" name="promoted_at" value="<?php echo date('Y-m-d'); ?>"></label>
            <span class="js-selected-ids"></span>
            <button class="btn primary" type="submit" <?php echo !$bulk_promotion_rows ? 'disabled' : ''; ?>>선택 승급 완료</button>
        </form>
        <form class="js-promotion-confirm" method="post" data-title="전체 승급 처리 확인" data-lines="<?php echo htmlspecialchars(implode("\n", $bulk_confirm_lines), ENT_QUOTES); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="mark_promoted_bulk">
            <input type="hidden" class="js-confirm-summary" value="<?php echo htmlspecialchars(implode("\n", $bulk_summary_lines), ENT_QUOTES); ?>">
            <input type="hidden" class="js-confirm-belts" value="<?php echo htmlspecialchars(implode("\n", $bulk_belt_lines), ENT_QUOTES); ?>">
            <input type="hidden" class="js-confirm-kind" value="bulk">
            <label class="date-action">심사/승급일<input type="date" name="promoted_at" value="<?php echo date('Y-m-d'); ?>"></label>
            <?php foreach ($bulk_promotion_rows as $bulk_row) { ?>
            <input type="hidden" name="student_ids[]" value="<?php echo (int) $bulk_row['student_id']; ?>">
            <?php } ?>
            <button class="btn" type="submit" <?php echo !$bulk_promotion_rows ? 'disabled' : ''; ?>>전체 승급 완료</button>
        </form>
        </div>
    </section>

    <section class="panel" id="promotionTargetList">
        <div class="target-table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="select-cell">선택</th><th>원생</th><th>학년/부</th><th>현재 띠</th><th>현재 단계</th><th>승급 후</th><th>마지막 승급일</th><th>승급 주기</th><th>다음 예정일</th><th>상태</th><th>관리</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows) { ?>
                <tr><td colspan="11">조건에 맞는 승급 대상자가 없습니다.</td></tr>
            <?php } ?>
            <?php foreach ($rows as $row) {
                $status = $row['_promotion_status'];
                $next_rank = isset($status['next_rank']) ? $status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
                $class_label = trim((isset($row['class_name']) ? $row['class_name'] : '') . ' ' . (isset($row['start_time']) ? $row['start_time'] : ''));
                $state_class = !$status['enabled'] ? 'excluded' : ($status['overdue'] ? 'overdue' : '');
                $state_label = !$status['enabled'] ? '제외' : ($status['overdue'] ? '기간 지남' : ($status['due_this_month'] ? '이번 달' : '예정'));
                $current_confirm_label = ($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_rank_label($status['poom_dan'], $status['grade_level']);
                $next_confirm_label = ($next_rank['belt'] !== '' ? $next_rank['belt'] . ' ' : '') . ieum_promotion_rank_label($next_rank['poom_dan'], $next_rank['grade_level']);
                $single_confirm_line = $row['student_name'] . ' (' . $row['student_code'] . ')|' . $current_confirm_label . ' -> ' . $next_confirm_label . '|' . $state_label;
                $is_promotion_candidate = $status['enabled'] && ($status['due_this_month'] || $status['overdue']);
            ?>
                <tr>
                    <td class="select-cell">
                        <?php if ($is_promotion_candidate) { ?>
                        <input type="checkbox" class="js-promotion-check" value="<?php echo (int) $row['student_id']; ?>" data-line="<?php echo htmlspecialchars($single_confirm_line, ENT_QUOTES); ?>" data-belt="<?php echo htmlspecialchars($next_rank['belt'] !== '' ? $next_rank['belt'] : '미지정', ENT_QUOTES); ?>">
                        <?php } ?>
                    </td>
                    <td class="left"><strong><?php echo get_text($row['student_name']); ?></strong> <span class="muted"><?php echo get_text($row['student_code']); ?></span></td>
                    <td><?php echo get_text(ieum_promotion_grade_label_local($row['grade_group'])); ?><br><span class="muted"><?php echo get_text($class_label !== '' ? $class_label : '부 미지정'); ?></span></td>
                    <td><?php echo get_text($status['belt'] !== '' ? $status['belt'] : '미지정'); ?></td>
                    <td><?php echo get_text(ieum_promotion_rank_label($status['poom_dan'], $status['grade_level'])); ?></td>
                    <td><strong><?php echo get_text($next_rank['belt'] !== '' ? $next_rank['belt'] : '미지정'); ?></strong><br><span class="muted"><?php echo get_text(ieum_promotion_rank_label($next_rank['poom_dan'], $next_rank['grade_level'])); ?></span></td>
                    <td><?php echo get_text($status['base_date'] !== '' ? $status['base_date'] : '-'); ?></td>
                    <td><?php echo (int) $status['cycle_months']; ?>개월</td>
                    <td><?php echo get_text($status['next_date'] !== '' ? $status['next_date'] : '-'); ?></td>
                    <td><span class="state <?php echo $state_class; ?>"><?php echo get_text($state_label); ?></span></td>
                    <td>
                        <?php if ($status['enabled']) { ?>
                        <form class="actions js-promotion-confirm" method="post" data-title="승급 처리 확인" data-lines="<?php echo htmlspecialchars($single_confirm_line, ENT_QUOTES); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="mark_promoted">
                            <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                            <label class="date-action">심사/승급일<input type="date" name="promoted_at" value="<?php echo date('Y-m-d'); ?>"></label>
                            <button class="btn primary" type="submit">승급 완료</button>
                        </form>
                        <?php } else { ?>
                        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>">원생 수정</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.promotion-targets-page-tune.ieum-dashboard-page';
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
<div class="confirm-modal" id="promotionConfirmModal" aria-hidden="true">
    <div class="confirm-box" role="dialog" aria-modal="true" aria-labelledby="promotionConfirmTitle">
        <div class="confirm-head"><h2 id="promotionConfirmTitle">승급 처리 확인</h2></div>
        <div class="confirm-body">
            <p>아래 내용으로 승급 처리합니다. 확인 후 진행해 주세요.</p>
            <div class="confirm-summary" id="promotionConfirmSummary"></div>
            <div class="confirm-belts" id="promotionConfirmBelts"></div>
            <div class="confirm-list" id="promotionConfirmList"></div>
        </div>
        <div class="confirm-foot">
            <button type="button" class="btn" id="promotionConfirmCancel">취소</button>
            <button type="button" class="btn primary" id="promotionConfirmSubmit">확인</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('promotionConfirmModal');
    var title = document.getElementById('promotionConfirmTitle');
    var list = document.getElementById('promotionConfirmList');
    var summary = document.getElementById('promotionConfirmSummary');
    var belts = document.getElementById('promotionConfirmBelts');
    var cancel = document.getElementById('promotionConfirmCancel');
    var submit = document.getElementById('promotionConfirmSubmit');
    var pendingForm = null;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function splitLines(value) {
        return String(value || '').split(/\n/).filter(Boolean);
    }

    function readHidden(form, selector) {
        var input = form.querySelector(selector);
        return input ? input.value : '';
    }

    function prepareSelectedForm(form) {
        if (!form || form.id !== 'selectedPromotionForm') {
            return true;
        }
        var checked = Array.prototype.slice.call(document.querySelectorAll('.js-promotion-check:checked'));
        var idBox = form.querySelector('.js-selected-ids');
        var summaryInput = form.querySelector('.js-confirm-summary');
        var beltInput = form.querySelector('.js-confirm-belts');
        var beltCounts = {};
        idBox.innerHTML = '';
        if (!checked.length) {
            alert('승급 처리할 원생을 먼저 선택해 주세요.');
            return false;
        }
        checked.forEach(function (checkbox) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'student_ids[]';
            hidden.value = checkbox.value;
            idBox.appendChild(hidden);
            var belt = checkbox.getAttribute('data-belt') || '미지정';
            beltCounts[belt] = (beltCounts[belt] || 0) + 1;
        });
        form.setAttribute('data-lines', checked.map(function (checkbox) {
            return checkbox.getAttribute('data-line') || '';
        }).filter(Boolean).join('\n'));
        summaryInput.value = '선택 원생|' + checked.length + '명';
        beltInput.value = Object.keys(beltCounts).map(function (belt) {
            return belt + '|' + beltCounts[belt] + '개';
        }).join('\n');
        return true;
    }

    function renderGroup(container, lines, className, openClass) {
        container.classList.toggle(openClass, lines.length > 0);
        container.innerHTML = lines.map(function (line) {
            var parts = line.split('|');
            if (className === 'confirm-summary-card') {
                return '<div class="' + className + '"><span>' + escapeHtml(parts[0] || '') + '</span><b>' + escapeHtml(parts.slice(1).join('|') || '') + '</b></div>';
            }
            return '<span class="' + className + '"><span>' + escapeHtml(parts[0] || '') + '</span><b>' + escapeHtml(parts.slice(1).join('|') || '') + '</b></span>';
        }).join('');
    }

    document.querySelectorAll('.js-promotion-confirm').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-confirmed') === '1') {
                return;
            }
            if (!prepareSelectedForm(form)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            var lines = splitLines(form.getAttribute('data-lines'));
            if (!lines.length) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            pendingForm = form;
            title.textContent = form.getAttribute('data-title') || title.textContent;

            renderGroup(summary, splitLines(readHidden(form, '.js-confirm-summary')), 'confirm-summary-card', 'open');
            renderGroup(belts, splitLines(readHidden(form, '.js-confirm-belts')), 'confirm-belt', 'open');
            list.innerHTML = lines.map(function (line) {
                var parts = line.split('|');
                var name = parts[0] || '';
                var change = parts[1] || parts.slice(1).join('|') || '';
                var state = parts[2] || '';
                return '<div><strong>' + escapeHtml(name) + '</strong><span>' + escapeHtml(change) + '</span><em>' + escapeHtml(state) + '</em></div>';
            }).join('');
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }, true);
    });

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        pendingForm = null;
    }

    cancel.addEventListener('click', closeModal);
    submit.addEventListener('click', function () {
        if (!pendingForm) {
            return;
        }
        pendingForm.setAttribute('data-confirmed', '1');
        pendingForm.submit();
    });
})();
</script>
</body>
</html>
