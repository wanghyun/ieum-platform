<?php
$sub_menu = '950183';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 월말 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

ieum_character_ensure_table();
ieum_character_mission_ensure_tables();

function ieum_monthly_close_valid_month($month)
{
    return preg_match('/^\d{4}\-\d{2}$/', $month) ? $month : date('Y-m');
}

function ieum_monthly_close_month_label($month)
{
    $time = strtotime($month . '-01');
    return $time ? date('Y년 n월', $time) : $month;
}

function ieum_monthly_close_grade_label($value)
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
        'jump_rope' => '줄넘기',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '미지정');
}

function ieum_monthly_close_expected_weeks($month)
{
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $end = $month_end;
    if ($month === date('Y-m') && G5_TIME_YMD < $month_end) {
        $end = G5_TIME_YMD;
    }

    $count = 0;
    for ($time = strtotime($month_start); $time <= strtotime($end); $time = strtotime('+1 day', $time)) {
        if ((int) date('N', $time) === 1) {
            $count++;
        }
    }

    return max(1, $count);
}

function ieum_monthly_close_primary_phone_count($academy_id, $student_id)
{
    $row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and student_id = '" . (int) $student_id . "'
           and is_active = 1
           and is_primary = 1
           and guardian_phone <> ''
    ", false);

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_monthly_close_percent($value, $total)
{
    $total = (int) $total;
    if ($total <= 0) {
        return 0;
    }

    return (int) round(((int) $value / $total) * 100);
}

$month = isset($_GET['month']) ? ieum_monthly_close_valid_month(trim($_GET['month'])) : date('Y-m');
$issue = isset($_GET['issue']) ? preg_replace('/[^a-z_]/', '', trim($_GET['issue'])) : 'all';
if (!in_array($issue, array('all', 'critical', 'character', 'phone', 'mission', 'tuition', 'absent'), true)) {
    $issue = 'all';
}
$month_sql = sql_escape_string($month);
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month = date('Y-m', strtotime($month_start . ' +1 month'));
$expected_weeks = ieum_monthly_close_expected_weeks($month);
$billing_month = $month;

ieum_tuition_ensure_month($academy_id, $billing_month);
$tuition_settings = ieum_tuition_get_settings($academy_id);
$overdue_days = max(1, min(30, (int) (isset($tuition_settings['overdue_after_days']) ? $tuition_settings['overdue_after_days'] : 5)));

$students = array();
$student_query = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_phone, s.birth_date,
           s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
           c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_query)) {
    $students[] = $row;
}

$total_students = count($students);
$summary = array(
    'report_ready' => 0,
    'report_send_ready' => 0,
    'critical' => 0,
    'no_phone' => 0,
    'character_missing' => 0,
    'mission_done' => 0,
    'mission_wait' => 0,
    'tuition_unpaid' => 0,
    'tuition_overdue' => 0,
    'long_absent' => 0,
);
$class_summary = array();
$blockers = array();
$issue_counts = array(
    'all' => 0,
    'critical' => 0,
    'character' => 0,
    'phone' => 0,
    'mission' => 0,
    'tuition' => 0,
    'absent' => 0,
);
$today = G5_TIME_YMD;
$long_absent_start = date('Y-m-d', strtotime($today . ' -14 days'));

