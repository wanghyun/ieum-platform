<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급증 인쇄';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_certificate_print_grade_label($value)
{
    $labels = array(
        '' => '미지정',
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
    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_promotion_certificate_print_rank_text($belt, $poom_dan, $grade_level)
{
    return trim(($belt !== '' ? $belt . ' ' : '') . ieum_promotion_rank_label($poom_dan, $grade_level));
}

function ieum_promotion_certificate_print_level_text($poom_dan, $grade_level)
{
    $poom_dan = (int) $poom_dan;
    $grade_level = (int) $grade_level;
    if ($poom_dan > 0) {
        return $poom_dan . '품';
    }
    if ($grade_level > 0) {
        return $grade_level . '급';
    }
    return '';
}

function ieum_promotion_certificate_print_poom_text($poom_dan)
{
    $poom_dan = (int) $poom_dan;
    return $poom_dan > 0 ? (string) $poom_dan : '0';
}

function ieum_promotion_certificate_print_grade_text($grade_level)
{
    $grade_level = (int) $grade_level;
    return $grade_level > 0 ? (string) $grade_level : '0';
}

function ieum_promotion_certificate_belt_tone($belt)
{
    $belt = trim((string) $belt);
    if (strpos($belt, '노') !== false || strpos($belt, '주황') !== false) {
        return array('line' => '#f5b301', 'soft' => '#fff7d8', 'deep' => '#9a5b00');
    }
    if (strpos($belt, '초') !== false) {
        return array('line' => '#239b56', 'soft' => '#e7f7ed', 'deep' => '#116232');
    }
    if (strpos($belt, '파') !== false) {
        return array('line' => '#1f63c5', 'soft' => '#e8f1ff', 'deep' => '#173f86');
    }
    if (strpos($belt, '빨') !== false) {
        return array('line' => '#d62828', 'soft' => '#fff0f0', 'deep' => '#941b1b');
    }
    if (strpos($belt, '품') !== false || strpos($belt, '단') !== false) {
        return array('line' => '#111827', 'soft' => '#f3f4f6', 'deep' => '#111827');
    }
    if (strpos($belt, '흰') !== false) {
        return array('line' => '#94a3b8', 'soft' => '#f8fafc', 'deep' => '#475569');
    }
    return array('line' => '#244bc5', 'soft' => '#edf2ff', 'deep' => '#1e3a8a');
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$print_status = isset($_GET['print_status']) ? preg_replace('/[^a-z_]/', '', $_GET['print_status']) : 'not_printed';
if (!in_array($print_status, array('all', 'not_printed', 'printed'), true)) {
    $print_status = 'not_printed';
}
$issue_date = isset($_GET['issue_date']) ? preg_replace('/[^0-9-]/', '', trim($_GET['issue_date'])) : '';
if ($issue_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
    $issue_date = '';
}
if ($issue_date !== '') {
    $issue_parts = explode('-', $issue_date);
    if (!checkdate((int) $issue_parts[1], (int) $issue_parts[2], (int) $issue_parts[0])) {
        $issue_date = '';
    }
}
$log_ids_raw = isset($_GET['log_ids']) ? trim((string) $_GET['log_ids']) : '';
$log_ids = array();
foreach (explode(',', $log_ids_raw) as $log_id) {
    $log_id = (int) $log_id;
    if ($log_id > 0) {
        $log_ids[] = $log_id;
    }
}
$log_ids = array_values(array_unique($log_ids));

$where = " where l.academy_id = '{$academy_id}' ";
if ($log_ids) {
    $where .= " and l.log_id in (" . implode(',', $log_ids) . ") ";
} else {
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $where .= " and l.promoted_at between '{$month_start}' and '{$month_end}' ";
    if ($program_code !== '') {
        $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
    }
    if ($class_time_id > 0) {
        $where .= " and s.class_time_id = '{$class_time_id}' ";
    }
    if ($print_status === 'not_printed') {
        $where .= " and l.certificate_printed_at is null ";
    } elseif ($print_status === 'printed') {
        $where .= " and l.certificate_printed_at is not null ";
    }
}

$rows = array();
$result = sql_query("
    select l.*, s.student_name, s.student_code, s.grade_group, s.school_name, s.program_code, s.class_time_id,
           c.class_name, c.start_time, c.sort_order
      from " . IEUM_PROMOTION_LOG_TABLE . " l
 left join " . IEUM_STUDENT_TABLE . " s on s.student_id = l.student_id and s.academy_id = l.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, l.promoted_at asc, s.student_name asc, s.student_code asc, l.log_id asc
", false);
while ($row = sql_fetch_array($result)) {
    $rows[] = $row;
}

$back_url = IEUM_URL . '/admin/promotion_certificates.php?' . http_build_query(array(
    'month' => $month,
    'program_code' => $program_code,
    'class_time_id' => $class_time_id,
    'print_status' => $print_status,
));
$certificate_template = ieum_promotion_certificate_template($academy);
$certificate_seal_url = ieum_promotion_certificate_seal_url($academy);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#111827;font-family:"Noto Serif KR","Nanum Myeongjo","Malgun Gothic",serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.toolbar{width:210mm;margin:18px auto 0;display:flex;justify-content:flex-end;gap:8px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#111827;text-decoration:none;padding:8px 14px;font-weight:900;cursor:pointer}
.primary{background:#172554;border-color:#172554;color:#fff}
.certificate{--belt:#244bc5;--belt-soft:#edf2ff;--belt-deep:#1e3a8a;--gold:#a98943;--gold-soft:#efe4c6;position:relative;width:210mm;height:297mm;margin:18px auto;background:linear-gradient(135deg,#fffdf8 0%,#fff 38%,#fbfcff 100%);padding:16mm;box-shadow:0 18px 44px rgba(15,23,42,.18);page-break-after:always;break-after:page;overflow:hidden}
.certificate:before{content:"";position:absolute;inset:9mm;border:2.6mm solid transparent;border-image:linear-gradient(135deg,#826a2b,#e3d19c,#8b6f2d,#d9c077) 1}
.certificate:after{content:"";position:absolute;inset:14mm;border:1px solid rgba(130,106,43,.72);box-shadow:inset 0 0 0 2px rgba(255,255,255,.8)}
.corner{position:absolute;width:45mm;height:45mm;opacity:.38;background:linear-gradient(135deg,rgba(169,137,67,.9),rgba(255,255,255,0));clip-path:polygon(0 0,100% 0,0 100%)}
.corner.tl{top:16mm;left:16mm}
.corner.br{right:16mm;bottom:16mm;transform:rotate(180deg)}
.side-ribbon{display:none}
.watermark{position:absolute;left:50%;top:52%;transform:translate(-50%,-50%);width:118mm;height:118mm;border:2px solid var(--gold);border-radius:999px;opacity:.045}
.watermark:before{content:"IEUM";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#1f2937;font-size:25mm;font-weight:900;letter-spacing:.12em;opacity:.9}
.cert-inner{position:relative;z-index:1;height:265mm;display:flex;flex-direction:column;text-align:center;padding:7mm 10mm 0}
.cert-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10mm;border-bottom:1px solid rgba(169,137,67,.32);padding-bottom:6mm}
.academy{text-align:left}
.academy strong{display:block;color:#111827;font-size:21px;font-weight:900;letter-spacing:-.02em}
.academy span{display:block;margin-top:3px;color:#6b7280;font-size:11px;font-weight:800;letter-spacing:.16em}
.cert-no{text-align:right;color:#111827;font-size:16px;font-weight:900;letter-spacing:.2em;padding-top:2mm}
.title-wrap{margin-top:18mm}
.eyebrow{display:inline-flex;align-items:center;gap:5mm;color:#6b4f16;font-weight:900;letter-spacing:.2em;font-size:14px}
.eyebrow:before,.eyebrow:after{content:"";display:block;width:20mm;height:1px;background:linear-gradient(90deg,transparent,var(--gold))}
.eyebrow:after{background:linear-gradient(90deg,var(--gold),transparent)}
.title{margin:5mm 0 2mm;font-size:72px;letter-spacing:.44em;color:#111827;font-weight:900;text-indent:.44em;text-shadow:0 2px 0 rgba(169,137,67,.18)}
.title-line{width:88mm;height:3px;background:linear-gradient(90deg,transparent,var(--gold),#e8d59d,var(--gold),transparent);margin:0 auto}
.rank-form{width:134mm;margin:14mm auto 0;display:grid;gap:9mm;font-size:25px;line-height:1.35}
.rank-line{display:grid;grid-template-columns:35mm 1fr;align-items:end;text-align:left}
.rank-line span{font-weight:900;color:#111827;font-size:27px;letter-spacing:.08em}
.rank-line strong{display:block;min-height:12mm;border-bottom:1.5px solid rgba(31,41,55,.62);text-align:center;font-size:31px;letter-spacing:.1em;color:#111827}
.rank-line strong.rank-value{display:grid;border-bottom:0}
.rank-value{grid-template-columns:1fr 1fr;gap:12mm;align-items:end}
.rank-value i{font-style:normal;display:flex;justify-content:center;align-items:baseline;gap:2.5mm;min-height:12mm;border-bottom:1.5px solid rgba(31,41,55,.62)}
.rank-value b{display:inline-block;min-width:13mm;font-size:34px;color:#1f2937}
.rank-value b:empty:before{content:"\00a0"}
.rank-value em{font-style:normal;font-size:22px;color:#111827;font-weight:900}
.rank-line.name strong{font-size:38px;color:#111827}
.body-text{max-width:148mm;margin:17mm auto 0;color:#1f2937;font-size:25px;line-height:2.12;word-break:keep-all;letter-spacing:.03em}
.date{margin-top:18mm;font-size:21px;color:#111827;font-weight:900;letter-spacing:.42em}
.director{margin-top:10mm;width:100%;display:flex;justify-content:center;align-items:center;gap:10mm;font-size:29px;font-weight:900}
.director strong{display:inline-block}
.stamp{width:28mm;height:28mm;border:2px solid #991b1b;border-radius:999px;color:#991b1b;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;transform:rotate(-8deg);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.seal-img{width:31mm;height:31mm;object-fit:contain;transform:rotate(-5deg);filter:drop-shadow(0 3px 5px rgba(15,23,42,.12))}
.foot-note{margin-top:auto;display:flex;justify-content:space-between;border-top:1px solid rgba(169,137,67,.24);padding-top:4mm;color:#8a7351;font-size:11px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.certificate.template-classic{background:radial-gradient(circle at 50% 42%,rgba(169,137,67,.14),transparent 38%),linear-gradient(135deg,#fffaf0 0%,#fff 45%,#f8fafc 100%)}
.certificate.template-classic:before{border-width:3mm;border-image:linear-gradient(135deg,#6d5522,#f1dea7,#70551f,#c49d3f) 1}
.certificate.template-classic .watermark{opacity:.06;border-color:#9f7d34}
.certificate.template-classic .title{color:#18181b;text-shadow:0 3px 0 rgba(169,137,67,.22)}
.certificate.template-clean{background:#fff;padding:17mm}
.certificate.template-clean:before{inset:11mm;border-width:1.2mm;border-image:linear-gradient(135deg,#172554,#d9dee7,#172554) 1}
.certificate.template-clean:after{inset:15mm;border-color:#d7deea;box-shadow:none}
.certificate.template-clean .corner,.certificate.template-clean .watermark{display:none}
.certificate.template-clean .title{font-size:66px;text-shadow:none}
.certificate.template-clean .title-line{background:linear-gradient(90deg,transparent,#172554,transparent)}
.certificate.template-clean .eyebrow{color:#172554}
.certificate.template-clean .cert-head{border-bottom-color:#d9dee7}
.empty{width:210mm;margin:18px auto;background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:40px;text-align:center;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#64748b;font-weight:900}
@page{size:A4 portrait;margin:0}
@media print{body{background:#fff}.toolbar{display:none}.certificate{width:210mm;height:297mm;margin:0;box-shadow:none;page-break-after:always;break-after:page}.certificate:last-child{page-break-after:auto;break-after:auto}.empty{display:none}}
</style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?php echo get_text($back_url); ?>">목록으로</a>
    <button class="btn primary" type="button" onclick="window.print()">인쇄</button>
</div>
<?php if (!$rows) { ?>
<div class="empty">인쇄할 승급증이 없습니다.</div>
<?php } ?>
<?php foreach ($rows as $row) {
    $to_poom_label = ieum_promotion_certificate_print_poom_text((int) $row['to_poom_dan']);
    $to_grade_label = ieum_promotion_certificate_print_grade_text((int) $row['to_grade_level']);
    $to_belt_label = trim((string) $row['to_belt']);
    $class_label = trim((isset($row['class_name']) ? $row['class_name'] : '') . ' ' . (isset($row['start_time']) ? $row['start_time'] : ''));
    $grade_label = ieum_promotion_certificate_print_grade_label($row['grade_group']);
    $certificate_date = $issue_date !== '' ? $issue_date : $row['promoted_at'];
    $certificate_no = ieum_promotion_certificate_no($academy_id, (int) $row['log_id'], $certificate_date, $academy);
    $promoted_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $certificate_date) ? date('Y년 n월 j일', strtotime($certificate_date)) : get_text($certificate_date);
    $tone = ieum_promotion_certificate_belt_tone($row['to_belt']);
?>
<main class="certificate template-<?php echo get_text($certificate_template); ?>" style="--belt:<?php echo get_text($tone['line']); ?>;--belt-soft:<?php echo get_text($tone['soft']); ?>;--belt-deep:<?php echo get_text($tone['deep']); ?>;">
    <span class="side-ribbon"></span>
    <span class="corner tl"></span>
    <span class="corner br"></span>
    <span class="watermark"></span>
    <section class="cert-inner">
        <header class="cert-head">
            <div class="academy">
                <strong><?php echo get_text($academy['academy_name']); ?></strong>
            <span>TAEKWONDO PROMOTION</span>
            </div>
            <div class="cert-no">제 <?php echo get_text($certificate_no); ?> 호</div>
        </header>

        <section class="title-wrap">
            <div class="eyebrow">태권도 승급 심사</div>
            <h1 class="title">급 증</h1>
            <div class="title-line"></div>
        </section>

        <section class="rank-form">
            <div class="rank-line">
                <span>태권도</span>
                <strong class="rank-value">
                    <i><b><?php echo htmlspecialchars($to_poom_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></b><em>품</em></i>
                    <i><b><?php echo htmlspecialchars($to_grade_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></b><em>급</em></i>
                </strong>
            </div>
            <div class="rank-line name">
                <span>성명</span>
                <strong><?php echo get_text($row['student_name']); ?></strong>
            </div>
        </section>

        <p class="body-text">
            위 수련생은 본 도장의 태권도 승급 심사 기준에 따라<br>
            성실한 수련 태도와 기량 향상을 인정받았기에<br>
            위와 같이 승급증을 수여합니다.
        </p>

        <div class="date"><?php echo get_text($promoted_date); ?></div>
        <div class="director">
            <strong><?php echo get_text($academy['academy_name']); ?> 관장</strong>
            <?php if ($certificate_seal_url !== '') { ?>
            <img class="seal-img" src="<?php echo get_text($certificate_seal_url); ?>" alt="직인">
            <?php } else { ?>
            <span class="stamp">직인</span>
            <?php } ?>
        </div>
        <footer class="foot-note">
            <span>아이이음 도장운영 OS</span>
            <span><?php echo get_text($academy['academy_name']); ?></span>
        </footer>
    </section>
</main>
<?php } ?>
</body>
</html>
