<?php
$sub_menu = '950180';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';

$g5['title'] = '아이이음 인성 체크표';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$week_start = isset($_GET['week_start']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['week_start'])) : date('Y-m-d', strtotime('monday this week', strtotime(G5_TIME_YMD)));
if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $week_start)) {
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime(G5_TIME_YMD)));
}
$week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : "";

function ieum_sheet_grade_label($value)
{
    $labels = array(
        '' => '미지정',
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
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_sheet_weekday_label($date)
{
    $labels = array('일', '월', '화', '수', '목', '금', '토');
    return $labels[(int) date('w', strtotime($date))];
}

$selected_class = sql_fetch("
    select class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and class_time_id = '{$class_time_id}'
     limit 1
", false);
$class_label = isset($selected_class['class_name']) ? trim($selected_class['class_name'] . ' ' . $selected_class['start_time']) : '전체 부';
$scheduled_dates = array_keys(ieum_attendance_scheduled_dates('mon,tue,wed,thu,fri', $week_start, $week_end, $academy_id));
$excluded = array();
$all_weekdays = array();
for ($ts = strtotime($week_start); $ts <= strtotime($week_end); $ts = strtotime('+1 day', $ts)) {
    $date = date('Y-m-d', $ts);
    if ((int) date('N', $ts) > 5) {
        continue;
    }
    $all_weekdays[] = $date;
    if (!in_array($date, $scheduled_dates, true)) {
        $excluded[] = $date . ' ' . ieum_sheet_weekday_label($date) . (ieum_attendance_fixed_public_holiday_label($date) ? '(' . ieum_attendance_fixed_public_holiday_label($date) . ')' : '');
    }
}

$students = sql_query("
    select s.student_code, s.student_name, s.school_name, s.grade_group, s.admission_date, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1280px;margin:20px auto;padding:0 14px}.topline{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;margin-bottom:12px}h1{margin:0;font-size:26px}.meta{color:#5b6472;margin-top:6px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#1769c2;color:#fff;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.guide{border:1px solid #d9dee7;background:#fff;border-radius:8px;padding:10px;margin-bottom:10px;color:#344054}.sheet{width:100%;border-collapse:collapse;background:#fff;table-layout:fixed}th,td{border:1px solid #9aa7b8;padding:5px;text-align:center;font-size:11px;vertical-align:middle}th{background:#72829d;color:#fff}.student-head{width:132px}.student-name{font-weight:900;font-size:13px}.student-sub{color:#344054;font-size:10px;line-height:1.35}.day-head{background:#15204a!important;color:#fff!important;font-size:13px}.trait{width:42px;height:26px}.memo{width:88px}.blank{height:28px}.excluded{color:#667085;font-size:12px;margin-top:5px}.new{color:#9a5b00;font-weight:900}.date-small{display:block;font-size:10px;color:#d8e2ff;margin-top:2px}@media print{@page{size:A4 landscape;margin:5mm}body{background:#fff;color:#111}.wrap{max-width:none;margin:0;padding:0}.print-hide{display:none}.topline{margin-bottom:4px}h1{font-size:16px}.meta,.excluded{font-size:9px;margin-top:2px}.guide{font-size:9px;padding:5px;margin-bottom:4px;border-color:#999}.sheet{page-break-inside:auto}tr{page-break-inside:avoid;page-break-after:auto}th,td{font-size:8px;padding:2px;border-color:#666}.student-head{width:96px}.student-name{font-size:9.5px}.student-sub{font-size:7.5px}.day-head{font-size:9px}.date-small{font-size:7px}.trait{width:28px;height:18px}.memo{width:58px}.blank{height:20px}}
</style>
</head>
<body>
<main class="wrap">
    <section class="topline">
        <div>
            <h1><?php echo get_text($class_label); ?> 주간 인성 체크표</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($week_start); ?> ~ <?php echo get_text($week_end); ?> · 정상 수업일만 표시</div>
            <?php if ($excluded) { ?><div class="excluded">제외일: <?php echo get_text(implode(', ', $excluded)); ?> · 도장 휴관일은 수업일 설정에서 반영됩니다.</div><?php } ?>
        </div>
        <button type="button" class="btn print-hide" onclick="window.print()">인쇄</button>
    </section>
    <div class="guide print-hide">주 1회 출력해서 수업 중 바로 기록하는 체크표입니다. 인성 칸에는 바를 정(正) 방식으로 누적하고, 입력 화면에서는 기본 4점에서 포인트와 메모를 보고 필요한 학생만 수정하세요.</div>
    <table class="sheet">
        <thead>
            <tr>
                <th class="student-head" rowspan="2">학생</th>
                <?php foreach ($scheduled_dates as $date) { ?>
                <th class="day-head" colspan="5"><?php echo get_text(ieum_sheet_weekday_label($date)); ?><span class="date-small"><?php echo get_text(substr($date, 5)); ?></span></th>
                <?php } ?>
            </tr>
            <tr>
                <?php foreach ($scheduled_dates as $date) { ?>
                <th class="trait">예절</th><th class="trait">집중</th><th class="trait">자신감</th><th class="trait">배려</th><th class="memo">메모</th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
        <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; $admission = $row['admission_date'] && $row['admission_date'] !== '0000-00-00' ? $row['admission_date'] : ''; ?>
            <tr>
                <td class="student-head">
                    <div class="student-name"><?php echo get_text($row['student_name']); ?></div>
                    <div class="student-sub"><?php echo get_text(ieum_sheet_grade_label($row['grade_group'])); ?> · <?php echo get_text($row['school_name'] ?: '-'); ?></div>
                    <div class="student-sub">입관일: <?php echo get_text($admission ?: '-'); ?><?php echo $admission && $admission >= $week_start && $admission <= $week_end ? ' <span class="new">신규</span>' : ''; ?></div>
                </td>
                <?php foreach ($scheduled_dates as $date) { ?>
                <td class="blank"></td><td class="blank"></td><td class="blank"></td><td class="blank"></td><td class="blank"></td>
                <?php } ?>
            </tr>
        <?php } ?>
        <?php if ($i === 0) { ?><tr><td colspan="<?php echo max(1, 1 + count($scheduled_dates) * 5); ?>">출력할 학생이 없습니다.</td></tr><?php } ?>
        <?php if (!$scheduled_dates) { ?><tr><td colspan="1">이번 주 정상 수업일이 없습니다.</td></tr><?php } ?>
        </tbody>
    </table>
</main>
</body>
</html>
