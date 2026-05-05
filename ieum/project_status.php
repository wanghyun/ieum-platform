<?php
require_once './_common.php';

$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$expected = defined('IEUM_PROJECT_STATUS_TOKEN') ? IEUM_PROJECT_STATUS_TOKEN : '';
if ($expected === '' || !hash_equals($expected, $token)) {
    header('HTTP/1.1 403 Forbidden');
    echo '접근 토큰이 올바르지 않습니다.';
    exit;
}

function ieum_project_count($sql)
{
    $row = sql_fetch($sql, false);
    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_project_status_label($status)
{
    $labels = array(
        'requested' => '지시됨',
        'working' => '작업 중',
        'review' => '확인 필요',
        'done' => '완료',
        'hold' => '보류',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}

function ieum_project_status_class($status)
{
    if ($status === 'done') {
        return 'done';
    }
    if ($status === 'working') {
        return 'working';
    }
    if ($status === 'review') {
        return 'review';
    }
    if ($status === 'hold') {
        return 'hold';
    }

    return 'requested';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'add_task') {
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $instruction = isset($_POST['instruction']) ? trim($_POST['instruction']) : '';
        $priority = isset($_POST['priority']) ? (int) $_POST['priority'] : 3;
        if ($priority < 1) {
            $priority = 1;
        } elseif ($priority > 5) {
            $priority = 5;
        }

        if ($title === '') {
            $error = '작업 제목을 입력하세요.';
        } elseif ($instruction === '') {
            $error = 'Codex에게 줄 작업 지시를 입력하세요.';
        } else {
            sql_query("
                insert into " . IEUM_PROJECT_TASK_TABLE . "
                    set title = '" . sql_escape_string($title) . "',
                        instruction = '" . sql_escape_string($instruction) . "',
                        status = 'requested',
                        priority = '{$priority}',
                        created_by = 'planner',
                        created_at = '" . G5_TIME_YMDHIS . "'
            ");
            $message = '새 작업 지시가 등록되었습니다.';
        }
    } elseif ($action === 'update_task') {
        $task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
        $status = isset($_POST['status']) ? preg_replace('/[^a-z_]/', '', trim($_POST['status'])) : 'requested';
        $result_summary = isset($_POST['result_summary']) ? trim($_POST['result_summary']) : '';
        $result_detail = isset($_POST['result_detail']) ? trim($_POST['result_detail']) : '';
        if (!in_array($status, array('requested', 'working', 'review', 'done', 'hold'), true)) {
            $status = 'requested';
        }
        if ($task_id) {
            $completed_sql = $status === 'done' ? ", completed_at = '" . G5_TIME_YMDHIS . "'" : '';
            sql_query("
                update " . IEUM_PROJECT_TASK_TABLE . "
                   set status = '{$status}',
                       result_summary = '" . sql_escape_string($result_summary) . "',
                       result_detail = '" . sql_escape_string($result_detail) . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                       {$completed_sql}
                 where task_id = '{$task_id}'
            ");
            $message = '작업 결과가 갱신되었습니다.';
        }
    }
}

$today = G5_TIME_YMD;
$active_academy_count = ieum_project_count("select count(*) as cnt from " . IEUM_ACADEMY_TABLE . " where is_active = 1 and service_status = 'active'");
$student_count = ieum_project_count("select count(*) as cnt from " . IEUM_STUDENT_TABLE . " where is_active = 1");
$guardian_count = ieum_project_count("select count(*) as cnt from " . IEUM_STUDENT_GUARDIAN_TABLE . " where is_active = 1");
$sms_pending = ieum_project_count("select count(*) as cnt from " . IEUM_SMS_QUEUE_TABLE . " where status = 'pending'");
$sms_sent = ieum_project_count("select count(*) as cnt from " . IEUM_SMS_QUEUE_TABLE . " where status = 'sent'");
$attendance_today = ieum_project_count("select count(*) as cnt from " . IEUM_ATTENDANCE_TABLE . " where attendance_date = '{$today}'");
$requested_tasks = ieum_project_count("select count(*) as cnt from " . IEUM_PROJECT_TASK_TABLE . " where status in ('requested', 'working', 'review')");

$tasks = sql_query("
    select *
      from " . IEUM_PROJECT_TASK_TABLE . "
  order by case status
        when 'working' then 1
        when 'review' then 2
        when 'requested' then 3
        when 'hold' then 4
        else 5 end,
        priority asc,
        task_id desc
     limit 50
", false);

$seed_exists = ieum_project_count("select count(*) as cnt from " . IEUM_PROJECT_TASK_TABLE);
if (!$seed_exists) {
    sql_query("
        insert into " . IEUM_PROJECT_TASK_TABLE . "
            set title = '관리자 메뉴와 진행 페이지 정리',
                instruction = '관리자 메뉴를 일관되게 만들고, Codex와 기획자가 다음 작업을 주고받을 수 있는 프로젝트 진행 페이지를 만든다.',
                status = 'review',
                priority = 1,
                result_summary = '공통 관리자 메뉴와 작업 지시/결과형 진행 페이지를 구현했습니다.',
                result_detail = '상단 메뉴를 공통 함수로 통일했고, 프로젝트 진행 페이지에서 다음 작업 지시를 등록하고 Codex 결과를 남길 수 있는 구조를 만들었습니다.',
                created_by = 'codex',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");
    $tasks = sql_query("
        select *
          from " . IEUM_PROJECT_TASK_TABLE . "
      order by task_id desc
         limit 50
    ", false);
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 Codex 진행 보드</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:18px 24px}.top-inner{max-width:1240px;margin:0 auto;display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.brand{font-size:20px;font-weight:900}.stamp{color:#cbd5e1}
.wrap{max-width:1240px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:6px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 13px;font-weight:800;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.card,.panel,.task{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{color:#667085;font-size:14px}.num{font-size:32px;font-weight:900;margin-top:4px}
.layout{display:grid;grid-template-columns:.85fr 1.15fr;gap:18px}.panel{margin-bottom:18px}.panel h2{margin:0 0 12px;font-size:20px}.notice{padding:12px 14px;border-radius:8px;margin:0 0 14px}.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}
input,select,textarea{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px}textarea{min-height:120px;resize:vertical}.form-grid{display:grid;gap:10px}.row{display:grid;grid-template-columns:1fr 130px;gap:10px}
.task{margin-bottom:12px}.task-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.task-title{font-size:18px;font-weight:900}.badge{border-radius:999px;padding:5px 9px;font-weight:900;font-size:13px;background:#eef2f7;color:#344054}.badge.working{background:#e8f2ff;color:#1557a6}.badge.review{background:#fff6db;color:#8a5b00}.badge.done{background:#e8f7ee;color:#176b2c}.badge.hold{background:#f1f3f5;color:#667085}.task-meta{color:#667085;font-size:13px;margin-top:4px}.task-body{white-space:pre-wrap;line-height:1.6;margin-top:12px}.result{border-top:1px solid #e2e8f0;margin-top:12px;padding-top:12px}.result strong{display:block;margin-bottom:6px}
.phase{border-left:4px solid #1769c2;padding-left:12px;margin:10px 0;line-height:1.6}.phase.wait{border-color:#cfd6df}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}}@media(max-width:520px){.grid,.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <div class="brand">아이이음 Codex 진행 보드</div>
        <div class="stamp"><?php echo get_text(G5_TIME_YMDHIS); ?></div>
    </div>
</header>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>지시하고, 작업하고, 확인하고, 다음으로</h1>
            <div class="meta">기획자와 Codex가 작업 범위와 결과를 주고받는 전용 보드입니다.</div>
        </div>
        <?php if ($is_member) { ?><a class="btn" href="<?php echo IEUM_URL; ?>/dashboard.php">관리 대시보드</a><?php } ?>
    </section>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="grid">
        <article class="card"><div class="label">열린 작업</div><div class="num"><?php echo number_format($requested_tasks); ?></div></article>
        <article class="card"><div class="label">도장 / 학생</div><div class="num"><?php echo number_format($active_academy_count); ?> / <?php echo number_format($student_count); ?></div></article>
        <article class="card"><div class="label">보호자 / 오늘 출석</div><div class="num"><?php echo number_format($guardian_count); ?> / <?php echo number_format($attendance_today); ?></div></article>
        <article class="card"><div class="label">문자 대기 / 발송</div><div class="num"><?php echo number_format($sms_pending); ?> / <?php echo number_format($sms_sent); ?></div></article>
    </section>

    <section class="layout">
        <aside>
            <section class="panel">
                <h2>다음 작업 지시</h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="token" value="<?php echo get_text($token); ?>">
                    <input type="hidden" name="action" value="add_task">
                    <input type="text" name="title" maxlength="150" placeholder="작업 제목" required>
                    <div class="row">
                        <textarea name="instruction" placeholder="Codex에게 지시할 내용을 적어주세요." required></textarea>
                        <select name="priority">
                            <option value="1">긴급</option>
                            <option value="2">높음</option>
                            <option value="3" selected>보통</option>
                            <option value="4">낮음</option>
                            <option value="5">아이디어</option>
                        </select>
                    </div>
                    <button class="btn primary" type="submit">작업 지시 등록</button>
                </form>
            </section>

            <section class="panel">
                <h2>전체 방향</h2>
                <div class="phase">1구간: 출석 저장, 중복 방지, 문자 큐, 안드로이드 발송</div>
                <div class="phase">운영 골격: 도장 권한, 학생/보호자, 수업 부, 알림 담당자, 진행 보드</div>
                <div class="phase wait">2구간: 인성리포트 주 1회 5분 입력</div>
                <div class="phase wait">3구간: 체력리포트 6개 항목 빠른 입력</div>
                <div class="phase wait">4구간: 티어와 월간 통합 리포트</div>
            </section>
        </aside>

        <section>
            <?php $i = 0; while ($task = sql_fetch_array($tasks)) { $i++; $status_class = ieum_project_status_class($task['status']); ?>
            <article class="task">
                <div class="task-head">
                    <div>
                        <div class="task-title">#<?php echo (int) $task['task_id']; ?> <?php echo get_text($task['title']); ?></div>
                        <div class="task-meta">우선순위 <?php echo (int) $task['priority']; ?> · <?php echo get_text($task['created_at']); ?><?php echo $task['updated_at'] ? ' · 갱신 ' . get_text($task['updated_at']) : ''; ?></div>
                    </div>
                    <span class="badge <?php echo get_text($status_class); ?>"><?php echo get_text(ieum_project_status_label($task['status'])); ?></span>
                </div>
                <div class="task-body"><?php echo get_text($task['instruction']); ?></div>
                <?php if ($task['result_summary'] || $task['result_detail']) { ?>
                <div class="result">
                    <strong>Codex 결과</strong>
                    <?php if ($task['result_summary']) { ?><div class="task-body"><?php echo get_text($task['result_summary']); ?></div><?php } ?>
                    <?php if ($task['result_detail']) { ?><div class="task-body"><?php echo get_text($task['result_detail']); ?></div><?php } ?>
                </div>
                <?php } ?>
            </article>
            <?php } ?>
            <?php if ($i === 0) { ?><article class="task">등록된 작업 지시가 없습니다.</article><?php } ?>
        </section>
    </section>
</main>
</body>
</html>
