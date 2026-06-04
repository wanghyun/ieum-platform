<?php
$sub_menu = '950191';
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/fitness.php';

$g5['title'] = '아이이음 체력 기준 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_fitness_ensure_table();

function ieum_fitness_standard_sql_number($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'null';
    }
    $value = preg_replace('/[^0-9\.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.') {
        return 'null';
    }
    return "'" . (float) $value . "'";
}

function ieum_fitness_standard_display_number($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return (string) $value;
    }

    $number = (float) $value;
    if (abs($number - round($number)) < 0.00001) {
        return (string) (int) round($number);
    }
    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function ieum_fitness_standard_display_label($label)
{
    return str_replace(array('남학생', '여학생', '학생'), array('남자', '여자', '원생'), (string) $label);
}

function ieum_fitness_standard_fetch_rows($academy_id, $grade_group, $gender, $metric_key)
{
    $academy_id = (int) $academy_id;
    $grade_sql = sql_escape_string($grade_group);
    $gender_sql = sql_escape_string($gender);
    $metric_sql = sql_escape_string($metric_key);

    $result = sql_query("
        select *
          from " . IEUM_FITNESS_STANDARD_TABLE . "
         where academy_id = '{$academy_id}'
           and grade_group = '{$grade_sql}'
           and gender = '{$gender_sql}'
           and metric_key = '{$metric_sql}'
      order by level_no asc
    ", false);

    $rows = array();
    while ($row = sql_fetch_array($result)) {
        $rows[(int) $row['level_no']] = $row;
    }
    return $rows;
}

function ieum_fitness_standard_page_rows($academy_id, $grade_group, $gender, $metric_key)
{
    $sources = array(
        array((int) $academy_id, $grade_group, $gender, 1),
        array((int) $academy_id, $grade_group, 'all', 1),
        array(0, $grade_group, $gender, 0),
        array(0, $grade_group, 'all', 0),
        array(0, '', $gender, 0),
        array(0, '', 'all', 0),
    );

    $rows = array();
    foreach ($sources as $source) {
        list($source_academy_id, $source_grade, $source_gender, $is_custom) = $source;
        foreach (ieum_fitness_standard_fetch_rows($source_academy_id, $source_grade, $source_gender, $metric_key) as $level => $row) {
            if (!isset($rows[$level])) {
                $row['is_custom'] = $is_custom;
                $row['source_grade_group'] = $source_grade;
                $row['source_gender'] = $source_gender;
                $rows[$level] = $row;
            }
        }
    }

    ksort($rows);
    return $rows;
}

$items = ieum_fitness_standard_metrics($academy_id);
$grades = ieum_fitness_grade_labels();
$gender_options = ieum_fitness_gender_options();

$metric_key = isset($_REQUEST['metric_key']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_REQUEST['metric_key'])) : 'jump_rope';
if (!isset($items[$metric_key])) {
    $metric_key = 'jump_rope';
}
$grade_group = isset($_REQUEST['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_REQUEST['grade_group'])) : '';
if (!array_key_exists($grade_group, $grades)) {
    $grade_group = '';
}
$gender = isset($_REQUEST['gender']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_REQUEST['gender'])) : 'all';
if (!isset($gender_options[$gender])) {
    $gender = 'all';
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['ieum_token']) ? trim($_POST['ieum_token']) : '';
    if (!ieum_verify_csrf_token($token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 저장해 주세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'save';
        if ($action === 'save_fitness_settings') {
            ieum_fitness_save_settings($academy_id, array(
                'cycle_months' => isset($_POST['cycle_months']) ? $_POST['cycle_months'] : 1,
                'report_send_enabled' => isset($_POST['report_send_enabled']) ? 1 : 0,
                'report_send_day' => isset($_POST['report_send_day']) ? $_POST['report_send_day'] : 25,
                'custom_metric_enabled' => isset($_POST['custom_metric_enabled']) ? 1 : 0,
                'memo' => isset($_POST['fitness_setting_memo']) ? $_POST['fitness_setting_memo'] : '',
            ));
            $message = '체력 측정 운영 설정을 저장했습니다.';
        } elseif ($action === 'save_metric_settings') {
            ieum_fitness_save_metric_settings($academy_id, isset($_POST['metrics']) && is_array($_POST['metrics']) ? $_POST['metrics'] : array());
            $message = '체력 측정 항목 설정을 저장했습니다. 입력 화면과 리포트에 바로 반영됩니다.';
        } else {
        $grade_sql = sql_escape_string($grade_group);
        $gender_sql = sql_escape_string($gender);
        $metric_sql = sql_escape_string($metric_key);

        if ($action === 'reset') {
            sql_query("
                delete from " . IEUM_FITNESS_STANDARD_TABLE . "
                 where academy_id = '{$academy_id}'
                   and grade_group = '{$grade_sql}'
                   and gender = '{$gender_sql}'
                   and metric_key = '{$metric_sql}'
            ");
            $message = '이 기준의 도장 맞춤값을 삭제하고 기본 기준으로 되돌렸습니다.';
        } else {
            $standards = isset($_POST['standards']) && is_array($_POST['standards']) ? $_POST['standards'] : array();
            foreach (range(1, 5) as $level_no) {
                $row = isset($standards[$level_no]) && is_array($standards[$level_no]) ? $standards[$level_no] : array();
                $min_sql = ieum_fitness_standard_sql_number(isset($row['min_value']) ? $row['min_value'] : '');
                $max_sql = ieum_fitness_standard_sql_number(isset($row['max_value']) ? $row['max_value'] : '');
                $score_min = max(0, min(100, (int) (isset($row['score_min']) ? $row['score_min'] : 0)));
                $score_max = max(0, min(100, (int) (isset($row['score_max']) ? $row['score_max'] : 0)));
                $label = trim(isset($row['label']) ? $row['label'] : ($level_no . '단계'));
                if ($label === '') {
                    $label = $level_no . '단계';
                }
                $source = trim(isset($row['source']) ? $row['source'] : '도장 맞춤 기준');
                if ($source === '') {
                    $source = '도장 맞춤 기준';
                }
                $label_sql = sql_escape_string($label);
                $source_sql = sql_escape_string($source);

                sql_query("
                    insert into " . IEUM_FITNESS_STANDARD_TABLE . "
                        set academy_id = '{$academy_id}',
                            grade_group = '{$grade_sql}',
                            gender = '{$gender_sql}',
                            metric_key = '{$metric_sql}',
                            level_no = '{$level_no}',
                            min_value = {$min_sql},
                            max_value = {$max_sql},
                            score_min = '{$score_min}',
                            score_max = '{$score_max}',
                            label = '{$label_sql}',
                            source = '{$source_sql}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            min_value = values(min_value),
                            max_value = values(max_value),
                            score_min = values(score_min),
                            score_max = values(score_max),
                            label = values(label),
                            source = values(source),
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
            }
            $message = '체력 기준을 저장했습니다. 이후 체력 입력과 리포트에 자동 반영됩니다.';
        }
        }
    }
}

$items = ieum_fitness_standard_metrics($academy_id);
if (!isset($items[$metric_key])) {
    $metric_key = 'jump_rope';
}

$token = ieum_new_csrf_token();
$fitness_settings = ieum_fitness_settings($academy_id);
$cycle_options = ieum_fitness_cycle_options();
$metric_settings = ieum_fitness_metric_rows($academy_id, true);
$rows = ieum_fitness_standard_page_rows($academy_id, $grade_group, $gender, $metric_key);
$selected_item = $items[$metric_key];
$reference_target = '';
if ($metric_key !== 'bmi' && isset($selected_item['target'])) {
    $reference_target = ieum_fitness_standard_display_number(ieum_fitness_metric_target($metric_key, $selected_item, $grade_group, $gender));
    if ($selected_item['unit'] !== '') {
        $reference_target .= $selected_item['unit'];
    }
} else {
    $reference_target = '성장도표 기준';
}
$direction_label = isset($selected_item['type']) && $selected_item['type'] === 'lower' ? '낮을수록 좋은 항목' : '높을수록 좋은 항목';
if (isset($selected_item['type']) && $selected_item['type'] === 'range') {
    $direction_label = '적정 범위를 보는 항목';
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:6px;line-height:1.45}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.notice{padding:12px;border-radius:8px;margin-top:12px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{background:#fff5f5;border-color:#f4b5b5;color:#9f1f1f}input,select,textarea{border:1px solid #cfd6df;border-radius:8px;padding:9px;font-size:14px;background:#fff}.settings-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.field{display:grid;gap:6px}.field label{font-size:13px;color:#475467;font-weight:900}.field.check{align-content:center}.field.check label{display:flex;gap:8px;align-items:center;background:#f8fbff;border:1px solid #dbe7f6;border-radius:10px;padding:11px}.field.wide{grid-column:span 2}.guide{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.guide-card{border:1px solid #d9dee7;border-radius:12px;background:#fbfcff;padding:14px}.guide-card span{display:block;color:#667085;font-size:12px;font-weight:900}.guide-card strong{display:block;margin-top:5px;font-size:20px}.guide-card.feature{background:#eff6ff;border-color:#bad4f3}.standard-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.standard-flow .step{border:1px solid #d9e5f4;border-radius:12px;background:#f8fbff;padding:14px}.standard-flow b{display:block;font-size:16px}.standard-flow span{display:block;margin-top:6px;color:#667085;line-height:1.5}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:980px}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.level{font-weight:1000;color:#1769c2}.hint{margin-top:10px;color:#667085;line-height:1.6}.source-chip{display:inline-flex;border-radius:999px;padding:5px 9px;background:#eef6ff;color:#1769c2;font-size:12px;font-weight:900}.source-chip.custom{background:#eef9f1;color:#176b2c}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.range-note{font-size:12px;color:#667085;margin-top:3px}.small{font-size:12px;color:#667085;line-height:1.45}@media(max-width:1100px){.guide,.settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.field.wide{grid-column:span 2}}@media(max-width:900px){.wrap{padding:0 12px;margin:16px auto}.guide,.standard-flow,.settings-grid{grid-template-columns:1fr}.field.wide{grid-column:span 1}.filters{display:grid}.filters>*{width:100%}.panel{padding:14px}}@media print{.ieum-top,.ieum-subnav-wrap,.filters,.actions{display:none!important}.wrap{max-width:none;margin:0;padding:10mm}.panel{box-shadow:none;border-color:#aaa}}
body.ieum-side-layout.ieum-dashboard-page.fitness-standards-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.fitness-standards-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.fitness-standards-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
.fitness-standards-page-tune .side-brand-mark,
.fitness-standards-page-tune .side-profile,
.fitness-standards-page-tune .side-search,
.fitness-standards-page-tune .ieum-right-rail{display:none!important}
.fitness-standards-page-tune .side-nav{padding:0 14px 22px!important}
.fitness-standards-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.fitness-standards-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.fitness-standards-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.fitness-standards-page-tune .ieum-shell-link,
.fitness-standards-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.fitness-standards-page-tune .ieum-shell-link::before{display:none!important}
.fitness-standards-page-tune .ieum-shell-meta{color:#0f172a!important}
.fitness-standards-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fitness-standards-page-tune .dashboard-shell-divider,
.fitness-standards-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.fitness-standards-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.fitness-standards-page-tune{--ieum-side-width:0px}
    .fitness-standards-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.fitness-standards-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page fitness-standards-page-tune">
<?php echo ieum_admin_header('fitness_standards', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>체력 기준 관리</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 측정값을 연령/학년/성별 기준으로 자동 환산합니다.</div>
        </div>
        <div class="actions" style="margin-top:0">
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/fitness.php">체력 입력</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/fitness_reports.php">체력 리포트</a>
        </div>
    </section>
    <?php if ($message !== '') { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>

    <form method="post" class="panel">
        <input type="hidden" name="ieum_token" value="<?php echo get_text($token); ?>">
        <input type="hidden" name="action" value="save_fitness_settings">
        <h2 style="margin:0 0 12px">도장 체력 운영 설정</h2>
        <div class="meta" style="margin-bottom:14px">본사는 기본 기준을 제공하고, 도장은 측정 주기와 발송 시점, 추후 맞춤 항목 사용 여부를 정합니다.</div>
        <div class="settings-grid">
            <div class="field">
                <label for="cycle_months">측정 주기</label>
                <select id="cycle_months" name="cycle_months">
                    <?php foreach ($cycle_options as $months => $label) { ?>
                    <option value="<?php echo (int) $months; ?>" <?php echo get_selected((int) $fitness_settings['cycle_months'], (int) $months); ?>><?php echo get_text($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="field">
                <label for="report_send_day">리포트 발송 기준일</label>
                <select id="report_send_day" name="report_send_day">
                    <?php for ($day = 1; $day <= 31; $day++) { ?>
                    <option value="<?php echo $day; ?>" <?php echo get_selected((int) $fitness_settings['report_send_day'], $day); ?>>매월 <?php echo $day; ?>일</option>
                    <?php } ?>
                </select>
            </div>
            <div class="field check">
                <label><input type="checkbox" name="report_send_enabled" value="1" <?php echo !empty($fitness_settings['report_send_enabled']) ? 'checked' : ''; ?>> 발송 예정 관리 사용</label>
            </div>
            <div class="field check">
                <label><input type="checkbox" name="custom_metric_enabled" value="1" <?php echo !empty($fitness_settings['custom_metric_enabled']) ? 'checked' : ''; ?>> 도장 맞춤 항목 준비</label>
            </div>
            <div class="field wide">
                <label for="fitness_setting_memo">운영 메모</label>
                <input type="text" id="fitness_setting_memo" name="fitness_setting_memo" value="<?php echo get_text($fitness_settings['memo']); ?>" placeholder="예: 체력 측정은 짝수달 마지막 주에 진행">
            </div>
            <div class="field">
                <button type="submit" class="btn primary">운영 설정 저장</button>
            </div>
        </div>
        <p class="hint">측정 주기와 발송 기준일은 체력 리포트 발송 예정 관리에 사용됩니다. 항목 사용 여부는 아래 측정 항목 설정에서 바로 조정할 수 있습니다.</p>
    </form>

    <form method="post" class="panel">
        <input type="hidden" name="ieum_token" value="<?php echo get_text($token); ?>">
        <input type="hidden" name="action" value="save_metric_settings">
        <h2 style="margin:0 0 12px">측정 항목 설정</h2>
        <div class="meta" style="margin-bottom:14px">도장에서 실제로 측정하는 항목만 켜두면 체력 입력, 체력 리포트, 학부모 리포트에 같은 순서로 반영됩니다.</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:90px">사용</th>
                        <th>항목</th>
                        <th style="width:160px">단위</th>
                        <th style="width:180px">환산 방향</th>
                        <th style="width:120px">순서</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($metric_settings as $key => $metric) { ?>
                    <tr>
                        <td><input type="checkbox" name="metrics[<?php echo get_text($key); ?>][is_active]" value="1" <?php echo !empty($metric['is_active']) ? 'checked' : ''; ?>></td>
                        <td><input type="text" name="metrics[<?php echo get_text($key); ?>][label]" value="<?php echo get_text($metric['label']); ?>" style="width:100%"></td>
                        <td><input type="text" name="metrics[<?php echo get_text($key); ?>][unit]" value="<?php echo get_text($metric['unit']); ?>" style="width:100%"></td>
                        <td>
                            <select name="metrics[<?php echo get_text($key); ?>][metric_type]" style="width:100%">
                                <option value="higher" <?php echo get_selected(isset($metric['type']) ? $metric['type'] : 'higher', 'higher'); ?>>높을수록 좋음</option>
                                <option value="lower" <?php echo get_selected(isset($metric['type']) ? $metric['type'] : 'higher', 'lower'); ?>>낮을수록 좋음</option>
                            </select>
                        </td>
                        <td><input type="number" name="metrics[<?php echo get_text($key); ?>][sort_order]" value="<?php echo (int) $metric['sort_order']; ?>" min="1" max="999" style="width:100%"></td>
                    </tr>
                <?php } ?>
                <?php if (!empty($fitness_settings['custom_metric_enabled'])) { ?>
                    <tr>
                        <td><span class="source-chip custom">신규</span></td>
                        <td><input type="text" name="metrics[_new][label]" value="" placeholder="예: 20m 달리기" style="width:100%"></td>
                        <td><input type="text" name="metrics[_new][unit]" value="" placeholder="초, 회, cm 등" style="width:100%"></td>
                        <td>
                            <select name="metrics[_new][metric_type]" style="width:100%">
                                <option value="higher">높을수록 좋음</option>
                                <option value="lower">낮을수록 좋음</option>
                            </select>
                        </td>
                        <td><input type="number" name="metrics[_new][sort_order]" value="90" min="1" max="999" style="width:100%"></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="actions">
            <button type="submit" class="btn primary">측정 항목 저장</button>
        </div>
        <p class="hint">도장 맞춤 항목 준비를 켜면 신규 항목을 추가할 수 있습니다. 추가한 항목은 체력 입력과 리포트에 바로 나타나며, 기준표를 별도로 잡기 전에는 입력값을 10점 기준으로 임시 환산합니다.</p>
    </form>

    <section class="panel">
        <form method="get" class="filters">
            <select name="metric_key">
                <?php foreach ($items as $key => $item) { ?>
                <option value="<?php echo get_text($key); ?>" <?php echo get_selected($metric_key, $key); ?>><?php echo get_text($item['label']); ?></option>
                <?php } ?>
            </select>
            <select name="grade_group">
                <?php foreach ($grades as $key => $label) { ?>
                <option value="<?php echo get_text($key); ?>" <?php echo get_selected($grade_group, $key); ?>><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <select name="gender">
                <?php foreach ($gender_options as $key => $label) { ?>
                <option value="<?php echo get_text($key); ?>" <?php echo get_selected($gender, $key); ?>><?php echo get_text(ieum_fitness_standard_display_label($label)); ?></option>
                <?php } ?>
            </select>
            <button type="submit" class="btn primary">기준 조회</button>
        </form>
    </section>

    <section class="panel standard-flow">
        <div class="step"><b>1. 실제 수치 입력</b><span>지도진은 줄넘기 횟수, 셔틀런 횟수, cm처럼 측정값만 입력합니다.</span></div>
        <div class="step"><b>2. 기준표 자동 환산</b><span>학년/성별/항목 기준에 따라 자동점수와 표시 문구가 정해집니다.</span></div>
        <div class="step"><b>3. 리포트 반영</b><span>학부모 화면에는 점수보다 구간, 성장 방향, 보강 포인트 중심으로 표시합니다.</span></div>
    </section>

    <section class="panel guide">
        <article class="guide-card"><span>항목</span><strong><?php echo get_text($selected_item['label']); ?></strong></article>
        <article class="guide-card"><span>대상</span><strong><?php echo get_text($grades[$grade_group] . ' · ' . ieum_fitness_standard_display_label($gender_options[$gender])); ?></strong></article>
        <article class="guide-card feature"><span>우수 기준 목표</span><strong><?php echo get_text($reference_target); ?></strong></article>
        <article class="guide-card"><span>단위</span><strong><?php echo get_text($selected_item['unit'] !== '' ? $selected_item['unit'] : '범위'); ?></strong></article>
        <article class="guide-card"><span>판정 방향</span><strong><?php echo get_text($direction_label); ?></strong></article>
    </section>

    <form method="post" class="panel">
        <input type="hidden" name="ieum_token" value="<?php echo get_text($token); ?>">
        <input type="hidden" name="metric_key" value="<?php echo get_text($metric_key); ?>">
        <input type="hidden" name="grade_group" value="<?php echo get_text($grade_group); ?>">
        <input type="hidden" name="gender" value="<?php echo get_text($gender); ?>">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>단계</th>
                        <th>측정값 최소</th>
                        <th>측정값 최대</th>
                        <th>환산점수 최소</th>
                        <th>환산점수 최대</th>
                        <th>표시 문구</th>
                        <th>출처/메모</th>
                        <th>기준 상태</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (range(1, 5) as $level_no) {
                    $row = isset($rows[$level_no]) ? $rows[$level_no] : array();
                ?>
                    <tr>
                        <td class="level"><?php echo $level_no; ?>단계</td>
                        <td>
                            <input type="number" step="0.01" name="standards[<?php echo $level_no; ?>][min_value]" value="<?php echo get_text(isset($row['min_value']) && $row['min_value'] !== null ? ieum_fitness_standard_display_number($row['min_value']) : ''); ?>" placeholder="없음">
                            <div class="range-note">비우면 하한 없음</div>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="standards[<?php echo $level_no; ?>][max_value]" value="<?php echo get_text(isset($row['max_value']) && $row['max_value'] !== null ? ieum_fitness_standard_display_number($row['max_value']) : ''); ?>" placeholder="없음">
                            <div class="range-note">비우면 상한 없음</div>
                        </td>
                        <td><input type="number" min="0" max="100" name="standards[<?php echo $level_no; ?>][score_min]" value="<?php echo get_text(isset($row['score_min']) ? (int) $row['score_min'] : 0); ?>"></td>
                        <td><input type="number" min="0" max="100" name="standards[<?php echo $level_no; ?>][score_max]" value="<?php echo get_text(isset($row['score_max']) ? (int) $row['score_max'] : 0); ?>"></td>
                        <td><input type="text" name="standards[<?php echo $level_no; ?>][label]" value="<?php echo get_text(isset($row['label']) ? $row['label'] : $level_no . '단계'); ?>"></td>
                        <td><input type="text" name="standards[<?php echo $level_no; ?>][source]" value="<?php echo get_text(isset($row['source']) ? $row['source'] : '도장 맞춤 기준'); ?>"></td>
                        <td>
                            <?php if (isset($row['is_custom']) && (int) $row['is_custom']) { ?>
                            <span class="source-chip custom">도장 맞춤</span>
                            <?php } else { ?>
                            <span class="source-chip">기본 기준</span>
                            <div class="small"><?php echo get_text((isset($grades[$row['source_grade_group']]) ? $grades[$row['source_grade_group']] : '전체') . ' · ' . ieum_fitness_standard_display_label(isset($gender_options[$row['source_gender']]) ? $gender_options[$row['source_gender']] : '공통')); ?></div>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="hint">
            지도진은 실제 측정값만 입력합니다. 이 기준표가 줄넘기 횟수, 셔틀런 횟수, BMI 같은 숫자를 자동점수와 성장 흐름으로 바꿉니다.
            BMI는 학부모 화면에서 점수보다 성장 균형 문구 중심으로 표시됩니다.
        </p>
        <div class="actions">
            <button type="submit" name="action" value="save" class="btn primary">기준 저장</button>
            <button type="submit" name="action" value="reset" class="btn danger" onclick="return confirm('이 도장의 맞춤 기준을 삭제하고 기본 기준으로 되돌릴까요?');">기본 기준으로 되돌리기</button>
        </div>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.fitness-standards-page-tune.ieum-dashboard-page';
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
</body>
</html>