foreach ($students as $student) {
    $student_id = (int) $student['student_id'];
    $class_label = trim((string) $student['class_name']) !== ''
        ? trim($student['class_name'] . ' ' . $student['start_time'])
        : '부 미지정';
    if (!isset($class_summary[$class_label])) {
        $class_summary[$class_label] = array(
            'total' => 0,
            'ready' => 0,
            'character_missing' => 0,
            'no_phone' => 0,
            'mission_done' => 0,
        );
    }
    $class_summary[$class_label]['total']++;

    $score = ieum_character_month_score($academy_id, $student, $month);
    $evaluated_weeks = isset($score['evaluated_weeks']) ? (int) $score['evaluated_weeks'] : 0;
    $character_missing = $evaluated_weeks < $expected_weeks;
    $phone_count = ieum_monthly_close_primary_phone_count($academy_id, $student_id);
    $mission = ieum_character_mission_report($academy_id, $student_id, $month);
    $mission_done = $mission && !empty($mission['is_participated']);

    $payment = sql_fetch("
        select payment_id, due_date, amount_due, amount_paid, status
          from " . IEUM_TUITION_PAYMENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and billing_month = '{$month_sql}'
         limit 1
    ", false);
    $balance = isset($payment['amount_due']) ? max(0, (int) $payment['amount_due'] - (int) $payment['amount_paid']) : 0;
    $tuition_unpaid = isset($payment['status']) && $payment['status'] !== 'paid' && $balance > 0;
    $tuition_overdue = $tuition_unpaid
        && !empty($payment['due_date'])
        && $payment['due_date'] !== '0000-00-00'
        && (int) floor((strtotime($today) - strtotime($payment['due_date'])) / 86400) > $overdue_days;

    $attendance_row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_ATTENDANCE_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and attendance_date between '{$long_absent_start}' and '{$today}'
    ", false);
    $long_absent = isset($attendance_row['cnt']) && (int) $attendance_row['cnt'] <= 0;

    if ($character_missing) {
        $summary['character_missing']++;
        $class_summary[$class_label]['character_missing']++;
    }
    if ($phone_count > 0) {
        $summary['report_ready']++;
    } else {
        $summary['no_phone']++;
        $class_summary[$class_label]['no_phone']++;
    }
    if ($mission_done) {
        $summary['mission_done']++;
        $class_summary[$class_label]['mission_done']++;
    } else {
        $summary['mission_wait']++;
    }
    if ($tuition_unpaid) {
        $summary['tuition_unpaid']++;
    }
    if ($tuition_overdue) {
        $summary['tuition_overdue']++;
    }
    if ($long_absent) {
        $summary['long_absent']++;
    }

    $reasons = array();
    $flags = array();
    $priority = 100;
    if ($character_missing) {
        $reasons[] = '인성 입력 ' . $evaluated_weeks . '/' . $expected_weeks . '주';
        $flags['character'] = true;
        $priority = min($priority, 10);
    }
    if ($phone_count <= 0) {
        $reasons[] = '대표 보호자 연락처 없음';
        $flags['phone'] = true;
        $priority = min($priority, 5);
    }
    if (!$mission_done) {
        $reasons[] = '아이잘해 미참여';
        $flags['mission'] = true;
        $priority = min($priority, 60);
    }
    if ($tuition_overdue) {
        $reasons[] = '수련비 미납 ' . number_format($balance) . '원';
        $flags['tuition'] = true;
        $priority = min($priority, 20);
    } elseif ($tuition_unpaid) {
        $reasons[] = '수련비 미결제';
        $flags['tuition'] = true;
        $priority = min($priority, 40);
    }
    if ($long_absent) {
        $reasons[] = '14일 이상 출석 없음';
        $flags['absent'] = true;
        $priority = min($priority, 15);
    }

    $report_ready = $phone_count > 0 && !$character_missing;
    if ($report_ready) {
        $summary['report_send_ready']++;
        $class_summary[$class_label]['ready']++;
    }

    if ($reasons) {
        $is_critical = isset($flags['phone']) || isset($flags['character']) || ($tuition_overdue ? true : false) || isset($flags['absent']);
        $flags['all'] = true;
        if ($is_critical) {
            $flags['critical'] = true;
            $summary['critical']++;
        }
        foreach ($flags as $flag => $active) {
            if ($active && isset($issue_counts[$flag])) {
                $issue_counts[$flag]++;
            }
        }
        $blockers[] = array(
            'student' => $student,
            'class_label' => $class_label,
            'reasons' => $reasons,
            'flags' => $flags,
            'priority' => $priority,
        );
    }
}

usort($blockers, function ($a, $b) {
    if ($a['priority'] === $b['priority']) {
        return strcmp($a['student']['student_name'], $b['student']['student_name']);
    }
    return $a['priority'] - $b['priority'];
});

$filtered_blockers = array();
foreach ($blockers as $blocker) {
    if ($issue === 'all' || !empty($blocker['flags'][$issue])) {
        $filtered_blockers[] = $blocker;
    }
}

$ready_for_send = (int) $summary['report_send_ready'];
$close_score = 0;
if ($total_students > 0) {
    $report_percent = ieum_monthly_close_percent($ready_for_send, $total_students);
    $tuition_percent = ieum_monthly_close_percent($total_students - $summary['tuition_overdue'], $total_students);
    $mission_percent = ieum_monthly_close_percent($summary['mission_done'], $total_students);
    $close_score = (int) round(($report_percent * 0.5) + ($tuition_percent * 0.3) + ($mission_percent * 0.2));
}

$status_text = '점검 필요';
if ($close_score >= 90) {
    $status_text = '발송 준비';
} elseif ($close_score >= 70) {
    $status_text = '마감 진행 중';
}

$blocker_preview = array_slice($filtered_blockers, 0, 40);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}h1{margin:0;font-size:32px}.meta{color:#667085;margin-top:6px;line-height:1.45}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 13px;font-weight:900;cursor:pointer}.primary{background:#1947ba;border-color:#1947ba;color:#fff}.dark{background:#111827;border-color:#111827;color:#fff}input,select{border:1px solid #cfd6df;border-radius:8px;padding:9px;font-size:14px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.close-hero{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:stretch}.score-card{background:linear-gradient(135deg,#1947ba,#2c2a25);color:#fff;border:0}.score-big{font-size:58px;font-weight:1000;line-height:1;margin-top:12px}.score-card p{color:#dbe6ff;line-height:1.6}.routine{display:grid;gap:10px}.routine-item{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#fbfcff}.routine-no{width:30px;height:30px;border-radius:999px;background:#1947ba;color:#fff;display:grid;place-items:center;font-weight:1000}.routine-item strong{display:block}.routine-item span{display:block;color:#667085;font-size:13px;margin-top:2px}.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.kpi{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.kpi span{display:block;color:#667085;font-size:13px;font-weight:900}.kpi strong{display:block;margin-top:7px;font-size:30px}.good{color:#087f5b}.warn{color:#9a5b00}.danger{color:#c92a2a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.chips{display:flex;gap:6px;flex-wrap:wrap}.chip{display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.chip.warn{background:#fff4e6;color:#9a5b00}.chip.danger{background:#fdecec;color:#c92a2a}.chip.good{background:#e8f7ee;color:#087f5b}.empty{padding:28px;text-align:center;color:#667085}.progress{height:9px;border-radius:999px;background:#edf2f7;overflow:hidden;margin-top:10px}.progress span{display:block;height:100%;background:#1947ba;border-radius:999px}.note{background:#eef6ff;border:1px solid #cfe0ff;border-radius:12px;padding:14px;color:#173b7a;line-height:1.6;margin-top:18px}.issue-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px}.issue-tabs a{display:inline-flex;align-items:center;gap:6px;border:1px solid #d8dee9;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:8px 11px;font-weight:900}.issue-tabs a.active{background:#1947ba;border-color:#1947ba;color:#fff}.issue-tabs b{font-size:12px;border-radius:999px;background:#eef2f7;color:#344054;padding:2px 7px}.issue-tabs a.active b{background:rgba(255,255,255,.22);color:#fff}.priority-high{background:#fffafa}.priority-mid{background:#fffdf5}@media(max-width:900px){.close-hero,.grid{grid-template-columns:1fr}.kpis{grid-template-columns:1fr 1fr}.routine-item{grid-template-columns:auto 1fr}.routine-item .btn{grid-column:2}}@media(max-width:620px){.kpis{grid-template-columns:1fr}h1{font-size:28px}}
</style>
</head>
<body>
<?php echo ieum_admin_header('monthly_close'); ?>
<?php echo ieum_admin_subnav('monthly_close'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>월말 관리</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text(ieum_monthly_close_month_label($month)); ?> · 관장님 월말 운영 마감 화면</div>
        </div>
        <form class="filters" method="get">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($prev_month); ?>">이전달</a>
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button class="btn primary" type="submit">조회</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($next_month); ?>">다음달</a>
        </form>
    </section>

    <section class="close-hero">
        <article class="panel score-card">
            <span>이번 달 마감 준비율</span>
            <div class="score-big"><?php echo number_format($close_score); ?>%</div>
            <p><?php echo get_text($status_text); ?> 상태입니다. 이 화면은 관장님이 월말에 놓친 원생 없이 관리 마감을 끝내기 위한 점검판입니다.</p>
            <div class="progress"><span style="width:<?php echo (int) $close_score; ?>%"></span></div>
        </article>
        <article class="panel">
            <h2>월말 마감 순서</h2>
            <div class="routine">
                <div class="routine-item"><div class="routine-no">1</div><div><strong>인성 입력 부족 확인</strong><span>부별 입력이 덜 된 학생을 먼저 정리합니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($month); ?>-01">열기</a></div>
                <div class="routine-item"><div class="routine-no">2</div><div><strong>보호자 연락처 확인</strong><span>대표 보호자 연락처가 있어야 리포트 링크를 보낼 수 있습니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">3</div><div><strong>아이잘해 미션 체크</strong><span>가정 실천 성공 여부를 빠르게 반영합니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">4</div><div><strong>수련비/상담 신호 확인</strong><span>미납, 장기 미등원, 상담 필요 학생을 함께 봅니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">5</div><div><strong>리포트 발송 준비</strong><span>발송 가능 학생만 선택해서 학부모 링크 문자를 준비합니다.</span></div><a class="btn dark" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>">발송 준비</a></div>
            </div>
        </article>
    </section>

    <section class="kpis" aria-label="월말 관리 요약">
        <article class="kpi"><span>원생</span><strong><?php echo number_format($total_students); ?>명</strong></article>
        <article class="kpi"><span>리포트 발송 가능</span><strong class="good"><?php echo number_format($ready_for_send); ?>명</strong></article>
        <article class="kpi"><span>인성 입력 부족</span><strong class="<?php echo $summary['character_missing'] ? 'warn' : 'good'; ?>"><?php echo number_format($summary['character_missing']); ?>명</strong></article>
        <article class="kpi"><span>대표 보호자 없음</span><strong class="<?php echo $summary['no_phone'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['no_phone']); ?>명</strong></article>
        <article class="kpi"><span>아이잘해 성공</span><strong><?php echo number_format($summary['mission_done']); ?>명</strong></article>
        <article class="kpi"><span>수련비 미결제</span><strong class="<?php echo $summary['tuition_unpaid'] ? 'warn' : 'good'; ?>"><?php echo number_format($summary['tuition_unpaid']); ?>명</strong></article>
        <article class="kpi"><span>미납 <?php echo number_format($overdue_days); ?>일 초과</span><strong class="<?php echo $summary['tuition_overdue'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['tuition_overdue']); ?>명</strong></article>
        <article class="kpi"><span>장기 미등원</span><strong class="<?php echo $summary['long_absent'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['long_absent']); ?>명</strong></article>
        <article class="kpi"><span>우선 처리</span><strong class="<?php echo $summary['critical'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['critical']); ?>명</strong></article>
        <article class="kpi"><span>평가 기준</span><strong><?php echo number_format($expected_weeks); ?>주</strong></article>
    </section>

    <div class="note">
        월말 관리는 학부모에게 보여줄 문서를 꾸미는 화면이 아니라, 관장님이 이번 달 원생 관리가 어디까지 끝났는지 확인하는 운영 루틴입니다. 아래의 막힌 항목만 정리하면 리포트 발송과 상담 준비가 훨씬 가벼워집니다.
    </div>

    <section class="grid">
        <article class="panel">
            <h2>부별 마감 현황</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>부</th>
                            <th>원생</th>
                            <th>발송 가능</th>
                            <th>입력 부족</th>
                            <th>연락처 없음</th>
                            <th>미션 성공</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($class_summary as $class_label => $row) { ?>
                        <tr>
                            <td class="left"><?php echo get_text($class_label); ?></td>
                            <td><?php echo number_format((int) $row['total']); ?></td>
                            <td class="good"><?php echo number_format((int) $row['ready']); ?></td>
                            <td class="<?php echo $row['character_missing'] ? 'warn' : ''; ?>"><?php echo number_format((int) $row['character_missing']); ?></td>
                            <td class="<?php echo $row['no_phone'] ? 'danger' : ''; ?>"><?php echo number_format((int) $row['no_phone']); ?></td>
                            <td><?php echo number_format((int) $row['mission_done']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="panel">
            <h2>이번 달 핵심 신호</h2>
            <div class="routine">
                <div class="routine-item"><div class="routine-no">!</div><div><strong>발송 불가 원인</strong><span>대표 보호자 연락처 없음 <?php echo number_format($summary['no_phone']); ?>명, 인성 입력 부족 <?php echo number_format($summary['character_missing']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">₩</div><div><strong>수련비 관리</strong><span>미결제 <?php echo number_format($summary['tuition_unpaid']); ?>명 중 <?php echo number_format($overdue_days); ?>일 초과 <?php echo number_format($summary['tuition_overdue']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">M</div><div><strong>아이잘해 미션</strong><span>성공 <?php echo number_format($summary['mission_done']); ?>명, 미참여 <?php echo number_format($summary['mission_wait']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">A</div><div><strong>상담 신호</strong><span>최근 14일 이상 출석 기록이 없는 학생 <?php echo number_format($summary['long_absent']); ?>명</span></div></div>
            </div>
        </article>
    </section>

    <section class="panel">
        <h2>먼저 정리할 학생</h2>
        <?php
        $issue_tabs = array(
            'all' => '전체',
            'critical' => '우선 처리',
            'character' => '인성 입력',
            'phone' => '연락처',
            'mission' => '아이잘해',
            'tuition' => '수련비',
            'absent' => '장기 미등원',
        );
        ?>
        <div class="issue-tabs" aria-label="점검 유형 필터">
            <?php foreach ($issue_tabs as $key => $label) { ?>
                <a class="<?php echo $issue === $key ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($month); ?>&amp;issue=<?php echo get_text($key); ?>">
                    <?php echo get_text($label); ?> <b><?php echo number_format(isset($issue_counts[$key]) ? (int) $issue_counts[$key] : 0); ?></b>
                </a>
            <?php } ?>
        </div>
        <?php if (!$blocker_preview) { ?>
            <div class="empty">이번 달 월말 관리에서 크게 막힌 항목이 없습니다. 리포트 발송 준비로 넘어가도 좋습니다.</div>
        <?php } else { ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>학생</th>
                            <th>부</th>
                            <th>학년/부</th>
                            <th>정리할 내용</th>
                            <th>바로가기</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($blocker_preview as $row) { $s = $row['student']; ?>
                        <tr class="<?php echo (int) $row['priority'] <= 20 ? 'priority-high' : 'priority-mid'; ?>">
                            <td class="left"><strong><?php echo get_text($s['student_name']); ?></strong> <span class="meta">(<?php echo get_text($s['student_code']); ?>)</span></td>
                            <td><?php echo get_text($row['class_label']); ?></td>
                            <td><?php echo get_text(ieum_monthly_close_grade_label($s['grade_group'])); ?></td>
                            <td class="left">
                                <div class="chips">
                                <?php foreach ($row['reasons'] as $reason) {
                                    $class = (strpos($reason, '없음') !== false || strpos($reason, '초과') !== false || strpos($reason, '14일') !== false) ? 'danger' : 'warn';
                                ?>
                                    <span class="chip <?php echo $class; ?>"><?php echo get_text($reason); ?></span>
                                <?php } ?>
                                </div>
                            </td>
                            <td>
                                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $s['student_id']; ?>">학생</a>
                                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $s['student_id']; ?>">리포트</a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>
</main>
</body>
</html>
