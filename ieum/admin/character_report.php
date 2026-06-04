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

function ieum_character_report_primary_phone_count($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and is_primary = 1
           and guardian_phone <> ''
    ", false);

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_character_report_expected_weeks($month)
{
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $count = 0;
    for ($time = strtotime($month_start); $time <= strtotime($month_end); $time = strtotime('+1 day', $time)) {
        if ((int) date('N', $time) === 1) {
            $count++;
        }
    }

    return max(1, $count);
}

function ieum_character_report_week_starts($month)
{
    $month_start = strtotime($month . '-01');
    if (!$month_start) {
        $month_start = strtotime(date('Y-m-01'));
    }
    $month_end = strtotime(date('Y-m-t', $month_start));
    $weeks = array();
    for ($time = $month_start; $time <= $month_end; $time = strtotime('+1 day', $time)) {
        if ((int) date('N', $time) === 1) {
            $weeks[] = date('Y-m-d', $time);
        }
    }
    if (!$weeks) {
        $weeks[] = date('Y-m-d', strtotime('monday this week', $month_start));
    }

    return $weeks;
}

function ieum_character_report_input_url($week_start, $program_code, $class_time_id, $student_id = 0)
{
    $query = array(
        'week_start' => $week_start,
        'program_code' => $program_code,
        'class_time_id' => (int) $class_time_id,
    );
    if ((int) $student_id > 0) {
        $query['focus_student_id'] = (int) $student_id;
        if ((int) $class_time_id <= 0) {
            $query['class_scope'] = 'unassigned';
        }
    }

    return IEUM_URL . '/admin/character.php?' . http_build_query($query);
}

function ieum_character_report_sms_template_default()
{
    return "[{도장명}] 안녕하세요. {원생명} 원생의 {리포트월} 인성리포트가 준비되었습니다.\n아래 링크에서 확인해주세요.\n{리포트주소}";
}

function ieum_character_report_sms_template($academy_id)
{
    $academy_id = (int) $academy_id;
    $row = sql_fetch("
        select message
          from " . IEUM_SMS_TEMPLATE_TABLE . "
         where academy_id = '{$academy_id}'
           and template_key = 'character_report'
           and is_active = 1
         limit 1
    ", false);

    if (!empty($row['message'])) {
        return $row['message'];
    }

    return ieum_character_report_sms_template_default();
}

function ieum_character_report_save_sms_template($academy_id, $message)
{
    $academy_id = (int) $academy_id;
    $message = trim((string) $message);
    if ($message === '') {
        return false;
    }

    sql_query("
        insert into " . IEUM_SMS_TEMPLATE_TABLE . "
            set academy_id = '{$academy_id}',
                template_key = 'character_report',
                title = '인성 리포트 발송 안내',
                message = '" . sql_escape_string($message) . "',
                is_active = 1,
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                title = values(title),
                message = values(message),
                is_active = 1,
                updated_at = values(updated_at)
    ", false);

    return true;
}

function ieum_character_report_render_sms_template($template, $vars)
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }

    return $template;
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$report_status = isset($_GET['report_status']) ? preg_replace('/[^a-z_]/', '', trim($_GET['report_status'])) : '';
$report_status_labels = array(
    '' => '전체',
    'input_complete' => '입력 완료',
    'input_missing' => '입력 부족',
    'send_ready' => '발송 가능',
    'no_phone' => '연락처 확인',
    'excluded' => '리포트 제외',
);
if (!isset($report_status_labels[$report_status])) {
    $report_status = '';
}
$expected_weeks = ieum_character_report_expected_weeks($month);
$report_week_starts = ieum_character_report_week_starts($month);
$report_first_week_start = $report_week_starts ? $report_week_starts[0] : $month . '-01';
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
           s.class_time_id, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
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
               s.class_time_id, c.class_name, c.start_time
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
           and s.student_id = '{$student_id}'
           and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
         limit 1
    ", false);
}

$score = $student ? ieum_character_month_score($academy_id, $student, $month) : null;
$mission_report = $student ? ieum_character_mission_report($academy_id, (int) $student['student_id'], $month) : null;
$level_summary = $student ? ieum_character_level_sync_snapshot($academy_id, $student, $month) : null;
$level_current = $level_summary ? $level_summary['current']['level'] : null;
$special_score = $score && isset($score['special']['score']) ? (int) $score['special']['score'] : 0;
$special_reasons = $score && !empty($score['special']['reasons']) ? $score['special']['reasons'] : array();
$special_reason_label = $special_reasons ? implode(', ', $special_reasons) : '지도진 특별 칭찬';
$level_color = $level_current ? $level_current['current']['color'] : '#1769c2';
$level_accent = $level_current && isset($level_current['current']['accent']) ? $level_current['current']['accent'] : $level_color;
$items = $score ? ieum_character_component_values($score) : array();
$comment = $student && $score ? ieum_character_parent_comment($student['student_name'], $score) : '';
$student_summaries = array();
$disabled_summaries = array();
$input_complete_count = 0;
$input_missing_count = 0;
$report_ready_count = 0;
$report_no_phone_count = 0;
$report_excluded_count = 0;
$mission_done_count = 0;
foreach ($student_options as $option) {
    $option_score = ieum_character_month_score($academy_id, $option, $month);
    $option_mission = ieum_character_mission_report($academy_id, (int) $option['student_id'], $month);
    $primary_phone_count = ieum_character_report_primary_phone_count($academy_id, (int) $option['student_id']);
    $input_complete = !empty($option_score) && empty($option_score['is_before_admission']) && (int) $option_score['evaluated_weeks'] >= $expected_weeks;
    if ($input_complete) {
        $input_complete_count++;
        if ($primary_phone_count > 0) {
            $report_ready_count++;
        } else {
            $report_no_phone_count++;
        }
    } else {
        $input_missing_count++;
    }
    if ($option_mission && $option_mission['is_participated']) {
        $mission_done_count++;
    }
    $student_summaries[] = array(
        'student' => $option,
        'score' => $option_score,
        'mission' => $option_mission,
        'primary_phone_count' => $primary_phone_count,
        'input_complete' => $input_complete,
    );
}

$disabled_rows = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_phone, s.student_photo,
           s.birth_date, s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
           s.class_time_id, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 0
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($disabled = sql_fetch_array($disabled_rows)) {
    $report_excluded_count++;
    $disabled_summaries[] = array(
        'student' => $disabled,
        'score' => null,
        'mission' => null,
        'primary_phone_count' => 0,
        'input_complete' => false,
        'excluded' => true,
    );
}

$report_all_total = count($student_summaries);
if ($report_status === 'excluded') {
    $student_summaries = $disabled_summaries;
} elseif ($report_status !== '') {
    $student_summaries = array_values(array_filter($student_summaries, function ($summary) use ($report_status) {
        $complete = !empty($summary['input_complete']);
        $has_phone = !empty($summary['primary_phone_count']);
        if ($report_status === 'input_complete') {
            return $complete;
        }
        if ($report_status === 'input_missing') {
            return !$complete;
        }
        if ($report_status === 'send_ready') {
            return $complete && $has_phone;
        }
        if ($report_status === 'no_phone') {
            return $complete && !$has_phone;
        }

        return true;
    }));
}

$report_page_size = isset($_GET['report_page_size']) ? (int) $_GET['report_page_size'] : 25;
if (!in_array($report_page_size, array(10, 25, 50), true)) {
    $report_page_size = 25;
}
$report_page = isset($_GET['report_page']) ? (int) $_GET['report_page'] : 1;
$report_total = count($student_summaries);
$report_total_pages = max(1, (int) ceil($report_total / $report_page_size));
if ($report_page < 1) {
    $report_page = 1;
}
if ($report_page > $report_total_pages) {
    $report_page = $report_total_pages;
}
$report_page_summaries = array_slice($student_summaries, ($report_page - 1) * $report_page_size, $report_page_size);
$report_start = $report_total ? (($report_page - 1) * $report_page_size + 1) : 0;
$report_end = min($report_total, $report_page * $report_page_size);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } elseif ($action === 'send_parent_report_links') {
        $final_approved = !empty($_POST['final_approved']) ? 1 : 0;
        if (!$final_approved) {
            $error = '문자 발송 확인 팝업에서 발송을 눌러야 문자를 준비할 수 있습니다.';
        } else {
        $selected_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : array();
        $student_map = array();
        foreach ($student_options as $option) {
            $student_map[(int) $option['student_id']] = $option;
        }

        $created = 0;
        $skipped = 0;
        $month_label_for_sms = ieum_character_report_month_label($month);
        $sms_template = ieum_character_report_sms_template($academy_id);
        $sms_template_override = isset($_POST['notice_message_template']) ? trim((string) $_POST['notice_message_template']) : '';
        if ($sms_template_override !== '') {
            $sms_template = $sms_template_override;
            if (!empty($_POST['save_notice_template'])) {
                ieum_character_report_save_sms_template($academy_id, $sms_template_override);
            }
        }
        foreach ($selected_ids as $selected_id) {
            if (empty($student_map[$selected_id])) {
                $skipped++;
                continue;
            }
            $target = $student_map[$selected_id];
            $target_score = ieum_character_month_score($academy_id, $target, $month);
            if (!$target_score || !empty($target_score['is_before_admission']) || (int) $target_score['evaluated_weeks'] < $expected_weeks) {
                $skipped++;
                continue;
            }
            $report_url = ieum_character_report_public_url($academy_id, (int) $target['student_id'], $month);
            $sms_message = ieum_character_report_render_sms_template($sms_template, array(
                'academy_name' => $academy['academy_name'],
                'student_name' => $target['student_name'],
                'report_month' => $month_label_for_sms,
                'report_url' => $report_url,
                '도장명' => $academy['academy_name'],
                '원생명' => $target['student_name'],
                '리포트월' => $month_label_for_sms,
                '리포트주소' => $report_url,
            ));
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
        $message = '학부모 인성리포트 링크 문자 ' . number_format($created) . '건을 발송 준비했습니다.';
        if ($skipped) {
            $message .= ' 대표 보호자 연락처가 없거나 대상이 아닌 원생 ' . number_format($skipped) . '명은 제외했습니다.';
        }
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
$print_all_url = IEUM_URL . '/admin/character_reports_print.php?' . http_build_query(array(
    'month' => $month,
    'program_code' => $program_code,
    'class_time_id' => $class_time_id,
));
$character_sms_template = ieum_character_report_sms_template($academy_id);
$character_sms_preview_student = null;
foreach ($report_page_summaries as $preview_summary) {
    if (empty($preview_summary['excluded'])) {
        $character_sms_preview_student = $preview_summary['student'];
        break;
    }
}
if (!$character_sms_preview_student && $student_options) {
    $character_sms_preview_student = $student_options[0];
}
$character_sms_preview_name = $character_sms_preview_student ? $character_sms_preview_student['student_name'] : '홍길동';
$character_sms_preview_url = $character_sms_preview_student ? ieum_character_report_public_url($academy_id, (int) $character_sms_preview_student['student_id'], $month) : IEUM_URL . '/r/character';
$character_sms_values = array(
    'academy_name' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
    'student_name' => (string) $character_sms_preview_name,
    'report_month' => ieum_character_report_month_label($month),
    'report_url' => $character_sms_preview_url,
    '도장명' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
    '원생명' => (string) $character_sms_preview_name,
    '리포트월' => ieum_character_report_month_label($month),
    '리포트주소' => $character_sms_preview_url,
);
$character_sms_preview = ieum_character_report_render_sms_template($character_sms_template, $character_sms_values);
$character_sms_values_json = htmlspecialchars(json_encode($character_sms_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap}h1{margin:0;font-size:28px}h2{margin:0 0 14px;font-size:20px}.meta{color:#667085;margin-top:6px}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:16px 0}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}.report-card,.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.report-card{margin-top:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.profile{display:flex;gap:14px;align-items:center}.avatar{width:78px;height:78px;border-radius:18px;object-fit:cover;border:1px solid #d9dee7;background:#eef2f7}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#667085;font-weight:900}.stage{display:inline-flex;border-radius:999px;background:#eaf4ff;color:#1769c2;padding:7px 12px;font-weight:900}.first{background:#fff4e6;color:#9a5b00}.radar-wrap{display:grid;place-items:center;min-height:360px}canvas{max-width:100%;width:360px;height:360px}.levels{display:grid;gap:12px}.level-row{display:grid;grid-template-columns:90px 1fr auto;gap:10px;align-items:center}.bar{height:10px;border-radius:999px;background:#eef2f7;overflow:hidden}.fill{height:100%;border-radius:999px;background:#1769c2}.level{font-weight:900;color:#344054;white-space:nowrap}.score-small{color:#667085;font-size:12px;line-height:1.55}.comment{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;line-height:1.75;white-space:pre-wrap}.admin-score{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.score-box{border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fff}.score-box span{color:#667085;font-size:13px}.score-box strong{display:block;font-size:22px;margin-top:4px}.empty{padding:40px;text-align:center;color:#667085}.note{background:#fffbeb;border:1px solid #f6d58e;border-radius:8px;color:#7a4d00;padding:12px;margin-top:12px;line-height:1.6}.copy-box{width:100%;min-height:160px;border:1px solid #d9dee7;border-radius:8px;padding:14px;line-height:1.7;resize:vertical}.summary-kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}.kpi{border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fbfcfe}.kpi strong{display:block;font-size:21px;margin-top:4px;color:#1769c2}.level-panel{margin-top:18px;display:grid;grid-template-columns:130px 1fr;gap:16px;align-items:center;border:1px solid #d9dee7;border-radius:12px;padding:16px;background:linear-gradient(135deg,#f2f6ff,#fff)}.level-emblem{height:110px;border-radius:24px;color:#fff;display:grid;place-items:center;text-align:center;font-weight:900;box-shadow:inset 0 0 0 5px rgba(255,255,255,.2)}.level-emblem strong{display:block;font-size:22px}.level-emblem span{display:block;font-size:12px;margin-top:4px}.level-info h2{margin:0 0 6px}.level-track{height:12px;background:#e7edf5;border-radius:999px;overflow:hidden;margin-top:10px}.level-track i{display:block;height:100%;border-radius:999px}.level-meta{display:flex;justify-content:space-between;margin-top:8px;color:#667085;font-size:12px;font-weight:900}.student-summary{margin-top:18px}.summary-table{width:100%;border-collapse:collapse}.summary-table th,.summary-table td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px}.summary-table th{background:#72829d;color:#fff}.summary-table .left{text-align:left}.summary-table tr.active{background:#eef6ff}.mini{font-size:12px;color:#667085}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.pill.ok{background:#e8f7ee;color:#087f5b}.pill.wait{background:#fff4e6;color:#9a5b00}.table-scroll{overflow-x:auto}@media(max-width:900px){.grid{grid-template-columns:1fr}.admin-score,.summary-kpi{grid-template-columns:repeat(2,1fr)}.ieum-user{margin-left:0}.summary-table{min-width:760px}}@media(max-width:560px){.level-row{grid-template-columns:70px 1fr}.level{grid-column:2}.admin-score,.summary-kpi{grid-template-columns:1fr}.level-panel{grid-template-columns:1fr}.level-emblem{height:84px}}@media print{.top,.filters,.print-hide,.student-summary{display:none}.wrap{max-width:none;margin:0;padding:0}.report-card,.panel{box-shadow:none;border-color:#aaa}.grid{grid-template-columns:1fr 1fr}body{background:#fff}}
</style>
<style>
.notice{margin:14px 0 0;padding:12px 14px;border-radius:8px;font-weight:800;line-height:1.5}.notice.ok{background:#eef9f1;color:#176b2c}.notice.err{background:#fdecec;color:#a4262c}.wrap.is-loading{opacity:.55;pointer-events:none}.report-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.report-kpi{border:1px solid #d9dee7;border-radius:8px;background:#fbfcff;padding:12px}.report-kpi span{display:block;color:#667085;font-size:12px;font-weight:900}.report-kpi strong{display:block;margin-top:4px;font-size:22px}.report-status-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 8px}.report-status-tab{display:inline-flex;align-items:center;gap:6px;min-height:38px;border:1px solid #cfd6df;border-radius:999px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:900}.report-status-tab strong{font-size:13px}.report-status-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}.report-status-tab.warn{border-color:#ffd7a8}.report-status-tab.danger{border-color:#ffc9c9}.report-send-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:14px 0}.send-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.check-cell{width:46px}.check-cell input{width:auto}.summary-table th.check-cell,.summary-table td.check-cell{text-align:center}.send-help{color:#667085;font-size:13px;line-height:1.5}.sendable{font-size:12px;color:#087f5b;font-weight:900}.blocked{font-size:12px;color:#a4262c;font-weight:900}.report-list-tools{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0}.report-page-size{display:flex;align-items:center;gap:8px;color:#475467;font-size:13px;font-weight:900}.report-page-size select{height:36px}.pagination{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:12px}.page-link{display:inline-flex;min-width:34px;height:34px;align-items:center;justify-content:center;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-weight:900;padding:0 10px}.page-link.active{background:#1769c2;border-color:#1769c2;color:#fff}.page-link.disabled{opacity:.45;pointer-events:none}@media(max-width:760px){.report-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.report-kpis{grid-template-columns:1fr}.report-list-tools{align-items:flex-start;flex-direction:column}}
.report-ready-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.ready-step{border:1px solid #d9dee7;border-radius:10px;background:#fbfcff;padding:12px}.ready-step strong{display:block;font-size:15px}.ready-step span{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.45}.ready-step.good{border-color:#b7e4c7;background:#f0fff4}.ready-step.warn{border-color:#ffd7a8;background:#fffaf0}.ready-step.off{border-color:#d9dee7;background:#f8fafc}@media(max-width:820px){.report-ready-guide{grid-template-columns:1fr}}
.month-week-links{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px;border:1px solid #d9dee7;border-radius:10px;background:#f8fbff;padding:12px}.month-week-links strong{font-size:15px}.week-link-list{display:flex;gap:8px;flex-wrap:wrap}.week-link{display:inline-flex;align-items:center;gap:7px;border:1px solid #cfd6df;border-radius:999px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900}.week-link span{color:#667085;font-size:12px}.report-actions{display:flex;justify-content:center;gap:6px;flex-wrap:wrap}.summary-table .btn{min-height:34px;padding:6px 10px}
.rank-emblem{position:relative;isolation:isolate;overflow:visible;background:radial-gradient(circle at 50% 20%,rgba(255,255,255,.58),transparent 24%),linear-gradient(145deg,var(--rank-accent),var(--rank-color) 54%,#171a22);clip-path:polygon(50% 2%,96% 36%,82% 98%,18% 98%,4% 36%)}.rank-emblem:before,.rank-emblem:after{content:"";position:absolute;z-index:-1;top:36%;width:58px;height:18px;border-radius:999px;background:linear-gradient(90deg,rgba(255,255,255,.78),var(--rank-accent));box-shadow:0 10px 18px rgba(15,23,42,.18)}.rank-emblem:before{left:8px;transform:rotate(-24deg)}.rank-emblem:after{right:8px;transform:rotate(24deg)}.rank-core{width:42px;height:42px;margin:0 auto 7px;background:linear-gradient(145deg,#fff,var(--rank-accent) 46%,var(--rank-color));clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);filter:drop-shadow(0 8px 10px rgba(15,23,42,.22))}.special-note{display:inline-flex;align-items:center;border-radius:999px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:7px 10px;font-size:12px;font-weight:900;margin-top:8px}.score-box.special{background:#fff7ed;border-color:#fed7aa}.score-box.special strong{color:#9a3412}.summary-table td a[href*="/admin/character_report.php?month="]{display:none}.report-modal-opened{overflow:hidden}.report-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.50);opacity:0;pointer-events:none;transition:opacity .18s ease;z-index:80}.report-modal{position:fixed;inset:24px;max-width:1180px;margin:0 auto;background:#fff;border:1px solid #d9dee7;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.30);display:grid;grid-template-rows:auto 1fr;overflow:hidden;opacity:0;pointer-events:none;transform:translateY(18px) scale(.985);transition:opacity .2s ease,transform .2s ease;z-index:81}.report-modal-backdrop.open{opacity:1;pointer-events:auto}.report-modal.open{opacity:1;pointer-events:auto;transform:translateY(0) scale(1)}.report-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #e4eaf2;background:#f8fbff}.report-modal-title{font-size:18px;font-weight:1000}.report-modal-title span{display:block;margin-top:3px;color:#667085;font-size:13px;font-weight:800}.report-modal-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.report-modal-actions .btn{min-height:36px;padding:7px 10px}.report-modal-close{border:1px solid #cfd6df;background:#fff;border-radius:10px;width:42px;height:42px;font-size:20px;font-weight:1000;cursor:pointer}.report-modal-frame{width:100%;height:100%;border:0;background:#eef2f7}@media(max-width:720px){.report-modal{inset:8px;border-radius:12px}.report-modal-head{padding:11px 12px}.report-modal-title{font-size:16px}.report-modal-actions{gap:6px}}
</style>
<style>
.character-report-page-tune .wrap{max-width:1900px;margin:0 auto;padding:24px 28px 56px}
.character-report-page-tune .hero{align-items:center;margin-bottom:14px}
.character-report-page-tune .hero h1{font-size:30px;letter-spacing:-.01em}
.character-report-page-tune .filters{background:#fff;border:1px solid #d9dee7;border-radius:16px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.character-report-page-tune .hero .filters{box-shadow:none;padding:0;border:0;background:transparent}
.character-report-page-tune .btn,.character-report-page-tune input,.character-report-page-tune select{border-radius:10px}
.character-report-page-tune .report-card,.character-report-page-tune .panel,.character-report-page-tune .report-kpi,.character-report-page-tune .ready-step{border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.character-report-page-tune .report-kpis{grid-template-columns:repeat(4,minmax(0,1fr))}
.character-report-page-tune .report-status-tabs{margin-top:16px}
.character-report-page-tune .report-status-tab{background:#fff}
.character-report-page-tune .report-status-tab.active{background:#1769c2!important;border-color:#1769c2!important;color:#fff!important}
.character-report-page-tune .report-send-bar{background:#f8fbff;border:1px solid #d9dee7;border-radius:14px;padding:12px}
.character-report-page-tune .student-summary{margin-top:18px}
.character-report-page-tune .summary-table{min-width:1040px}
.character-report-page-tune .summary-table th{background:#72829d}
.character-report-page-tune .summary-table td{vertical-align:middle}
.character-report-page-tune .report-actions .btn{border-radius:10px;min-height:34px}
.character-report-page-tune .table-scroll{border:1px solid #d8dee9;border-radius:14px;overflow:auto;background:#fff}
.character-report-page-tune .table-scroll .summary-table th:first-child{border-top-left-radius:14px}
.character-report-page-tune .table-scroll .summary-table th:last-child{border-top-right-radius:14px}
.character-report-page-tune .summary-table th,.character-report-page-tune .summary-table td{border-left:0;border-right:0}
.character-report-page-tune .summary-table th:last-child,.character-report-page-tune .summary-table td:last-child{position:sticky;right:0;background:#fff;box-shadow:-10px 0 18px rgba(255,255,255,.92);z-index:2}
.character-report-page-tune .summary-table th:last-child{background:#72829d;color:#fff;box-shadow:-10px 0 18px rgba(114,130,157,.28);z-index:3}
.character-report-page-tune .summary-table tr.active td:last-child{background:#eef6ff}
.character-report-page-tune .student-summary{padding:18px 20px!important}
.character-report-page-tune .student-summary>.hero{margin-bottom:12px}
.character-report-page-tune .report-kpis{gap:8px;margin-top:12px}
.character-report-page-tune .report-kpi{min-height:64px;padding:10px 12px!important}
.character-report-page-tune .report-kpi strong{font-size:21px}
.character-report-page-tune .report-ready-guide{gap:8px;margin-top:10px}
.character-report-page-tune .ready-step{padding:10px 12px!important}
.character-report-page-tune .ready-step span{line-height:1.35}
.character-report-page-tune .month-week-links{margin-top:10px;padding:10px 12px}
.character-report-page-tune .week-link{min-height:32px;padding:6px 10px}
.character-report-page-tune .report-status-tabs{gap:6px;margin:12px 0 8px}
.character-report-page-tune .report-status-tab{min-height:34px;padding:6px 11px}
.character-report-page-tune .note{margin-top:8px;padding:10px 12px}
.character-report-page-tune .report-list-tools{margin:10px 0}
.character-report-page-tune .report-send-bar{margin:10px 0;padding:9px 10px}
.character-report-page-tune .summary-table th,
.character-report-page-tune .summary-table td{padding:7px 8px!important;font-size:13px!important}
.character-report-page-tune .summary-table .mini{font-size:11px;line-height:1.35}
.character-report-page-tune .sendable,
.character-report-page-tune .blocked{font-size:11px}
.character-report-page-tune .report-actions .btn{min-height:30px;padding:5px 9px}
body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune .report-ready-guide{display:none!important}
@media(max-width:1180px){.character-report-page-tune .report-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.character-report-page-tune .wrap{padding:18px 14px 44px}.character-report-page-tune .report-kpis{grid-template-columns:1fr}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.character-report-page-tune .side-brand{
    height:144px!important;
    padding:0 28px!important;
    align-items:center!important;
    font-size:30px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
.character-report-page-tune .side-brand-mark,
.character-report-page-tune .side-profile,
.character-report-page-tune .side-search,
.character-report-page-tune .ieum-right-rail{display:none!important}
.character-report-page-tune .side-nav{padding:0 14px 22px!important}
.character-report-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.character-report-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.character-report-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:#fff!important;
    border-bottom:1px solid #eef2f7!important;
    color:#0f172a!important;
    box-shadow:none!important;
}
.character-report-page-tune .ieum-shell-link,
.character-report-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.character-report-page-tune .ieum-shell-link::before{display:none!important}
.character-report-page-tune .ieum-shell-meta{color:#0f172a!important}
.character-report-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.character-report-page-tune .dashboard-shell-divider,
.character-report-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune .wrap{
    max-width:none!important;
    width:auto!important;
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
}
.character-report-page-tune .report-card,
.character-report-page-tune .panel,
.character-report-page-tune .report-kpi,
.character-report-page-tune .ready-step,
.character-report-page-tune .filters,
.character-report-page-tune .table-scroll{border-radius:8px!important;box-shadow:none!important}
.character-report-page-tune .summary-table th{background:#f8fafc!important;color:#475569!important;border-color:#e5e7eb!important}
.character-report-page-tune .summary-table td{border-color:#eef2f7!important}
.character-report-page-tune .summary-table th:last-child{background:#f8fafc!important;color:#475569!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune{--ieum-side-width:0px}
    .character-report-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.character-report-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
<link rel="stylesheet" href="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.css?v=20260527b">
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page character-report-page-tune">
<?php echo ieum_admin_header('character_report', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>월간 인성리포트</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 점수보다 성장 균형을 먼저 보여주는 학부모용 화면</div>
        </div>
        <div class="filters print-hide" style="margin:0">
            <?php if ($student) { ?>
            <a class="btn primary character-report-modal-open" href="<?php echo IEUM_URL; ?>/admin/character_parent_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $student_id; ?>" data-student-name="<?php echo get_text($student['student_name']); ?>" data-student-code="<?php echo get_text($student['student_code']); ?>">리포트 보기</a>
            <?php } ?>
            <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($print_all_url); ?>">입력완료 대상 전체 인쇄</a>
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
        <a class="btn" href="<?php echo get_text(ieum_character_report_input_url($report_first_week_start, $program_code, $class_time_id)); ?>">인성 입력</a>
    </form>

    <section class="panel student-summary print-hide">
        <div class="hero">
            <div>
                <h2>원생별 월간 상태</h2>
                <div class="meta">필터에 해당하는 원생을 먼저 훑고, 필요한 원생만 리포트로 들어갑니다.</div>
            </div>
            <span class="stage"><?php echo get_text($report_status_labels[$report_status]); ?> <?php echo number_format(count($student_summaries)); ?>명</span>
        </div>
        <div class="report-kpis">
            <article class="report-kpi"><span>현재 목록</span><strong><?php echo number_format(count($student_summaries)); ?>명</strong></article>
            <article class="report-kpi"><span><?php echo number_format($expected_weeks); ?>주차 입력 완료</span><strong><?php echo number_format($input_complete_count); ?>명</strong></article>
            <article class="report-kpi"><span>링크 발송 가능</span><strong><?php echo number_format($report_ready_count); ?>명</strong></article>
            <article class="report-kpi"><span>제외/보완 필요</span><strong><?php echo number_format($input_missing_count + $report_no_phone_count + $report_excluded_count); ?>명</strong></article>
        </div>
        <div class="report-ready-guide">
            <article class="ready-step good"><strong>1. 준비 완료</strong><span><?php echo number_format($expected_weeks); ?>주차 입력과 대표 보호자 연락처가 있으면 인쇄/문자 발송 대상입니다.</span></article>
            <article class="ready-step warn"><strong>2. 입력 보완</strong><span>입력 부족 원생은 미리보기만 확인하고, 인성 입력을 채운 뒤 최종 발송합니다.</span></article>
            <article class="ready-step off"><strong>3. 리포트 제외</strong><span>고학년 등 리포트를 운영하지 않는 원생은 원생관리에서 사용 여부를 관리합니다.</span></article>
        </div>
        <div class="month-week-links">
            <strong>이번 달 주차 입력</strong>
            <div class="week-link-list">
                <?php foreach ($report_week_starts as $week_index => $week_start) { ?>
                <a class="week-link" href="<?php echo get_text(ieum_character_report_input_url($week_start, $program_code, $class_time_id)); ?>">
                    <?php echo number_format($week_index + 1); ?>주차 입력 <span><?php echo get_text(date('m/d', strtotime($week_start))); ?></span>
                </a>
                <?php } ?>
            </div>
        </div>
        <div class="report-status-tabs" aria-label="인성리포트 상태 필터">
            <?php
            $status_counts = array(
                '' => $report_all_total,
                'input_complete' => $input_complete_count,
                'input_missing' => $input_missing_count,
                'send_ready' => $report_ready_count,
                'no_phone' => $report_no_phone_count,
                'excluded' => $report_excluded_count,
            );
            foreach ($report_status_labels as $status_key => $status_label) {
                $status_query = array(
                    'month' => $month,
                    'program_code' => $program_code,
                    'class_time_id' => $class_time_id,
                    'student_id' => $student_id,
                    'report_status' => $status_key,
                    'report_page_size' => $report_page_size,
                    'report_page' => 1,
                );
                if ($status_key === '') {
                    unset($status_query['report_status']);
                }
                $status_class = $status_key === 'input_missing' ? ' warn' : ($status_key === 'no_phone' || $status_key === 'excluded' ? ' danger' : '');
            ?>
            <a class="report-status-tab<?php echo $report_status === $status_key ? ' active' : ''; ?><?php echo $status_class; ?>" href="<?php echo IEUM_URL; ?>/admin/character_report.php?<?php echo http_build_query($status_query); ?>">
                <?php echo get_text($status_label); ?> <strong><?php echo number_format(isset($status_counts[$status_key]) ? $status_counts[$status_key] : 0); ?>명</strong>
            </a>
            <?php } ?>
        </div>
        <p class="note">발송 가능은 <?php echo number_format($expected_weeks); ?>주차 인성 입력과 대표 보호자 연락처가 모두 준비된 원생입니다.</p>
        <form method="post" class="send-confirm-form" data-send-title="인성 리포트 문자 발송 확인" data-send-message="선택한 원생의 학부모 인성리포트 링크 문자를 발송 준비합니다." data-send-summary="ok:발송 가능 <?php echo number_format($report_ready_count); ?>명|warn:입력 부족 <?php echo number_format($input_missing_count); ?>명|danger:대표 보호자 없음 <?php echo number_format($report_no_phone_count); ?>명|info:리포트 제외 <?php echo number_format($report_excluded_count); ?>명|info:아이잘해 완료 <?php echo number_format($mission_done_count); ?>명" data-message-editable="1" data-message-template="<?php echo get_text($character_sms_template); ?>" data-message-preview="<?php echo get_text($character_sms_preview); ?>" data-message-values="<?php echo $character_sms_values_json; ?>" data-message-tokens="사용 가능: {도장명}, {원생명}, {리포트월}, {리포트주소}">
        <div class="report-list-tools">
            <div class="send-help"><?php echo get_text($report_status_labels[$report_status]); ?> <?php echo number_format($report_total); ?>명 중 <?php echo number_format($report_start); ?>-<?php echo number_format($report_end); ?>명 표시 · <?php echo number_format($report_page); ?>/<?php echo number_format($report_total_pages); ?>페이지</div>
            <div class="report-page-size">
                <span>목록</span>
                <select onchange="location.href=this.value">
                    <?php foreach (array(10, 25, 50) as $size) {
                        $size_query = array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'student_id' => $student_id, 'report_status' => $report_status, 'report_page_size' => $size, 'report_page' => 1);
                        if ($report_status === '') {
                            unset($size_query['report_status']);
                        }
                    ?>
                    <option value="<?php echo IEUM_URL; ?>/admin/character_report.php?<?php echo http_build_query($size_query); ?>" <?php echo get_selected($report_page_size, $size); ?>><?php echo (int) $size; ?>명씩 보기</option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
        <input type="hidden" name="action" value="send_parent_report_links">
        <input type="hidden" name="final_approved" value="0">
        <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
        <input type="hidden" name="program_code" value="<?php echo get_text($program_code); ?>">
        <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
        <input type="hidden" name="student_id" value="<?php echo (int) $student_id; ?>">
        <div class="report-send-bar">
            <div class="send-actions">
                <label class="send-help"><input type="checkbox" id="checkAllReportStudents"> 현재 목록 전체 선택</label>
                <button type="button" class="btn" id="selectReportReady" <?php echo $report_status === 'excluded' ? 'disabled' : ''; ?>>발송 가능 원생만 선택</button>
                <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($print_all_url); ?>">입력완료 대상 전체 인쇄</a>
            </div>
            <button type="submit" class="btn primary js-character-send-button" disabled>선택 문자발송</button>
        </div>
        <div class="table-scroll">
            <table class="summary-table">
                <thead>
                    <tr><th class="check-cell">선택</th><th>원생</th><th>프로그램</th><th>부</th><th>총 흐름</th><th>성실</th><th>아이잘해</th><th>관리</th></tr>
                </thead>
                <tbody>
                <?php foreach ($report_page_summaries as $summary) {
                    $option = $summary['student'];
                    $option_score = $summary['score'];
                    $option_mission = $summary['mission'];
                    $option_complete = !empty($summary['input_complete']);
                    $option_excluded = !empty($summary['excluded']);
                    $option_sendable = $option_complete && !empty($summary['primary_phone_count']);
                    $option_input_url = ieum_character_report_input_url($report_first_week_start, isset($option['program_code']) ? $option['program_code'] : $program_code, isset($option['class_time_id']) ? (int) $option['class_time_id'] : $class_time_id, (int) $option['student_id']);
                ?>
                <tr class="<?php echo (int) $option['student_id'] === $student_id ? 'active' : ''; ?>">
                    <td class="check-cell"><input type="checkbox" class="report-student-check" name="student_ids[]" value="<?php echo (int) $option['student_id']; ?>" data-ready="<?php echo $option_sendable ? '1' : '0'; ?>" <?php echo $option_excluded ? 'disabled' : ''; ?>></td>
                    <td class="left"><strong><?php echo get_text($option['student_name']); ?></strong><div class="mini"><?php echo get_text($option['student_code'] . ' · ' . ieum_character_report_grade_label($option['grade_group'])); ?></div><div class="<?php echo $option_sendable ? 'sendable' : 'blocked'; ?>"><?php echo $option_excluded ? '인성리포트 사용 안함' : ($option_sendable ? '링크 문자 가능' : ($option_complete ? '대표 보호자 연락처 필요' : '인성 입력 부족')); ?></div></td>
                    <td><?php echo get_text(ieum_program_label($academy_id, isset($option['program_code']) ? $option['program_code'] : '')); ?></td>
                    <td><?php echo get_text(trim(($option['class_name'] ?: '미지정') . ' ' . ($option['start_time'] ?: ''))); ?></td>
                    <td><strong><?php echo ($option_score && empty($option_score['is_before_admission'])) ? number_format((int) $option_score['total_score']) : '-'; ?></strong><div class="mini"><?php echo ($option_score && empty($option_score['is_before_admission'])) ? number_format((int) $option_score['evaluated_weeks']) . '/' . number_format($expected_weeks) . '주 · ' . get_text(ieum_character_total_stage($option_score['total_score'])) : ($option_excluded ? '제외 대상' : '입관 전'); ?></div></td>
                    <td><?php echo ($option_score && empty($option_score['is_before_admission'])) ? number_format((int) $option_score['attendance']['rate']) . '%' : '-'; ?></td>
                    <td><span class="pill <?php echo $option_mission && $option_mission['is_participated'] ? 'ok' : 'wait'; ?>"><?php echo get_text($option_mission ? $option_mission['status_label'] : '미설정'); ?></span></td>
                    <td>
                        <div class="report-actions">
                        <?php if ($option_excluded) { ?>
                        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $option['student_id']; ?>">사용 설정</a>
                        <?php } else { ?>
                        <a class="btn primary character-report-modal-open" href="<?php echo IEUM_URL; ?>/admin/character_parent_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $option['student_id']; ?>" data-student-name="<?php echo get_text($option['student_name']); ?>" data-student-code="<?php echo get_text($option['student_code']); ?>">보기</a>
                        <?php if (!$option_complete) { ?>
                        <a class="btn" href="<?php echo get_text($option_input_url); ?>">입력하기</a>
                        <?php } ?>
                        <?php } ?>
                        </div>
                    </td>
                </tr>
                <?php } ?>
                <?php if (!$student_summaries) { ?><tr><td colspan="8">조건에 맞는 원생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($report_total_pages > 1) { ?>
        <nav class="pagination" aria-label="월간 인성리포트 원생 목록 페이지">
            <?php
            $page_query = array(
                'month' => $month,
                'program_code' => $program_code,
                'class_time_id' => $class_time_id,
                'student_id' => $student_id,
                'report_status' => $report_status,
                'report_page_size' => $report_page_size,
            );
            if ($report_status === '') {
                unset($page_query['report_status']);
            }
            $page_query['report_page'] = max(1, $report_page - 1);
            ?>
            <a class="page-link <?php echo $report_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character_report.php?<?php echo http_build_query($page_query); ?>">이전</a>
            <?php
            $page_from = max(1, $report_page - 3);
            $page_to = min($report_total_pages, $report_page + 3);
            for ($page_no = $page_from; $page_no <= $page_to; $page_no++) {
                $page_query['report_page'] = $page_no;
            ?>
            <a class="page-link <?php echo $page_no === $report_page ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character_report.php?<?php echo http_build_query($page_query); ?>"><?php echo number_format($page_no); ?></a>
            <?php } ?>
            <?php $page_query['report_page'] = min($report_total_pages, $report_page + 1); ?>
            <a class="page-link <?php echo $report_page >= $report_total_pages ? 'disabled' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character_report.php?<?php echo http_build_query($page_query); ?>">다음</a>
        </nav>
        <?php } ?>
        </form>
    </section>

</main>
<script>
(function(){
    var rootSelector = '.character-report-page-tune.ieum-dashboard-page';
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

    const ensureReportModal = () => {
        let backdrop = document.getElementById('characterReportBackdrop');
        let modal = document.getElementById('characterReportModal');
        if (backdrop && modal) {
            return {backdrop, modal, frame: document.getElementById('characterReportFrame')};
        }

        backdrop = document.createElement('div');
        backdrop.className = 'report-modal-backdrop';
        backdrop.id = 'characterReportBackdrop';

        modal = document.createElement('section');
        modal.className = 'report-modal';
        modal.id = 'characterReportModal';
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('aria-label', '학부모용 인성 리포트 미리보기');
        modal.innerHTML = '<header class="report-modal-head"><div class="report-modal-title" id="characterReportTitle">인성 리포트 보기<span id="characterReportSubtitle">목록을 유지한 채 확인합니다.</span></div><div class="report-modal-actions"><a class="btn" id="characterReportOpenNew" target="_blank" rel="noopener">새 창</a><button type="button" class="btn" id="characterReportPrint">인쇄</button><button type="button" class="report-modal-close" id="characterReportClose" aria-label="리포트 닫기">×</button></div></header><iframe class="report-modal-frame" id="characterReportFrame" title="학부모용 인성 리포트"></iframe>';

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);

        const close = () => closeReportModal();
        backdrop.addEventListener('click', close);
        modal.querySelector('#characterReportClose').addEventListener('click', close);
        modal.querySelector('#characterReportPrint').addEventListener('click', () => {
            const frame = document.getElementById('characterReportFrame');
            if (frame && frame.contentWindow) {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            }
        });
        return {backdrop, modal, frame: modal.querySelector('#characterReportFrame')};
    };

    const openReportModal = (url, studentName, studentCode) => {
        const {backdrop, modal, frame} = ensureReportModal();
        const subtitle = modal.querySelector('#characterReportSubtitle');
        if (subtitle) {
            subtitle.textContent = [studentName, studentCode].filter(Boolean).join(' · ') || '목록을 유지한 채 확인합니다.';
        }
        const openNew = modal.querySelector('#characterReportOpenNew');
        if (openNew) {
            openNew.href = url;
        }
        frame.src = url;
        backdrop.classList.add('open');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('report-modal-opened');
    };

    const closeReportModal = () => {
        const backdrop = document.getElementById('characterReportBackdrop');
        const modal = document.getElementById('characterReportModal');
        const frame = document.getElementById('characterReportFrame');
        if (backdrop) backdrop.classList.remove('open');
        if (modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }
        if (frame) frame.removeAttribute('src');
        document.body.classList.remove('report-modal-opened');
    };

    const enhanceReportActions = () => {
        document.querySelectorAll('.summary-table td a.btn.primary[target="_blank"][href*="/admin/character_parent_report.php"]').forEach((parentLink) => {
            const cell = parentLink.closest('td');
            if (!cell || cell.querySelector('.character-report-modal-open')) return;
            const row = parentLink.closest('tr');
            const studentName = row ? (row.querySelector('td.left strong') || {}).textContent || '' : '';
            const studentCode = row ? (row.querySelector('td.left .mini') || {}).textContent || '' : '';
            const modalLink = document.createElement('a');
            modalLink.className = 'btn character-report-modal-open';
            modalLink.href = parentLink.href;
            modalLink.dataset.studentName = studentName.trim();
            modalLink.dataset.studentCode = studentCode.trim();
            modalLink.textContent = '보기';
            modalLink.addEventListener('click', (event) => {
                event.preventDefault();
                openReportModal(modalLink.href, modalLink.dataset.studentName || '', modalLink.dataset.studentCode || '');
            });
            cell.insertBefore(modalLink, cell.firstChild);
            parentLink.remove();
        });
    };

    const setupChecks = () => {
        const checkAll = document.getElementById('checkAllReportStudents');
        if (!checkAll || checkAll.dataset.ajaxBound === '1') return;
        checkAll.dataset.ajaxBound = '1';
        const updateCharacterSendButton = () => {
            const button = document.querySelector('.js-character-send-button');
            if (!button) return;
            button.disabled = document.querySelectorAll('.report-student-check:checked:not(:disabled)').length === 0;
        };
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.report-student-check').forEach((input) => {
                input.checked = !input.disabled && checkAll.checked;
            });
            updateCharacterSendButton();
        });
        const selectReady = document.getElementById('selectReportReady');
        if (selectReady && selectReady.dataset.ajaxBound !== '1') {
            selectReady.dataset.ajaxBound = '1';
            selectReady.addEventListener('click', () => {
                document.querySelectorAll('.report-student-check').forEach((input) => {
                    input.checked = input.dataset.ready === '1';
                });
                checkAll.checked = false;
                updateCharacterSendButton();
            });
        }
        document.querySelectorAll('.report-student-check').forEach((input) => {
            input.addEventListener('change', updateCharacterSendButton);
        });
        updateCharacterSendButton();
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
            enhanceReportActions();
            if (push) history.pushState({ieumAjax: true}, '', url);
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

    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });

    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filters input, form.filters select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });

    main.addEventListener('click', (event) => {
        const modalLink = event.target.closest('.character-report-modal-open');
        if (modalLink) {
            event.preventDefault();
            openReportModal(modalLink.href, modalLink.dataset.studentName || '', modalLink.dataset.studentCode || '');
            return;
        }
        const link = event.target.closest('.summary-table a.btn');
        if (!link || link.target) return;
        if (link.href && link.href.indexOf('/admin/character.php') !== -1) {
            return;
        }
        event.preventDefault();
        loadView(link.href, true);
    });

    setupChecks();
    enhanceReportActions();
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeReportModal();
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
<script src="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.js?v=20260530d"></script>
</body>
</html>
