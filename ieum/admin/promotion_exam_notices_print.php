<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '심사 안내문 인쇄';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_exam_notice_print_grade_label($value)
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

function ieum_exam_notice_print_target_rows($academy, $academy_id, $month, $exam_type, $program_code, $class_time_id)
{
    $academy_id = (int) $academy_id;
    $exam_type = $exam_type === 'poomdan' ? 'poomdan' : 'promotion';
    $where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 and coalesce(s.promotion_enabled, 1) = 1 ";
    if ($program_code !== '') {
        $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
    }
    if ($class_time_id > 0) {
        $where .= " and s.class_time_id = '{$class_time_id}' ";
    }

    $rows = array();
    $result = sql_query("
        select s.*, c.class_name, c.start_time, c.sort_order
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
          {$where}
      order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
    ", false);
    while ($student = sql_fetch_array($result)) {
        $status = ieum_promotion_status($academy, $student, $month);
        if (empty($status['enabled']) || (!$status['due_this_month'] && !$status['overdue'])) {
            continue;
        }
        if ($exam_type === 'poomdan' && empty($status['is_poomdan_exam'])) {
            continue;
        }
        if ($exam_type === 'promotion' && !empty($status['is_poomdan_exam'])) {
            continue;
        }
        $student['_promotion_status'] = $status;
        $rows[] = $student;
    }
    return $rows;
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$exam_type = isset($_GET['exam_type']) && $_GET['exam_type'] === 'poomdan' ? 'poomdan' : 'promotion';
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$selected_student_ids = array();
if (isset($_GET['student_ids'])) {
    $raw_student_ids = $_GET['student_ids'];
    if (!is_array($raw_student_ids)) {
        $raw_student_ids = explode(',', (string) $raw_student_ids);
    }
    foreach ($raw_student_ids as $raw_student_id) {
        $student_id = (int) $raw_student_id;
        if ($student_id > 0) {
            $selected_student_ids[$student_id] = $student_id;
        }
    }
}

$rows = ieum_exam_notice_print_target_rows($academy, $academy_id, $month, $exam_type, $program_code, $class_time_id);
if ($selected_student_ids) {
    $rows = array_values(array_filter($rows, function ($row) use ($selected_student_ids) {
        return isset($selected_student_ids[(int) $row['student_id']]);
    }));
}
$exam_label = ieum_promotion_exam_type_label($exam_type);
$print_scope_label = $selected_student_ids ? '선택 인쇄' : '전체 인쇄';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 18px;background:#111827;color:#fff}.toolbar h1{margin:0;font-size:18px}.toolbar-actions{display:flex;gap:8px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.35);background:#fff;color:#111827;text-decoration:none;font-weight:900;cursor:pointer}.bundle{max-width:980px;margin:24px auto;padding:0 16px}.sheet{width:210mm;min-height:297mm;margin:0 auto 18px;background:#fff;padding:18mm 17mm;border:1px solid #d9dee7;box-shadow:0 16px 42px rgba(15,23,42,.12);page-break-after:always}.sheet:last-child{page-break-after:auto}.brand{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:3px solid #1f4fba;padding-bottom:14px}.brand-name{font-size:24px;font-weight:1000;color:#1f4fba}.doc-type{font-size:13px;color:#667085;font-weight:900;letter-spacing:0}.title{margin:32px 0 18px;text-align:center}.title p{margin:0;color:#667085;font-weight:900}.title h2{margin:8px 0 0;font-size:34px;letter-spacing:0}.student-box{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:22px 0}.field{border:1px solid #d8dee9;border-radius:12px;padding:12px 14px}.field span{display:block;color:#667085;font-size:12px;font-weight:900}.field strong{display:block;margin-top:4px;font-size:19px}.message{white-space:pre-wrap;line-height:1.75;font-size:18px;border:1px solid #d8dee9;border-radius:14px;padding:20px;margin:18px 0;background:#fbfdff}.notice{border-radius:14px;background:#f8fafc;border:1px solid #d8dee9;padding:16px;line-height:1.65;color:#344054}.notice strong{color:#111827}.footer{margin-top:30px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px;color:#667085}.date{font-size:16px;color:#111827;font-weight:900}.seal{min-width:160px;text-align:center;border-top:1px solid #cfd6df;padding-top:10px;font-weight:900;color:#111827}.empty{background:#fff;border:1px dashed #cfd6df;border-radius:14px;padding:40px;text-align:center;color:#667085}@media print{body{background:#fff}.toolbar{display:none}.bundle{max-width:none;margin:0;padding:0}.sheet{width:210mm;height:297mm;margin:0;border:0;box-shadow:none;page-break-after:always}.sheet:last-child{page-break-after:auto}}@page{size:A4;margin:0}
</style>
</head>
<body>
<div class="toolbar">
    <h1><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> <?php echo get_text($exam_label); ?> 안내문 <?php echo get_text($print_scope_label); ?> <?php echo number_format(count($rows)); ?>명</h1>
    <div class="toolbar-actions">
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => $exam_type, 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>">관리 화면</a>
        <button class="btn" type="button" onclick="window.print()">인쇄</button>
    </div>
</div>
<main class="bundle">
<?php if (!$rows) { ?>
    <div class="empty">인쇄할 심사 안내 대상자가 없습니다.</div>
<?php } ?>
<?php foreach ($rows as $row) {
    $status = $row['_promotion_status'];
    $next_rank = $status['next_rank'];
    $fee = ieum_promotion_get_fee($academy_id, $exam_type, $next_rank);
    $fee_amount = $fee ? (int) $fee['fee_amount'] : 0;
    $target_label = isset($next_rank['exam_label']) ? $next_rank['exam_label'] : ieum_promotion_rank_display($academy, $next_rank);
    $post_pass_label = isset($next_rank['post_pass_label']) ? $next_rank['post_pass_label'] : ieum_promotion_rank_display($academy, $next_rank);
    $class_label = trim((string) $row['class_name'] . ' ' . (string) $row['start_time']);
    $message = ieum_promotion_render_notice_message($academy, $row, $status, $fee_amount);
?>
    <section class="sheet">
        <div class="brand">
            <div>
                <div class="brand-name"><?php echo get_text($academy['academy_name']); ?></div>
                <div class="doc-type">IEUM EXAM NOTICE</div>
            </div>
            <div><?php echo get_text($month); ?></div>
        </div>
        <div class="title">
            <p><?php echo get_text($exam_label); ?></p>
            <h2><?php echo get_text($row['student_name']); ?> 학생 안내문</h2>
        </div>
        <div class="student-box">
            <div class="field"><span>학생</span><strong><?php echo get_text($row['student_name']); ?></strong></div>
            <div class="field"><span>학년/부</span><strong><?php echo get_text(ieum_exam_notice_print_grade_label($row['grade_group'])); ?></strong></div>
            <div class="field"><span>수업 부</span><strong><?php echo get_text($class_label !== '' ? $class_label : '부 미지정'); ?></strong></div>
            <div class="field"><span>현재 단계</span><strong><?php echo get_text(ieum_promotion_student_current_rank_display($row)); ?></strong></div>
            <div class="field"><span>심사 단계</span><strong><?php echo get_text($target_label); ?></strong></div>
            <div class="field"><span>합격 후 단계</span><strong><?php echo get_text($post_pass_label); ?></strong></div>
            <div class="field"><span>심사 예정일</span><strong><?php echo get_text($status['next_date'] !== '' ? $status['next_date'] : '별도 안내'); ?></strong></div>
            <div class="field"><span>심사비</span><strong><?php echo number_format($fee_amount); ?>원</strong></div>
        </div>
        <div class="message"><?php echo get_text($message); ?></div>
        <div class="notice">
            <strong>안내드립니다.</strong><br>
            위 내용은 <?php echo get_text($academy['academy_name']); ?>의 심사 운영 기준에 따라 자동 작성되었습니다.
            세부 일정이나 준비물은 도장 공지를 함께 확인해 주세요.
        </div>
        <div class="footer">
            <div>
                <div class="date"><?php echo date('Y년 m월 d일'); ?></div>
                <div>아이의 성장을 함께 응원하겠습니다.</div>
            </div>
            <div class="seal"><?php echo get_text($academy['academy_name']); ?></div>
        </div>
    </section>
<?php } ?>
</main>
</body>
</html>
