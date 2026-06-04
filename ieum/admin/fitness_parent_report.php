<?php
$sub_menu = '950192';
$ieum_fitness_parent_public = defined('IEUM_FITNESS_PARENT_REPORT_PUBLIC') && IEUM_FITNESS_PARENT_REPORT_PUBLIC;
if ($ieum_fitness_parent_public) {
    require_once dirname(dirname(__DIR__)) . '/common.php';
    require_once dirname(__DIR__) . '/_common.php';
    require_once IEUM_PATH . '/lib/security.php';
    require_once IEUM_PATH . '/lib/academy.php';
} else {
    require_once './_common.php';
}
require_once IEUM_PATH . '/lib/fitness.php';
require_once IEUM_PATH . '/lib/character_report_link.php';

$g5['title'] = '아이이음 학부모 체력리포트';
$public_payload = null;
if ($ieum_fitness_parent_public) {
    $public_payload = ieum_fitness_report_payload(isset($_GET['t']) ? $_GET['t'] : '');
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
ieum_fitness_ensure_table();

function ieum_parent_fitness_grade_label($value)
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
        'adult' => '성인부',
        'jump_rope' => '줄넘기부',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_parent_fitness_photo_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    $relative = ltrim($path, '/');
    $source = G5_PATH . '/' . $relative;
    if (!is_file($source)) {
        return G5_URL . '/' . $relative;
    }

    $thumb_dir = G5_PATH . '/data/ieum/student_photo_thumbs';
    $thumb_rel_dir = 'data/ieum/student_photo_thumbs';
    $thumb_name = md5($relative . '|' . filemtime($source)) . '_180.jpg';
    $thumb_path = $thumb_dir . '/' . $thumb_name;
    if (!is_file($thumb_path) && function_exists('imagecreatefromstring')) {
        if (!is_dir($thumb_dir)) {
            @mkdir($thumb_dir, 0755, true);
        }
        $bytes = @file_get_contents($source);
        $image = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($image) {
            $src_w = imagesx($image);
            $src_h = imagesy($image);
            $size = 180;
            $thumb = imagecreatetruecolor($size, $size);
            $bg = imagecolorallocate($thumb, 238, 242, 247);
            imagefilledrectangle($thumb, 0, 0, $size, $size, $bg);
            $scale = max($size / max(1, $src_w), $size / max(1, $src_h));
            $dst_w = (int) ceil($src_w * $scale);
            $dst_h = (int) ceil($src_h * $scale);
            $dst_x = (int) floor(($size - $dst_w) / 2);
            $dst_y = (int) floor(($size - $dst_h) / 2);
            imagecopyresampled($thumb, $image, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
            imagejpeg($thumb, $thumb_path, 82);
            imagedestroy($thumb);
            imagedestroy($image);
        }
    }

    if (is_file($thumb_path)) {
        return G5_URL . '/' . $thumb_rel_dir . '/' . $thumb_name;
    }

    return G5_URL . '/' . $relative;
}

function ieum_parent_fitness_month_label($month)
{
    $time = strtotime($month . '-01');
    if (!$time) {
        return $month;
    }

    return date('Y년 n월', $time);
}

function ieum_parent_fitness_total_label($score)
{
    $score = (float) $score;
    if ($score >= 88) {
        return '우수 균형';
    }
    if ($score >= 78) {
        return '양호 균형';
    }
    if ($score >= 68) {
        return '표준 범위';
    }
    return '보강 관찰';
}

function ieum_parent_fitness_recommendation($metric_key, $label)
{
    $tips = array(
        'jump_rope' => '리듬 유지, 발목 탄성, 반복 수행 안정성을 함께 관찰합니다. 짧은 세트 반복으로 수행 편차를 줄이겠습니다.',
        'shuttle_run' => '심폐지구력과 페이스 조절 능력을 확인합니다. 호흡 리듬과 반환 동작을 중심으로 관리하겠습니다.',
        'push_up' => '상지 근력과 몸통 안정성을 확인합니다. 횟수보다 정확한 자세 유지 여부를 먼저 보강하겠습니다.',
        'sit_up' => '복근 지구력과 반복 수행 능력을 확인합니다. 정해진 시간 안의 정확한 수행 횟수를 높이겠습니다.',
        'long_jump' => '순발력과 착지 균형을 확인합니다. 팔 흔들기, 무릎 사용, 착지 안정성을 함께 보강하겠습니다.',
        'flexibility' => '관절 가동성과 근육 긴장도를 참고합니다. 수업 전후 스트레칭 루틴으로 관리하겠습니다.',
    );

    return isset($tips[$metric_key]) ? $tips[$metric_key] : $label . ' 항목을 꾸준히 관찰하겠습니다.';
}

function ieum_parent_fitness_metric_value($fitness, $key, $item)
{
    if (!isset($fitness[$key]) || $fitness[$key] === null || $fitness[$key] === '') {
        return '-';
    }

    $value = (float) $fitness[$key];
    $unit = isset($item['unit']) ? $item['unit'] : '';
    if (in_array($key, array('jump_rope', 'push_up', 'sit_up'), true)) {
        return number_format((int) $value) . $unit;
    }
    if ($key === 'shuttle_run') {
        return number_format($value, $value == (int) $value ? 0 : 1) . $unit;
    }
    return number_format($value, 1) . $unit;
}

$month = $ieum_fitness_parent_public ? $public_payload['m'] : (isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m'));
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$student_id = $ieum_fitness_parent_public ? (int) $public_payload['s'] : (isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0);
$print_bundle = !$ieum_fitness_parent_public && !empty($_GET['print_bundle']);

$student = null;
if ($student_id > 0) {
    $student = sql_fetch("
        select s.student_id, s.student_code, s.student_name, s.student_photo, s.birth_date,
               s.admission_date, s.grade_group, s.school_name, s.gender,
               c.class_name, c.start_time
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
           and s.student_id = '{$student_id}'
           and s.is_active = 1
       and coalesce(s.fitness_report_enabled, 1) = 1
         limit 1
    ", false);
}

$fitness = $student ? sql_fetch("
    select fitness_id, academy_id, student_id, report_month,
           height_cm, weight_kg, jump_rope, shuttle_run, push_up, sit_up,
           long_jump, flexibility, memo
      from " . IEUM_REPORT_FITNESS_TABLE . "
     where academy_id = '{$academy_id}'
       and student_id = '{$student_id}'
       and report_month = '" . sql_escape_string($month) . "'
     limit 1
", false) : null;

$items = ieum_fitness_active_items($academy_id);
if ($fitness) {
    $fitness_map_for_values = array($student_id => $fitness);
    ieum_fitness_merge_metric_values($fitness_map_for_values, ieum_fitness_metric_values($academy_id, $month, array($student_id)), array($student_id));
    $fitness = $fitness_map_for_values[$student_id];
}
$calc = $student && $fitness ? ieum_fitness_calculate($fitness, $student, $academy_id, $month) : null;
$photo_url = $student ? ieum_parent_fitness_photo_url(isset($student['student_photo']) ? $student['student_photo'] : '') : '';
$class_label = $student ? trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: '')) : '';
$grade_label = $student ? ieum_parent_fitness_grade_label($student['grade_group']) : '';
$school_label = $student && $student['school_name'] !== '' ? $student['school_name'] : '';
$month_label = ieum_parent_fitness_month_label($month);

$best_key = '';
$watch_key = '';
if ($calc) {
    foreach ($items as $key => $item) {
        if ($best_key === '' || (float) $calc['item_scores'][$key] > (float) $calc['item_scores'][$best_key]) {
            $best_key = $key;
        }
        if ($watch_key === '' || (float) $calc['item_scores'][$key] < (float) $calc['item_scores'][$watch_key]) {
            $watch_key = $key;
        }
    }
}
$best_item = $best_key !== '' ? $items[$best_key] : null;
$watch_item = $watch_key !== '' ? $items[$watch_key] : null;
$total_label = $calc ? ieum_parent_fitness_total_label($calc['total']) : '';
$bmi_bar = $calc ? ieum_fitness_bmi_bar($calc['bmi'], $student, $academy_id, $month) : null;
$completed_count = 0;
$score_sum = 0;
if ($calc) {
    foreach ($items as $key => $item) {
        if (isset($fitness[$key]) && $fitness[$key] !== null && $fitness[$key] !== '') {
            $completed_count++;
        }
        $score_sum += isset($calc['item_scores'][$key]) ? (float) $calc['item_scores'][$key] : 0;
    }
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#e8eef5;color:#152033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{min-height:100dvh;display:grid;place-items:center;padding:18px}.sheet{width:min(100%,210mm);min-height:297mm;background:#fbfcff;border:1px solid #d5dce8;border-radius:18px;box-shadow:0 22px 58px rgba(30,44,68,.2);overflow:hidden}.cover{background:linear-gradient(135deg,#214bc2 0%,#10213d 100%);color:#fff;padding:12mm 15mm 8mm;position:relative;overflow:hidden}.cover:after{content:"";position:absolute;right:-24mm;top:-34mm;width:86mm;height:86mm;border-radius:50%;background:rgba(255,255,255,.11)}.brand{display:flex;justify-content:space-between;align-items:center;gap:10px;position:relative;z-index:1}.brand-name{font-weight:900;font-size:15px}.month{border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:7px 12px;font-size:13px;font-weight:900;color:#dceaff}.profile{display:grid;grid-template-columns:84px 1fr;gap:16px;align-items:center;margin-top:16px;position:relative;z-index:1}.avatar{width:84px;height:84px;border-radius:20px;object-fit:cover;background:#e8eef7;border:4px solid rgba(255,255,255,.92)}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#65748a;font-weight:900}.eyebrow{font-size:12px;color:#cfe0ff;font-weight:800;letter-spacing:.04em}.name{margin:5px 0 0;font-size:32px;line-height:1.08;letter-spacing:0}.meta{margin-top:7px;color:#e4edff;font-size:14px;line-height:1.4}.stage-line{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;position:relative;z-index:1}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900}.pill.primary{background:#fff;color:#1947ba}.pill.soft{background:rgba(255,255,255,.14);color:#fff}.body{padding:7mm 12mm 6mm;display:grid;gap:3.2mm}.headline{background:#fff;border:1px solid #dce3ef;border-radius:16px;padding:12px;display:grid;grid-template-columns:1.15fr .85fr;gap:12px;align-items:center}.headline h2{margin:0;font-size:21px;line-height:1.22;letter-spacing:0}.headline p{margin:7px 0 0;color:#4d5d73;font-size:12px;line-height:1.5}.score-block{display:grid;grid-template-columns:102px 1fr;gap:10px;align-items:center}.score-orb{width:102px;height:102px;border-radius:999px;background:conic-gradient(#214bc2 calc(var(--score)*1%),#e8eef7 0);display:grid;place-items:center;margin-left:auto}.score-orb-inner{width:82px;height:82px;border-radius:999px;background:#fff;display:grid;place-items:center;text-align:center}.score-orb strong{display:block;font-size:24px}.score-orb span{display:block;color:#667085;font-size:10px;font-weight:900}.score-facts{display:grid;gap:6px}.fact{background:#f7faff;border:1px solid #dce7f6;border-radius:10px;padding:7px 8px}.fact span{display:block;color:#667085;font-size:10px;font-weight:900}.fact strong{display:block;margin-top:2px;font-size:14px}.main-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:10px}.panel{background:#fff;border:1px solid #dce3ef;border-radius:14px;padding:11px}.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.section-title strong{font-size:15px}.section-title span{font-size:11px;color:#6b7a90;font-weight:800}.metric-list{display:grid;gap:6px}.metric-row{display:grid;grid-template-columns:86px 58px 1fr 52px;gap:8px;align-items:center}.metric-row strong{font-size:12px}.metric-value{font-size:12px;font-weight:900;color:#25324a;text-align:right}.bar{height:9px;background:#e8eef7;border-radius:999px;overflow:hidden}.bar i{display:block;height:100%;border-radius:999px;background:#214bc2}.level-badge{display:inline-flex;justify-content:center;min-width:46px;border-radius:999px;background:#eef4ff;color:#214bc2;padding:3px 6px;font-size:10.5px;font-weight:900}.story{background:#f8fbff;border-color:#dce7f6}.story p{margin:0;font-size:12.5px;line-height:1.55;color:#263a56}.analysis-tags{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:9px}.analysis-tags div{border:1px solid #dce7f6;border-radius:11px;background:#fff;padding:8px}.analysis-tags span{display:block;color:#6b7a90;font-size:10px;font-weight:900}.analysis-tags strong{display:block;margin-top:3px;font-size:15px}.analysis-tags small{display:block;margin-top:3px;color:#53647c;font-size:10.5px;line-height:1.35}.bmi-panel{background:#fff;border:1px solid #dce3ef;border-radius:14px;padding:11px}.bmi-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.bmi-head strong{display:block;font-size:15px}.bmi-head span{display:block;margin-top:3px;color:#667085;font-size:11px;font-weight:800}.bmi-value{text-align:right}.bmi-value b{display:block;font-size:20px;color:#172033}.bmi-track{position:relative;height:28px;border-radius:999px;display:flex;overflow:visible;background:#eef3f9}.bmi-seg{height:13px;margin-top:8px;display:grid;place-items:center;color:#fff;font-size:9.5px;font-weight:900;text-shadow:0 1px 2px rgba(0,0,0,.18)}.bmi-seg:first-child{border-radius:999px 0 0 999px}.bmi-seg:last-child{border-radius:0 999px 999px 0}.bmi-seg.under{background:#4b9eea}.bmi-seg.normal{background:#29a66a}.bmi-seg.over{background:#f0a726}.bmi-seg.obese{background:#e44d4d}.bmi-marker{position:absolute;top:-7px;transform:translateX(-50%);display:grid;justify-items:center;gap:3px;color:#172033;font-size:9.5px;font-weight:900;white-space:nowrap}.bmi-marker i{display:block;width:3px;height:28px;background:#172033;border-radius:999px;box-shadow:0 0 0 3px rgba(255,255,255,.85)}.bmi-range-note{display:flex;justify-content:space-between;margin-top:7px;color:#667085;font-size:10px;line-height:1.35}.bmi-source{margin-top:7px;color:#53647c;font-size:9.8px;line-height:1.4}.note{background:#f8fafc;border:1px solid #dce3ef;border-radius:12px;padding:8px 10px;color:#41516a;font-size:11px;line-height:1.45}.footer{display:flex;justify-content:space-between;align-items:center;color:#6b7a90;font-size:11px;padding:0 2px}.empty{width:min(100%,210mm);background:#fff;border-radius:18px;padding:36px;text-align:center;color:#667085}.actions{position:fixed;right:12px;top:12px;display:flex;gap:6px;z-index:5}.actions a,.actions button{border:0;border-radius:999px;background:#172033;color:#fff;text-decoration:none;padding:9px 12px;font-weight:900;font-size:12px;cursor:pointer}@media screen and (max-width:820px){.page{padding:10px;place-items:start center}.sheet{width:min(100%,430px);min-height:auto}.cover{padding:18px}.profile{grid-template-columns:72px 1fr;gap:13px;margin-top:16px}.avatar{width:72px;height:72px;border-radius:20px}.name{font-size:27px}.meta{font-size:13px}.body{padding:14px;gap:12px}.headline,.score-block{grid-template-columns:1fr}.headline h2{font-size:20px}.score-orb{margin:0 auto}.main-grid,.analysis-tags{grid-template-columns:1fr}.metric-row{grid-template-columns:74px 48px 1fr 48px}}@media print{@page{size:A4;margin:0}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}html,body{width:210mm;height:297mm;min-height:297mm;background:#fff;overflow:hidden}.page{display:block;width:210mm;height:297mm;min-height:0;padding:0;overflow:hidden}.sheet{width:210mm;height:297mm;min-height:297mm;border-radius:0;border:0;box-shadow:none;page-break-inside:avoid;break-inside:avoid;overflow:hidden}.actions{display:none}.cover{padding:11mm 14mm 7mm}.profile{margin-top:12px}.avatar{width:76px;height:76px}.name{font-size:28px}.body{padding:5.5mm 10mm 4.5mm;gap:3mm}.headline{padding:10px}.headline h2{font-size:19px}.headline p,.story p{font-size:11px}.panel{padding:9px}.metric-list{gap:5px}.note{font-size:10px}.score-block{grid-template-columns:98px 1fr}.score-orb{width:98px;height:98px}.score-orb-inner{width:78px;height:78px}.score-orb strong{font-size:22px}.bmi-panel{padding:9px}.bmi-source{font-size:9px}}
</style>
<style>
body{background:#eef3f8}.sheet{background:#fff;border:0;border-radius:22px;box-shadow:0 28px 70px rgba(15,23,42,.18)}.cover{background:linear-gradient(135deg,#2347bf 0%,#244bc2 42%,#2c2b27 100%);padding:10mm 14mm 6mm}.body{padding:5.5mm 10mm 4.5mm;gap:2.5mm}.cover:after{right:-18mm;top:-28mm;width:92mm;height:92mm;background:radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 65%)}.brand-name{font-size:16px;letter-spacing:0}.month{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.34)}.avatar{width:78px;height:78px;border-radius:24px;border:4px solid #fff;box-shadow:0 14px 26px rgba(0,0,0,.18)}.profile{margin-top:12px}.eyebrow{color:#dce6ff;letter-spacing:.08em}.name{font-weight:1000;font-size:29px}.stage-line{margin-top:11px}.stage-line .pill{box-shadow:inset 0 0 0 1px rgba(255,255,255,.12)}.pill.primary{color:#2347bf}.headline{border:0;background:linear-gradient(135deg,#f7faff,#fff);box-shadow:inset 0 0 0 1px #dce5f0;padding:10px}.headline h2{font-weight:1000;color:#10213d;font-size:20px}.headline p{font-size:11.5px}.score-orb{width:94px;height:94px;background:conic-gradient(#244bc2 calc(var(--score)*1%),#dce6f2 0);box-shadow:0 12px 24px rgba(36,75,194,.18)}.score-orb-inner{width:74px;height:74px;box-shadow:inset 0 0 0 1px #e2e8f2}.fact{background:#fff;border-color:#dde7f3;padding:6px 8px}.fact strong{color:#10213d;font-size:13px}.panel,.bmi-panel{border-color:#dce5f0;box-shadow:0 8px 22px rgba(15,23,42,.05);padding:9px}.section-title{margin-bottom:6px}.section-title strong{color:#10213d}.metric-list{gap:4px}.metric-row{grid-template-columns:88px 54px 1fr 54px;gap:7px}.bar{background:#edf2f7}.bar i{background:linear-gradient(90deg,#244bc2,#4b79e6)}.level-badge{background:#edf3ff;color:#244bc2}.story{background:linear-gradient(135deg,#f7fbff,#fff)}.story p{font-size:11.5px}.analysis-tags{margin-top:7px}.analysis-tags div{background:#fbfdff;padding:7px}.analysis-tags strong{color:#10213d}.bmi-head strong{color:#10213d}.bmi-track{height:30px;background:#f0f4f8}.bmi-seg{height:15px;margin-top:8px}.bmi-marker{top:-8px}.bmi-marker i{height:31px;background:#10213d}.note{background:#f6f8fb;border-color:#dce5f0;color:#475569;font-size:10.2px;padding:7px 9px}.footer{border-top:1px solid #e5ebf3;padding-top:6px}.report-cert{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.report-cert span{border:1px solid #dce5f0;border-radius:999px;background:#fff;padding:5px 10px;color:#40526b;font-size:10.5px;font-weight:900;text-align:center}.bmi-source b{color:#10213d}@media screen and (max-width:820px){.report-cert{grid-template-columns:1fr}.metric-row{grid-template-columns:76px 52px 1fr 50px}}@media print{.sheet{border-radius:0;box-shadow:none}.cover{padding:9mm 14mm 5mm}.profile{margin-top:8px}.avatar{width:70px;height:70px}.name{font-size:26px}.stage-line{margin-top:10px}.pill{padding:5px 9px;font-size:11px}.body{padding:4.5mm 9mm 3.5mm;gap:2.2mm}.headline{padding:8px}.headline h2{font-size:17px}.headline p,.story p{font-size:10.3px}.score-orb{width:86px;height:86px}.score-orb-inner{width:68px;height:68px}.score-orb strong{font-size:20px}.fact{padding:5px 7px}.fact strong{font-size:12px}.report-cert{gap:5px}.report-cert span{padding:4px 8px;font-size:10px}.panel,.bmi-panel{padding:7px}.metric-list{gap:3px}.metric-row{grid-template-columns:82px 50px 1fr 50px;gap:6px}.metric-row strong,.metric-value{font-size:10.5px}.level-badge{font-size:9.5px;padding:2px 5px}.section-title{margin-bottom:5px}.section-title strong{font-size:13px}.section-title span{font-size:10px}.analysis-tags{gap:5px;margin-top:6px}.analysis-tags div{padding:6px}.analysis-tags strong{font-size:13px}.analysis-tags small{font-size:9.5px}.bmi-head{margin-bottom:7px}.bmi-head strong{font-size:13px}.bmi-value b{font-size:17px}.bmi-track{height:26px}.bmi-seg{height:12px;margin-top:7px;font-size:8.5px}.bmi-marker i{height:27px}.bmi-range-note{margin-top:5px;font-size:9px}.bmi-source{font-size:8.7px;margin-top:5px}.note{font-size:9px;padding:6px 8px}.footer{font-size:9px;padding-top:5px}.metric-row{grid-template-columns:82px 50px 1fr 50px}}
.print-bundle{width:210mm;height:297mm;min-height:297mm;background:#fff;overflow:hidden}.print-bundle .page{display:block;width:210mm;height:297mm;min-height:0;padding:0;overflow:hidden}.print-bundle .sheet{width:210mm;height:297mm;min-height:297mm;border:0;border-radius:0;box-shadow:none;overflow:hidden}.print-bundle .cover{padding:9mm 14mm 5mm}.print-bundle .profile{margin-top:8px}.print-bundle .avatar{width:70px;height:70px}.print-bundle .name{font-size:26px}.print-bundle .stage-line{margin-top:10px}.print-bundle .pill{padding:5px 9px;font-size:11px}.print-bundle .body{padding:4.5mm 9mm 3.5mm;gap:2.2mm}.print-bundle .headline{padding:8px}.print-bundle .headline h2{font-size:17px}.print-bundle .headline p,.print-bundle .story p{font-size:10.3px}.print-bundle .score-orb{width:86px;height:86px}.print-bundle .score-orb-inner{width:68px;height:68px}.print-bundle .score-orb strong{font-size:20px}.print-bundle .fact{padding:5px 7px}.print-bundle .fact strong{font-size:12px}.print-bundle .report-cert{gap:5px}.print-bundle .report-cert span{padding:4px 8px;font-size:10px}.print-bundle .panel,.print-bundle .bmi-panel{padding:7px}.print-bundle .metric-list{gap:3px}.print-bundle .metric-row{grid-template-columns:82px 50px 1fr 50px;gap:6px}.print-bundle .metric-row strong,.print-bundle .metric-value{font-size:10.5px}.print-bundle .level-badge{font-size:9.5px;padding:2px 5px}.print-bundle .section-title{margin-bottom:5px}.print-bundle .section-title strong{font-size:13px}.print-bundle .section-title span{font-size:10px}.print-bundle .analysis-tags{gap:5px;margin-top:6px}.print-bundle .analysis-tags div{padding:6px}.print-bundle .analysis-tags strong{font-size:13px}.print-bundle .analysis-tags small{font-size:9.5px}.print-bundle .bmi-head{margin-bottom:7px}.print-bundle .bmi-head strong{font-size:13px}.print-bundle .bmi-value b{font-size:17px}.print-bundle .bmi-track{height:26px}.print-bundle .bmi-seg{height:12px;margin-top:7px;font-size:8.5px}.print-bundle .bmi-marker i{height:27px}.print-bundle .bmi-range-note{margin-top:5px;font-size:9px}.print-bundle .bmi-source{font-size:8.7px;margin-top:5px}.print-bundle .note{font-size:9px;padding:6px 8px}.print-bundle .footer{font-size:9px;padding-top:5px}
</style>
</head>
<body class="<?php echo $print_bundle ? 'print-bundle' : ''; ?>">
<?php if (!$ieum_fitness_parent_public && !$print_bundle) { ?>
<div class="actions">
    <a href="<?php echo IEUM_URL; ?>/admin/fitness.php?month=<?php echo get_text($month); ?>">관리자</a>
    <button type="button" onclick="window.print()">인쇄</button>
</div>
<?php } ?>
<main class="page">
<?php if (!$student || !$fitness || !$calc) { ?>
    <section class="empty">체력 리포트를 볼 학생 또는 측정 기록이 없습니다.</section>
<?php } else { ?>
    <section class="sheet">
        <header class="cover">
            <div class="brand">
                <span class="brand-name"><?php echo get_text($academy['academy_name']); ?></span>
                <span class="month"><?php echo get_text($month_label); ?></span>
            </div>
            <div class="profile">
                <?php if ($photo_url !== '') { ?>
                <img class="avatar" src="<?php echo get_text($photo_url); ?>" width="92" height="92" alt="">
                <?php } else { ?>
                <div class="avatar avatar-empty">사진</div>
                <?php } ?>
                <div>
                    <div class="eyebrow">MONTHLY FITNESS REPORT</div>
                    <h1 class="name"><?php echo get_text($student['student_name']); ?> 학생</h1>
                    <div class="meta"><?php echo get_text($class_label); ?><br><?php echo get_text(trim($school_label . ' ' . $grade_label)); ?></div>
                </div>
            </div>
            <div class="stage-line">
                <span class="pill primary"><?php echo get_text($total_label); ?></span>
                <span class="pill soft"><?php echo get_text($calc['standard_context']); ?></span>
                <span class="pill soft">체질량지수(BMI) <?php echo $calc['bmi'] === null ? '미입력' : get_text($calc['bmi_label']); ?></span>
                <span class="pill soft"><?php echo (int) $completed_count; ?>/<?php echo count($items); ?>개 체력 항목 측정</span>
            </div>
        </header>

        <section class="body">
            <article class="headline">
                <div>
                    <h2><?php echo get_text($student['student_name']); ?> 학생의 이번 달 신체·체력 흐름은<br><?php echo get_text($total_label); ?> 구간입니다.</h2>
                    <p>도장에서 측정한 실제 기록을 <?php echo get_text($calc['standard_context']); ?>으로 환산해 항목별 강점, 보강 관찰 항목, 체질량지수(BMI) 성장 참고 구간을 정리했습니다.</p>
                </div>
                <div class="score-block">
                    <div class="score-orb" style="--score:<?php echo max(0, min(100, (float) $calc['total'])); ?>">
                        <div class="score-orb-inner">
                            <div><strong><?php echo number_format((float) $calc['total'], 1); ?></strong><span>종합 환산</span></div>
                        </div>
                    </div>
                    <div class="score-facts">
                        <div class="fact"><span>체력 항목 환산</span><strong><?php echo number_format((float) $calc['item_total'], 1); ?>점</strong></div>
                        <div class="fact"><span>키 / 몸무게</span><strong><?php echo $fitness['height_cm'] !== null && $fitness['height_cm'] !== '' ? number_format((float) $fitness['height_cm'], 1) . 'cm' : '-'; ?> · <?php echo $fitness['weight_kg'] !== null && $fitness['weight_kg'] !== '' ? number_format((float) $fitness['weight_kg'], 1) . 'kg' : '-'; ?></strong></div>
                        <div class="fact"><span>체질량지수(BMI)</span><strong><?php echo $calc['bmi'] === null ? '-' : number_format((float) $calc['bmi'], 1) . ' · ' . get_text($calc['bmi_label']); ?></strong></div>
                    </div>
                </div>
            </article>

            <div class="report-cert">
                <span>실측 기록 기반</span>
                <span>연령·성별 기준 환산</span>
                <span>월별 변화 관찰용</span>
            </div>

            <section class="main-grid">
                <article class="panel">
                    <div class="section-title"><strong>항목별 수행 분석</strong><span>측정값 환산</span></div>
                    <div class="metric-list">
                    <?php foreach ($items as $key => $item) {
                        $score = (float) $calc['item_scores'][$key];
                        $level = $calc['item_levels'][$key];
                    ?>
                        <div class="metric-row">
                            <strong><?php echo get_text($item['label']); ?></strong>
                            <span class="metric-value"><?php echo get_text(ieum_parent_fitness_metric_value($fitness, $key, $item)); ?></span>
                            <div class="bar"><i style="width:<?php echo max(0, min(100, $score * 10)); ?>%"></i></div>
                            <span class="level-badge"><?php echo get_text(ieum_fitness_public_level_label($level)); ?></span>
                        </div>
                    <?php } ?>
                    </div>
                </article>

                <article class="panel story">
                    <div class="section-title"><strong>지도진 분석</strong><span>학부모 공유용</span></div>
                    <p>
                        이번 달 <?php echo get_text($student['student_name']); ?> 학생은 <?php echo get_text($best_item ? $best_item['label'] : '체력'); ?> 항목에서 상대적으로 높은 수행 결과를 보였습니다.
                        다음 측정 전까지 <?php echo get_text($watch_item ? $watch_item['label'] : '기초 체력'); ?> 항목은 수업 중 보강 관리 항목으로 두고, 자세 안정성과 반복 수행의 질을 함께 확인하겠습니다.
                        본 리포트는 순위를 매기기 위한 성적표가 아니라 월별 측정값의 변화와 수업 관리 방향을 확인하기 위한 성장 관찰 자료입니다.
                    </p>
                    <div class="analysis-tags">
                        <div><span>상대 강점</span><strong><?php echo get_text($best_item ? $best_item['label'] : '-'); ?></strong><small><?php echo $best_key !== '' ? get_text(ieum_fitness_public_level_note($calc['item_levels'][$best_key])) : '측정 기록 입력 후 자동 분석됩니다.'; ?></small></div>
                        <div><span>보강 관리</span><strong><?php echo get_text($watch_item ? $watch_item['label'] : '-'); ?></strong><small><?php echo $watch_item ? get_text(ieum_parent_fitness_recommendation($watch_key, $watch_item['label'])) : '다음 측정 후 안내합니다.'; ?></small></div>
                    </div>
                </article>
            </section>

            <?php if ($calc['bmi'] !== null && $bmi_bar) { ?>
            <section class="bmi-panel">
                <div class="bmi-head">
                    <div>
                        <strong>체질량지수(BMI) 성장 참고 구간</strong>
                        <span><?php echo get_text($calc['standard_context']); ?> · BMI <?php echo number_format((float) $calc['bmi'], 1); ?> kg/m²</span>
                    </div>
                    <div class="bmi-value">
                        <b><?php echo get_text($calc['bmi_label']); ?></b>
                        <span>우리 아이 구간</span>
                    </div>
                </div>
                <div class="bmi-track" aria-label="BMI 구간">
                    <?php foreach ($bmi_bar['segments'] as $segment) { ?>
                    <div class="bmi-seg <?php echo get_text($segment['class']); ?>" style="width:<?php echo (float) $segment['width']; ?>%"><?php echo get_text($segment['label']); ?></div>
                    <?php } ?>
                    <?php if ($bmi_bar['position'] !== null) { ?>
                    <div class="bmi-marker" style="left:<?php echo (float) $bmi_bar['position']; ?>%"><span>우리 아이</span><i></i></div>
                    <?php } ?>
                </div>
                <div class="bmi-range-note">
                    <span>평균/정상 참고 구간: <?php echo number_format((float) $bmi_bar['normal_min'], 1); ?>~<?php echo number_format((float) $bmi_bar['normal_max'], 1); ?> kg/m²</span>
                    <span>저체중 · 정상체중 · 과체중 · 비만</span>
                </div>
                <div class="bmi-source"><b>체질량지수(BMI)</b>는 키와 몸무게로 산출한 성장 참고 지표입니다. 소아청소년은 성인 고정 기준이 아니라 성별·연령별 체질량지수 백분위수 흐름을 함께 보아야 하며, 의학적 진단은 전문기관 상담 기준을 따릅니다.</div>
            </section>
            <?php } ?>

            <div class="note">본 리포트는 도장 수업에서 측정한 신체·체력 데이터를 월별로 정리한 참고 자료입니다. 점수는 순위를 매기기 위한 목적이 아니라 항목별 수행 변화와 보강 방향을 확인하기 위한 환산 지표입니다.</div>
            <footer class="footer">
                <span>아이이음 신체·체력 측정 리포트</span>
                <span>측정 기반 수업 관리 자료</span>
            </footer>
        </section>
    </section>
<?php } ?>
</main>
</body>
</html>

