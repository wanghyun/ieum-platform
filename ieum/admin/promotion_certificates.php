<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급증 인쇄';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_certificate_grade_label($value)
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

function ieum_promotion_certificate_rank_text($belt, $poom_dan, $grade_level)
{
    $rank = ieum_promotion_rank_label($poom_dan, $grade_level);
    return trim(($belt !== '' ? $belt . ' ' : '') . $rank);
}

$message = '';
$error = '';
$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$print_status = isset($_GET['print_status']) ? preg_replace('/[^a-z_]/', '', $_GET['print_status']) : 'not_printed';
if (!in_array($print_status, array('all', 'not_printed', 'printed'), true)) {
    $print_status = 'not_printed';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $log_ids = isset($_POST['log_ids']) && is_array($_POST['log_ids']) ? $_POST['log_ids'] : array();
        $ids = array();
        foreach ($log_ids as $log_id) {
            $log_id = (int) $log_id;
            if ($log_id > 0) {
                $ids[] = $log_id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($action === 'mark_printed' && $ids) {
            $id_sql = implode(',', $ids);
            $printed_by = isset($member['mb_id']) ? $member['mb_id'] : '';
            sql_query("
                update " . IEUM_PROMOTION_LOG_TABLE . "
                   set certificate_printed_at = '" . G5_TIME_YMDHIS . "',
                       certificate_printed_by = '" . sql_escape_string($printed_by) . "'
                 where academy_id = '{$academy_id}'
                   and log_id in ({$id_sql})
            ", false);
            $message = number_format(count($ids)) . '명의 승급증을 인쇄 완료로 표시했습니다.';
        } elseif ($action === 'reset_printed' && $ids) {
            $id_sql = implode(',', $ids);
            sql_query("
                update " . IEUM_PROMOTION_LOG_TABLE . "
                   set certificate_printed_at = null,
                       certificate_printed_by = ''
                 where academy_id = '{$academy_id}'
                   and log_id in ({$id_sql})
            ", false);
            $message = number_format(count($ids)) . '명의 승급증 인쇄 완료 표시를 해제했습니다.';
        } else {
            $error = '처리할 승급증 대상자를 선택해 주세요.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
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

$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$where = " where l.academy_id = '{$academy_id}' and l.promoted_at between '{$month_start}' and '{$month_end}' ";
if ($program_code !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
if ($class_time_id > 0) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}
if ($print_status === 'not_printed') {
    $where .= " and l.certificate_printed_at is null ";
} elseif ($print_status === 'printed') {
    $where .= " and l.certificate_printed_at is not null ";
}

$rows = array();
$counts = array('all' => 0, 'not_printed' => 0, 'printed' => 0);
$count_result = sql_query("
    select l.certificate_printed_at
      from " . IEUM_PROMOTION_LOG_TABLE . " l
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = l.student_id and s.academy_id = l.academy_id
     where l.academy_id = '{$academy_id}'
       and l.promoted_at between '{$month_start}' and '{$month_end}'
       " . ($program_code !== '' ? " and s.program_code = '" . sql_escape_string($program_code) . "' " : '') . "
       " . ($class_time_id > 0 ? " and s.class_time_id = '{$class_time_id}' " : '') . "
", false);
while ($count_row = sql_fetch_array($count_result)) {
    $counts['all']++;
    if (!empty($count_row['certificate_printed_at'])) {
        $counts['printed']++;
    } else {
        $counts['not_printed']++;
    }
}

$result = sql_query("
    select l.*, s.student_name, s.student_code, s.grade_group, s.school_name, s.program_code, s.class_time_id,
           c.class_name, c.start_time, c.sort_order
      from " . IEUM_PROMOTION_LOG_TABLE . " l
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = l.student_id and s.academy_id = l.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, l.promoted_at asc, s.student_name asc, s.student_code asc, l.log_id asc
", false);
while ($row = sql_fetch_array($result)) {
    $rows[] = $row;
}

$row_ids = array();
foreach ($rows as $row) {
    $row_ids[] = (int) $row['log_id'];
}
$promotion_dates = array();
foreach ($rows as $row) {
    if (!empty($row['promoted_at'])) {
        $promotion_dates[$row['promoted_at']] = true;
    }
}
$default_issue_date = count($promotion_dates) === 1 ? key($promotion_dates) : '';

$base_qs = array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id);
$print_qs = array_merge($base_qs, array('print_status' => $print_status));
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;box-shadow:0 8px 22px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;margin-bottom:18px}.notice{padding:12px 14px;border-radius:10px;font-weight:900}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.card{border:1px solid #d9dee7;border-radius:14px;background:#fff;padding:17px;text-decoration:none;color:#111827}.card.active{border-color:#1769c2;background:#eef6ff}.card span{display:block;color:#667085;font-size:13px;font-weight:900}.card b{display:block;margin-top:6px;font-size:30px}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.filters input,.filters select{border:1px solid #cfd6df;border-radius:10px;padding:10px 11px;font-size:14px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:900;cursor:pointer;white-space:nowrap}.btn:disabled{opacity:.45;cursor:not-allowed}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.dark{background:#101828;border-color:#101828;color:#fff}.danger{border-color:#fca5a5;color:#b91c1c}.bulk{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.bulk-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.selected-summary{display:inline-flex;align-items:center;min-height:40px;border:1px solid #d8e4f2;border-radius:999px;background:#f8fbff;color:#334155;padding:0 13px;font-weight:900}.selected-summary b{color:#1769c2}.issue-date-box{display:inline-flex;align-items:center;gap:7px;border:1px solid #d9e2ef;border-radius:12px;background:#f8fbff;padding:7px 10px;font-weight:900;color:#334155}.issue-date-box input{width:148px;border:1px solid #cfd6df;border-radius:8px;padding:8px;background:#fff}.muted{color:#667085}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1120px}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;vertical-align:middle}th{background:#72829d;color:#fff}tbody tr.is-selected{background:#f3f8ff}.left{text-align:left}.select-cell{width:54px}.select-cell input{width:18px;height:18px;accent-color:#1769c2}.pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:1000;background:#eef2ff;color:#1d4ed8}.pill.wait{background:#fff8e7;color:#9a5b00}.pill.done{background:#eef9f1;color:#176b2c}.rank-change{font-weight:1000}.rank-change span{color:#1769c2}.actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.empty{padding:44px 12px;color:#667085;font-weight:900}.certificate-modal{position:fixed;inset:0;z-index:1000;display:none;grid-template-rows:auto 1fr;gap:10px;background:rgba(15,23,42,.66);padding:28px}.certificate-modal.is-open{display:grid}.certificate-modal-head{max-width:1120px;width:100%;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:10px;background:#fff;border-radius:14px;padding:12px 14px;box-shadow:0 18px 44px rgba(15,23,42,.24)}.certificate-modal-title{font-weight:1000}.certificate-modal-title span{display:block;color:#667085;font-size:13px;margin-top:2px;font-weight:700}.certificate-modal-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.certificate-modal-close{width:40px;height:40px;border:0;border-radius:999px;background:#101828;color:#fff;font-size:22px;font-weight:900;line-height:1;cursor:pointer}.certificate-modal-frame-wrap{max-width:1120px;width:100%;height:calc(100vh - 118px);margin:0 auto;background:#eef2f7;border-radius:16px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.28)}.certificate-modal-frame{width:100%;height:100%;border:0;background:#eef2f7}body.modal-open{overflow:hidden}
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .ieum-right-rail{display:none!important}
.promotion-certificates-page-tune .side-nav{padding:0 14px 22px!important}.promotion-certificates-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.promotion-certificates-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.promotion-certificates-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.promotion-certificates-page-tune .ieum-shell-link,.promotion-certificates-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.promotion-certificates-page-tune .ieum-shell-link::before{display:none!important}.promotion-certificates-page-tune .ieum-shell-meta{color:#0f172a!important}.promotion-certificates-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.promotion-certificates-page-tune .dashboard-shell-divider,.promotion-certificates-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
.promotion-certificates-page-tune .bulk{display:grid;grid-template-columns:1fr;align-items:start}
.promotion-certificates-page-tune .bulk-copy strong{display:block;font-size:17px;margin-bottom:4px}
.promotion-certificates-page-tune .bulk-actions{justify-content:space-between;margin-top:14px;gap:12px}
.promotion-certificates-page-tune .bulk-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-start}
.promotion-certificates-page-tune .bulk-tools-status{margin-left:auto;padding-left:12px;border-left:1px solid #edf1f7}
.promotion-certificates-page-tune .bulk-actions .btn{min-height:38px}
.promotion-certificates-page-tune .table-wrap{border:1px solid #d8dee9;border-radius:12px;overflow:auto}
.promotion-certificates-page-tune .table-wrap table{border:0}
.promotion-certificates-page-tune .table-wrap th:first-child,.promotion-certificates-page-tune .table-wrap td:first-child{border-left:0}
.promotion-certificates-page-tune .table-wrap th:last-child,.promotion-certificates-page-tune .table-wrap td:last-child{border-right:0}
.promotion-certificates-page-tune .table-wrap thead tr:first-child th{border-top:0}
.promotion-certificates-page-tune .table-wrap tbody tr:last-child td{border-bottom:0}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune{--ieum-side-width:0px}.promotion-certificates-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.promotion-certificates-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
@media(max-width:1320px){.promotion-certificates-page-tune .bulk-actions{justify-content:flex-start}.promotion-certificates-page-tune .bulk-tools-status{margin-left:0;padding-left:0;border-left:0;padding-top:8px;border-top:1px solid #edf1f7}}
@media(max-width:900px){.cards{grid-template-columns:1fr}.bulk{align-items:stretch}.bulk-actions{width:100%}.bulk-actions .btn{flex:1 1 auto}.certificate-modal{padding:10px}.certificate-modal-head{align-items:flex-start}.certificate-modal-frame-wrap{height:calc(100vh - 104px)}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-certificates-page-tune">
<?php echo ieum_admin_header('promotion_certificates', 'side'); ?>
<main class="wrap">
    <h1>승급증 인쇄</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 승급 완료자에게 바로 줄 수 있는 A4 승급증을 한 번에 준비합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="cards">
        <a class="card <?php echo $print_status === 'not_printed' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_certificates.php?<?php echo http_build_query(array_merge($base_qs, array('print_status' => 'not_printed'))); ?>"><span>인쇄 대기</span><b><?php echo number_format((int) $counts['not_printed']); ?>명</b></a>
        <a class="card <?php echo $print_status === 'printed' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_certificates.php?<?php echo http_build_query(array_merge($base_qs, array('print_status' => 'printed'))); ?>"><span>인쇄 완료</span><b><?php echo number_format((int) $counts['printed']); ?>명</b></a>
        <a class="card <?php echo $print_status === 'all' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_certificates.php?<?php echo http_build_query(array_merge($base_qs, array('print_status' => 'all'))); ?>"><span>이번 달 승급 완료</span><b><?php echo number_format((int) $counts['all']); ?>명</b></a>
    </section>

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
        <select name="print_status">
            <option value="not_printed" <?php echo get_selected($print_status, 'not_printed'); ?>>인쇄 대기</option>
            <option value="printed" <?php echo get_selected($print_status, 'printed'); ?>>인쇄 완료</option>
            <option value="all" <?php echo get_selected($print_status, 'all'); ?>>전체</option>
        </select>
        <button class="btn primary" type="submit">조회</button>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php?<?php echo http_build_query($base_qs); ?>">승급 대상</a>
    </form>

    <form id="certificateForm" class="panel" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <section class="bulk">
            <div class="bulk-copy">
                <strong>선택한 원생 승급증 처리</strong>
                <div class="muted">승급증은 팝업에서 미리 확인하고, 인쇄 후 바로 완료 표시까지 처리합니다.</div>
            </div>
            <div class="bulk-actions">
                <div class="bulk-tools">
                    <label class="issue-date-box">승급증 발급일 <input type="date" id="certificateIssueDate" value="<?php echo get_text($default_issue_date); ?>" title="비워두면 각 원생의 승급일로 인쇄됩니다."></label>
                    <button class="btn" type="button" id="selectAll">전체 선택</button>
                    <button class="btn" type="button" id="clearAll">선택 해제</button>
                    <span class="selected-summary">선택 <b id="selectedCount">0</b>명</span>
                    <button class="btn primary" type="button" id="printSelected">선택 승급증 인쇄</button>
                    <a class="btn dark certificate-modal-open" href="<?php echo IEUM_URL; ?>/admin/promotion_certificates_print.php?<?php echo http_build_query($print_qs); ?>" data-title="현재 목록 전체 승급증" data-log-ids="<?php echo get_text(implode(',', $row_ids)); ?>">현재 목록 전체 인쇄</a>
                </div>
                <div class="bulk-tools bulk-tools-status">
                    <button class="btn" type="submit" name="action" value="mark_printed" id="markPrintedSelected" disabled>선택 인쇄완료</button>
                    <button class="btn danger" type="submit" name="action" value="reset_printed" id="resetPrintedSelected" disabled>완료표시 해제</button>
                </div>
            </div>
        </section>
        <div class="table-wrap" style="margin-top:16px">
            <table>
                <thead>
                    <tr>
                        <th class="select-cell">선택</th>
                        <th>원생</th>
                        <th>부/학년</th>
                        <th>승급 내용</th>
                        <th>승급일</th>
                        <th>승급증번호</th>
                        <th>인쇄상태</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) { ?>
                    <tr><td class="empty" colspan="8">조건에 맞는 승급 완료자가 없습니다.</td></tr>
                <?php } ?>
                <?php foreach ($rows as $row) {
                    $from_label = ieum_promotion_certificate_rank_text($row['from_belt'], (int) $row['from_poom_dan'], (int) $row['from_grade_level']);
                    $to_label = ieum_promotion_certificate_rank_text($row['to_belt'], (int) $row['to_poom_dan'], (int) $row['to_grade_level']);
                    $class_label = trim((isset($row['class_name']) ? $row['class_name'] : '') . ' ' . (isset($row['start_time']) ? $row['start_time'] : ''));
                    $printed = !empty($row['certificate_printed_at']);
                    $certificate_no = ieum_promotion_certificate_no($academy_id, (int) $row['log_id'], $row['promoted_at']);
                ?>
                    <tr>
                        <td class="select-cell"><input type="checkbox" class="log-check" name="log_ids[]" value="<?php echo (int) $row['log_id']; ?>"></td>
                        <td class="left"><strong><?php echo get_text($row['student_name']); ?></strong> <span class="muted"><?php echo get_text($row['student_code']); ?></span></td>
                        <td><?php echo get_text($class_label !== '' ? $class_label : '부 미지정'); ?><br><span class="muted"><?php echo get_text(ieum_promotion_certificate_grade_label($row['grade_group'])); ?></span></td>
                        <td class="rank-change"><?php echo get_text($from_label); ?> <span>→</span> <?php echo get_text($to_label); ?></td>
                        <td><?php echo get_text($row['promoted_at']); ?></td>
                        <td><span class="muted"><?php echo get_text($certificate_no); ?></span></td>
                        <td><?php echo $printed ? '<span class="pill done">인쇄완료</span><br><span class="muted">' . get_text(substr($row['certificate_printed_at'], 0, 16)) . '</span>' : '<span class="pill wait">인쇄대기</span>'; ?></td>
                        <td class="actions"><a class="btn certificate-modal-open" href="<?php echo IEUM_URL; ?>/admin/promotion_certificates_print.php?log_ids=<?php echo (int) $row['log_id']; ?>" data-title="<?php echo get_text($row['student_name']); ?> 승급증" data-log-ids="<?php echo (int) $row['log_id']; ?>">보기</a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.promotion-certificates-page-tune.ieum-dashboard-page';
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
<div class="certificate-modal" id="certificateModal" aria-hidden="true">
    <header class="certificate-modal-head">
        <div class="certificate-modal-title">승급증 미리보기<span id="certificateModalSubtitle">목록을 먼저 확인합니다.</span></div>
        <div class="certificate-modal-actions">
            <a class="btn" id="certificateOpenNew" target="_blank" rel="noopener" href="#">새 창</a>
            <button type="button" class="btn primary" id="certificateModalPrint">인쇄</button>
            <button type="button" class="btn" id="certificateMarkPrinted">인쇄완료 표시</button>
            <button type="button" class="certificate-modal-close" id="certificateModalClose" aria-label="닫기">×</button>
        </div>
    </header>
    <div class="certificate-modal-frame-wrap">
        <iframe class="certificate-modal-frame" id="certificateModalFrame" title="승급증 미리보기"></iframe>
    </div>
</div>
<script>
(function () {
    var checks = Array.prototype.slice.call(document.querySelectorAll('.log-check'));
    var selectAll = document.getElementById('selectAll');
    var clearAll = document.getElementById('clearAll');
    var printSelected = document.getElementById('printSelected');
    var modal = document.getElementById('certificateModal');
    var frame = document.getElementById('certificateModalFrame');
    var subtitle = document.getElementById('certificateModalSubtitle');
    var openNew = document.getElementById('certificateOpenNew');
    var closeBtn = document.getElementById('certificateModalClose');
    var printBtn = document.getElementById('certificateModalPrint');
    var markPrintedBtn = document.getElementById('certificateMarkPrinted');
    var markPrintedSelected = document.getElementById('markPrintedSelected');
    var resetPrintedSelected = document.getElementById('resetPrintedSelected');
    var selectedCount = document.getElementById('selectedCount');
    var issueDate = document.getElementById('certificateIssueDate');
    var form = document.getElementById('certificateForm');
    var currentModalIds = [];
    function selectedIds() {
        return checks.filter(function (checkbox) { return checkbox.checked; }).map(function (checkbox) { return checkbox.value; });
    }
    function updateSelectedState() {
        var count = selectedIds().length;
        if (selectedCount) {
            selectedCount.textContent = count;
        }
        if (markPrintedSelected) {
            markPrintedSelected.disabled = count === 0;
        }
        if (resetPrintedSelected) {
            resetPrintedSelected.disabled = count === 0;
        }
        checks.forEach(function (checkbox) {
            var row = checkbox.closest('tr');
            if (row) {
                row.classList.toggle('is-selected', checkbox.checked);
            }
        });
    }
    function openCertificateModal(url, label, ids) {
        if (issueDate && issueDate.value) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'issue_date=' + encodeURIComponent(issueDate.value);
        }
        currentModalIds = (ids || []).filter(Boolean);
        frame.src = url;
        openNew.href = url;
        subtitle.textContent = label || '목록을 먼저 확인합니다.';
        markPrintedBtn.disabled = currentModalIds.length === 0;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }
    function closeCertificateModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        frame.src = 'about:blank';
        openNew.href = '#';
        currentModalIds = [];
        markPrintedBtn.disabled = true;
    }
    function submitPrinted(ids) {
        Array.prototype.slice.call(form.querySelectorAll('.js-print-temp')).forEach(function (node) { node.remove(); });
        checks.forEach(function (checkbox) {
            checkbox.disabled = true;
        });
        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'mark_printed';
        action.className = 'js-print-temp';
        form.appendChild(action);
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'log_ids[]';
            input.value = id;
            input.className = 'js-print-temp';
            form.appendChild(input);
        });
        form.submit();
    }
    if (form) {
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            var action = submitter && submitter.name === 'action' ? submitter.value : '';
            var count = selectedIds().length;
            if ((action === 'mark_printed' || action === 'reset_printed') && count === 0) {
                event.preventDefault();
                alert('먼저 원생을 선택해 주세요.');
                return;
            }
            if (action === 'mark_printed' && count > 0 && !confirm('선택한 ' + count + '명을 인쇄완료로 표시할까요?')) {
                event.preventDefault();
            }
            if (action === 'reset_printed' && count > 0 && !confirm('선택한 ' + count + '명의 인쇄완료 표시를 해제할까요?')) {
                event.preventDefault();
            }
        });
    }
    selectAll.addEventListener('click', function () {
        checks.forEach(function (checkbox) { checkbox.checked = true; });
        updateSelectedState();
    });
    clearAll.addEventListener('click', function () {
        checks.forEach(function (checkbox) { checkbox.checked = false; });
        updateSelectedState();
    });
    checks.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateSelectedState);
    });
    printSelected.addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('승급증을 인쇄할 원생을 선택해 주세요.');
            return;
        }
        openCertificateModal('<?php echo IEUM_URL; ?>/admin/promotion_certificates_print.php?log_ids=' + encodeURIComponent(ids.join(',')), '선택한 원생 ' + ids.length + '명 승급증', ids);
    });
    Array.prototype.slice.call(document.querySelectorAll('.certificate-modal-open')).forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var ids = (link.getAttribute('data-log-ids') || '').split(',').map(function (id) { return id.trim(); }).filter(Boolean);
            openCertificateModal(link.href, link.getAttribute('data-title') || '승급증 미리보기', ids);
        });
    });
    printBtn.addEventListener('click', function () {
        if (frame.contentWindow) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }
    });
    markPrintedBtn.addEventListener('click', function () {
        if (!currentModalIds.length) {
            alert('인쇄 완료로 표시할 원생이 없습니다.');
            return;
        }
        if (!confirm('현재 미리보기 대상 ' + currentModalIds.length + '명을 인쇄 완료로 표시할까요?')) {
            return;
        }
        submitPrinted(currentModalIds);
    });
    closeBtn.addEventListener('click', closeCertificateModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeCertificateModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeCertificateModal();
        }
    });
    updateSelectedState();
})();
</script>
</body>
</html>
