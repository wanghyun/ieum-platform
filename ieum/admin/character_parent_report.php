<?php
$sub_menu = '950181';
$ieum_parent_public = defined('IEUM_PARENT_REPORT_PUBLIC') && IEUM_PARENT_REPORT_PUBLIC;
if ($ieum_parent_public) {
    require_once dirname(dirname(__DIR__)) . '/common.php';
    require_once dirname(__DIR__) . '/_common.php';
    require_once IEUM_PATH . '/lib/security.php';
    require_once IEUM_PATH . '/lib/academy.php';
} else {
    require_once './_common.php';
}
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';
require_once IEUM_PATH . '/lib/character_level.php';
require_once IEUM_PATH . '/lib/character_report_link.php';

$g5['title'] = '아이이음 학부모 인성리포트';
$public_payload = null;
if ($ieum_parent_public) {
    $public_payload = ieum_character_report_payload(isset($_GET['t']) ? $_GET['t'] : '');
    if (!$public_payload) {
        alert('리포트 링크가 만료되었거나 올바르지 않습니다.');
    }
    $academy_id = (int) $public_payload['a'];
    $academy = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
         limit 1
    ", false);
    if (empty($academy['academy_id'])) {
        alert('리포트를 확인할 수 없습니다.');
    }
} else {
    $academy = ieum_require_academy_page();
    $academy_id = (int) $academy['academy_id'];
}
ieum_character_ensure_table();
ieum_character_mission_ensure_tables();

