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

function ieum_parent_character_month_label($month)
{
    $time = strtotime($month . '-01');
    if (!$time) {
        return $month;
    }

    return date('Y년 n월', $time);
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
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#dfe7f0;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{min-height:100dvh;display:grid;place-items:center;padding:14px}.sheet{width:min(440px,100%);min-height:calc(100dvh - 28px);background:#fbfcff;border:1px solid #d5dce8;border-radius:22px;box-shadow:0 22px 58px rgba(30,44,68,.2);overflow:hidden;display:grid;grid-template-rows:auto 1fr}.cover{background:#18345e;color:#fff;padding:18px 18px 16px;position:relative;overflow:hidden}.cover:after{content:"";position:absolute;right:-46px;top:-58px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.08)}.brand{display:flex;justify-content:space-between;align-items:center;gap:10px;position:relative;z-index:1}.brand-name{font-weight:900;font-size:13px;letter-spacing:0}.month{border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900;color:#dceaff}.profile{display:grid;grid-template-columns:72px 1fr;gap:13px;align-items:center;margin-top:16px;position:relative;z-index:1}.avatar{width:72px;height:72px;border-radius:20px;object-fit:cover;background:#e8eef7;border:3px solid rgba(255,255,255,.92)}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#65748a;font-weight:900}.eyebrow{font-size:12px;color:#bcd0ec;font-weight:800}.name{margin:3px 0 0;font-size:27px;line-height:1.1;letter-spacing:0}.meta{margin-top:6px;color:#d6e3f5;font-size:13px;line-height:1.35}.stage-line{display:flex;gap:7px;flex-wrap:wrap;margin-top:13px;position:relative;z-index:1}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:900}.pill.primary{background:#fff;color:#18345e}.pill.soft{background:rgba(255,255,255,.14);color:#fff}.body{padding:14px;display:grid;gap:11px}.headline{background:#fff;border:1px solid #dce3ef;border-radius:17px;padding:14px;display:grid;gap:10px}.headline h2{margin:0;font-size:20px;line-height:1.25;letter-spacing:0}.headline p{margin:0;color:#4d5d73;font-size:13px;line-height:1.55}.summary{display:grid;grid-template-columns:1fr 1fr;gap:8px}.summary-card{background:#f6f8fb;border:1px solid #e1e7f0;border-radius:14px;padding:10px}.summary-card span{display:block;color:#6b7a90;font-size:11px;font-weight:800}.summary-card strong{display:block;margin-top:4px;font-size:15px}.main-grid{display:grid;grid-template-columns:1fr;gap:11px}.radar-box,.story-box,.growth-box{background:#fff;border:1px solid #dce3ef;border-radius:17px;padding:13px}.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.section-title strong{font-size:15px}.section-title span{font-size:11px;color:#6b7a90;font-weight:800}.radar-wrap{display:grid;place-items:center}canvas{width:236px;height:236px;max-width:100%}.story-box{background:#f1f8f4;border-color:#cfe8d8;color:#17452a}.story-box p{margin:0;font-size:14px;line-height:1.67}.growth-list{display:grid;grid-template-columns:1fr 1fr;gap:8px}.growth{border:1px solid #e1e7f0;border-radius:13px;padding:9px;background:#fbfcff}.growth strong{display:block;font-size:13px}.growth span{display:inline-flex;margin-top:5px;border-radius:999px;background:#eef3f9;color:#3a4a60;padding:4px 7px;font-size:11px;font-weight:900}.rhythm{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.mini{background:#fff;border:1px solid #dce3ef;border-radius:14px;padding:9px;text-align:center}.mini span{display:block;color:#6b7a90;font-size:11px;font-weight:800}.mini strong{display:block;margin-top:3px;font-size:15px;color:#172033}.note{background:#fff8e8;border:1px solid #f1d49a;border-radius:14px;padding:10px;color:#7a4d00;font-size:12px;line-height:1.5}.footer{display:flex;justify-content:space-between;align-items:center;color:#6b7a90;font-size:11px;padding:0 2px 2px}.empty{width:min(440px,100%);background:#fff;border-radius:18px;padding:36px;text-align:center;color:#667085}.actions{position:fixed;right:12px;top:12px;display:flex;gap:6px;z-index:5}.actions a,.actions button{border:0;border-radius:999px;background:#172033;color:#fff;text-decoration:none;padding:9px 12px;font-weight:900;font-size:12px;cursor:pointer}@media(min-width:860px){.sheet{width:min(820px,100%);min-height:auto;grid-template-columns:330px 1fr;grid-template-rows:1fr}.cover{padding:24px;display:grid;align-content:space-between}.profile{grid-template-columns:84px 1fr;align-self:start}.avatar{width:84px;height:84px}.name{font-size:30px}.body{padding:18px}.main-grid{grid-template-columns:260px 1fr}.radar-box{grid-row:1 / 3}.story-box{align-self:stretch}.growth-list{grid-template-columns:1fr}.rhythm{grid-column:1 / 3}.note{grid-column:1 / 3}.footer{grid-column:1 / 3}}@media(max-height:760px){.page{padding:8px}.sheet{min-height:calc(100dvh - 16px);border-radius:18px}.cover{padding:13px 14px 11px}.profile{grid-template-columns:58px 1fr;gap:10px;margin-top:10px}.avatar{width:58px;height:58px;border-radius:16px}.name{font-size:22px}.meta{font-size:12px}.stage-line{margin-top:9px}.body{padding:10px;gap:8px}.headline{padding:11px;gap:7px}.headline h2{font-size:17px}.headline p{font-size:12px}.radar-box,.story-box,.growth-box{padding:10px}.radar-wrap canvas{width:188px;height:188px}.story-box p{font-size:12px;line-height:1.5}.growth{padding:7px}.mini{padding:7px}.note{font-size:11px;padding:8px}}@media print{@page{size:A4;margin:8mm}body{background:#fff}.page{display:block;min-height:0;padding:0}.sheet{width:100%;min-height:0;border-radius:0;border:0;box-shadow:none}.actions{display:none}}
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
