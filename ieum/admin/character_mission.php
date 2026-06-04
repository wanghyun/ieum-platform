<?php
$sub_menu = '950182';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character_mission.php';

$g5['title'] = '아이이음 아이잘해 미션';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

ieum_character_mission_ensure_tables();

$month = isset($_GET['month']) ? ieum_character_mission_valid_month(trim($_GET['month'])) : date('Y-m');
$class_time_id = isset($_REQUEST['class_time_id']) ? (int) $_REQUEST['class_time_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $month = isset($_POST['month']) ? ieum_character_mission_valid_month(trim($_POST['month'])) : $month;
        $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : $class_time_id;
        $month_sql = sql_escape_string($month);

        if ($action === 'save_mission') {
            $mission_title = isset($_POST['mission_title']) ? trim($_POST['mission_title']) : '';
            $mission_theme = isset($_POST['mission_theme']) ? trim($_POST['mission_theme']) : '';
            $guide_url = isset($_POST['guide_url']) ? trim($_POST['guide_url']) : '';
            $guide_summary = isset($_POST['guide_summary']) ? trim($_POST['guide_summary']) : '';
            if ($mission_title === '') {
                $mission_title = '아이잘해 월간 인성미션';
            }
            if ($mission_theme === '') {
                $mission_theme = '본사 제공 주제';
            }

            sql_query("
                insert into " . IEUM_CHARACTER_MISSION_TABLE . "
                    set academy_id = '{$academy_id}',
                        mission_month = '{$month_sql}',
                        mission_title = '" . sql_escape_string($mission_title) . "',
                        mission_theme = '" . sql_escape_string($mission_theme) . "',
                        guide_url = '" . sql_escape_string($guide_url) . "',
                        guide_summary = '" . sql_escape_string($guide_summary) . "',
                        is_active = 1,
                        created_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        mission_title = values(mission_title),
                        mission_theme = values(mission_theme),
                        guide_url = values(guide_url),
                        guide_summary = values(guide_summary),
                        is_active = 1,
                        updated_at = '" . G5_TIME_YMDHIS . "'
            ");
            $message = '이번 달 아이잘해 미션 정보를 저장했습니다.';
        } elseif ($action === 'save_students') {
            $mission = ieum_character_mission_get($academy_id, $month);
            if (!$mission) {
                $error = '먼저 이번 달 미션 정보를 저장해 주세요.';
            } else {
                $mission_id = (int) $mission['mission_id'];
                $statuses = isset($_POST['mission_status']) && is_array($_POST['mission_status']) ? $_POST['mission_status'] : array();
                $proof_memos = isset($_POST['proof_memo']) && is_array($_POST['proof_memo']) ? $_POST['proof_memo'] : array();
                $checked_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
                $saved = 0;

                foreach ($statuses as $student_id => $status) {
                    $student_id = (int) $student_id;
                    $status = trim($status) === 'done' ? 'done' : 'none';
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

                    $proof_memo = isset($proof_memos[$student_id]) ? trim($proof_memos[$student_id]) : '';
                    $bonus_score = ieum_character_mission_bonus_score($status);
                    $checked_at_sql = $status === 'none' ? 'null' : "'" . G5_TIME_YMDHIS . "'";

                    sql_query("
                        insert into " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . "
                            set academy_id = '{$academy_id}',
                                mission_id = '{$mission_id}',
                                student_id = '{$student_id}',
                                mission_month = '{$month_sql}',
                                participation_status = '{$status}',
                                proof_memo = '" . sql_escape_string($proof_memo) . "',
                                proof_url = '',
                                bonus_score = '{$bonus_score}',
                                checked_by = '{$checked_by}',
                                checked_at = {$checked_at_sql},
                                created_at = '" . G5_TIME_YMDHIS . "'
                        on duplicate key update
                                mission_id = values(mission_id),
                                participation_status = values(participation_status),
                                proof_memo = values(proof_memo),
                                proof_url = '',
                                bonus_score = values(bonus_score),
                                checked_by = values(checked_by),
                                checked_at = {$checked_at_sql},
                                updated_at = '" . G5_TIME_YMDHIS . "'
                    ");
                    $saved++;
                }
                $message = '아이잘해 미션 참여 상태 ' . number_format($saved) . '명을 저장했습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$mission = ieum_character_mission_get($academy_id, $month);
if (!$mission) {
    $mission = array('mission_id' => 0, 'mission_title' => '아이잘해 월간 인성미션', 'mission_theme' => '', 'guide_url' => '', 'guide_summary' => '');
}
$prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
$next_month = date('Y-m', strtotime($month . '-01 +1 month'));

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
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : '';

$student_rows = array();
$done_count = 0;
$students = sql_query("
    select s.student_id, s.student_code, s.student_name, s.class_time_id, c.class_name, c.start_time,
           case when ms.participation_status in ('done', 'comment', 'photo', 'excellent') then 'done' else 'none' end as participation_status,
           coalesce(ms.proof_memo, '') as proof_memo,
           coalesce(ms.bonus_score, 0) as bonus_score
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . " ms on ms.student_id = s.student_id and ms.academy_id = s.academy_id and ms.mission_month = '" . sql_escape_string($month) . "'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and coalesce(s.character_report_enabled, 1) = 1
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($students)) {
    if ($row['participation_status'] === 'done') {
        $done_count++;
    }
    $student_rows[] = $row;
}
$student_count = count($student_rows);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:0 auto;padding:24px 28px 56px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}h1{margin:0;font-size:30px;letter-spacing:-.01em}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:16px;padding:20px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px 14px;border-radius:12px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.class-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 4px}.class-tab{display:inline-flex;align-items:center;min-height:38px;border:1px solid #cfd6df;border-radius:999px;background:#fff;color:#111827;text-decoration:none;padding:8px 14px;font-weight:900}.class-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}.mission-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:grid;gap:6px}.wide{grid-column:1 / 3}label{font-weight:900}input,textarea,select{border:1px solid #cfd6df;border-radius:10px;padding:10px;font-size:14px;background:#fff}textarea{min-height:82px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.soft{background:#f6f8fb}.help{color:#667085;font-size:13px;line-height:1.5}.mission-head{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center}.mission-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.summary-card{border:1px solid #d9dee7;border-radius:14px;padding:14px;background:#fbfcff}.summary-card span{display:block;color:#667085;font-size:12px;font-weight:900}.summary-card strong{display:block;margin-top:5px;font-size:24px}.students-grid{display:grid;grid-template-columns:repeat(3,minmax(240px,1fr));gap:10px}.student-card{border:1px solid #d8dee9;border-radius:14px;background:#fbfcfe;padding:12px;display:grid;gap:10px;transition:border-color .15s,background .15s,box-shadow .15s}.student-card.done{border-color:#1769c2;background:#eef6ff;box-shadow:0 10px 24px rgba(23,105,194,.08)}.student-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.student-name{font-weight:900;font-size:16px}.student-sub{color:#667085;font-size:13px;margin-top:3px}.toggle{border:1px solid #cfd6df;border-radius:999px;background:#fff;padding:7px 11px;font-weight:900;cursor:pointer;white-space:nowrap}.done .toggle{background:#1769c2;border-color:#1769c2;color:#fff}.memo{width:100%}.quick-actions{display:flex;gap:8px;flex-wrap:wrap}.badge{display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.done .badge{background:#dbe8ff;color:#1769c2}.mission-settings summary{cursor:pointer;font-weight:900;color:#1769c2}.save-row{display:flex;justify-content:flex-end;margin-top:14px}@media(max-width:860px){.mission-grid,.students-grid,.mission-summary{grid-template-columns:1fr}.wide{grid-column:auto}.wrap{padding:18px 14px 44px}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.character-mission-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.character-mission-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.character-mission-page-tune .side-brand{
    height:144px!important;
    padding:0 28px!important;
    align-items:center!important;
    font-size:30px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
.character-mission-page-tune .side-brand-mark,
.character-mission-page-tune .side-profile,
.character-mission-page-tune .side-search,
.character-mission-page-tune .ieum-right-rail{display:none!important}
.character-mission-page-tune .side-nav{padding:0 14px 22px!important}
.character-mission-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.character-mission-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.character-mission-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:#fff!important;
    border-bottom:1px solid #eef2f7!important;
    color:#0f172a!important;
    box-shadow:none!important;
}
.character-mission-page-tune .ieum-shell-link,
.character-mission-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.character-mission-page-tune .ieum-shell-link::before{display:none!important}
.character-mission-page-tune .ieum-shell-meta{color:#0f172a!important}
.character-mission-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.character-mission-page-tune .dashboard-shell-divider,
.character-mission-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.character-mission-page-tune .wrap{
    max-width:none!important;
    width:auto!important;
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
}
.character-mission-page-tune .panel,
.character-mission-page-tune .summary-card,
.character-mission-page-tune .student-card{border-radius:8px!important;box-shadow:none!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.character-mission-page-tune{--ieum-side-width:0px}
    .character-mission-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.character-mission-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page character-mission-page-tune">
<?php echo ieum_admin_header('character_mission', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>아이잘해 미션</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 밴드 댓글/인증샷 확인 후 실천완료만 빠르게 체크합니다.</div>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <div class="mission-head">
            <div>
                <h2 style="margin:0">이번 달 미션 관리</h2>
                <p class="help" style="margin:6px 0 0">본사 제공 주제와 안내문 링크를 연결하고, 도장에서는 원생별 실천 여부만 확인합니다.</p>
            </div>
            <div class="toolbar" style="margin:0">
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?<?php echo http_build_query(array('month' => $prev_month, 'class_time_id' => $class_time_id)); ?>">이전달</a>
                <form method="get" class="toolbar" style="margin:0">
                    <input type="month" name="month" value="<?php echo get_text($month); ?>">
                    <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
                    <button type="submit" class="btn primary">조회</button>
                </form>
                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?<?php echo http_build_query(array('month' => $next_month, 'class_time_id' => $class_time_id)); ?>">다음달</a>
            </div>
        </div>
        <div class="mission-summary">
            <div class="summary-card"><span>대상 원생</span><strong><?php echo number_format($student_count); ?>명</strong></div>
            <div class="summary-card"><span>실천완료</span><strong><?php echo number_format($done_count); ?>명</strong></div>
            <div class="summary-card"><span>미참여</span><strong><?php echo number_format(max(0, $student_count - $done_count)); ?>명</strong></div>
        </div>

        <details class="mission-settings" style="margin-top:16px">
            <summary>미션명/안내문 링크 수정</summary>
            <form method="post" class="mission-grid" style="margin-top:14px">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="save_mission">
                <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
                <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
                <div class="field"><label>미션명</label><input type="text" name="mission_title" value="<?php echo get_text($mission['mission_title']); ?>" placeholder="아이잘해 월간 인성미션"></div>
                <div class="field"><label>이번 달 주제</label><input type="text" name="mission_theme" value="<?php echo get_text($mission['mission_theme']); ?>" placeholder="본사 제공 주제"></div>
                <div class="field wide"><label>아이잘해 미션 확인하러 가기</label><input type="url" name="guide_url" value="<?php echo get_text($mission['guide_url']); ?>" placeholder="https://..."></div>
                <div class="field wide"><label>리포트용 짧은 안내문</label><textarea name="guide_summary" placeholder="예: 이번 달은 자기존중을 주제로 가정에서 나를 소중히 여기는 말을 실천합니다."><?php echo get_text($mission['guide_summary']); ?></textarea></div>
                <div class="field wide"><button type="submit" class="btn primary">미션 정보 저장</button></div>
            </form>
        </details>
    </section>

    <nav class="class-tabs" aria-label="아이잘해 미션 부별 필터">
        <a class="class-tab <?php echo $class_time_id === 0 ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?<?php echo http_build_query(array('month' => $month, 'class_time_id' => 0)); ?>">전체 부</a>
        <?php foreach ($class_options as $class) { ?>
        <a class="class-tab <?php echo $class_time_id === (int) $class['class_time_id'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?<?php echo http_build_query(array('month' => $month, 'class_time_id' => (int) $class['class_time_id'])); ?>">
            <?php echo get_text(trim($class['class_name'] . ' ' . $class['start_time'])); ?>
        </a>
        <?php } ?>
    </nav>

    <section class="panel">
        <div class="section-head" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
                <h2 style="margin:0">원생별 실천 체크</h2>
                <p class="help" style="margin:6px 0 0">카드를 누르면 미참여/미션 성공이 바로 바뀝니다. 메모는 도장 내부 확인용입니다.</p>
            </div>
            <div class="quick-actions">
                <button type="button" class="btn soft" onclick="setAllMission('done')">전체 미션 성공</button>
                <button type="button" class="btn soft" onclick="setAllMission('none')">전체 미참여</button>
                <button type="submit" form="missionForm" class="btn primary">참여 상태 저장</button>
            </div>
        </div>
        <form method="post" id="missionForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_students">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <input type="hidden" name="class_time_id" value="<?php echo (int) $class_time_id; ?>">
            <div class="students-grid" style="margin-top:14px">
                <?php foreach ($student_rows as $row) { $sid = (int) $row['student_id']; $done = $row['participation_status'] === 'done'; ?>
                <article class="student-card <?php echo $done ? 'done' : ''; ?>" data-student-card>
                    <input type="hidden" name="mission_status[<?php echo $sid; ?>]" value="<?php echo $done ? 'done' : 'none'; ?>" data-status-input>
                    <div class="student-head">
                        <div>
                            <div class="student-name"><?php echo get_text($row['student_name']); ?> <span class="badge"><?php echo get_text($row['student_code']); ?></span></div>
                            <div class="student-sub"><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></div>
                        </div>
                        <button type="button" class="toggle" data-toggle-mission><?php echo $done ? '미션 성공' : '미참여'; ?></button>
                    </div>
                    <input class="memo" type="text" name="proof_memo[<?php echo $sid; ?>]" value="<?php echo get_text($row['proof_memo']); ?>" placeholder="관리자 메모">
                </article>
                <?php } ?>
                <?php if (!$student_rows) { ?><p>등록된 원생이 없습니다.</p><?php } ?>
            </div>
            <div class="save-row"><button type="submit" class="btn primary">참여 상태 저장</button></div>
        </form>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.character-mission-page-tune.ieum-dashboard-page';
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
function updateMissionCard(card, status) {
    const done = status === 'done';
    card.classList.toggle('done', done);
    card.querySelector('[data-status-input]').value = done ? 'done' : 'none';
    card.querySelector('[data-toggle-mission]').textContent = done ? '미션 성공' : '미참여';
}
document.querySelectorAll('[data-toggle-mission]').forEach((button) => {
    button.addEventListener('click', () => {
        const card = button.closest('[data-student-card]');
        const input = card.querySelector('[data-status-input]');
        updateMissionCard(card, input.value === 'done' ? 'none' : 'done');
    });
});
function setAllMission(status) {
    document.querySelectorAll('[data-student-card]').forEach((card) => updateMissionCard(card, status));
}
</script>
</body>
</html>
