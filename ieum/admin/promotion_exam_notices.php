<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

$g5['title'] = '아이이음 심사 안내';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_exam_notice_grade_label($value)
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

function ieum_exam_notice_target_rows($academy, $academy_id, $month, $exam_type, $program_code, $class_time_id)
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

function ieum_exam_notice_log_exists($academy_id, $student_id, $month, $exam_type)
{
    if (!defined('IEUM_PROMOTION_NOTICE_LOG_TABLE')) {
        return false;
    }
    $row = sql_fetch("
        select notice_id, sent_at
          from " . IEUM_PROMOTION_NOTICE_LOG_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and student_id = '" . (int) $student_id . "'
           and notice_month = '" . sql_escape_string($month) . "'
           and exam_type = '" . sql_escape_string($exam_type) . "'
      order by notice_id desc
         limit 1
    ", false);
    return !empty($row['notice_id']) ? $row : false;
}

$message = '';
$error = '';
$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$exam_type = isset($_GET['exam_type']) && $_GET['exam_type'] === 'poomdan' ? 'poomdan' : 'promotion';
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'save_templates') {
            $promotion_notice_template = isset($_POST['promotion_notice_template']) ? trim((string) $_POST['promotion_notice_template']) : '';
            $poomdan_notice_template = isset($_POST['promotion_poomdan_notice_template']) ? trim((string) $_POST['promotion_poomdan_notice_template']) : '';
            sql_query("
                update " . IEUM_ACADEMY_TABLE . "
                   set promotion_notice_template = '" . sql_escape_string($promotion_notice_template) . "',
                       promotion_poomdan_notice_template = '" . sql_escape_string($poomdan_notice_template) . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
            ");
            $message = '심사 안내문 문구를 저장했습니다.';
        } elseif ($action === 'save_fees') {
            $fee_rows = isset($_POST['fees']) && is_array($_POST['fees']) ? $_POST['fees'] : array();
            $saved = ieum_promotion_save_fee_rows($academy_id, $fee_rows);
            $message = '심사비 ' . number_format($saved) . '건을 저장했습니다.';
        } elseif ($action === 'send_selected') {
            $post_month = isset($_POST['month']) ? preg_replace('/[^0-9-]/', '', trim($_POST['month'])) : $month;
            if (preg_match('/^\d{4}-\d{2}$/', $post_month)) {
                $month = $post_month;
            }
            $post_exam_type = isset($_POST['exam_type']) && $_POST['exam_type'] === 'poomdan' ? 'poomdan' : 'promotion';
            $student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : array();
            $force_resend = !empty($_POST['force_resend']);
            $target_rows = ieum_exam_notice_target_rows($academy, $academy_id, $month, $post_exam_type, $program_code, $class_time_id);
            $target_map = array();
            foreach ($target_rows as $row) {
                $target_map[(int) $row['student_id']] = $row;
            }
            $sent = 0;
            $skipped = 0;
            $no_recipient = 0;
            foreach ($student_ids as $student_id) {
                if (!isset($target_map[$student_id])) {
                    $skipped++;
                    continue;
                }
                $row = $target_map[$student_id];
                $status = $row['_promotion_status'];
                if (!$force_resend && ieum_exam_notice_log_exists($academy_id, $student_id, $month, $post_exam_type)) {
                    $skipped++;
                    continue;
                }
                $fee = ieum_promotion_get_fee($academy_id, $post_exam_type, $status['next_rank']);
                $fee_amount = $fee ? (int) $fee['fee_amount'] : 0;
                $result = ieum_promotion_create_notice_queue($academy, $row, $status, $fee_amount, isset($member['mb_id']) ? $member['mb_id'] : '', $month);
                if (!$result['recipients']) {
                    $no_recipient++;
                    continue;
                }
                if ($result['queue_ids']) {
                    $sent += count($result['queue_ids']);
                }
            }
            $message = '심사 안내 문자 ' . number_format($sent) . '건을 발송 준비했습니다.';
            if ($skipped || $no_recipient) {
                $message .= ' 중복/대상아님 ' . number_format($skipped) . '명, 연락처 없음 ' . number_format($no_recipient) . '명은 제외했습니다.';
            }
        }
    }
    $academy = sql_fetch("select * from " . IEUM_ACADEMY_TABLE . " where academy_id = '{$academy_id}'", false);
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
$target_rows = ieum_exam_notice_target_rows($academy, $academy_id, $month, $exam_type, $program_code, $class_time_id);
$sent_count = 0;
$missing_fee_count = 0;
foreach ($target_rows as $row) {
    if (ieum_exam_notice_log_exists($academy_id, (int) $row['student_id'], $month, $exam_type)) {
        $sent_count++;
    }
    $row_status = isset($row['_promotion_status']) ? $row['_promotion_status'] : array();
    if (!empty($row_status['next_rank']) && !ieum_promotion_get_fee($academy_id, $exam_type, $row_status['next_rank'])) {
        $missing_fee_count++;
    }
}
$payment_account = ieum_promotion_payment_account($academy, false);
$missing_account = $payment_account === '';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#101828;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:18px}.head h1{margin:0;font-size:32px}.meta{margin-top:8px;color:#667085}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-bottom:18px}.notice{padding:12px;border-radius:10px;margin:0 0 14px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.filters input,.filters select,input,textarea{border:1px solid #cfd6df;border-radius:9px;padding:10px 12px;font-size:14px;background:#fff}textarea{width:100%;min-height:116px;line-height:1.55;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #cfd6df;border-radius:9px;background:#fff;color:#101828;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.dark{background:#101828;border-color:#101828;color:#fff}.btn.soft{background:#eef4ff;border-color:#c7d7fe;color:#174a8b}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.summary-card{border:1px solid #d9e2ef;border-radius:14px;background:linear-gradient(135deg,#fff,#f8fbff);padding:16px}.summary-card.warn{border-color:#f4c27a;background:#fffaf0}.summary-card span{display:block;color:#667085;font-size:13px;font-weight:800}.summary-card strong{display:block;margin-top:6px;font-size:30px}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.tab{border-radius:999px;padding:9px 14px;border:1px solid #d9dee7;background:#fff;text-decoration:none;color:#344054;font-weight:900}.tab.active{background:#1769c2;color:#fff;border-color:#1769c2}.two{display:grid;grid-template-columns:1fr 1fr;gap:18px}.template-title{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px}.template-title h2{margin:0;font-size:20px}.help{color:#667085;font-size:13px;line-height:1.55}.fee-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-height:480px;overflow:auto;padding-right:4px}.fee-row{border:1px solid #e3e8f1;border-radius:12px;background:#f8fafc;padding:10px}.fee-row strong{display:block;margin-bottom:7px}.fee-row .line{display:grid;grid-template-columns:1fr 1fr;gap:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.muted{color:#667085}.state{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;background:#eef6ff;color:#1769c2}.state.sent{background:#eaf8ef;color:#176b2c}.state.overdue{background:#fff1f2;color:#be123c}.empty{padding:36px;text-align:center;border:1px dashed #cfd6df;border-radius:14px;color:#667085}.send-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}.send-options{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.send-options input{width:auto}.preview-box{white-space:pre-wrap;border:1px solid #d9e2ef;border-radius:12px;background:#f8fbff;padding:12px;line-height:1.55;font-size:13px}@media(max-width:1100px){.two{grid-template-columns:1fr}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.fee-grid{grid-template-columns:1fr}}@media(max-width:760px){.wrap{padding:0 12px}.summary{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}.head{align-items:flex-start;flex-direction:column}}
</style>
<style>
.check-note{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}.check-chip{display:inline-flex;align-items:center;border-radius:999px;background:#eef4ff;color:#174a8b;padding:7px 11px;font-weight:900;font-size:13px}.check-chip.warn{background:#fff7ed;color:#9a3412}.check-chip.danger{background:#fff1f2;color:#be123c}.state.missing{background:#fff7ed;color:#9a3412}.send-tip{color:#667085;font-size:12px;line-height:1.5}
.two{grid-template-columns:1fr}
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune{--ieum-side-width:260px;--ieum-top-height:64px;--ieum-rail-width:0px;--ieum-shell-top:#fff;background:#f5f7fb!important;color:#111827!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .ieum-right-rail{display:none!important}
.promotion-exam-notices-page-tune .side-nav{padding:0 14px 22px!important}.promotion-exam-notices-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}.promotion-exam-notices-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}.promotion-exam-notices-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}.promotion-exam-notices-page-tune .ieum-shell-link,.promotion-exam-notices-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}.promotion-exam-notices-page-tune .ieum-shell-link::before{display:none!important}.promotion-exam-notices-page-tune .ieum-shell-meta{color:#0f172a!important}.promotion-exam-notices-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.promotion-exam-notices-page-tune .dashboard-shell-divider,.promotion-exam-notices-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
.promotion-exam-notices-page-tune table td{vertical-align:top}
.promotion-exam-notices-page-tune .preview-box{max-height:58px;overflow:hidden;white-space:pre-line;line-height:1.42}
.promotion-exam-notices-page-tune .send-bar{align-items:flex-start}
.promotion-exam-notices-page-tune .send-options{justify-content:flex-end}
.promotion-exam-notices-page-tune .exam-target-table-wrap{border:1px solid #d8dee9;border-radius:12px;overflow:auto}
.promotion-exam-notices-page-tune .exam-target-table-wrap table{min-width:1280px;border:0}
.promotion-exam-notices-page-tune .exam-target-table-wrap th,.promotion-exam-notices-page-tune .exam-target-table-wrap td{white-space:nowrap}
.promotion-exam-notices-page-tune .exam-target-table-wrap td.left{white-space:normal}
.promotion-exam-notices-page-tune .exam-target-table-wrap .preview-box{width:380px}
.promotion-exam-notices-page-tune .exam-target-table-wrap th:first-child,.promotion-exam-notices-page-tune .exam-target-table-wrap td:first-child{border-left:0}
.promotion-exam-notices-page-tune .exam-target-table-wrap th:last-child,.promotion-exam-notices-page-tune .exam-target-table-wrap td:last-child{border-right:0}
.promotion-exam-notices-page-tune .exam-target-table-wrap thead tr:first-child th{border-top:0}
.promotion-exam-notices-page-tune .exam-target-table-wrap tbody tr:last-child td{border-bottom:0}
@media(max-width:980px){body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune{--ieum-side-width:0px}.promotion-exam-notices-page-tune .ieum-shell-top{left:0!important}body.ieum-side-layout.ieum-dashboard-page.promotion-exam-notices-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page promotion-exam-notices-page-tune">
<?php echo ieum_admin_header('promotion_exam_notices', 'side'); ?>
<main class="wrap">
    <div class="head">
        <div>
            <h1>심사 안내 자동화</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 승급/승품단 안내문, 심사비, 문자 발송을 한 화면에서 관리합니다.</div>
        </div>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php">문자 발송 기록</a>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <nav class="tabs">
        <a class="tab <?php echo $exam_type === 'promotion' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => 'promotion', 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>">도장 승급 안내</a>
        <a class="tab <?php echo $exam_type === 'poomdan' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => 'poomdan', 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>">협회 승품/단 안내</a>
    </nav>

    <form class="panel filters" method="get">
        <input type="month" name="month" value="<?php echo get_text($month); ?>">
        <input type="hidden" name="exam_type" value="<?php echo get_text($exam_type); ?>">
        <select name="program_code">
            <option value="">전체 프로그램</option>
            <?php foreach ($programs as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id">
            <option value="0">전체 부</option>
            <?php foreach ($classes as $class) { $class_label = trim($class['class_name'] . ' ' . $class['start_time']); ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text($class_label); ?></option>
            <?php } ?>
        </select>
        <button class="btn primary" type="submit">조회</button>
    </form>

    <section class="summary panel">
        <article class="summary-card warn"><span>안내 대상</span><strong><?php echo number_format(count($target_rows)); ?>명</strong></article>
        <article class="summary-card"><span>발송 기록 있음</span><strong><?php echo number_format($sent_count); ?>명</strong></article>
        <article class="summary-card"><span>미발송</span><strong><?php echo number_format(max(0, count($target_rows) - $sent_count)); ?>명</strong></article>
        <article class="summary-card"><span>구분</span><strong><?php echo get_text(ieum_promotion_exam_type_label($exam_type)); ?></strong></article>
    </section>

    <section class="two">
        <form method="post" class="panel">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_templates">
            <div class="template-title">
                <h2>안내문 문구</h2>
                <button class="btn primary" type="submit">문구 저장</button>
            </div>
            <p class="help">사용 가능 변수: {academy_name}, {student_name}, {student_code}, {exam_name}, {current_rank}, {target_rank}, {post_pass_rank}, {exam_date}, {fee_amount}, {payment_account}</p>
            <label><strong>도장 승급 안내문</strong></label>
            <textarea name="promotion_notice_template"><?php echo get_text(ieum_promotion_notice_template($academy, 'promotion')); ?></textarea>
            <label><strong>협회 승품/단 안내문</strong></label>
            <textarea name="promotion_poomdan_notice_template"><?php echo get_text(ieum_promotion_notice_template($academy, 'poomdan')); ?></textarea>
        </form>

    </section>

    <form method="post" class="panel">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="send_selected">
        <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
        <input type="hidden" name="exam_type" value="<?php echo get_text($exam_type); ?>">
        <div class="send-bar">
            <div>
                <h2 style="margin:0 0 6px">안내 대상자</h2>
                <p class="help" style="margin:0">선택한 원생의 대표 수련비 문자 수신 보호자에게 안내 문자를 발송 준비합니다.</p>
            </div>
            <div class="send-options">
                <label><input type="checkbox" name="force_resend" value="1"> 이미 발송한 원생도 재발송</label>
                <button class="btn dark" type="submit" onclick="return confirmExamNoticeSend(this.form);">선택 문자 발송</button>
                <button class="btn primary" type="button" onclick="return printSelectedExamNotices();">선택 인쇄</button>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_exam_notices_print.php?<?php echo http_build_query(array('month' => $month, 'exam_type' => $exam_type, 'program_code' => $program_code, 'class_time_id' => $class_time_id)); ?>" target="_blank">전체 인쇄</a>
            </div>
        </div>
        <div class="check-note">
            <span class="check-chip <?php echo $missing_account ? 'danger' : ''; ?>">계좌 <?php echo $missing_account ? '미입력' : '입력 완료'; ?></span>
            <span class="check-chip <?php echo $missing_fee_count ? 'warn' : ''; ?>">심사비 미설정 <?php echo number_format($missing_fee_count); ?>명</span>
            <span class="check-chip">이미 발송 <?php echo number_format($sent_count); ?>명</span>
        </div>
        <p class="send-tip">심사비와 입금 계좌는 학원 설정 &gt; 승급 설정에서 관리합니다. 여기서는 안내문 확인, 선택 인쇄, 문자 발송 준비만 진행합니다.</p>
        <?php if (!$target_rows) { ?>
            <div class="empty">이번 조건에 해당하는 심사 안내 대상자가 없습니다.</div>
        <?php } else { ?>
        <div class="exam-target-table-wrap">
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" onclick="document.querySelectorAll('.student-check').forEach((el)=>el.checked=this.checked)"></th>
                    <th>원생</th>
                    <th>부</th>
                    <th>현재</th>
                    <th>심사</th>
                    <th>심사비</th>
                    <th>상태</th>
                    <th>미리보기</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($target_rows as $row) {
                    $status = $row['_promotion_status'];
                    $next_rank = $status['next_rank'];
                    $fee = ieum_promotion_get_fee($academy_id, $exam_type, $next_rank);
                    $fee_amount = $fee ? (int) $fee['fee_amount'] : 0;
                    $log = ieum_exam_notice_log_exists($academy_id, (int) $row['student_id'], $month, $exam_type);
                    $class_label = trim((string) $row['class_name'] . ' ' . (string) $row['start_time']);
                    $target_label = isset($next_rank['exam_label']) ? $next_rank['exam_label'] : ieum_promotion_rank_display($academy, $next_rank);
                    $preview = ieum_promotion_render_notice_message($academy, $row, $status, $fee_amount);
                ?>
                <tr>
                    <td><input type="checkbox" class="student-check" name="student_ids[]" value="<?php echo (int) $row['student_id']; ?>" data-sent="<?php echo $log ? '1' : '0'; ?>" data-missing-fee="<?php echo $fee_amount > 0 ? '0' : '1'; ?>"></td>
                    <td class="left"><strong><?php echo get_text($row['student_name']); ?></strong> <span class="muted"><?php echo get_text($row['student_code']); ?></span><br><span class="muted"><?php echo get_text(ieum_exam_notice_grade_label($row['grade_group'])); ?></span></td>
                    <td><?php echo get_text($class_label !== '' ? $class_label : '부 미지정'); ?></td>
                    <td><?php echo get_text(ieum_promotion_student_current_rank_display($row)); ?></td>
                    <td><strong><?php echo get_text($target_label); ?></strong><br><span class="muted"><?php echo get_text($status['next_date']); ?></span></td>
                    <td><?php echo $fee_amount > 0 ? number_format($fee_amount) . '원' : '<span class="state missing">미설정</span>'; ?></td>
                    <td><?php if ($log) { ?><span class="state sent">발송됨</span><br><span class="muted"><?php echo get_text($log['sent_at']); ?></span><?php } elseif (!empty($status['overdue'])) { ?><span class="state overdue">기간 지남</span><?php } else { ?><span class="state">대기</span><?php } ?></td>
                    <td class="left"><div class="preview-box"><?php echo get_text($preview); ?></div></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        </div>
        <?php } ?>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.promotion-exam-notices-page-tune.ieum-dashboard-page';
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
<script>
function printSelectedExamNotices() {
    var checked = Array.prototype.slice.call(document.querySelectorAll('.student-check:checked'));
    if (!checked.length) {
        alert('인쇄할 원생을 선택해 주세요.');
        return false;
    }
    var url = <?php echo json_encode(IEUM_URL . '/admin/promotion_exam_notices_print.php?' . http_build_query(array('month' => $month, 'exam_type' => $exam_type, 'program_code' => $program_code, 'class_time_id' => $class_time_id)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    checked.forEach(function (el) {
        url += '&student_ids[]=' + encodeURIComponent(el.value);
    });
    window.open(url, '_blank');
    return false;
}
function confirmExamNoticeSend(form) {
    var checked = Array.prototype.slice.call(document.querySelectorAll('.student-check:checked'));
    if (!checked.length) {
        alert('문자를 발송할 원생을 선택해 주세요.');
        return false;
    }
    var sent = checked.filter(function (el) { return el.getAttribute('data-sent') === '1'; }).length;
    var missingFee = checked.filter(function (el) { return el.getAttribute('data-missing-fee') === '1'; }).length;
    var force = form && form.querySelector('[name="force_resend"]') && form.querySelector('[name="force_resend"]').checked;
    var lines = [
        '선택한 ' + checked.length + '명에게 심사 안내 문자를 발송 준비할까요?'
    ];
    if (sent > 0 && !force) {
        lines.push('이미 발송한 ' + sent + '명은 제외됩니다. 다시 보내려면 “이미 발송한 원생도 재발송”을 체크하세요.');
    } else if (sent > 0 && force) {
        lines.push('이미 발송한 ' + sent + '명도 재발송 대상에 포함됩니다.');
    }
    if (missingFee > 0) {
        lines.push('심사비가 미설정된 ' + missingFee + '명은 0원/미설정 안내가 나갈 수 있습니다.');
    }
    <?php if ($missing_account) { ?>
    lines.push('입금 계좌가 아직 없습니다. 안내문에는 “별도 안내”로 표시됩니다.');
    <?php } ?>
    return confirm(lines.join('\n'));
}
</script>
</body>
</html>
