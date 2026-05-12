<?php
$sub_menu = '950180';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';

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
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;

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
        $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : 0;
        $scores = isset($_POST['scores']) && is_array($_POST['scores']) ? $_POST['scores'] : array();
        $saved = 0;
        $created_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
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
                 limit 1
            ", false);
            if (!isset($student['student_id'])) {
                continue;
            }
            $courtesy = max(1, min(5, isset($row['courtesy']) ? (int) $row['courtesy'] : 4));
            $focus = max(1, min(5, isset($row['focus']) ? (int) $row['focus'] : 4));
            $confidence = max(1, min(5, isset($row['confidence']) ? (int) $row['confidence'] : 4));
            $consideration = max(1, min(5, isset($row['consideration']) ? (int) $row['consideration'] : 4));
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
                        memo = '" . sql_escape_string($memo) . "',
                        created_by = '{$created_by}',
                        created_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        courtesy = values(courtesy),
                        focus = values(focus),
                        confidence = values(confidence),
                        consideration = values(consideration),
                        memo = values(memo),
                        updated_at = '" . G5_TIME_YMDHIS . "'
            ");
            $saved++;
        }
        $message = '인성 점수 ' . number_format($saved) . '명을 저장했습니다.';
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
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : "";
$selected_class_label = '전체 부';
foreach ($class_options as $class) {
    if ((int) $class['class_time_id'] === $class_time_id) {
        $selected_class_label = trim($class['class_name'] . ' ' . $class['start_time']);
        break;
    }
}
$students = sql_query("
    select s.student_id, s.student_code, s.student_name, s.grade_group, c.class_name, c.start_time,
           coalesce(r.courtesy, 4) as courtesy,
           coalesce(r.focus, 4) as focus,
           coalesce(r.confidence, 4) as confidence,
           coalesce(r.consideration, 4) as consideration,
           coalesce(r.memo, '') as character_memo
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_REPORT_CHARACTER_TABLE . " r on r.student_id = s.student_id and r.academy_id = s.academy_id and r.week_start = '{$week_sql}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);

$report_students = sql_query("
    select s.student_id, s.student_code, s.student_name, s.admission_date, s.attendance_days, s.grade_group, c.class_name, c.start_time
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}h1{margin:0;font-size:28px}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:800;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.print{background:#111827;border-color:#111827;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.score{width:74px}.memo{min-width:180px;width:100%}.quick-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#344054;margin-top:12px}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.badge.first{background:#fff4e6;color:#9a5b00}.total{font-size:20px;font-weight:900;color:#1769c2}.class-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.class-tab{display:inline-flex;border:1px solid #d8dee9;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:7px 12px;font-weight:900}.class-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}@media(max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}
</style>
</head>
<body>
<?php echo ieum_admin_header('character'); ?>
<?php echo ieum_admin_subnav('character'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>인성 입력</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 기본값 4점 · 예절/집중력/자신감/배려심</div>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <form method="get" class="filters">
        <input type="date" name="week_start" value="<?php echo get_text($week_start); ?>">
        <select name="class_time_id">
            <option value="0">전체 부</option>
            <?php foreach ($class_options as $class) { ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
            </option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">주간 조회</button>
        <a class="btn print" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_sheet.php?week_start=<?php echo get_text($week_start); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">체크표 인쇄</a>
    </form>
    <div class="class-tabs">
        <a class="class-tab <?php echo $class_time_id ? '' : 'active'; ?>" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($week_start); ?>&amp;class_time_id=0">전체 부</a>
        <?php foreach ($class_options as $class) { ?>
        <a class="class-tab <?php echo $class_time_id === (int) $class['class_time_id'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($week_start); ?>&amp;class_time_id=<?php echo (int) $class['class_time_id']; ?>">
            <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
        </a>
        <?php } ?>
    </div>
    <div class="quick-note">대부분 학생은 기본 4점으로 두고, 이번 주에 특별히 눈에 띈 학생만 1~5점으로 수정하는 5분 입력 흐름입니다. 입관 전 주차와 미입력 주차는 월간 점수에서 제외되고, 성실 점수는 입관일 이후 정상 수업일 출석률로 자동 반영됩니다.</div>

    <section class="panel">
        <div class="section-head">
            <h2><?php echo get_text($selected_class_label); ?> 주간 인성 입력</h2>
            <a class="btn print" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_sheet.php?week_start=<?php echo get_text($week_start); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">이 부 체크표 인쇄</a>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="week_start" value="<?php echo get_text($week_start); ?>">
            <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
            <table>
                <thead>
                    <tr><th>학생</th><th>수업부</th><th>예절</th><th>집중력</th><th>자신감</th><th>배려심</th><th>메모</th></tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; $sid = (int) $row['student_id']; ?>
                <tr>
                    <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                    <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                    <td><select class="score" name="scores[<?php echo $sid; ?>][courtesy]"><?php echo ieum_character_score_options($row['courtesy']); ?></select></td>
                    <td><select class="score" name="scores[<?php echo $sid; ?>][focus]"><?php echo ieum_character_score_options($row['focus']); ?></select></td>
                    <td><select class="score" name="scores[<?php echo $sid; ?>][confidence]"><?php echo ieum_character_score_options($row['confidence']); ?></select></td>
                    <td><select class="score" name="scores[<?php echo $sid; ?>][consideration]"><?php echo ieum_character_score_options($row['consideration']); ?></select></td>
                    <td><input class="memo" type="text" name="scores[<?php echo $sid; ?>][memo]" value="<?php echo get_text($row['character_memo']); ?>" placeholder="이번 주 특이사항만 간단히"></td>
                </tr>
                <?php } ?>
                <?php if ($i === 0) { ?><tr><td colspan="7">사용 중인 학생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
            <p><button type="submit" class="btn primary">이번 주 인성 점수 저장</button></p>
        </form>
    </section>

    <section class="panel">
        <h2><?php echo get_text($month); ?> <?php echo get_text($selected_class_label); ?> 인성 점수 자동 계산</h2>
        <table>
            <thead>
                <tr><th>학생</th><th>평가 주차</th><th>예절</th><th>집중력</th><th>자신감</th><th>배려심</th><th>성실</th><th>총점</th><th>기준</th></tr>
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
                <td class="total"><?php echo number_format((int) $score['total_score']); ?>점</td>
                <td><?php if ($score['is_first_month']) { ?><span class="badge first">입관 첫 달</span><br><?php } ?><span class="badge"><?php echo get_text($score['base_date']); ?>부터</span></td>
            </tr>
            <?php } ?>
            <?php if ($ri === 0) { ?><tr><td colspan="9">사용 중인 학생이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
