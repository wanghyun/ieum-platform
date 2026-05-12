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
if ($is_member && $is_admin !== 'super' && !$token_ok) {
    alert('본사 관리자만 확인할 수 있습니다.', IEUM_URL . '/dashboard.php');
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

$phase1_items = array(
    array('area' => '출석/문자', 'status' => 'done', 'title' => '학생번호 등원, 중복 방지, 문자 큐', 'note' => '1구간 핵심 흐름은 동작 확인됨. 실제 문자 발송은 도장별 운영 전 최종 점검 필요.'),
    array('area' => '출석 앱', 'status' => 'review', 'title' => '태블릿 앱 QR 연결과 등원 화면', 'note' => '연결/입력은 동작. 7인치/10인치 실기기 화면과 화면 고정 운영 안내가 추가 검증 포인트.'),
    array('area' => '학생 관리', 'status' => 'review', 'title' => '보호자, 프로그램, 학년/부, 수업부, 차량', 'note' => '기본 구조는 갖춤. 프로그램 필터와 대량 원생 관리 UX 보강 필요.'),
    array('area' => '수련비', 'status' => 'review', 'title' => '정책, 납부, 자동 발송 설정', 'note' => '청구/미납 흐름은 준비됨. 비대면 결제 연동 전 상태값과 발송 로그 정리가 필요.'),
    array('area' => '차량', 'status' => 'review', 'title' => '차량/노선/정류장, 일지, 탑승 확인', 'note' => '운영 골격은 좋음. 요일별 예외와 모바일 탑승확인 기록의 실제 기사님 UX 확인 필요.'),
    array('area' => '인성 리포트', 'status' => 'review', 'title' => '주간 입력, 월간 리포트, 아이잘해 미션, 성장 레벨', 'note' => '기능 방향은 맞음. 학부모용 A4/모바일 디자인 고도화가 상품성 핵심.'),
    array('area' => '운영 지표', 'status' => 'done', 'title' => '신규/휴관/퇴관, 입관 경로, 학년/부 분포', 'note' => '기본 지표는 구현됨. 프로그램별/월별 추세 시각화는 다음 단계.'),
    array('area' => '메뉴/권한', 'status' => 'review', 'title' => '상단 대분류, 하위 탭, 본사/도장 분리', 'note' => '기본 구조는 정리됨. 본사 전용/도장 전용 노출 정책을 한 번 더 잠가야 함.'),
);

$phase1_total = count($phase1_items);
$phase1_done = 0;
$phase1_review = 0;
foreach ($phase1_items as $item) {
    if ($item['status'] === 'done') {
        $phase1_done++;
    } elseif ($item['status'] === 'review') {
        $phase1_review++;
    }
}
$phase1_percent = $phase1_total ? (int) round(($phase1_done / $phase1_total) * 100) : 0;

function ieum_project_phase_status_label($status)
{
    $labels = array('done' => '완료', 'review' => '보강', 'todo' => '미작업', 'hold' => '보류');
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1180px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.form-grid{display:grid;grid-template-columns:1fr 120px;gap:10px}.form-grid textarea{grid-column:1 / -1;min-height:90px}input,select,textarea{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px;width:100%}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #1769c2;border-radius:8px;background:#1769c2;color:#fff;text-decoration:none;padding:9px 13px;font-weight:800;cursor:pointer}.task{border:1px solid #d8dee9;border-radius:8px;padding:14px;margin-top:10px;background:#fff}.task-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#253469;padding:4px 10px;font-weight:800;font-size:12px}.task p{line-height:1.55}.muted{color:#667085;font-size:13px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.phase-head{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:center}.phase-score{border:1px solid #d9dee7;border-radius:8px;padding:16px;background:#f8fbff}.phase-score strong{display:block;font-size:34px}.progress{height:12px;background:#e9eef6;border-radius:999px;overflow:hidden;margin-top:10px}.progress span{display:block;height:100%;background:#1769c2}.phase-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px}.phase-item{border:1px solid #d9dee7;border-radius:8px;padding:13px;background:#fff}.phase-item h3{margin:6px 0 6px;font-size:16px}.phase-item p{margin:0;color:#667085;font-size:13px;line-height:1.5}.phase-badge{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:900}.phase-badge.done{background:#eef9f1;color:#176b2c}.phase-badge.review{background:#fff8e6;color:#8a5200}.phase-badge.todo{background:#eef2ff;color:#253469}.next-list{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.next-card{border:1px solid #d9dee7;border-radius:8px;padding:13px;background:#fff}.next-card strong{display:block;margin-bottom:5px}@media(max-width:900px){.phase-head,.phase-grid,.next-list{grid-template-columns:1fr}}@media(max-width:760px){.form-grid{grid-template-columns:1fr}.ieum-user{margin-left:0}.task-head{align-items:flex-start;flex-direction:column}}
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
        <div class="phase-head">
            <article class="phase-score">
                <span class="muted">1차 마감 완료도</span>
                <strong><?php echo (int) $phase1_percent; ?>%</strong>
                <div class="progress"><span style="width:<?php echo (int) $phase1_percent; ?>%"></span></div>
                <p class="muted">완료 <?php echo (int) $phase1_done; ?>개 · 보강 <?php echo (int) $phase1_review; ?>개 · 전체 <?php echo (int) $phase1_total; ?>개</p>
            </article>
            <div>
                <h2>1차 마감 체크리스트</h2>
                <p class="muted">현재 목표는 “실제 도장 1곳이 학생 등록부터 출석, 문자, 차량, 수련비, 인성 기록까지 하루 운영을 테스트할 수 있는 상태”입니다.</p>
            </div>
        </div>
        <div class="phase-grid">
            <?php foreach ($phase1_items as $item) { ?>
            <article class="phase-item">
                <span class="phase-badge <?php echo get_text($item['status']); ?>"><?php echo get_text(ieum_project_phase_status_label($item['status'])); ?></span>
                <h3><?php echo get_text($item['area'] . ' · ' . $item['title']); ?></h3>
                <p><?php echo get_text($item['note']); ?></p>
            </article>
            <?php } ?>
        </div>
    </section>

    <section class="panel">
        <h2>다음 우선순위</h2>
        <div class="next-list">
            <article class="next-card"><strong>1. Git 정리</strong><span class="muted">Android 캐시 추적 제외, 변경 파일 묶음 정리, 커밋 기준 확정</span></article>
            <article class="next-card"><strong>2. 실사용 시나리오 테스트</strong><span class="muted">학생 등록 → 출석 앱 → 문자 큐 → 차량 → 수련비 → 인성 입력</span></article>
            <article class="next-card"><strong>3. 관장님 UX 다듬기</strong><span class="muted">용어 통일, 리스트 필터, 대량 원생 관리, 모바일/태블릿 화면 확인</span></article>
        </div>
    </section>

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
