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

$selected_class = sql_fetch("
    select class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and class_time_id = '{$class_time_id}'
     limit 1
", false);
$class_label = isset($selected_class['class_name']) ? trim($selected_class['class_name'] . ' ' . $selected_class['start_time']) : '전체 부';

$students = sql_query("
    select s.student_code, s.student_name, s.school_name, s.grade_group, s.memo, c.class_name, c.start_time
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1120px;margin:24px auto;padding:0 18px}.topline{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;margin-bottom:14px}h1{margin:0;font-size:28px}.meta{color:#5b6472;margin-top:6px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#1769c2;color:#fff;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.guide{border:1px solid #d9dee7;background:#fff;border-radius:8px;padding:12px;margin-bottom:14px;color:#344054}.sheet{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #aeb8c6;padding:8px;text-align:center;font-size:13px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.check{width:36px;height:24px}.point{width:68px}.memo{width:230px;height:34px}.small{font-size:12px;color:#5b6472}.student{font-weight:900;font-size:14px}@media print{@page{size:A4;margin:7mm}body{background:#fff;color:#111}.wrap{max-width:none;margin:0;padding:0}.print-hide{display:none}.topline{margin-bottom:6px}h1{font-size:18px}.meta{font-size:11px}.guide{font-size:10px;padding:6px;margin-bottom:6px;border-color:#999}.sheet{page-break-inside:auto}tr{page-break-inside:avoid;page-break-after:auto}th,td{font-size:10px;padding:4px;border-color:#777}.student{font-size:11px}.small{font-size:9px}.memo{height:25px}.check{width:28px}.point{width:48px}}
</style>
</head>
<body>
<main class="wrap">
    <section class="topline">
        <div>
            <h1><?php echo get_text($class_label); ?> 인성 체크표</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($week_start); ?> 주간 · 수업 중 체크 후 인성 입력에 반영</div>
        </div>
        <button type="button" class="btn print-hide" onclick="window.print()">인쇄</button>
    </section>
    <div class="guide print-hide">수업 중에는 점수까지 정확히 쓰기보다 출석, 인성 포인트, 특이사항만 빠르게 남기세요. 수업 직후 인성 입력 화면에서 같은 부 순서로 1~5점을 저장하면 됩니다.</div>
    <table class="sheet">
        <thead>
            <tr>
                <th>출석</th>
                <th>학생</th>
                <th>학년/학교</th>
                <th>예절</th>
                <th>집중</th>
                <th>자신감</th>
                <th>배려</th>
                <th>수업 포인트</th>
                <th>메모</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; ?>
            <tr>
                <td class="check">□</td>
                <td class="left"><div class="student"><?php echo get_text($row['student_name']); ?></div><div class="small"><?php echo get_text($row['student_code']); ?> · <?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></div></td>
                <td><?php echo get_text(ieum_sheet_grade_label($row['grade_group'])); ?><br><span class="small"><?php echo get_text($row['school_name'] ?: '-'); ?></span></td>
                <td class="point">+ / -</td>
                <td class="point">+ / -</td>
                <td class="point">+ / -</td>
                <td class="point">+ / -</td>
                <td class="point">☆</td>
                <td class="memo left"></td>
            </tr>
        <?php } ?>
        <?php if ($i === 0) { ?><tr><td colspan="9">출력할 학생이 없습니다.</td></tr><?php } ?>
        </tbody>
    </table>
</main>
</body>
</html>
