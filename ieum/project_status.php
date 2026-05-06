<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/ui.php';

$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$expected_token = defined('IEUM_PROJECT_STATUS_TOKEN') ? IEUM_PROJECT_STATUS_TOKEN : '';
$token_ok = $expected_token !== '' && hash_equals($expected_token, $token);

if (!$is_member && !$token_ok) {
    goto_url(G5_BBS_URL . '/login.php?url=' . urlencode(IEUM_URL . '/project_status.php'));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } elseif ($action === 'create_task') {
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $instruction = isset($_POST['instruction']) ? trim($_POST['instruction']) : '';
        $priority = isset($_POST['priority']) ? (int) $_POST['priority'] : 2;
        if ($priority < 1) {
            $priority = 1;
        } elseif ($priority > 5) {
            $priority = 5;
        }

        if ($title === '' || $instruction === '') {
            $error = '제목과 작업 지시를 입력해 주세요.';
        } else {
            sql_query("
                insert into " . IEUM_PROJECT_TASK_TABLE . "
                    set title = '" . sql_escape_string($title) . "',
                        instruction = '" . sql_escape_string($instruction) . "',
                        status = 'requested',
                        priority = '{$priority}',
                        result_summary = '',
                        result_detail = '',
                        created_by = '" . sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '') . "',
                        created_at = '" . G5_TIME_YMDHIS . "'
            ");
            $message = '새 작업 지시를 등록했습니다.';
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$tasks = sql_query("
    select *
      from " . IEUM_PROJECT_TASK_TABLE . "
  order by field(status, 'requested', 'in_progress', 'review', 'completed', 'failed'), priority asc, task_id desc
     limit 80
", false);

function ieum_project_status_label($status)
{
    $labels = array(
        'requested' => '요청',
        'working' => '진행중',
        'in_progress' => '진행중',
        'review' => '확인필요',
        'done' => '완료',
        'completed' => '완료',
        'hold' => '보류',
        'failed' => '실패',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 프로젝트 진행 현황</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1180px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.form-grid{display:grid;grid-template-columns:1fr 120px;gap:10px}.form-grid textarea{grid-column:1 / -1;min-height:90px}input,select,textarea{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px;width:100%}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #1769c2;border-radius:8px;background:#1769c2;color:#fff;text-decoration:none;padding:9px 13px;font-weight:800;cursor:pointer}.task{border:1px solid #d8dee9;border-radius:8px;padding:14px;margin-top:10px;background:#fff}.task-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#253469;padding:4px 10px;font-weight:800;font-size:12px}.task p{line-height:1.55}.muted{color:#667085;font-size:13px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}@media(max-width:760px){.form-grid{grid-template-columns:1fr}.ieum-user{margin-left:0}.task-head{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php echo ieum_admin_header('project'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>프로젝트 진행 현황</h1>
            <div class="meta">승인자 지시 → Codex 작업 → 결과 확인 흐름을 한 곳에 남깁니다.</div>
        </div>
        <a class="btn" href="<?php echo IEUM_URL; ?>/dashboard.php">대시보드</a>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <h2>다음 작업 지시</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="create_task">
            <?php if ($token_ok) { ?><input type="hidden" name="token" value="<?php echo get_text($token); ?>"><?php } ?>
            <input type="text" name="title" placeholder="예: 체력리포트 1차 화면 구성" required>
            <select name="priority">
                <option value="1">긴급</option>
                <option value="2" selected>높음</option>
                <option value="3">보통</option>
                <option value="4">낮음</option>
                <option value="5">아이디어</option>
            </select>
            <textarea name="instruction" placeholder="원하는 방향, 완료 조건, 금지 조건을 적어주세요." required></textarea>
            <button type="submit" class="btn">작업 지시 등록</button>
        </form>
    </section>

    <section class="panel">
        <h2>작업 기록</h2>
        <?php $i = 0; while ($task = sql_fetch_array($tasks)) { $i++; ?>
            <article class="task">
                <div class="task-head">
                    <strong><?php echo get_text('#' . $task['task_id'] . ' ' . $task['title']); ?></strong>
                    <span class="badge"><?php echo get_text(ieum_project_status_label($task['status'])); ?> · 우선순위 <?php echo (int) $task['priority']; ?></span>
                </div>
                <p><?php echo nl2br(get_text($task['instruction'])); ?></p>
                <?php if ($task['result_summary'] !== '') { ?><p><strong>결과:</strong> <?php echo nl2br(get_text($task['result_summary'])); ?></p><?php } ?>
                <?php if ($task['result_detail'] !== '') { ?><p class="muted"><?php echo nl2br(get_text($task['result_detail'])); ?></p><?php } ?>
                <div class="muted"><?php echo get_text($task['created_at']); ?> · <?php echo get_text($task['created_by']); ?></div>
            </article>
        <?php } ?>
        <?php if ($i === 0) { ?><p class="muted">아직 등록된 작업 기록이 없습니다.</p><?php } ?>
    </section>
</main>
</body>
</html>
