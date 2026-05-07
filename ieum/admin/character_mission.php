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
                $mission_theme = '이번 달 인성주제';
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
                $proof_urls = isset($_POST['proof_url']) && is_array($_POST['proof_url']) ? $_POST['proof_url'] : array();
                $status_options = ieum_character_mission_status_options();
                $checked_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
                $saved = 0;

                foreach ($statuses as $student_id => $status) {
                    $student_id = (int) $student_id;
                    $status = trim($status);
                    if (!isset($status_options[$status])) {
                        $status = 'none';
                    }
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
                    $proof_url = isset($proof_urls[$student_id]) ? trim($proof_urls[$student_id]) : '';
                    $bonus_score = ieum_character_mission_bonus_score($status);
                    $checked_at_sql = $status === 'none' ? 'null' : "'" . G5_TIME_YMDHIS . "'";

                    sql_query("
                        insert into " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . "
                            set academy_id = '{$academy_id}',
                                mission_id = '{$mission_id}',
                                student_id = '{$student_id}',
                                mission_month = '{$month_sql}',
                                participation_status = '" . sql_escape_string($status) . "',
                                proof_memo = '" . sql_escape_string($proof_memo) . "',
                                proof_url = '" . sql_escape_string($proof_url) . "',
                                bonus_score = '{$bonus_score}',
                                checked_by = '{$checked_by}',
                                checked_at = {$checked_at_sql},
                                created_at = '" . G5_TIME_YMDHIS . "'
                        on duplicate key update
                                mission_id = values(mission_id),
                                participation_status = values(participation_status),
                                proof_memo = values(proof_memo),
                                proof_url = values(proof_url),
                                bonus_score = values(bonus_score),
                                checked_by = values(checked_by),
                                checked_at = {$checked_at_sql},
                                updated_at = '" . G5_TIME_YMDHIS . "'
                    ");
                    $saved++;
                }
                $message = '학생별 아이잘해 미션 인증 상태 ' . number_format($saved) . '명을 저장했습니다.';
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
$status_options = ieum_character_mission_status_options();

$students = sql_query("
    select s.student_id, s.student_code, s.student_name, c.class_name, c.start_time,
           coalesce(ms.participation_status, 'none') as participation_status,
           coalesce(ms.proof_memo, '') as proof_memo,
           coalesce(ms.proof_url, '') as proof_url,
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}h1{margin:0;font-size:28px}.meta{color:#667085;margin-top:6px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.mission-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:grid;gap:6px}.wide{grid-column:1 / 3}label{font-weight:900}input,select,textarea{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:14px}textarea{min-height:96px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1947ba;border-color:#1947ba;color:#fff}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.proof{width:100%;min-width:160px}.help{color:#667085;font-size:13px;line-height:1.5}.bonus{font-weight:900;color:#1947ba}@media(max-width:900px){.mission-grid{grid-template-columns:1fr}.wide{grid-column:auto}table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}
</style>
</head>
<body>
<?php echo ieum_admin_header('character_mission'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>아이잘해 미션</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 가정 실천 인증을 인성리포트에 반영합니다.</div>
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
        <p class="help">상세 안내문은 홈페이지 제공 내용을 요약하거나 링크로 연결하세요. 도장 시스템에서는 리포트에 보여줄 주제와 인증 상태를 관리합니다.</p>
        <form method="post" class="mission-grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_mission">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <div class="field"><label>미션명</label><input type="text" name="mission_title" value="<?php echo get_text($mission['mission_title']); ?>" placeholder="아이잘해 월간 인성미션"></div>
            <div class="field"><label>이번 달 주제</label><input type="text" name="mission_theme" value="<?php echo get_text($mission['mission_theme']); ?>" placeholder="예: 자기존중"></div>
            <div class="field wide"><label>홈페이지 안내문 링크</label><input type="url" name="guide_url" value="<?php echo get_text($mission['guide_url']); ?>" placeholder="https://..."></div>
            <div class="field wide"><label>리포트용 짧은 안내문</label><textarea name="guide_summary" placeholder="예: 이번 달은 자기존중을 주제로 가정에서 나를 소중히 여기는 말을 실천합니다."><?php echo get_text($mission['guide_summary']); ?></textarea></div>
            <div class="field wide"><button type="submit" class="btn primary">미션 정보 저장</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>학생별 참여 상태 체크</h2>
        <p class="help">점수는 인성 본점수 100점에 더하지 않고 내부 보너스 0~5점으로만 관리합니다. 학부모 화면에는 점수 대신 참여 상태와 성장 배지로 표시합니다.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save_students">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <table>
                <thead><tr><th>학생</th><th>수업 부</th><th>참여 상태</th><th>보너스</th><th>인증 메모</th><th>인증 링크</th></tr></thead>
                <tbody>
                <?php $i = 0; while ($row = sql_fetch_array($students)) { $i++; $sid = (int) $row['student_id']; ?>
                <tr>
                    <td class="left"><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                    <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                    <td><select name="mission_status[<?php echo $sid; ?>]"><?php foreach ($status_options as $status => $info) { ?><option value="<?php echo get_text($status); ?>" <?php echo get_selected($row['participation_status'], $status); ?>><?php echo get_text($info['label']); ?></option><?php } ?></select></td>
                    <td class="bonus"><?php echo number_format((int) $row['bonus_score']); ?>점</td>
                    <td><input class="proof" type="text" name="proof_memo[<?php echo $sid; ?>]" value="<?php echo get_text($row['proof_memo']); ?>" placeholder="예: 밴드 댓글 확인"></td>
                    <td><input class="proof" type="url" name="proof_url[<?php echo $sid; ?>]" value="<?php echo get_text($row['proof_url']); ?>" placeholder="인증 링크 선택"></td>
                </tr>
                <?php } ?>
                <?php if ($i === 0) { ?><tr><td colspan="6">등록된 학생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
            <p><button type="submit" class="btn primary">참여 상태 저장</button></p>
        </form>
    </section>
</main>
</body>
</html>
