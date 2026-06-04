<?php
$sub_menu = '950193';
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/fitness.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/character_report_link.php';

$g5['title'] = '아이이음 체력 리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_fitness_ensure_table();
$message = '';
$error = '';

function ieum_fitness_reports_grade_label($value)
{
    $labels = ieum_fitness_grade_labels();
    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_fitness_reports_total_label($score)
{
    $score = (float) $score;
    if ($score >= 88) { return '매우 좋은 성장 흐름'; }
    if ($score >= 78) { return '좋은 성장 흐름'; }
    if ($score >= 68) { return '꾸준한 관리 필요'; }
    return '관찰 필요';
}

function ieum_fitness_reports_display_label($label)
{
    return str_replace(array('남학생', '여학생', '학생'), array('남자', '여자', '원생'), (string) $label);
}

function ieum_fitness_reports_cycle_label($cycle_months)
{
    $cycle_months = (int) $cycle_months;
    if ($cycle_months <= 1) { return '매월'; }
    if ($cycle_months === 12) { return '1년에 1회'; }
    return $cycle_months . '개월마다';
}

function ieum_fitness_reports_month_diff($from_date, $to_month)
{
    $from_date = trim((string) $from_date);
    if ($from_date === '' || !preg_match('/^\d{4}\-\d{2}/', $from_date)) {
        return 0;
    }
    $from_year = (int) substr($from_date, 0, 4);
    $from_month = (int) substr($from_date, 5, 2);
    $to_year = (int) substr($to_month, 0, 4);
    $to_month_no = (int) substr($to_month, 5, 2);
    return (($to_year - $from_year) * 12) + ($to_month_no - $from_month);
}

function ieum_fitness_reports_cycle_state($student, $month, $settings)
{
    $cycle_months = isset($settings['cycle_months']) ? (int) $settings['cycle_months'] : 1;
    if ($cycle_months < 1) { $cycle_months = 1; }
    $base_date = '';
    if (!empty($student['admission_date'])) { $base_date = $student['admission_date']; }
    elseif (!empty($student['created_at'])) { $base_date = $student['created_at']; }
    if ($cycle_months <= 1) {
        return array('due' => true, 'label' => '이번 달 대상', 'reason' => '매월 측정 주기');
    }
    $diff = ieum_fitness_reports_month_diff($base_date, $month);
    if ($diff < 0) {
        return array('due' => false, 'label' => '주기 제외', 'reason' => '입관 전 월');
    }
    $due = ($diff % $cycle_months) === 0;
    $next_left = $due ? 0 : ($cycle_months - ($diff % $cycle_months));
    return array(
        'due' => $due,
        'label' => $due ? '이번 주기 대상' : '다음 주기',
        'reason' => $due ? ieum_fitness_reports_cycle_label($cycle_months) . ' 측정 대상' : $next_left . '개월 후 측정 예정',
    );
}

function ieum_fitness_reports_send_state($entry)
{
    if (empty($entry['cycle_due'])) { return array('key' => 'not_due', 'label' => '주기 제외', 'class' => 'muted', 'detail' => $entry['cycle_reason']); }
    if (empty($entry['completed'])) { return array('key' => 'wait', 'label' => '측정 대기', 'class' => 'wait', 'detail' => '체력 수치 입력 필요'); }
    if (empty($entry['primary_phone_count'])) { return array('key' => 'no_phone', 'label' => '연락처 필요', 'class' => 'warn', 'detail' => '대표 보호자 연락처 없음'); }
    if (!empty($entry['sms_sent_count'])) { return array('key' => 'sent', 'label' => '발송 완료', 'class' => 'done', 'detail' => '이번 달 발송 기록 있음'); }
    if (!empty($entry['sms_queued_count'])) { return array('key' => 'queued', 'label' => '발송 준비', 'class' => 'ready', 'detail' => '문자 발송 준비됨'); }
    return array('key' => 'send_ready', 'label' => '발송 준비', 'class' => 'ready', 'detail' => '일괄 발송 가능');
}

function ieum_fitness_reports_sms_template_default()
{
    return "[{도장명}] 안녕하세요. {원생명} 원생의 {리포트월} 체력 리포트가 준비되었습니다.\n키·몸무게·체력 측정 흐름을 아래 링크에서 확인해 주세요.\n{리포트주소}";
}

function ieum_fitness_reports_sms_template($academy_id)
{
    $academy_id = (int) $academy_id;
    $row = sql_fetch("
        select message
          from " . IEUM_SMS_TEMPLATE_TABLE . "
         where academy_id = '{$academy_id}'
           and template_key = 'fitness_report'
           and is_active = 1
         limit 1
    ", false);

    if (!empty($row['message'])) {
        return $row['message'];
    }

    return ieum_fitness_reports_sms_template_default();
}

function ieum_fitness_reports_save_sms_template($academy_id, $message)
{
    $academy_id = (int) $academy_id;
    $message = trim((string) $message);
    if ($message === '') {
        return false;
    }

    sql_query("
        insert into " . IEUM_SMS_TEMPLATE_TABLE . "
            set academy_id = '{$academy_id}',
                template_key = 'fitness_report',
                title = '체력 리포트 발송 안내',
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

function ieum_fitness_reports_render_sms_template($template, $vars)
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
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$report_status = isset($_GET['report_status']) ? preg_replace('/[^a-z_]/', '', trim($_GET['report_status'])) : '';
$allowed_statuses = array('', 'cycle_due', 'send_ready', 'done', 'wait', 'sent', 'not_due');
if (!in_array($report_status, $allowed_statuses, true)) {
    $report_status = '';
}

$fitness_settings = ieum_fitness_settings($academy_id);
$cycle_label = ieum_fitness_reports_cycle_label((int) $fitness_settings['cycle_months']);
$send_day = max(1, min(31, (int) $fitness_settings['report_send_day']));

$program_filter_sql = '';
if ($program_code !== '') {
    $program_filter_sql = " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : '';

$class_options = array();
$class_result = sql_query("
    select class_time_id, class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($class_result)) {
    $class_options[] = $class;
}

$students = array();
$student_result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.birth_date, s.grade_group, s.program_code, s.gender,
           s.admission_date, s.created_at, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.fitness_report_enabled, 1) = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_result)) {
    $students[(int) $row['student_id']] = $row;
}

$guardian_counts = array();
$sms_month_map = array();
if ($students) {
    $student_ids_sql = implode(',', array_map('intval', array_keys($students)));
    $guardian_result = sql_query("
        select student_id, count(*) as cnt
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id in ({$student_ids_sql})
           and is_active = 1
           and is_primary = 1
           and guardian_phone <> ''
      group by student_id
    ", false);
    while ($guardian = sql_fetch_array($guardian_result)) {
        $guardian_counts[(int) $guardian['student_id']] = (int) $guardian['cnt'];
    }

    $sms_month_start = $month . '-01 00:00:00';
    $sms_next_month = date('Y-m-d H:i:s', strtotime($month . '-01 +1 month'));
    $sms_result = sql_query("
        select student_id,
               sum(case when status = 'sent' then 1 else 0 end) as sent_count,
               sum(case when status in ('pending', 'processing') then 1 else 0 end) as queued_count,
               sum(case when status in ('pending', 'processing', 'sent') then 1 else 0 end) as active_count
          from " . IEUM_SMS_QUEUE_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id in ({$student_ids_sql})
           and message_type = 'fitness_report'
           and created_at >= '" . sql_escape_string($sms_month_start) . "'
           and created_at < '" . sql_escape_string($sms_next_month) . "'
      group by student_id
    ", false);
    while ($sms = sql_fetch_array($sms_result)) {
        $sms_month_map[(int) $sms['student_id']] = array(
            'sent' => (int) $sms['sent_count'],
            'queued' => (int) $sms['queued_count'],
            'active' => (int) $sms['active_count'],
        );
    }
}

$fitness_map = array();
$fitness_result = sql_query("
    select *
      from " . IEUM_REPORT_FITNESS_TABLE . "
     where academy_id = '{$academy_id}'
       and report_month = '" . sql_escape_string($month) . "'
", false);
while ($row = sql_fetch_array($fitness_result)) {
    $fitness_map[(int) $row['student_id']] = $row;
}
$student_ids_for_values = array_keys($students);
ieum_fitness_merge_metric_values($fitness_map, ieum_fitness_metric_values($academy_id, $month, $student_ids_for_values), $student_ids_for_values);

$items = ieum_fitness_active_items($academy_id);
$completed_count = 0;
$cycle_due_count = 0;
$cycle_wait_count = 0;
$send_ready_count = 0;
$sent_or_queued_count = 0;
$total_sum = 0;
$all_rows = array();
$rows = array();

foreach ($students as $sid => $student) {
    $fitness = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
    $is_completed = false;
    if ($fitness) {
        foreach (array_keys($items) as $key) {
            if (isset($fitness[$key]) && $fitness[$key] !== null && $fitness[$key] !== '') {
                $is_completed = true;
                break;
            }
        }
    }

    $calc = $is_completed ? ieum_fitness_calculate($fitness, $student, $academy_id, $month) : null;
    $best_key = '';
    $watch_key = '';
    if ($calc) {
        $completed_count++;
        $total_sum += (float) $calc['total'];
        foreach ($items as $key => $item) {
            if ($best_key === '' || (float) $calc['item_scores'][$key] > (float) $calc['item_scores'][$best_key]) {
                $best_key = $key;
            }
            if ($watch_key === '' || (float) $calc['item_scores'][$key] < (float) $calc['item_scores'][$watch_key]) {
                $watch_key = $key;
            }
        }
    }

    $cycle_state = ieum_fitness_reports_cycle_state($student, $month, $fitness_settings);
    $sms_state = isset($sms_month_map[$sid]) ? $sms_month_map[$sid] : array('sent' => 0, 'queued' => 0, 'active' => 0);
    $entry = array(
        'student' => $student,
        'fitness' => $fitness,
        'completed' => $is_completed,
        'calc' => $calc,
        'best_key' => $best_key,
        'watch_key' => $watch_key,
        'primary_phone_count' => isset($guardian_counts[$sid]) ? (int) $guardian_counts[$sid] : 0,
        'cycle_due' => !empty($cycle_state['due']),
        'cycle_label' => $cycle_state['label'],
        'cycle_reason' => $cycle_state['reason'],
        'sms_sent_count' => (int) $sms_state['sent'],
        'sms_queued_count' => (int) $sms_state['queued'],
        'sms_active_count' => (int) $sms_state['active'],
    );
    $send_state = ieum_fitness_reports_send_state($entry);
    $entry['send_state'] = $send_state;
    $entry['send_ready'] = $send_state['key'] === 'send_ready';

    if ($entry['cycle_due']) {
        $cycle_due_count++;
        if (!$entry['completed']) {
            $cycle_wait_count++;
        }
    }
    if ($entry['send_ready']) {
        $send_ready_count++;
    }
    if ($entry['sms_active_count'] > 0) {
        $sent_or_queued_count++;
    }

    $all_rows[$sid] = $entry;

    if ($report_status === 'cycle_due' && !$entry['cycle_due']) {
        continue;
    }
    if ($report_status === 'send_ready' && !$entry['send_ready']) {
        continue;
    }
    if ($report_status === 'done' && !$entry['completed']) {
        continue;
    }
    if ($report_status === 'wait' && !($entry['cycle_due'] && !$entry['completed'])) {
        continue;
    }
    if ($report_status === 'sent' && !$entry['sms_active_count']) {
        continue;
    }
    if ($report_status === 'not_due' && $entry['cycle_due']) {
        continue;
    }

    $rows[] = $entry;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도해 주세요.';
    } elseif ($action === 'send_fitness_report_links') {
        $final_approved = !empty($_POST['final_approved']) ? 1 : 0;
        if (!$final_approved) {
            $error = '문자 발송 확인 팝업에서 발송을 눌러야 문자를 준비할 수 있습니다.';
        } else {
        $selected_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : array();
        $created = 0;
        $skipped = 0;
        $duplicate = 0;
        $month_label_for_sms = date('Y년 n월', strtotime($month . '-01'));
        $sms_month_start = $month . '-01 00:00:00';
        $sms_next_month = date('Y-m-d H:i:s', strtotime($month . '-01 +1 month'));
        $sms_template = ieum_fitness_reports_sms_template($academy_id);
        $sms_template_override = isset($_POST['notice_message_template']) ? trim((string) $_POST['notice_message_template']) : '';
        if ($sms_template_override !== '') {
            $sms_template = $sms_template_override;
            if (!empty($_POST['save_notice_template'])) {
                ieum_fitness_reports_save_sms_template($academy_id, $sms_template_override);
            }
        }

        foreach ($selected_ids as $selected_id) {
            if (empty($all_rows[$selected_id]) || empty($all_rows[$selected_id]['send_ready'])) {
                $skipped++;
                continue;
            }
            $target = $all_rows[$selected_id]['student'];
            $report_url = ieum_fitness_report_public_url($academy_id, (int) $target['student_id'], $month);
            $sms_message = ieum_fitness_reports_render_sms_template($sms_template, array(
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
                       and message_type = 'fitness_report'
                       and status in ('pending', 'processing', 'sent')
                       and created_at >= '" . sql_escape_string($sms_month_start) . "'
                       and created_at < '" . sql_escape_string($sms_next_month) . "'
                     limit 1
                ", false);
                if (!empty($exists['sms_id'])) {
                    $duplicate++;
                    continue;
                }
                if (ieum_create_direct_sms_queue($academy_id, $phone, $sms_message, 'fitness_report', (int) $target['student_id'], 0)) {
                    $created++;
                }
            }
        }
        $message = '체력 리포트 링크 문자 ' . number_format($created) . '건을 발송 준비했습니다.';
        if ($duplicate) {
            $message .= ' 이미 이번 달 발송 준비 또는 발송 기록이 있는 ' . number_format($duplicate) . '건은 제외했습니다.';
        }
        if ($skipped) {
            $message .= ' 측정 주기/입력 상태/보호자 연락처 조건이 맞지 않는 원생 ' . number_format($skipped) . '명은 제외했습니다.';
        }
        }
    }
}

$avg_total = $completed_count ? round($total_sum / $completed_count, 1) : 0;
$ready_rate = $cycle_due_count ? round((($cycle_due_count - $cycle_wait_count) / $cycle_due_count) * 100) : 0;
$base_report_params = array(
    'month' => $month,
    'program_code' => $program_code,
    'class_time_id' => $class_time_id,
);
$report_all_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query($base_report_params);
$report_cycle_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'cycle_due')));
$report_send_ready_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'send_ready')));
$report_done_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'done')));
$report_wait_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'wait')));
$report_sent_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'sent')));
$report_not_due_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array_merge($base_report_params, array('report_status' => 'not_due')));
$print_ready_url = IEUM_URL . '/admin/fitness_reports_print.php?' . http_build_query(array_merge($base_report_params, array('scope' => 'ready')));
$print_completed_url = IEUM_URL . '/admin/fitness_reports_print.php?' . http_build_query(array_merge($base_report_params, array('scope' => 'completed')));
$fitness_sms_template = ieum_fitness_reports_sms_template($academy_id);
$fitness_sms_preview_student = null;
foreach ($rows as $preview_entry) {
    if (!empty($preview_entry['send_ready'])) {
        $fitness_sms_preview_student = $preview_entry['student'];
        break;
    }
}
if (!$fitness_sms_preview_student && $students) {
    $first_student = reset($students);
    $fitness_sms_preview_student = $first_student;
}
$fitness_sms_preview_name = $fitness_sms_preview_student ? $fitness_sms_preview_student['student_name'] : '홍길동';
$fitness_sms_preview_url = $fitness_sms_preview_student ? ieum_fitness_report_public_url($academy_id, (int) $fitness_sms_preview_student['student_id'], $month) : IEUM_URL . '/r/fitness';
$fitness_sms_values = array(
    'academy_name' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
    'student_name' => (string) $fitness_sms_preview_name,
    'report_month' => date('Y년 n월', strtotime($month . '-01')),
    'report_url' => $fitness_sms_preview_url,
    '도장명' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
    '원생명' => (string) $fitness_sms_preview_name,
    '리포트월' => date('Y년 n월', strtotime($month . '-01')),
    '리포트주소' => $fitness_sms_preview_url,
);
$fitness_sms_preview = ieum_fitness_reports_render_sms_template($fitness_sms_template, $fitness_sms_values);
$fitness_sms_values_json = htmlspecialchars(json_encode($fitness_sms_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$csrf_token = ieum_new_csrf_token();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:6px;line-height:1.45}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn:disabled{opacity:.45;cursor:not-allowed}input,select{border:1px solid #cfd6df;border-radius:8px;padding:9px;font-size:14px;background:#fff}.summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.summary-card{border:1px solid #d9dee7;border-radius:12px;background:#fbfcff;padding:16px;text-decoration:none;color:inherit}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:900}.summary-card strong{display:block;margin-top:6px;font-size:28px}.summary-card.good{background:#effaf3;border-color:#bde5ca}.summary-card.warn{background:#fff8ef;border-color:#f3d4a8}.summary-card.ready{background:#eef6ff;border-color:#bbd7f7}.summary-card.done{background:#f4f7fb}.summary-card.active{outline:3px solid rgba(23,105,194,.2);border-color:#1769c2}.notice{border-radius:10px;padding:12px 14px;margin-top:16px;font-weight:900}.notice.ok{background:#e8f7ee;border:1px solid #bde5ca;color:#176b2c}.notice.err{background:#fff0f0;border:1px solid #f3b4b4;color:#9b1c1c}.quick-note{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#f8fbff;border:1px solid #dbe7f6;border-radius:12px;padding:12px 14px;margin-top:12px;color:#344054}.quick-note strong{font-size:15px}.routine-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.routine-box{border:1px solid #d9dee7;background:#fff;border-radius:10px;padding:12px}.routine-box b{display:block;font-size:13px;color:#667085}.routine-box strong{display:block;font-size:20px;margin-top:4px}.ready-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.ready-step{border:1px solid #d9dee7;border-radius:10px;background:#fbfcff;padding:12px}.ready-step strong{display:block;font-size:15px}.ready-step span{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.45}.ready-step.good{border-color:#b7e4c7;background:#f0fff4}.ready-step.warn{border-color:#ffd7a8;background:#fffaf0}.ready-step.off{border-color:#d9dee7;background:#f8fafc}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1340px}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.check-cell{width:42px}.student-name{font-weight:1000}.sub{display:block;color:#667085;font-size:12px;margin-top:3px}.sendable{display:block;margin-top:4px;color:#1769c2;font-size:12px;font-weight:900}.blocked{display:block;margin-top:4px;color:#9a5b00;font-size:12px;font-weight:900}.status{display:inline-flex;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900}.status.done{background:#e8f7ee;color:#176b2c}.status.wait{background:#fff3df;color:#8a5200}.status.ready{background:#eaf3ff;color:#175cd3}.status.warn{background:#fff0f0;color:#9b1c1c}.status.muted{background:#eef2f7;color:#475467}.score{font-size:18px;font-weight:1000;color:#1769c2}.empty{padding:28px;text-align:center;color:#667085}.progress{height:12px;background:#e7edf5;border-radius:999px;overflow:hidden;margin-top:10px}.progress i{display:block;height:100%;border-radius:999px;background:#1769c2}.report-actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.send-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.send-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.send-help{color:#667085;font-size:13px;font-weight:900}.approval-box{display:flex;align-items:flex-start;gap:9px;border:1px solid #f3d4a8;background:#fff8ef;border-radius:10px;padding:10px 12px;color:#7a4a00;font-weight:900}.approval-box input{margin-top:2px}.report-modal-opened{overflow:hidden}.report-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);opacity:0;pointer-events:none;transition:opacity .18s ease;z-index:80}.report-modal{position:fixed;inset:24px;max-width:1180px;margin:0 auto;background:#fff;border:1px solid #d9dee7;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.3);display:grid;grid-template-rows:auto 1fr;overflow:hidden;opacity:0;pointer-events:none;transform:translateY(18px) scale(.985);transition:opacity .2s ease,transform .2s ease;z-index:81}.report-modal-backdrop.open{opacity:1;pointer-events:auto}.report-modal.open{opacity:1;pointer-events:auto;transform:translateY(0) scale(1)}.report-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #e4eaf2;background:#f8fbff}.report-modal-title{font-size:18px;font-weight:1000}.report-modal-title span{display:block;margin-top:3px;color:#667085;font-size:13px;font-weight:800}.report-modal-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.report-modal-actions .btn{min-height:36px;padding:7px 10px}.report-modal-close{border:1px solid #cfd6df;background:#fff;border-radius:10px;width:42px;height:42px;font-size:20px;font-weight:1000;cursor:pointer}.report-modal-frame{width:100%;height:100%;border:0;background:#eef2f7}@media(max-width:1400px){.summary{grid-template-columns:repeat(3,minmax(0,1fr))}.routine-grid,.ready-guide{grid-template-columns:1fr}}@media(max-width:900px){.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.wrap{padding:0 12px;margin:16px auto}.panel{padding:14px}.quick-note{align-items:flex-start;flex-direction:column}}@media(max-width:720px){.report-modal{inset:8px;border-radius:12px}.report-modal-head{padding:11px 12px}.report-modal-title{font-size:16px}.report-modal-actions{gap:6px}}@media(max-width:560px){.summary{grid-template-columns:1fr}.filters{display:grid}.filters>*{width:100%}.send-bar{display:grid}.send-tools{display:grid}.send-tools>*{width:100%}}
body.fitness-reports-page-tune .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:96px 40px 42px!important}
body.fitness-reports-page-tune .hero{align-items:center;margin-bottom:14px}
body.fitness-reports-page-tune .hero h1{font-size:30px;letter-spacing:-.01em}
body.fitness-reports-page-tune .panel,body.fitness-reports-page-tune .quick-note,body.fitness-reports-page-tune .ready-step,body.fitness-reports-page-tune .summary-card{border-radius:8px;box-shadow:none}
body.fitness-reports-page-tune .panel{margin-top:14px}
body.fitness-reports-page-tune .summary{grid-template-columns:repeat(4,minmax(0,1fr));margin-top:14px;gap:10px}
body.fitness-reports-page-tune .summary-card:nth-child(1),body.fitness-reports-page-tune .summary-card:nth-child(6){display:none}
body.fitness-reports-page-tune .summary-card{min-height:94px;padding:14px 16px}
body.fitness-reports-page-tune .summary-card span{font-size:13px}
body.fitness-reports-page-tune .summary-card strong{font-size:28px}
body.fitness-reports-page-tune .quick-note{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,auto);align-items:start;background:#fff;margin-top:14px;padding:14px 16px}
body.fitness-reports-page-tune .quick-note>.report-actions{min-width:0;align-content:flex-start;justify-content:flex-end}
body.fitness-reports-page-tune .routine-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px;max-width:540px}
body.fitness-reports-page-tune .routine-box{padding:10px}
body.fitness-reports-page-tune .routine-box strong{font-size:18px}
body.fitness-reports-page-tune .ready-guide{display:none}
body.fitness-reports-page-tune .send-help{font-size:14px;color:#344054}
body.fitness-reports-page-tune .table-wrap{padding:0;overflow-x:auto}
body.fitness-reports-page-tune .send-bar{padding:12px 14px;margin:0;border-bottom:1px solid #e4eaf2;background:#fbfcff}
body.fitness-reports-page-tune .table-wrap form{display:block}
body.fitness-reports-page-tune .table-wrap table{margin:0}
body.fitness-reports-page-tune .table-wrap table{min-width:1260px}
body.fitness-reports-page-tune th,body.fitness-reports-page-tune td{padding:7px 8px;font-size:13px}
body.fitness-reports-page-tune .sub{font-size:11px;line-height:1.35}
body.fitness-reports-page-tune .sendable,
body.fitness-reports-page-tune .blocked{font-size:11px}
body.fitness-reports-page-tune .status{padding:4px 8px}
body.fitness-reports-page-tune .score{font-size:16px}
body.fitness-reports-page-tune .report-actions .btn{min-height:32px;padding:6px 10px}
@media(max-width:1400px){body.fitness-reports-page-tune .quick-note{grid-template-columns:1fr}body.fitness-reports-page-tune .quick-note>.report-actions{justify-content:flex-start}body.fitness-reports-page-tune .routine-grid{grid-template-columns:repeat(3,minmax(0,1fr));max-width:none}}
@media(max-width:1180px){body.fitness-reports-page-tune .summary{grid-template-columns:repeat(2,minmax(0,1fr))}body.fitness-reports-page-tune .quick-note{grid-template-columns:1fr}body.fitness-reports-page-tune .quick-note>.report-actions{justify-content:flex-start}body.fitness-reports-page-tune .routine-grid{grid-template-columns:1fr}}
body.ieum-side-layout.ieum-dashboard-page.fitness-reports-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.fitness-reports-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.fitness-reports-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
.fitness-reports-page-tune .side-brand-mark,
.fitness-reports-page-tune .side-profile,
.fitness-reports-page-tune .side-search,
.fitness-reports-page-tune .ieum-right-rail{display:none!important}
.fitness-reports-page-tune .side-nav{padding:0 14px 22px!important}
.fitness-reports-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.fitness-reports-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.fitness-reports-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.fitness-reports-page-tune .ieum-shell-link,
.fitness-reports-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.fitness-reports-page-tune .ieum-shell-link::before{display:none!important}
.fitness-reports-page-tune .ieum-shell-meta{color:#0f172a!important}
.fitness-reports-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fitness-reports-page-tune .dashboard-shell-divider,
.fitness-reports-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.fitness-reports-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.fitness-reports-page-tune{--ieum-side-width:0px}
    .fitness-reports-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.fitness-reports-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
<link rel="stylesheet" href="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.css?v=20260527b">
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page fitness-reports-page-tune">
<?php echo ieum_admin_header('fitness_reports', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>체력 리포트 일괄 발송</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?> · 측정 주기와 발송 상태를 기준으로 학부모용 리포트 대상을 자동 추출합니다.</div>
        </div>
        <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/fitness.php?month=<?php echo rawurlencode($month); ?>&amp;program_code=<?php echo rawurlencode($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>">체력 입력</a>
    </section>
    <?php if ($message !== '') { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>

    <section class="panel">
        <form class="filters" method="get">
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <select name="program_code">
                <option value="">전체 프로그램</option>
                <?php foreach ($program_options as $program) { ?>
                <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                <?php } ?>
            </select>
            <select name="class_time_id">
                <option value="0">전체 부</option>
                <?php foreach ($class_options as $class) { ?>
                <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></option>
                <?php } ?>
            </select>
            <select name="report_status">
                <option value="" <?php echo get_selected($report_status, ''); ?>>전체 상태</option>
                <option value="cycle_due" <?php echo get_selected($report_status, 'cycle_due'); ?>>이번 주기 대상</option>
                <option value="send_ready" <?php echo get_selected($report_status, 'send_ready'); ?>>발송 준비</option>
                <option value="done" <?php echo get_selected($report_status, 'done'); ?>>측정 완료</option>
                <option value="wait" <?php echo get_selected($report_status, 'wait'); ?>>측정 대기</option>
                <option value="sent" <?php echo get_selected($report_status, 'sent'); ?>>이미 생성/발송</option>
                <option value="not_due" <?php echo get_selected($report_status, 'not_due'); ?>>주기 제외</option>
            </select>
            <button type="submit" class="btn primary">조회</button>
        </form>
    </section>

    <section class="summary">
        <a class="summary-card <?php echo $report_status === '' ? 'active' : ''; ?>" href="<?php echo get_text($report_all_url); ?>"><span>조회 원생</span><strong><?php echo number_format(count($students)); ?>명</strong></a>
        <a class="summary-card ready <?php echo $report_status === 'cycle_due' ? 'active' : ''; ?>" href="<?php echo get_text($report_cycle_url); ?>"><span>이번 주기 대상</span><strong><?php echo number_format($cycle_due_count); ?>명</strong></a>
        <a class="summary-card good <?php echo $report_status === 'send_ready' ? 'active' : ''; ?>" href="<?php echo get_text($report_send_ready_url); ?>"><span>발송 준비</span><strong><?php echo number_format($send_ready_count); ?>명</strong><div class="progress"><i style="width:<?php echo $ready_rate; ?>%"></i></div></a>
        <a class="summary-card warn <?php echo $report_status === 'wait' ? 'active' : ''; ?>" href="<?php echo get_text($report_wait_url); ?>"><span>측정 대기</span><strong><?php echo number_format($cycle_wait_count); ?>명</strong></a>
        <a class="summary-card done <?php echo $report_status === 'sent' ? 'active' : ''; ?>" href="<?php echo get_text($report_sent_url); ?>"><span>이미 생성/발송</span><strong><?php echo number_format($sent_or_queued_count); ?>명</strong></a>
        <article class="summary-card"><span>평균 체력 환산</span><strong><?php echo number_format($avg_total, 1); ?>점</strong></article>
    </section>

    <section class="quick-note">
        <div>
            <strong>운영 기준</strong>
            <div class="meta">측정 주기 <?php echo get_text($cycle_label); ?> · 발송 기준일 매월 <?php echo (int) $send_day; ?>일 · 자동 발송 <?php echo !empty($fitness_settings['report_send_enabled']) ? '사용' : '미사용'; ?></div>
        </div>
        <div class="report-actions">
            <a class="btn" href="<?php echo get_text($report_wait_url); ?>">측정 대기</a>
            <a class="btn" href="<?php echo get_text($report_send_ready_url); ?>">발송 준비</a>
            <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($print_ready_url); ?>">준비 인쇄</a>
            <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($print_completed_url); ?>">완료 인쇄</a>
        </div>
    </section>

    <section class="ready-guide">
        <article class="ready-step good">
            <strong>1. 측정 완료</strong>
            <span>이번 주기 대상 중 키/몸무게와 체력 수치가 입력된 원생을 먼저 확인합니다.</span>
        </article>
        <article class="ready-step warn">
            <strong>2. 발송 준비</strong>
            <span>측정값과 대표 보호자 연락처가 모두 있으면 문자 발송과 일괄 인쇄 대상이 됩니다.</span>
        </article>
        <article class="ready-step off">
            <strong>3. 최종 확인</strong>
            <span>관리자가 발송 확인 팝업에서 승인해야 문자가 발송 준비됩니다. 인쇄는 준비된 원생만 모아 출력합니다.</span>
        </article>
    </section>

    <section class="panel table-wrap">
        <form method="post" class="send-confirm-form" data-send-title="체력 리포트 문자 발송 확인" data-send-message="선택한 원생의 학부모용 체력 리포트 링크 문자를 발송 준비합니다." data-send-summary="info:이번 주기 대상 <?php echo number_format($cycle_due_count); ?>명|ok:발송 준비 <?php echo number_format($send_ready_count); ?>명|warn:측정 대기 <?php echo number_format($cycle_wait_count); ?>명|muted:이미 준비/발송 <?php echo number_format($sent_or_queued_count); ?>명" data-message-editable="1" data-message-template="<?php echo get_text($fitness_sms_template); ?>" data-message-preview="<?php echo get_text($fitness_sms_preview); ?>" data-message-values="<?php echo $fitness_sms_values_json; ?>" data-message-tokens="사용 가능: {도장명}, {원생명}, {리포트월}, {리포트주소}">
        <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
        <input type="hidden" name="action" value="send_fitness_report_links">
        <input type="hidden" name="final_approved" value="0">
        <div class="send-bar">
            <div class="send-tools">
                <span class="send-help">발송 준비 화면에서는 대상 원생이 자동 선택됩니다.</span>
                <a class="btn" href="<?php echo get_text($report_send_ready_url); ?>">발송 준비 원생만 보기</a>
                <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($print_ready_url); ?>">준비된 원생 전체 인쇄</a>
            </div>
            <button type="submit" class="btn primary js-fitness-send-button" <?php echo ($report_status === 'send_ready' && $send_ready_count) ? '' : 'disabled'; ?>>선택 문자발송</button>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="check-cell">선택</th>
                    <th>원생</th>
                    <th>부</th>
                    <th>측정 주기</th>
                    <th>발송 상태</th>
                    <th>BMI</th>
                    <th>체력 환산</th>
                    <th>강점</th>
                    <th>성장 포인트</th>
                    <th>리포트</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $entry) {
                $student = $entry['student'];
                $sid = (int) $student['student_id'];
                $calc = $entry['calc'];
                $best = $entry['best_key'] !== '' ? $items[$entry['best_key']] : null;
                $watch = $entry['watch_key'] !== '' ? $items[$entry['watch_key']] : null;
                $class_label = trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: ''));
                $send_state = $entry['send_state'];
            ?>
                <tr>
                    <td class="check-cell">
                        <input type="checkbox" class="fitness-report-check" name="student_ids[]" value="<?php echo $sid; ?>" data-ready="<?php echo $entry['send_ready'] ? '1' : '0'; ?>" <?php echo $entry['send_ready'] ? '' : 'disabled'; ?> <?php echo $report_status === 'send_ready' && $entry['send_ready'] ? 'checked' : ''; ?>>
                    </td>
                    <td class="left">
                        <span class="student-name"><?php echo get_text($student['student_name']); ?></span>
                        <?php if ($calc) { ?><span class="sub"><?php echo get_text(ieum_fitness_reports_display_label($calc['standard_context'])); ?></span><?php } ?>
                        <span class="sub"><?php echo get_text($student['student_code'] . ' · ' . ieum_fitness_reports_grade_label($student['grade_group'])); ?></span>
                        <span class="<?php echo $entry['primary_phone_count'] ? 'sendable' : 'blocked'; ?>"><?php echo $entry['primary_phone_count'] ? '대표 보호자 ' . number_format($entry['primary_phone_count']) . '명' : '대표 보호자 연락처 필요'; ?></span>
                    </td>
                    <td><?php echo get_text($class_label); ?></td>
                    <td><span class="status <?php echo $entry['cycle_due'] ? 'ready' : 'muted'; ?>"><?php echo get_text($entry['cycle_label']); ?></span><span class="sub"><?php echo get_text($entry['cycle_reason']); ?></span></td>
                    <td><span class="status <?php echo get_text($send_state['class']); ?>"><?php echo get_text($send_state['label']); ?></span><span class="sub"><?php echo get_text($send_state['detail']); ?></span></td>
                    <td><?php echo $calc && $calc['bmi'] !== null ? number_format((float) $calc['bmi'], 1) . '<span class="sub">' . get_text($calc['bmi_label']) . '</span><span class="sub">' . get_text(ieum_fitness_reports_display_label($calc['bmi_source'])) . '</span>' : '-'; ?></td>
                    <td><?php echo $calc ? '<span class="score">' . number_format((float) $calc['total'], 1) . '</span><span class="sub">' . get_text(ieum_fitness_reports_total_label($calc['total'])) . '</span>' : '-'; ?></td>
                    <td><?php echo $best ? get_text($best['label']) : '-'; ?></td>
                    <td><?php echo $watch ? get_text($watch['label']) : '-'; ?></td>
                    <td>
                        <div class="report-actions">
                            <?php if ($entry['completed']) { ?>
                            <a class="btn fitness-report-modal-open" href="<?php echo IEUM_URL; ?>/admin/fitness_parent_report.php?month=<?php echo rawurlencode($month); ?>&amp;student_id=<?php echo $sid; ?>" data-student-name="<?php echo get_text($student['student_name']); ?>" data-student-code="<?php echo get_text($student['student_code']); ?>">보기</a>
                            <?php } ?>
                            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/fitness.php?month=<?php echo rawurlencode($month); ?>&amp;program_code=<?php echo rawurlencode($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>&amp;metric_key=all#student-<?php echo $sid; ?>">입력</a>
                        </div>
                    </td>
                </tr>
            <?php } ?>
            <?php if (!$rows) { ?><tr><td colspan="10" class="empty">조건에 맞는 원생이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
        </form>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.fitness-reports-page-tune.ieum-dashboard-page';
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
    const ensureReportModal = () => {
        let backdrop = document.getElementById('fitnessReportBackdrop');
        let modal = document.getElementById('fitnessReportModal');
        if (backdrop && modal) {
            return {backdrop, modal, frame: document.getElementById('fitnessReportFrame')};
        }

        backdrop = document.createElement('div');
        backdrop.className = 'report-modal-backdrop';
        backdrop.id = 'fitnessReportBackdrop';

        modal = document.createElement('section');
        modal.className = 'report-modal';
        modal.id = 'fitnessReportModal';
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('aria-label', '학부모용 체력 리포트 미리보기');
        modal.innerHTML = '<header class="report-modal-head"><div class="report-modal-title">체력 리포트 보기<span id="fitnessReportSubtitle">목록을 유지한 채 확인합니다.</span></div><div class="report-modal-actions"><a class="btn" id="fitnessReportOpenNew" target="_blank" rel="noopener">새 창</a><button type="button" class="btn" id="fitnessReportPrint">인쇄</button><button type="button" class="report-modal-close" id="fitnessReportClose" aria-label="리포트 닫기">×</button></div></header><iframe class="report-modal-frame" id="fitnessReportFrame" title="학부모용 체력 리포트"></iframe>';

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        backdrop.addEventListener('click', closeReportModal);
        modal.querySelector('#fitnessReportClose').addEventListener('click', closeReportModal);
        modal.querySelector('#fitnessReportPrint').addEventListener('click', () => {
            const frame = document.getElementById('fitnessReportFrame');
            if (frame && frame.contentWindow) {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            }
        });
        return {backdrop, modal, frame: modal.querySelector('#fitnessReportFrame')};
    };

    const openReportModal = (url, studentName, studentCode) => {
        const {backdrop, modal, frame} = ensureReportModal();
        const subtitle = modal.querySelector('#fitnessReportSubtitle');
        const openNew = modal.querySelector('#fitnessReportOpenNew');
        if (subtitle) {
            subtitle.textContent = [studentName, studentCode].filter(Boolean).join(' · ') || '목록을 유지한 채 확인합니다.';
        }
        if (openNew) {
            openNew.href = url;
        }
        frame.src = url;
        backdrop.classList.add('open');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('report-modal-opened');
    };

    function closeReportModal() {
        const backdrop = document.getElementById('fitnessReportBackdrop');
        const modal = document.getElementById('fitnessReportModal');
        const frame = document.getElementById('fitnessReportFrame');
        if (backdrop) backdrop.classList.remove('open');
        if (modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }
        if (frame) frame.removeAttribute('src');
        document.body.classList.remove('report-modal-opened');
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('.fitness-report-modal-open');
        if (!link) return;
        event.preventDefault();
        openReportModal(link.href, link.dataset.studentName || '', link.dataset.studentCode || '');
    });
    const updateFitnessSendButton = () => {
        const button = document.querySelector('.js-fitness-send-button');
        if (!button) return;
        button.disabled = document.querySelectorAll('.fitness-report-check:checked').length === 0;
    };
    document.addEventListener('change', (event) => {
        if (event.target.closest('.fitness-report-check')) {
            updateFitnessSendButton();
        }
    });
    updateFitnessSendButton();
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeReportModal();
    });
})();
</script>
<script src="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.js?v=20260530d"></script>
</body>
</html>