function ieum_parent_character_grade_label($value)
{
    $labels = array(
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
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_parent_character_photo_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    return G5_URL . '/' . ltrim($path, '/');
}

function ieum_parent_character_month_label($month)
{
    $time = strtotime($month . '-01');
    if (!$time) {
        return $month;
    }

    return date('Y년 n월', $time);
}

$month = $ieum_parent_public ? $public_payload['m'] : (isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m'));
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$student_id = $ieum_parent_public ? (int) $public_payload['s'] : (isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0);
$print_bundle = !$ieum_parent_public && !empty($_GET['print_bundle']);

$student = null;
if ($student_id > 0) {
    $student = sql_fetch("
        select s.student_id, s.student_code, s.student_name, s.student_photo, s.birth_date,
               s.admission_date, s.attendance_days, s.grade_group, s.school_name,
               c.class_name, c.start_time
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
$items = $score ? ieum_character_component_values($score) : array();
$comment = $student && $score ? ieum_character_parent_comment($student['student_name'], $score) : '';
$photo_url = $student ? ieum_parent_character_photo_url(isset($student['student_photo']) ? $student['student_photo'] : '') : '';
$class_label = $student ? trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: '')) : '';
$school_label = $student && $student['school_name'] !== '' ? $student['school_name'] : '';
$grade_label = $student ? ieum_parent_character_grade_label($student['grade_group']) : '';
$month_label = ieum_parent_character_month_label($month);
$stage_label = $score ? ieum_character_total_stage($score['total_score']) : '';
$radar_labels = array();
$radar_values = array();
$best = null;
$watch = null;
foreach ($items as $item) {
    $radar_labels[] = $item['label'];
    $radar_values[] = (int) $item['score'];
    if ($best === null || (int) $item['score'] > (int) $best['score']) {
        $best = $item;
    }
    if ($watch === null || (int) $item['score'] < (int) $watch['score']) {
        $watch = $item;
    }
}
$best_label = $best ? $best['label'] : '-';
$watch_label = $watch ? $watch['label'] : '-';
$attendance_rate = $score ? (int) $score['attendance']['rate'] : 0;
$rhythm_label = $attendance_rate >= 90 ? '아주 좋은 수련 리듬' : ($attendance_rate >= 80 ? '안정적인 수련 리듬' : ($attendance_rate >= 60 ? '다시 만들어가는 리듬' : '적응 중인 리듬'));
$mission = $mission_report ? $mission_report['mission'] : null;
$mission_theme = $mission && $mission['mission_theme'] !== '' ? $mission['mission_theme'] : '이번 달 인성미션';
$mission_title = $mission && $mission['mission_title'] !== '' ? $mission['mission_title'] : '아이잘해 월간 인성미션';
$mission_summary = $mission && $mission['guide_summary'] !== '' ? $mission['guide_summary'] : '가정에서 실천한 인성미션을 도장 성장 리포트에 함께 담습니다.';
$special_score = $score && isset($score['special']['score']) ? (int) $score['special']['score'] : 0;
$special_count = $score && isset($score['special']['count']) ? (int) $score['special']['count'] : 0;
$special_logs = $score && !empty($score['special']['logs']) ? $score['special']['logs'] : array();
$special_reasons = $score && !empty($score['special']['reasons']) ? $score['special']['reasons'] : array();
$special_reason_label = $special_reasons ? implode(', ', $special_reasons) : '지도진 특별 칭찬';
$special_latest = $special_logs ? $special_logs[count($special_logs) - 1] : null;
$special_latest_memo = $special_latest && trim((string) $special_latest['memo']) !== '' ? trim((string) $special_latest['memo']) : '';
$level_color = $level_current ? $level_current['current']['color'] : '#1947ba';
$level_accent = $level_current && isset($level_current['current']['accent']) ? $level_current['current']['accent'] : $level_color;
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#dfe7f0;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{min-height:100dvh;display:grid;place-items:center;padding:18px}.sheet{width:min(100%,210mm);min-height:297mm;background:#fbfcff;border:1px solid #d5dce8;border-radius:18px;box-shadow:0 22px 58px rgba(30,44,68,.2);overflow:hidden;display:grid;grid-template-rows:auto 1fr}.cover{background:#18345e;color:#fff;padding:18mm 18mm 13mm;position:relative;overflow:hidden}.cover:after{content:"";position:absolute;right:-20mm;top:-25mm;width:70mm;height:70mm;border-radius:50%;background:rgba(255,255,255,.08)}.brand{display:flex;justify-content:space-between;align-items:center;gap:10px;position:relative;z-index:1}.brand-name{font-weight:900;font-size:15px;letter-spacing:0}.month{border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:7px 12px;font-size:13px;font-weight:900;color:#dceaff}.profile{display:grid;grid-template-columns:92px 1fr;gap:18px;align-items:center;margin-top:22px;position:relative;z-index:1}.avatar{width:92px;height:92px;border-radius:24px;object-fit:cover;background:#e8eef7;border:4px solid rgba(255,255,255,.92)}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#65748a;font-weight:900}.eyebrow{font-size:12px;color:#bcd0ec;font-weight:800}.name{margin:5px 0 0;font-size:36px;line-height:1.08;letter-spacing:0}.meta{margin-top:8px;color:#d6e3f5;font-size:15px;line-height:1.45}.stage-line{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;position:relative;z-index:1}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:8px 12px;font-size:13px;font-weight:900}.pill.primary{background:#fff;color:#18345e}.pill.soft{background:rgba(255,255,255,.14);color:#fff}.body{padding:12mm 14mm 10mm;display:grid;grid-template-rows:auto 1fr auto auto auto;gap:8mm}.headline{background:#fff;border:1px solid #dce3ef;border-radius:16px;padding:16px;display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:center}.headline h2{margin:0;font-size:25px;line-height:1.25;letter-spacing:0}.headline p{margin:8px 0 0;color:#4d5d73;font-size:14px;line-height:1.6}.summary{display:grid;grid-template-columns:1fr 1fr;gap:8px}.summary-card{background:#f6f8fb;border:1px solid #e1e7f0;border-radius:13px;padding:11px}.summary-card span{display:block;color:#6b7a90;font-size:12px;font-weight:800}.summary-card strong{display:block;margin-top:5px;font-size:17px}.main-grid{display:grid;grid-template-columns:265px 1fr;gap:12px}.radar-box,.story-box,.growth-box,.mission-box{background:#fff;border:1px solid #dce3ef;border-radius:16px;padding:14px}.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.section-title strong{font-size:16px}.section-title span{font-size:12px;color:#6b7a90;font-weight:800}.radar-wrap{display:grid;place-items:center}canvas{width:232px;height:232px;max-width:100%}.radar-box{grid-row:1 / 3}.story-box{background:#f1f8f4;border-color:#cfe8d8;color:#17452a}.story-box p{margin:0;font-size:15px;line-height:1.72}.growth-list{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.growth{border:1px solid #e1e7f0;border-radius:12px;padding:10px;background:#fbfcff}.growth strong{display:block;font-size:13px}.growth span{display:inline-flex;margin-top:6px;border-radius:999px;background:#eef3f9;color:#3a4a60;padding:4px 7px;font-size:11px;font-weight:900}.mission-box{display:grid;grid-template-columns:1fr auto;gap:10px;background:#fff8ef;border-color:#f3d4a8}.mission-box p{grid-column:1 / 3;margin:0;color:#64420d;font-size:13px;line-height:1.55}.mission-badge{display:inline-flex;align-items:center;border-radius:999px;background:#1947ba;color:#fff;padding:7px 10px;font-size:12px;font-weight:900}.rhythm{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.mini{background:#fff;border:1px solid #dce3ef;border-radius:13px;padding:10px;text-align:center}.mini span{display:block;color:#6b7a90;font-size:12px;font-weight:800}.mini strong{display:block;margin-top:4px;font-size:18px;color:#172033}.note{background:#fff8e8;border:1px solid #f1d49a;border-radius:13px;padding:10px;color:#7a4d00;font-size:12px;line-height:1.5}.footer{display:flex;justify-content:space-between;align-items:center;color:#6b7a90;font-size:12px;padding:0 2px}.empty{width:min(100%,210mm);background:#fff;border-radius:18px;padding:36px;text-align:center;color:#667085}.actions{position:fixed;right:12px;top:12px;display:flex;gap:6px;z-index:5}.actions a,.actions button{border:0;border-radius:999px;background:#172033;color:#fff;text-decoration:none;padding:9px 12px;font-weight:900;font-size:12px;cursor:pointer}@media screen and (max-width:820px){.page{padding:10px;place-items:start center}.sheet{width:min(100%,430px);min-height:auto;border-radius:18px}.cover{padding:18px 18px 16px}.brand-name{font-size:13px}.month{font-size:12px;padding:6px 10px}.profile{grid-template-columns:72px 1fr;gap:13px;margin-top:16px}.avatar{width:72px;height:72px;border-radius:20px}.name{font-size:27px}.meta{font-size:13px}.stage-line{margin-top:13px}.pill{font-size:12px;padding:7px 10px}.body{padding:14px;gap:11px;grid-template-rows:none}.headline{grid-template-columns:1fr;padding:14px}.headline h2{font-size:20px}.headline p{font-size:13px}.main-grid{grid-template-columns:1fr}.radar-box{grid-row:auto}.growth-list{grid-template-columns:1fr 1fr}.mission-box{grid-template-columns:1fr}.mission-box p{grid-column:auto}.story-box p{font-size:14px}.rhythm{grid-template-columns:repeat(3,1fr)}canvas{width:236px;height:236px}.footer{font-size:11px}}@media print{@page{size:A4;margin:0}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}html,body{width:210mm;height:297mm;min-height:297mm;background:#fff;overflow:hidden}.page{display:block;width:210mm;height:297mm;min-height:0;padding:0;overflow:hidden}.sheet{width:210mm;height:297mm;min-height:297mm;border-radius:0;border:0;box-shadow:none;page-break-inside:avoid;break-inside:avoid;overflow:hidden}.actions{display:none}.cover{padding:16mm 16mm 12mm}.body{padding:9mm 13mm 7mm;gap:6mm}.headline{grid-template-columns:1.2fr .8fr;padding:12px}.headline h2{font-size:22px}.main-grid{grid-template-columns:250px 1fr}.radar-box{grid-row:1 / 3}.radar-box,.story-box,.growth-box,.mission-box{padding:11px}canvas{width:210px;height:210px}.story-box p{font-size:13px;line-height:1.55}.growth-list{grid-template-columns:repeat(5,1fr);gap:6px}.growth{padding:8px}.mission-box p{font-size:12px}.mini{padding:8px}}
.mission-box{position:relative;overflow:hidden;grid-template-columns:1fr 120px;background:#fff;border:0;box-shadow:0 12px 28px rgba(25,71,186,.14)}.mission-box:before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,#1947ba 0%,#1947ba 36%,#ffffff 36%,#fff8ef 100%);opacity:.12}.mission-copy,.mission-medal{position:relative;z-index:1}.mission-kicker{display:inline-flex;border-radius:999px;background:#1947ba;color:#fff;padding:5px 9px;font-size:11px;font-weight:900}.mission-title{margin:8px 0 3px;font-size:19px;font-weight:900;color:#172033}.mission-theme{font-weight:900;color:#1947ba}.mission-box .mission-text{grid-column:auto;margin-top:7px;color:#4b5563;font-size:13px;line-height:1.55}.mission-medal{width:112px;height:112px;border-radius:999px;background:linear-gradient(145deg,#1947ba,#5d77d9);color:#fff;display:grid;place-items:center;text-align:center;align-self:center;box-shadow:inset 0 0 0 5px rgba(255,255,255,.24),0 8px 18px rgba(25,71,186,.25)}.mission-medal.pending{background:linear-gradient(145deg,#2c2a25,#6b665d)}.mission-medal strong{display:block;font-size:17px;line-height:1.2}.mission-medal span{display:block;margin-top:4px;font-size:11px;font-weight:900;opacity:.86}@media screen and (max-width:820px){.mission-box{grid-template-columns:1fr}.mission-medal{width:92px;height:92px;justify-self:end}}@media print{.mission-box{grid-template-columns:1fr 105px}.mission-medal{width:98px;height:98px}.mission-title{font-size:17px}.mission-box .mission-text{font-size:12px}}
.level-panel{position:relative;overflow:hidden;background:#fff;border:1px solid #dce3ef;border-radius:16px;padding:14px;display:grid;grid-template-columns:140px 1fr;gap:16px;align-items:center}.level-panel:before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(25,71,186,.10),rgba(44,42,37,.04));pointer-events:none}.level-emblem,.level-info{position:relative;z-index:1}.level-emblem{height:120px;border-radius:26px;color:#fff;display:grid;place-items:center;text-align:center;box-shadow:inset 0 0 0 5px rgba(255,255,255,.22),0 14px 28px rgba(15,23,42,.12)}.level-emblem strong{display:block;font-size:23px;line-height:1.15}.level-emblem span{display:block;margin-top:6px;font-size:12px;font-weight:900;opacity:.9}.level-info h3{margin:0;font-size:22px;line-height:1.25}.level-info p{margin:8px 0;color:#4b5563;line-height:1.55}.level-track{height:12px;background:#e7edf5;border-radius:999px;overflow:hidden}.level-track i{display:block;height:100%;border-radius:999px}.level-meta{display:flex;justify-content:space-between;gap:8px;margin-top:8px;color:#667085;font-size:12px;font-weight:900}@media screen and (max-width:820px){.level-panel{grid-template-columns:1fr}.level-emblem{height:92px}}@media print{.level-panel{grid-template-columns:120px 1fr;padding:11px}.level-emblem{height:96px}.level-info h3{font-size:18px}.level-info p{font-size:12px}}
@media print{.cover{padding:13mm 15mm 9mm}.profile{margin-top:15px}.avatar{width:78px;height:78px}.name{font-size:30px}.stage-line{margin-top:12px}.body{padding:7mm 12mm 6mm;gap:4.5mm}.headline{padding:10px}.headline h2{font-size:20px}.headline p{font-size:12px}.main-grid{gap:9px}.radar-box,.story-box,.growth-box,.mission-box,.level-panel{border-radius:13px}.rhythm{gap:6px}.footer{font-size:11px}}
.rank-emblem{isolation:isolate;overflow:visible;background:radial-gradient(circle at 50% 22%,rgba(255,255,255,.55),transparent 24%),linear-gradient(145deg,var(--rank-accent),var(--rank-color) 52%,#171a22);clip-path:polygon(50% 2%,96% 36%,82% 98%,18% 98%,4% 36%)}.rank-emblem:before,.rank-emblem:after{content:"";position:absolute;z-index:-1;top:37%;width:64px;height:20px;border-radius:999px;background:linear-gradient(90deg,rgba(255,255,255,.8),var(--rank-accent));box-shadow:0 10px 18px rgba(15,23,42,.18)}.rank-emblem:before{left:8px;transform:rotate(-24deg)}.rank-emblem:after{right:8px;transform:rotate(24deg)}.rank-core{width:48px;height:48px;margin:0 auto 8px;background:linear-gradient(145deg,#fff,var(--rank-accent) 46%,var(--rank-color));clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%);filter:drop-shadow(0 8px 10px rgba(15,23,42,.22))}.rank-emblem strong,.rank-emblem span{filter:drop-shadow(0 1px 2px rgba(0,0,0,.34))}.special-card{position:relative;overflow:hidden;background:linear-gradient(135deg,#f8fbff,#eef6ff);border:1px solid #c9dcfb;border-radius:16px;padding:13px;display:grid;grid-template-columns:auto 1fr;gap:11px;align-items:center}.special-card:before{content:"";position:absolute;right:-18px;top:-30px;width:96px;height:96px;border-radius:50%;background:rgba(25,71,186,.1)}.special-mark{position:relative;width:50px;height:50px;border-radius:18px;background:linear-gradient(145deg,#1947ba,#42a5f5);color:#fff;display:grid;place-items:center;font-weight:900;box-shadow:0 10px 18px rgba(25,71,186,.22)}.special-card strong{position:relative;display:block;font-size:16px}.special-card span{position:relative;display:block;margin-top:4px;color:#4d5d73;font-size:13px;line-height:1.45}.special-log{position:relative;margin-top:7px;color:#1f3b63;font-weight:900}.special-log small{display:block;margin-top:2px;color:#64748b;font-weight:700}@media print{.rank-emblem:before,.rank-emblem:after{width:52px;height:16px}.rank-core{width:38px;height:38px}}
.print-bundle{width:210mm;height:297mm;min-height:297mm;background:#fff;overflow:hidden}.print-bundle .page{display:block;width:210mm;height:297mm;min-height:0;padding:0;overflow:hidden}.print-bundle .sheet{width:210mm;height:297mm;min-height:297mm;border:0;border-radius:0;box-shadow:none;overflow:hidden}.print-bundle .cover{padding:13mm 15mm 9mm}.print-bundle .profile{margin-top:15px}.print-bundle .avatar{width:78px;height:78px}.print-bundle .name{font-size:30px}.print-bundle .stage-line{margin-top:12px}.print-bundle .body{padding:7mm 12mm 6mm;gap:4.5mm}.print-bundle .headline{padding:10px}.print-bundle .headline h2{font-size:20px}.print-bundle .headline p{font-size:12px}.print-bundle .main-grid{grid-template-columns:250px 1fr;gap:9px}.print-bundle .radar-box{grid-row:1 / 3}.print-bundle .radar-box,.print-bundle .story-box,.print-bundle .growth-box,.print-bundle .mission-box,.print-bundle .level-panel{border-radius:13px;padding:11px}.print-bundle canvas{width:210px;height:210px}.print-bundle .story-box p{font-size:13px;line-height:1.55}.print-bundle .growth-list{grid-template-columns:repeat(5,1fr);gap:6px}.print-bundle .growth{padding:8px}.print-bundle .mission-box{grid-template-columns:1fr 105px}.print-bundle .mission-box p{font-size:12px}.print-bundle .mission-medal{width:98px;height:98px}.print-bundle .mission-title{font-size:17px}.print-bundle .level-panel{grid-template-columns:120px 1fr}.print-bundle .level-emblem{height:96px}.print-bundle .level-info h3{font-size:18px}.print-bundle .level-info p{font-size:12px}.print-bundle .rhythm{gap:6px}.print-bundle .footer{font-size:11px}.print-bundle .rank-emblem:before,.print-bundle .rank-emblem:after{width:52px;height:16px}.print-bundle .rank-core{width:38px;height:38px}
.print-bundle .cover{padding:7mm 12mm 5mm}.print-bundle .brand-name{font-size:13px}.print-bundle .month{padding:5px 9px;font-size:11px}.print-bundle .profile{grid-template-columns:58px 1fr;gap:11px;margin-top:8px}.print-bundle .avatar{width:58px;height:58px;border-radius:16px;border-width:3px}.print-bundle .eyebrow{font-size:10px}.print-bundle .name{font-size:23px}.print-bundle .meta{font-size:11px;margin-top:4px}.print-bundle .stage-line{gap:5px;margin-top:8px}.print-bundle .pill{padding:5px 8px;font-size:10px}.print-bundle .body{padding:4mm 9mm 3.5mm;gap:2.2mm}.print-bundle .headline{padding:8px;grid-template-columns:1.15fr .85fr}.print-bundle .headline h2{font-size:17px}.print-bundle .headline p{font-size:10.5px;line-height:1.35}.print-bundle .summary-card{padding:7px}.print-bundle .summary-card span{font-size:10px}.print-bundle .summary-card strong{font-size:13px}.print-bundle .level-panel{grid-template-columns:92px 1fr;padding:8px;gap:10px}.print-bundle .level-emblem{height:78px;border-radius:18px}.print-bundle .level-emblem strong{font-size:17px}.print-bundle .level-emblem span{font-size:10px;margin-top:3px}.print-bundle .rank-core{width:28px;height:28px;margin-bottom:4px}.print-bundle .rank-emblem:before,.print-bundle .rank-emblem:after{width:38px;height:12px}.print-bundle .level-info h3{font-size:15px}.print-bundle .level-info p{font-size:10.5px;line-height:1.34;margin:4px 0}.print-bundle .level-track{height:8px}.print-bundle .level-meta{font-size:9.5px;margin-top:4px}.print-bundle .main-grid{grid-template-columns:210px 1fr;gap:7px}.print-bundle .radar-box,.print-bundle .story-box,.print-bundle .growth-box,.print-bundle .mission-box,.print-bundle .level-panel{border-radius:11px}.print-bundle .radar-box,.print-bundle .story-box,.print-bundle .growth-box,.print-bundle .mission-box{padding:8px}.print-bundle canvas{width:150px;height:150px}.print-bundle .section-title{margin-bottom:5px}.print-bundle .section-title strong{font-size:12.5px}.print-bundle .section-title span{font-size:9.5px}.print-bundle .story-box p{font-size:10.5px;line-height:1.38}.print-bundle .growth-list{gap:4px}.print-bundle .growth{padding:5px}.print-bundle .growth strong{font-size:10px}.print-bundle .growth span{margin-top:3px;padding:2px 5px;font-size:9px}.print-bundle .note{padding:6px 8px;font-size:9.5px;line-height:1.35}.print-bundle .special-card{padding:8px;gap:8px}.print-bundle .special-mark{width:36px;height:36px;border-radius:12px;font-size:12px}.print-bundle .special-card strong{font-size:12.5px}.print-bundle .special-card span{font-size:10.5px;line-height:1.35}.print-bundle .special-log{margin-top:4px;font-size:10px}.print-bundle .special-log small{font-size:9px}.print-bundle .mission-box{grid-template-columns:1fr 76px;gap:8px}.print-bundle .mission-kicker{padding:3px 7px;font-size:9.5px}.print-bundle .mission-title{font-size:13px;margin:4px 0 2px}.print-bundle .mission-box .mission-text{font-size:10.5px;line-height:1.35;margin-top:4px}.print-bundle .mission-medal{width:72px;height:72px}.print-bundle .mission-medal strong{font-size:13px}.print-bundle .mission-medal span{font-size:9px}.print-bundle .rhythm{gap:5px}.print-bundle .mini{padding:6px}.print-bundle .mini span{font-size:9.5px}.print-bundle .mini strong{font-size:13px}.print-bundle .footer{font-size:9px}
</style>
</head>
<body class="<?php echo $print_bundle ? 'print-bundle' : ''; ?>">
<?php if (!$ieum_parent_public && !$print_bundle) { ?>
<div class="actions">
    <a href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $student_id; ?>">관리자</a>
    <button type="button" onclick="window.print()">인쇄</button>
</div>
<?php } ?>
<main class="page">
<?php if (!$student || !$score) { ?>
    <section class="empty">리포트를 볼 학생이 없습니다.</section>
<?php } else { ?>
    <section class="sheet">
        <header class="cover">
            <div>
                <div class="brand">
                    <span class="brand-name"><?php echo get_text($academy['academy_name']); ?></span>
                    <span class="month"><?php echo get_text($month_label); ?></span>
                </div>
                <div class="profile">
                    <?php if ($photo_url !== '') { ?>
                    <img class="avatar" src="<?php echo get_text($photo_url); ?>" alt="">
                    <?php } else { ?>
                    <div class="avatar avatar-empty">사진</div>
                    <?php } ?>
                    <div>
                        <div class="eyebrow">MONTHLY CHARACTER REPORT</div>
                        <h1 class="name"><?php echo get_text($student['student_name']); ?> 학생</h1>
                        <div class="meta"><?php echo get_text($class_label); ?><br><?php echo get_text(trim($school_label . ' ' . $grade_label)); ?></div>
                    </div>
                </div>
                <div class="stage-line">
                    <span class="pill primary"><?php echo get_text($stage_label); ?></span>
                    <span class="pill soft"><?php echo get_text($rhythm_label); ?></span>
                    <?php if ($special_score > 0) { ?><span class="pill soft">지도진 특별 칭찬 +<?php echo number_format($special_score); ?></span><?php } ?>
                    <?php if ($score['is_first_month']) { ?><span class="pill soft">입관 첫 달</span><?php } ?>
                </div>
            </div>
        </header>

        <section class="body">
            <article class="headline">
                <h2><?php echo get_text($student['student_name']); ?> 학생의 이번 달 성장은<br>균형 있게 쌓이고 있습니다.</h2>
                <p>점수보다 아이가 수업 안에서 보인 태도, 참여 흐름, 관계 속 변화를 함께 살펴보는 리포트입니다.</p>
                <div class="summary">
                    <div class="summary-card"><span>돋보인 영역</span><strong><?php echo get_text($best_label); ?></strong></div>
                    <div class="summary-card"><span>다음 관찰 영역</span><strong><?php echo get_text($watch_label); ?></strong></div>
                </div>
            </article>

            <?php if ($level_current) { ?>
            <article class="level-panel">
                <div class="level-emblem rank-emblem" style="--rank-color:<?php echo get_text($level_color); ?>;--rank-accent:<?php echo get_text($level_accent); ?>">
                    <div>
                        <div class="rank-core"></div>
                        <strong><?php echo get_text($level_current['current']['tier_label']); ?></strong>
                        <span><?php echo number_format((int) $level_current['current']['rank']); ?>단계</span>
                    </div>
                </div>
                <div class="level-info">
                    <h3><?php echo get_text($student['student_name']); ?> 학생의 인성이 자라납니다.</h3>
                    <p>현재 <?php echo get_text($level_current['current']['label']); ?>입니다. <?php echo (int) $level_current['points_to_next'] > 0 ? '다음 레벨까지 ' . number_format((int) $level_current['points_to_next']) . '점 남았습니다.' : '꾸준한 실천으로 최고 단계에 도착했습니다.'; ?></p>
                    <div class="level-track"><i style="width:<?php echo (int) $level_current['progress_rate']; ?>%;background:<?php echo get_text($level_current['current']['color']); ?>"></i></div>
                    <div class="level-meta">
                        <span>누적 성장 포인트 <?php echo number_format((int) $level_current['points']); ?>점</span>
                        <span>다음 <?php echo get_text($level_current['next_label']); ?></span>
                    </div>
                </div>
            </article>
            <?php } ?>

            <div class="main-grid">
                <article class="radar-box">
                    <div class="section-title"><strong>성장 균형 지도</strong><span>5가지 인성</span></div>
                    <div class="radar-wrap"><canvas id="radar" width="300" height="300"></canvas></div>
                </article>
                <article class="story-box">
                    <div class="section-title"><strong>관장님 코멘트</strong><span>학부모용</span></div>
                    <p><?php echo get_text($comment); ?></p>
                </article>
                <article class="growth-box">
                    <div class="section-title"><strong>이번 달 성장 요소</strong><span>단계 표현</span></div>
                    <div class="growth-list">
                        <?php foreach ($items as $item) { ?>
                        <div class="growth">
                            <strong><?php echo get_text($item['label']); ?></strong>
                            <span><?php echo get_text($item['level']); ?></span>
                        </div>
                        <?php } ?>
                    </div>
                </article>
            </div>

            <?php if ($score['is_first_month']) { ?>
            <div class="note">입관 첫 달은 아이가 도장 분위기와 수업 흐름에 적응하는 시기입니다. 입관일 이후의 기록만 반영해 아이에게 불리하지 않게 보았습니다.</div>
            <?php } ?>

            <?php if ($special_score > 0) { ?>
            <article class="special-card">
                <div class="special-mark">+<?php echo number_format($special_score); ?></div>
                <div>
                    <strong>지도진이 따로 발견한 성장</strong>
                    <span>이번 달 지도진이 <?php echo number_format($special_count); ?>회 칭찬 장면을 기록했습니다. <?php echo get_text($special_reason_label); ?> 부분에서 좋은 모습을 보여주었고, 수업 점수 외에도 아이의 실제 성장 장면을 함께 담았습니다.</span>
                    <?php if ($special_latest) { ?>
                    <div class="special-log">
                        최근 칭찬: <?php echo get_text($special_latest['date']); ?> · <?php echo get_text($special_latest['label']); ?>
                        <?php if ($special_latest_memo !== '') { ?><small><?php echo get_text($special_latest_memo); ?></small><?php } ?>
                    </div>
                    <?php } ?>
                </div>
            </article>
            <?php } ?>

            <article class="mission-box">
                <div class="mission-copy">
                    <span class="mission-kicker">아이잘해 가정 실천</span>
                    <div class="mission-title"><?php echo get_text($mission_title); ?></div>
                    <div class="mission-theme">이번 달 주제: <?php echo get_text($mission_theme); ?></div>
                    <p class="mission-text"><?php echo get_text($mission_summary); ?></p>
                    <p class="mission-text"><?php echo get_text($mission_report['parent_text']); ?></p>
                </div>
                <div class="mission-medal <?php echo $mission_report['is_participated'] ? '' : 'pending'; ?>">
                    <div>
                        <strong><?php echo get_text($mission_report['is_participated'] ? '미션 성공' : '다음 달 도전'); ?></strong>
                        <span><?php echo get_text($mission_report['is_participated'] ? '가정 인증 완료' : '참여 독려'); ?></span>
                    </div>
                </div>
            </article>

            <div class="rhythm">
                <div class="mini"><span>평가 주차</span><strong><?php echo number_format((int) $score['evaluated_weeks']); ?>주</strong></div>
                <div class="mini"><span>수련 흐름</span><strong><?php echo number_format($attendance_rate); ?>%</strong></div>
                <div class="mini"><span>출석일</span><strong><?php echo number_format((int) $score['attendance']['attended_days']); ?>/<?php echo number_format((int) $score['attendance']['scheduled_days']); ?>일</strong></div>
            </div>

            <div class="footer">
                <span>아이이음 성장 리포트</span>
                <span>작은 변화가 쌓이면 큰 자신감이 됩니다.</span>
            </div>
        </section>
    </section>
<?php } ?>
</main>
<script>
const radarLabels = <?php echo json_encode($radar_labels, JSON_UNESCAPED_UNICODE); ?>;
const radarValues = <?php echo json_encode($radar_values, JSON_UNESCAPED_UNICODE); ?>;
const canvas = document.getElementById('radar');
if (canvas && radarLabels.length) {
    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2;
    const cy = canvas.height / 2;
    const radius = 92;
    const max = 20;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.lineWidth = 1;
    ctx.font = '13px system-ui, sans-serif';
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
        ctx.fillStyle = '#172033';
        ctx.fillText(label, cx + Math.cos(angle) * (radius + 27), cy + Math.sin(angle) * (radius + 27));
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
    ctx.fillStyle = 'rgba(24,52,94,.20)';
    ctx.strokeStyle = '#18345e';
    ctx.lineWidth = 3;
    ctx.fill();
    ctx.stroke();
}
</script>
</body>
</html>

