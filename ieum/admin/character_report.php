<?php
$sub_menu = '950181';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';
require_once IEUM_PATH . '/lib/character_level.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/character_report_link.php';

$g5['title'] = '아이이음 월간 인성리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_character_ensure_table();
ieum_character_mission_ensure_tables();
$message = '';
$error = '';

function ieum_character_report_grade_label($value)
{
    $labels = array(
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
        'jump_rope' => '줄넘기부',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_character_report_photo_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    return G5_URL . '/' . ltrim($path, '/');
}

function ieum_character_report_month_label($month)
{
    $time = strtotime($month . '-01');
    if (!$time) {
        return $month;
    }

    return date('Y년 n월', $time);
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$program_filter_sql = '';
if ($program_code !== '') {
    $program_sql = sql_escape_string($program_code);
    $program_filter_sql = " and s.program_code = '{$program_sql}' ";
}
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : "";

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

$student_options = array();
$student_rows = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_phone, s.student_photo,
           s.birth_date, s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
           c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_rows)) {
    $student_options[] = $row;
}

if (!$student_id && $student_options) {
    $student_id = (int) $student_options[0]['student_id'];
}

$student = null;
foreach ($student_options as $option) {
    if ((int) $option['student_id'] === $student_id) {
        $student = $option;
        break;
    }
}
if (!$student && $student_id) {
    $student = sql_fetch("
        select s.student_id, s.student_code, s.student_name, s.student_phone, s.student_photo,
               s.birth_date, s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
               c.class_name, c.start_time
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
           and s.student_id = '{$student_id}'
           and s.is_active = 1
         limit 1
    ", false);
}

$score = $student ? ieum_character_month_score($academy_id, $student, $month) : null;
$mission_report = $student ? ieum_character_mission_report($academy_id, (int) $student['student_id'], $month) : null;
$level_summary = $student ? ieum_character_level_sync_snapshot($academy_id, $student, $month) : null;
$level_current = $level_summary ? $level_summary['current']['level'] : null;
$items = $score ? ieum_character_component_values($score) : array();
$comment = $student && $score ? ieum_character_parent_comment($student['student_name'], $score) : '';
$student_summaries = array();
foreach ($student_options as $option) {
    $option_score = ieum_character_month_score($academy_id, $option, $month);
    $option_mission = ieum_character_mission_report($academy_id, (int) $option['student_id'], $month);
    $student_summaries[] = array(
        'student' => $option,
        'score' => $option_score,
        'mission' => $option_mission,
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } elseif ($action === 'send_parent_report_links') {
        $selected_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : array();
        $student_map = array();
        foreach ($student_options as $option) {
            $student_map[(int) $option['student_id']] = $option;
        }

        $created = 0;
        $skipped = 0;
        $month_label_for_sms = ieum_character_report_month_label($month);
        foreach ($selected_ids as $selected_id) {
            if (empty($student_map[$selected_id])) {
                $skipped++;
                continue;
            }
            $target = $student_map[$selected_id];
            $report_url = ieum_character_report_public_url($academy_id, (int) $target['student_id'], $month);
            $sms_message = '[' . $academy['academy_name'] . '] ' . $target['student_name'] . ' 학생의 ' . $month_label_for_sms . " 인성리포트가 준비되었습니다.\n아래 링크에서 확인해주세요.\n" . $report_url;
            $guardians = sql_query("
                select guardian_phone
                  from " . IEUM_STUDENT_GUARDIAN_TABLE . "
                 where academy_id = '{$academy_id}'
                   and student_id = '" . (int) $target['student_id'] . "'
                   and is_active = 1
                   and is_primary = 1
                   and guardian_phone <> ''
              order by sort_order asc, guardian_id asc
            ", false);
            $phones = array();
            while ($guardian = sql_fetch_array($guardians)) {
                $phone = trim($guardian['guardian_phone']);
                if ($phone !== '' && !in_array($phone, $phones, true)) {
                    $phones[] = $phone;
                }
            }
            if (!$phones) {
                $skipped++;
                continue;
            }
            foreach ($phones as $phone) {
                $exists = sql_fetch("
                    select sms_id
                      from " . IEUM_SMS_QUEUE_TABLE . "
                     where academy_id = '{$academy_id}'
                       and student_id = '" . (int) $target['student_id'] . "'
                       and recipient_phone = '" . sql_escape_string($phone) . "'
                       and message_type = 'character_report'
                       and left(created_at, 10) = '" . G5_TIME_YMD . "'
                     limit 1
                ", false);
                if (!empty($exists['sms_id'])) {
                    continue;
                }
                if (ieum_create_direct_sms_queue($academy_id, $phone, $sms_message, 'character_report', (int) $target['student_id'], 0)) {
                    $created++;
                }
            }
        }
        $message = '학부모 인성리포트 링크 문자 큐 ' . number_format($created) . '건을 생성했습니다.';
        if ($skipped) {
            $message .= ' 대표 보호자 연락처가 없거나 대상이 아닌 학생 ' . number_format($skipped) . '명은 제외했습니다.';
        }
    }
}
$radar_labels = array();
$radar_values = array();
foreach ($items as $item) {
    $radar_labels[] = $item['label'];
    $radar_values[] = (int) $item['score'];
}

$photo_url = $student ? ieum_character_report_photo_url(isset($student['student_photo']) ? $student['student_photo'] : '') : '';
$class_label = $student ? trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: '')) : '';
$grade_label = $student ? ieum_character_report_grade_label($student['grade_group']) : '';
$school_label = $student && $student['school_name'] !== '' ? $student['school_name'] : '학교 미입력';
$csrf_token = ieum_new_csrf_token();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap}h1{margin:0;font-size:28px}h2{margin:0 0 14px;font-size:20px}.meta{color:#667085;margin-top:6px}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:16px 0}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}.report-card,.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.report-card{margin-top:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.profile{display:flex;gap:14px;align-items:center}.avatar{width:78px;height:78px;border-radius:18px;object-fit:cover;border:1px solid #d9dee7;background:#eef2f7}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#667085;font-weight:900}.stage{display:inline-flex;border-radius:999px;background:#eaf4ff;color:#1769c2;padding:7px 12px;font-weight:900}.first{background:#fff4e6;color:#9a5b00}.radar-wrap{display:grid;place-items:center;min-height:360px}canvas{max-width:100%;width:360px;height:360px}.levels{display:grid;gap:12px}.level-row{display:grid;grid-template-columns:90px 1fr auto;gap:10px;align-items:center}.bar{height:10px;border-radius:999px;background:#eef2f7;overflow:hidden}.fill{height:100%;border-radius:999px;background:#1769c2}.level{font-weight:900;color:#344054;white-space:nowrap}.score-small{color:#667085;font-size:12px;line-height:1.55}.comment{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;line-height:1.75;white-space:pre-wrap}.admin-score{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.score-box{border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fff}.score-box span{color:#667085;font-size:13px}.score-box strong{display:block;font-size:22px;margin-top:4px}.empty{padding:40px;text-align:center;color:#667085}.note{background:#fffbeb;border:1px solid #f6d58e;border-radius:8px;color:#7a4d00;padding:12px;margin-top:12px;line-height:1.6}.copy-box{width:100%;min-height:160px;border:1px solid #d9dee7;border-radius:8px;padding:14px;line-height:1.7;resize:vertical}.summary-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}.kpi{border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fbfcfe}.kpi strong{display:block;font-size:21px;margin-top:4px;color:#1769c2}.level-panel{margin-top:18px;display:grid;grid-template-columns:130px 1fr;gap:16px;align-items:center;border:1px solid #d9dee7;border-radius:12px;padding:16px;background:linear-gradient(135deg,#f2f6ff,#fff)}.level-emblem{height:110px;border-radius:24px;color:#fff;display:grid;place-items:center;text-align:center;font-weight:900;box-shadow:inset 0 0 0 5px rgba(255,255,255,.2)}.level-emblem strong{display:block;font-size:22px}.level-emblem span{display:block;font-size:12px;margin-top:4px}.level-info h2{margin:0 0 6px}.level-track{height:12px;background:#e7edf5;border-radius:999px;overflow:hidden;margin-top:10px}.level-track i{display:block;height:100%;border-radius:999px}.level-meta{display:flex;justify-content:space-between;margin-top:8px;color:#667085;font-size:12px;font-weight:900}.student-summary{margin-top:18px}.summary-table{width:100%;border-collapse:collapse}.summary-table th,.summary-table td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px}.summary-table th{background:#72829d;color:#fff}.summary-table .left{text-align:left}.summary-table tr.active{background:#eef6ff}.mini{font-size:12px;color:#667085}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.pill.ok{background:#e8f7ee;color:#087f5b}.pill.wait{background:#fff4e6;color:#9a5b00}.table-scroll{overflow-x:auto}@media(max-width:900px){.grid{grid-template-columns:1fr}.admin-score,.summary-kpi{grid-template-columns:repeat(2,1fr)}.ieum-user{margin-left:0}.summary-table{min-width:760px}}@media(max-width:560px){.level-row{grid-template-columns:70px 1fr}.level{grid-column:2}.admin-score,.summary-kpi{grid-template-columns:1fr}.level-panel{grid-template-columns:1fr}.level-emblem{height:84px}}@media print{.top,.filters,.print-hide,.student-summary{display:none}.wrap{max-width:none;margin:0;padding:0}.report-card,.panel{box-shadow:none;border-color:#aaa}.grid{grid-template-columns:1fr 1fr}body{background:#fff}}
</style>
<style>
.notice{margin:14px 0 0;padding:12px 14px;border-radius:8px;font-weight:800;line-height:1.5}.notice.ok{background:#eef9f1;color:#176b2c}.notice.err{background:#fdecec;color:#a4262c}.wrap.is-loading{opacity:.55;pointer-events:none}.report-send-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:14px 0}.check-cell{width:46px}.check-cell input{width:auto}.summary-table th.check-cell,.summary-table td.check-cell{text-align:center}.send-help{color:#667085;font-size:13px;line-height:1.5}
</style>
</head>
<body>
<?php echo ieum_admin_header('character_report'); ?>
<?php echo ieum_admin_subnav('character_report'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>월간 인성리포트</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 점수보다 성장 균형을 먼저 보여주는 학부모용 화면</div>
        </div>
        <div class="filters print-hide" style="margin:0">
            <?php if ($student) { ?>
            <a class="btn primary" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_parent_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $student_id; ?>">학부모용 한장 보기</a>
            <?php } ?>
            <button type="button" class="btn" onclick="window.print()">인쇄</button>
        </div>
    </section>
    <?php if ($message) { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>

    <form method="get" class="filters">
        <input type="month" name="month" value="<?php echo get_text($month); ?>">
        <select name="program_code" onchange="this.form.student_id.value='0'">
            <option value="">전체 프로그램</option>
            <?php foreach ($program_options as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id" onchange="this.form.student_id.value='0'">
            <option value="0">전체 부</option>
            <?php foreach ($class_options as $class) { ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
            </option>
            <?php } ?>
        </select>
        <select name="student_id">
            <?php foreach ($student_options as $option) { ?>
            <option value="<?php echo (int) $option['student_id']; ?>" <?php echo get_selected($student_id, (int) $option['student_id']); ?>>
                <?php echo get_text($option['student_name'] . ' (' . $option['student_code'] . ')'); ?>
            </option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">리포트 보기</button>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($month); ?>-01&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">인성 입력</a>
    </form>

    <section class="panel student-summary print-hide">
        <div class="hero">
            <div>
                <h2>학생별 월간 상태</h2>
                <div class="meta">필터에 해당하는 학생을 먼저 훑고, 필요한 학생만 리포트로 들어갑니다.</div>
            </div>
            <span class="stage"><?php echo number_format(count($student_summaries)); ?>명</span>
        </div>
        <form method="post" onsubmit="return confirm('선택한 학생의 학부모 인성리포트 링크 문자를 문자 큐에 생성할까요?');">
        <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
        <input type="hidden" name="action" value="send_parent_report_links">
        <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
        <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
        <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
        <input type="hidden" name="student_id" value="<?php echo (int) $student_id; ?>">
        <div class="report-send-bar">
            <label class="send-help"><input type="checkbox" id="checkAllReportStudents"> 현재 목록 전체 선택</label>
            <button type="submit" class="btn primary">선택 학생 학부모 링크 문자 큐 생성</button>
        </div>
        <div class="table-scroll">
            <table class="summary-table">
                <thead>
                    <tr><th>학생</th><th>프로그램</th><th>부</th><th>총 흐름</th><th>성실</th><th>아이잘해</th><th>관리</th></tr>
                </thead>
                <tbody>
                <?php foreach ($student_summaries as $summary) {
                    $option = $summary['student'];
                    $option_score = $summary['score'];
                    $option_mission = $summary['mission'];
                ?>
                <tr class="<?php echo (int) $option['student_id'] === $student_id ? 'active' : ''; ?>">
                    <td class="check-cell"><input type="checkbox" class="report-student-check" name="student_ids[]" value="<?php echo (int) $option['student_id']; ?>"></td>
                    <td class="left"><strong><?php echo get_text($option['student_name']); ?></strong><div class="mini"><?php echo get_text($option['student_code'] . ' · ' . ieum_character_report_grade_label($option['grade_group'])); ?></div></td>
                    <td><?php echo get_text(ieum_program_label($academy_id, isset($option['program_code']) ? $option['program_code'] : '')); ?></td>
                    <td><?php echo get_text(trim(($option['class_name'] ?: '미지정') . ' ' . ($option['start_time'] ?: ''))); ?></td>
                    <td><strong><?php echo empty($option_score['is_before_admission']) ? number_format((int) $option_score['total_score']) : '-'; ?></strong><div class="mini"><?php echo empty($option_score['is_before_admission']) ? get_text(ieum_character_total_stage($option_score['total_score'])) : '입관 전'; ?></div></td>
                    <td><?php echo empty($option_score['is_before_admission']) ? number_format((int) $option_score['attendance']['rate']) . '%' : '-'; ?></td>
                    <td><span class="pill <?php echo $option_mission && $option_mission['is_participated'] ? 'ok' : 'wait'; ?>"><?php echo get_text($option_mission ? $option_mission['status_label'] : '미설정'); ?></span></td>
                    <td>
                        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>&amp;student_id=<?php echo (int) $option['student_id']; ?>">보기</a>
                        <a class="btn primary" target="_blank" rel="noopener" href="<?php echo IEUM_URL; ?>/admin/character_parent_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $option['student_id']; ?>">학부모용</a>
                    </td>
                </tr>
                <?php } ?>
                <?php if (!$student_summaries) { ?><tr><td colspan="7">조건에 맞는 학생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        </form>
    </section>

    <?php if (!$student || !$score) { ?>
    <section class="report-card empty">리포트를 볼 학생이 없습니다.</section>
    <?php } else { ?>
    <section class="report-card">
        <div class="hero">
            <div class="profile">
                <?php if ($photo_url !== '') { ?>
                <img class="avatar" src="<?php echo get_text($photo_url); ?>" alt="">
                <?php } else { ?>
                <div class="avatar avatar-empty">사진</div>
                <?php } ?>
                <div>
                    <h1><?php echo get_text($student['student_name']); ?> 인성 성장 균형</h1>
                    <div class="meta"><?php echo get_text($month); ?> · <?php echo get_text($class_label); ?> · <?php echo get_text($school_label); ?> · <?php echo get_text($grade_label); ?></div>
                </div>
            </div>
            <div>
                <span class="stage"><?php echo get_text(ieum_character_total_stage($score['total_score'])); ?></span>
                <?php if ($score['is_first_month']) { ?><span class="stage first">입관 첫 달</span><?php } ?>
            </div>
        </div>

        <?php if (!empty($score['is_before_admission'])) { ?>
        <div class="note">선택한 월은 입관 전입니다. 입관일 이후 월을 선택하면 리포트가 계산됩니다.</div>
        <?php } elseif ($score['is_first_month']) { ?>
        <div class="note">입관 첫 달은 적응 기간으로 봅니다. 입관일 이후의 인성 입력과 정상 수업 출석만 반영해, 아이에게 불리하지 않게 계산합니다.</div>
        <?php } ?>

        <?php if ($level_current) { ?>
        <article class="level-panel">
            <div class="level-emblem" style="background:<?php echo get_text($level_current['current']['color']); ?>">
                <div>
                    <strong><?php echo get_text($level_current['current']['tier_label']); ?></strong>
                    <span><?php echo number_format((int) $level_current['current']['rank']); ?>단계</span>
                </div>
            </div>
            <div class="level-info">
                <h2>누적 인성 성장 레벨</h2>
                <p class="score-small"><?php echo get_text(ieum_character_level_message($student['student_name'], $level_current)); ?></p>
                <div class="level-track"><i style="width:<?php echo (int) $level_current['progress_rate']; ?>%;background:<?php echo get_text($level_current['current']['color']); ?>"></i></div>
                <div class="level-meta">
                    <span>현재 <?php echo get_text($level_current['current']['label']); ?></span>
                    <span>누적 <?php echo number_format((int) $level_current['points']); ?>점</span>
                    <span>다음 <?php echo get_text($level_current['next_label']); ?></span>
                </div>
            </div>
        </article>
        <?php } ?>

        <section class="grid" style="margin-top:18px">
            <article class="panel">
                <h2>5가지 인성 균형</h2>
                <div class="radar-wrap"><canvas id="radar" width="360" height="360"></canvas></div>
            </article>
            <article class="panel">
                <h2>학부모용 단계 표현</h2>
                <div class="levels">
                    <?php foreach ($items as $item) { $width = max(0, min(100, ((int) $item['score'] / 20) * 100)); ?>
                    <div class="level-row">
                        <strong><?php echo get_text($item['label']); ?></strong>
                        <div class="bar"><div class="fill" style="width:<?php echo (int) $width; ?>%"></div></div>
                        <span class="level"><?php echo get_text($item['level']); ?></span>
                    </div>
                    <?php } ?>
                </div>
                <div class="summary-kpi">
                    <div class="kpi"><span>평가 주차</span><strong><?php echo number_format((int) $score['evaluated_weeks']); ?>주</strong></div>
                    <div class="kpi"><span>성실 출석</span><strong><?php echo number_format((int) $score['attendance']['rate']); ?>%</strong></div>
                    <div class="kpi"><span>출석일</span><strong><?php echo number_format((int) $score['attendance']['attended_days']); ?>/<?php echo number_format((int) $score['attendance']['scheduled_days']); ?>일</strong></div>
                </div>
                <p class="score-small">학부모 화면에서는 낮은 숫자를 전면에 세우기보다, 영역별 성장 균형과 다음 달 관찰 방향을 보여주는 방식이 안전합니다.</p>
            </article>
        </section>

        <section class="grid" style="margin-top:18px">
            <article class="panel">
                <h2>학부모 코멘트 초안</h2>
                <textarea class="copy-box" readonly><?php echo get_text($comment); ?></textarea>
            </article>
            <article class="panel">
                <h2>관리자 내부 점수</h2>
                <div class="admin-score">
                    <?php foreach ($items as $item) { ?>
                    <div class="score-box">
                        <span><?php echo get_text($item['label']); ?></span>
                        <strong><?php echo number_format((int) $item['score']); ?>/20</strong>
                        <span><?php echo get_text($item['level']); ?></span>
                    </div>
                    <?php } ?>
                </div>
                <p class="score-small">총점 <?php echo number_format((int) $score['total_score']); ?>/100 · 기준 시작일 <?php echo get_text($score['base_date']); ?> · <?php echo get_text($score['message']); ?></p>
                <?php if ($mission_report) { ?>
                <p class="score-small">아이잘해 미션: <?php echo get_text($mission_report['status_label']); ?> · 내부 보너스 <?php echo number_format((int) $mission_report['bonus_score']); ?>/5점</p>
                <?php } ?>
            </article>
        </section>
    </section>
    <?php } ?>
</main>
<script>
const checkAllReportStudents = document.getElementById('checkAllReportStudents');
if (checkAllReportStudents) {
    const reportChecks = Array.from(document.querySelectorAll('.report-student-check'));
    const summaryHeader = document.querySelector('.summary-table thead tr');
    if (summaryHeader && summaryHeader.children.length === 7) {
        summaryHeader.insertAdjacentHTML('afterbegin', '<th class="check-cell">선택</th>');
    }
    checkAllReportStudents.addEventListener('change', () => {
        reportChecks.forEach((input) => {
            input.checked = checkAllReportStudents.checked;
        });
    });
}
const radarLabels = <?php echo json_encode($radar_labels, JSON_UNESCAPED_UNICODE); ?>;
const radarValues = <?php echo json_encode($radar_values, JSON_UNESCAPED_UNICODE); ?>;
const canvas = document.getElementById('radar');
if (canvas && radarLabels.length) {
    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2;
    const cy = canvas.height / 2;
    const radius = 118;
    const max = 20;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.lineWidth = 1;
    ctx.font = '14px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    for (let ring = 1; ring <= 10; ring++) {
        const r = radius * ring / 10;
        ctx.beginPath();
        radarLabels.forEach((label, index) => {
            const angle = -Math.PI / 2 + index * Math.PI * 2 / radarLabels.length;
            const x = cx + Math.cos(angle) * r;
            const y = cy + Math.sin(angle) * r;
            if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.closePath();
        ctx.strokeStyle = '#d8dee9';
        ctx.stroke();
    }

    radarLabels.forEach((label, index) => {
        const angle = -Math.PI / 2 + index * Math.PI * 2 / radarLabels.length;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius);
        ctx.strokeStyle = '#e2e8f0';
        ctx.stroke();
        ctx.fillStyle = '#111827';
        ctx.fillText(label, cx + Math.cos(angle) * (radius + 34), cy + Math.sin(angle) * (radius + 34));
    });

    ctx.beginPath();
    radarValues.forEach((value, index) => {
        const angle = -Math.PI / 2 + index * Math.PI * 2 / radarValues.length;
        const r = radius * Math.max(0, Math.min(max, Number(value) || 0)) / max;
        const x = cx + Math.cos(angle) * r;
        const y = cy + Math.sin(angle) * r;
        if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    });
    ctx.closePath();
    ctx.fillStyle = 'rgba(23,105,194,.20)';
    ctx.strokeStyle = '#1769c2';
    ctx.lineWidth = 3;
    ctx.fill();
    ctx.stroke();
}
</script>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;

    const setupChecks = () => {
        const checkAll = document.getElementById('checkAllReportStudents');
        if (!checkAll || checkAll.dataset.ajaxBound === '1') return;
        const header = document.querySelector('.summary-table thead tr');
        if (header && header.children.length === 7) {
            header.insertAdjacentHTML('afterbegin', '<th class="check-cell">선택</th>');
        }
        checkAll.dataset.ajaxBound = '1';
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.report-student-check').forEach((input) => {
                input.checked = checkAll.checked;
            });
        });
    };

    const extractRadar = (html) => {
        const labels = html.match(/const radarLabels = (\[[\s\S]*?\]);/);
        const values = html.match(/const radarValues = (\[[\s\S]*?\]);/);
        if (!labels || !values) return null;
        try {
            return {labels: JSON.parse(labels[1]), values: JSON.parse(values[1])};
        } catch (error) {
            return null;
        }
    };

    const drawRadar = (labels, values) => {
        const canvas = document.getElementById('radar');
        if (!canvas || !labels || !labels.length) return;
        const ctx = canvas.getContext('2d');
        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        const radius = 118;
        const max = 20;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 1;
        ctx.font = '14px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        for (let ring = 1; ring <= 10; ring++) {
            const r = radius * ring / 10;
            ctx.beginPath();
            labels.forEach((label, index) => {
                const angle = -Math.PI / 2 + index * Math.PI * 2 / labels.length;
                const x = cx + Math.cos(angle) * r;
                const y = cy + Math.sin(angle) * r;
                if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            });
            ctx.closePath();
            ctx.strokeStyle = '#d8dee9';
            ctx.stroke();
        }

        labels.forEach((label, index) => {
            const angle = -Math.PI / 2 + index * Math.PI * 2 / labels.length;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius);
            ctx.strokeStyle = '#e2e8f0';
            ctx.stroke();
            ctx.fillStyle = '#111827';
            ctx.fillText(label, cx + Math.cos(angle) * (radius + 34), cy + Math.sin(angle) * (radius + 34));
        });

        ctx.beginPath();
        values.forEach((value, index) => {
            const angle = -Math.PI / 2 + index * Math.PI * 2 / values.length;
            const r = radius * Math.max(0, Math.min(max, Number(value) || 0)) / max;
            const x = cx + Math.cos(angle) * r;
            const y = cy + Math.sin(angle) * r;
            if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.closePath();
        ctx.fillStyle = 'rgba(23,105,194,.20)';
        ctx.strokeStyle = '#1769c2';
        ctx.lineWidth = 3;
        ctx.fill();
        ctx.stroke();
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
            const radar = extractRadar(html);
            if (radar) drawRadar(radar.labels, radar.values);
            setupChecks();
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };

    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });

    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filters input, form.filters select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });

    main.addEventListener('click', (event) => {
        const link = event.target.closest('.summary-table a.btn');
        if (!link || link.target) return;
        event.preventDefault();
        loadView(link.href, true);
    });

    setupChecks();
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
