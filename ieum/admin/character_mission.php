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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $month = isset($_POST['month']) ? ieum_character_mission_valid_month(trim($_POST['month'])) : $month;
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
            $message = '월별 아이잘해 미션 정보를 저장했습니다.';
        } elseif ($action === 'save_students') {
            $mission = ieum_character_mission_get($academy_id, $month);
            if (!$mission) {
                $error = '먼저 월별 미션 정보를 저장하세요.';
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

$students = sql_query("
    select s.student_id, s.student_code, s.student_name, c.class_name, c.start_time,
           case when ms.participation_status in ('done', 'comment', 'photo', 'excellent') then 'done' else 'none' end as participation_status,
           coalesce(ms.proof_memo, '') as proof_memo,
           coalesce(ms.bonus_score, 0) as bonus_score
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . " ms on ms.student_id = s.student_id and ms.academy_id = s.academy_id and ms.mission_month = '" . sql_escape_string($month) . "'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}h1{margin:0;font-size:28px}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.mission-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:grid;gap:6px}.wide{grid-column:1 / 3}label{font-weight:900}input,textarea{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:14px}textarea{min-height:82px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1947ba;border-color:#1947ba;color:#fff}.help{color:#667085;font-size:13px;line-height:1.5}.students-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.student-card{border:1px solid #d8dee9;border-radius:10px;background:#fbfcfe;padding:12px;display:grid;gap:9px}.student-card.done{border-color:#1947ba;background:#eef4ff}.student-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.student-name{font-weight:900;font-size:16px}.student-sub{color:#667085;font-size:13px;margin-top:3px}.toggle{border:1px solid #cfd6df;border-radius:999px;background:#fff;padding:7px 11px;font-weight:900;cursor:pointer;white-space:nowrap}.done .toggle{background:#1947ba;border-color:#1947ba;color:#fff}.memo{width:100%}.quick-actions{display:flex;gap:8px;flex-wrap:wrap}.badge{display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.done .badge{background:#dbe8ff;color:#1947ba}@media(max-width:900px){.mission-grid,.students-grid{grid-template-columns:1fr}.wide{grid-column:auto}.ieum-user{margin-left:0}}
</style>
</head>
<body>
<?php echo ieum_admin_header('character_mission'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>아이잘해 미션</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 밴드 댓글/인증샷 확인 후 실천완료만 빠르게 체크합니다.</div>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <div class="toolbar">
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?month=<?php echo get_text($prev_month); ?>">이전달</a>
        <form method="get" class="toolbar">
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button type="submit" class="btn primary">조회</button>
        </form>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?month=<?php echo get_text($next_month); ?>">다음달</a>
    </div>

    <section class="panel">
        <h2>월별 미션 정보</h2>
        <p class="help">월 주제와 안내문은 아이이음 홈페이지에서 제공됩니다. 도장에서는 필요할 때 링크만 연결하고, 학생별 실천완료 여부만 빠르게 체크하는 흐름으로 운영합니다.</p>
        <form method="post" class="mission-grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_mission">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <div class="field"><label>미션명</label><input type="text" name="mission_title" value="<?php echo get_text($mission['mission_title']); ?>" placeholder="아이잘해 월간 인성미션"></div>
            <div class="field"><label>이번 달 주제</label><input type="text" name="mission_theme" value="<?php echo get_text($mission['mission_theme']); ?>" placeholder="본사 제공 주제"></div>
            <div class="field wide"><label>아이잘해 미션 확인하러 가기</label><input type="url" name="guide_url" value="<?php echo get_text($mission['guide_url']); ?>" placeholder="https://..."></div>
            <div class="field wide"><label>리포트용 짧은 안내문</label><textarea name="guide_summary" placeholder="예: 이번 달은 자기존중을 주제로 가정에서 나를 소중히 여기는 말을 실천합니다."><?php echo get_text($mission['guide_summary']); ?></textarea></div>
            <div class="field wide"><button type="submit" class="btn primary">미션 정보 저장</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>학생별 실천 체크</h2>
        <p class="help">카드를 누르면 `미참여 ↔ 미션 성공`이 바로 전환됩니다. 메모는 도장 내부 확인용입니다.</p>
        <form method="post" id="missionForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_students">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <div class="quick-actions">
                <button type="button" class="btn" onclick="setAllMission('done')">전체 미션 성공</button>
                <button type="button" class="btn" onclick="setAllMission('none')">전체 미참여</button>
                <button type="submit" class="btn primary">참여 상태 저장</button>
            </div>
            <div class="students-grid" style="margin-top:14px">
                <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; $sid = (int) $row['student_id']; $done = $row['participation_status'] === 'done'; ?>
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
                <?php if ($i === 0) { ?><p>등록된 학생이 없습니다.</p><?php } ?>
            </div>
            <p><button type="submit" class="btn primary">참여 상태 저장</button></p>
        </form>
    </section>
</main>
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
