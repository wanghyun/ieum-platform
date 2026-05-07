<?php
$sub_menu = '950181';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';

$g5['title'] = '아이이음 학부모 인성리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_character_ensure_table();

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

$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

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
         limit 1
    ", false);
}

$score = $student ? ieum_character_month_score($academy_id, $student, $month) : null;
$items = $score ? ieum_character_component_values($score) : array();
$comment = $student && $score ? ieum_character_parent_comment($student['student_name'], $score) : '';
$photo_url = $student ? ieum_parent_character_photo_url(isset($student['student_photo']) ? $student['student_photo'] : '') : '';
$class_label = $student ? trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: '')) : '';
$school_label = $student && $student['school_name'] !== '' ? $student['school_name'] : '';
$grade_label = $student ? ieum_parent_character_grade_label($student['grade_group']) : '';
$radar_labels = array();
$radar_values = array();
foreach ($items as $item) {
    $radar_labels[] = $item['label'];
    $radar_values[] = (int) $item['score'];
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#edf2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{min-height:100dvh;display:grid;place-items:center;padding:14px}.card{width:min(430px,100%);min-height:calc(100dvh - 28px);background:#fff;border:1px solid #d9dee7;border-radius:18px;padding:18px;box-shadow:0 18px 46px rgba(15,23,42,.16);display:grid;grid-template-rows:auto auto auto 1fr auto;gap:12px;overflow:hidden}.brand{display:flex;justify-content:space-between;align-items:center;gap:10px;color:#667085;font-size:12px}.month{font-weight:900;color:#1769c2}.profile{display:grid;grid-template-columns:66px 1fr;gap:12px;align-items:center}.avatar{width:66px;height:66px;border-radius:18px;object-fit:cover;background:#eef2f7;border:1px solid #d9dee7}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#667085;font-weight:900}.name{margin:0;font-size:25px;letter-spacing:0;line-height:1.15}.meta{margin-top:5px;color:#667085;font-size:13px;line-height:1.35}.stage-row{display:flex;gap:7px;flex-wrap:wrap}.stage{display:inline-flex;align-items:center;border-radius:999px;background:#eaf4ff;color:#1769c2;padding:7px 10px;font-size:13px;font-weight:900}.first{background:#fff4e6;color:#9a5b00}.main{display:grid;grid-template-columns:1fr;gap:10px;align-content:start}.radar-panel{display:grid;place-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:8px}canvas{width:260px;height:260px;max-width:100%}.levels{display:grid;grid-template-columns:1fr 1fr;gap:8px}.level{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#fff}.level strong{display:block;font-size:14px}.level span{display:inline-flex;margin-top:5px;border-radius:999px;background:#eef2f7;color:#344054;padding:4px 7px;font-size:12px;font-weight:900}.note{background:#fffbeb;border:1px solid #f6d58e;border-radius:12px;color:#7a4d00;padding:10px;font-size:13px;line-height:1.5}.comment{background:#eef9f1;border:1px solid #bfe8c9;border-radius:14px;padding:13px;color:#174d2a;line-height:1.65;font-size:14px}.attendance{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.mini{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:9px;text-align:center}.mini span{display:block;color:#667085;font-size:11px}.mini strong{display:block;margin-top:3px;font-size:16px;color:#111827}.empty{width:min(430px,100%);background:#fff;border-radius:18px;padding:36px;text-align:center;color:#667085}.actions{position:fixed;right:12px;top:12px;display:flex;gap:6px}.actions a,.actions button{border:0;border-radius:999px;background:#111827;color:#fff;text-decoration:none;padding:9px 12px;font-weight:900;font-size:12px;cursor:pointer}@media(min-width:760px){.card{width:min(760px,100%);min-height:auto;grid-template-columns:1fr 1.12fr;grid-template-rows:auto auto 1fr auto}.brand,.profile,.stage-row{grid-column:1 / 3}.main{grid-column:1 / 3;grid-template-columns:300px 1fr}.radar-panel{min-height:300px}.comment{align-self:stretch}.attendance{grid-column:1 / 3}.levels{grid-template-columns:1fr}.note{grid-column:1 / 3}}@media(max-height:760px){.card{gap:8px;padding:14px}.name{font-size:22px}.avatar{width:58px;height:58px;border-radius:15px}.profile{grid-template-columns:58px 1fr}canvas{width:220px;height:220px}.level{padding:8px}.comment{font-size:13px;padding:10px;line-height:1.5}.note{padding:8px;font-size:12px}.mini{padding:7px}}@media print{body{background:#fff}.page{padding:0;display:block}.card{width:100%;min-height:0;border:0;box-shadow:none;border-radius:0}.actions{display:none}}
</style>
</head>
<body>
<div class="actions">
    <a href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $student_id; ?>">관리자</a>
    <button type="button" onclick="window.print()">인쇄</button>
</div>
<main class="page">
<?php if (!$student || !$score) { ?>
    <section class="empty">리포트를 볼 학생이 없습니다.</section>
<?php } else { ?>
    <section class="card">
        <div class="brand">
            <span><?php echo get_text($academy['academy_name']); ?></span>
            <span class="month"><?php echo get_text($month); ?> 인성리포트</span>
        </div>
        <div class="profile">
            <?php if ($photo_url !== '') { ?>
            <img class="avatar" src="<?php echo get_text($photo_url); ?>" alt="">
            <?php } else { ?>
            <div class="avatar avatar-empty">사진</div>
            <?php } ?>
            <div>
                <h1 class="name"><?php echo get_text($student['student_name']); ?> 학생</h1>
                <div class="meta"><?php echo get_text($class_label); ?> · <?php echo get_text(trim($school_label . ' ' . $grade_label)); ?></div>
            </div>
        </div>
        <div class="stage-row">
            <span class="stage"><?php echo get_text(ieum_character_total_stage($score['total_score'])); ?></span>
            <?php if ($score['is_first_month']) { ?><span class="stage first">입관 첫 달 적응 기간</span><?php } ?>
        </div>
        <div class="main">
            <article class="radar-panel">
                <canvas id="radar" width="300" height="300"></canvas>
            </article>
            <article class="comment"><?php echo get_text($comment); ?></article>
            <div class="levels">
                <?php foreach ($items as $item) { ?>
                <div class="level">
                    <strong><?php echo get_text($item['label']); ?></strong>
                    <span><?php echo get_text($item['level']); ?></span>
                </div>
                <?php } ?>
            </div>
            <?php if ($score['is_first_month']) { ?>
            <div class="note">입관 첫 달은 아이가 도장 분위기와 수업 흐름에 적응하는 시기입니다. 입관일 이후 기록만 반영했습니다.</div>
            <?php } ?>
        </div>
        <div class="attendance">
            <div class="mini"><span>평가 주차</span><strong><?php echo number_format((int) $score['evaluated_weeks']); ?>주</strong></div>
            <div class="mini"><span>출석 흐름</span><strong><?php echo number_format((int) $score['attendance']['rate']); ?>%</strong></div>
            <div class="mini"><span>출석일</span><strong><?php echo number_format((int) $score['attendance']['attended_days']); ?>/<?php echo number_format((int) $score['attendance']['scheduled_days']); ?>일</strong></div>
        </div>
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

    for (let ring = 1; ring <= 4; ring++) {
        const r = radius * ring / 4;
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
        ctx.fillStyle = '#111827';
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
    ctx.fillStyle = 'rgba(23,105,194,.22)';
    ctx.strokeStyle = '#1769c2';
    ctx.lineWidth = 3;
    ctx.fill();
    ctx.stroke();
}
</script>
</body>
</html>
