<?php
$sub_menu = '950147';
require_once './_common.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $cycle = isset($_POST['promotion_interval_months']) ? (int) $_POST['promotion_interval_months'] : 3;
        if ($cycle < 1 || $cycle > 4) {
            $cycle = 3;
        }
        $notice_days = isset($_POST['promotion_notice_days']) ? (int) $_POST['promotion_notice_days'] : 31;
        if ($notice_days < 0) {
            $notice_days = 0;
        } elseif ($notice_days > 120) {
            $notice_days = 120;
        }
        $payment_account = isset($_POST['promotion_payment_account']) ? trim((string) $_POST['promotion_payment_account']) : '';
        $payment_account = preg_replace('/[\r\n\t]+/u', ' ', $payment_account);
        if (function_exists('mb_substr')) {
            $payment_account = mb_substr($payment_account, 0, 160, 'UTF-8');
        } else {
            $payment_account = substr($payment_account, 0, 160);
        }
        $certificate_template = isset($_POST['promotion_certificate_template']) ? trim($_POST['promotion_certificate_template']) : 'official';
        if (!in_array($certificate_template, array('official', 'classic', 'clean'), true)) {
            $certificate_template = 'official';
        }
        $certificate_no_rule = isset($_POST['promotion_certificate_no_rule']) ? trim($_POST['promotion_certificate_no_rule']) : 'ieum';
        if (!in_array($certificate_no_rule, array('ieum', 'academy_month', 'custom_prefix'), true)) {
            $certificate_no_rule = 'ieum';
        }
        $certificate_no_prefix = isset($_POST['promotion_certificate_no_prefix']) ? trim($_POST['promotion_certificate_no_prefix']) : '';
        $certificate_no_prefix = preg_replace('/[\r\n\t]+/u', ' ', $certificate_no_prefix);
        if (function_exists('mb_substr')) {
            $certificate_no_prefix = mb_substr($certificate_no_prefix, 0, 40, 'UTF-8');
        } else {
            $certificate_no_prefix = substr($certificate_no_prefix, 0, 40);
        }
        $certificate_seal_path = isset($academy['promotion_certificate_seal_path']) ? trim((string) $academy['promotion_certificate_seal_path']) : '';
        if (!empty($_POST['delete_certificate_seal'])) {
            if ($certificate_seal_path !== '' && strpos($certificate_seal_path, '..') === false && is_file(G5_DATA_PATH . '/' . ltrim($certificate_seal_path, '/'))) {
                @unlink(G5_DATA_PATH . '/' . ltrim($certificate_seal_path, '/'));
            }
            $certificate_seal_path = '';
        }
        if (isset($_FILES['promotion_certificate_seal']) && is_array($_FILES['promotion_certificate_seal']) && isset($_FILES['promotion_certificate_seal']['error']) && (int) $_FILES['promotion_certificate_seal']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['promotion_certificate_seal']['error'] === UPLOAD_ERR_OK) {
                $original_name = isset($_FILES['promotion_certificate_seal']['name']) ? (string) $_FILES['promotion_certificate_seal']['name'] : '';
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
                    $error = '직인 이미지는 jpg, png, webp 파일만 사용할 수 있습니다.';
                } else {
                    $seal_dir = G5_DATA_PATH . '/ieum/promotion_seals';
                    if (!is_dir($seal_dir)) {
                        @mkdir($seal_dir, G5_DIR_PERMISSION, true);
                        @chmod($seal_dir, G5_DIR_PERMISSION);
                    }
                    $relative_path = 'ieum/promotion_seals/academy_' . $academy_id . '_' . date('YmdHis') . '.' . $ext;
                    $target_path = G5_DATA_PATH . '/' . $relative_path;
                    if (!move_uploaded_file($_FILES['promotion_certificate_seal']['tmp_name'], $target_path)) {
                        $error = '직인 이미지를 저장하지 못했습니다.';
                    } else {
                        @chmod($target_path, G5_FILE_PERMISSION);
                        if ($certificate_seal_path !== '' && strpos($certificate_seal_path, '..') === false && is_file(G5_DATA_PATH . '/' . ltrim($certificate_seal_path, '/'))) {
                            @unlink(G5_DATA_PATH . '/' . ltrim($certificate_seal_path, '/'));
                        }
                        $certificate_seal_path = $relative_path;
                    }
                }
            } else {
                $error = '직인 이미지 업로드 중 오류가 발생했습니다.';
            }
        }
        if (isset($_POST['belt_name']) && is_array($_POST['belt_name'])) {
            $belt_names = array();
            foreach ($_POST['belt_name'] as $belt_name) {
                $belt_name = trim($belt_name);
                if ($belt_name !== '') {
                    $belt_names[] = $belt_name;
                }
            }
            $belts = ieum_promotion_parse_belts(implode("\n", $belt_names));
        } else {
            $belts = ieum_promotion_parse_belts(isset($_POST['promotion_belts']) ? $_POST['promotion_belts'] : '');
        }
        $belts_text = implode("\n", $belts);
        if (isset($_POST['range_belt'], $_POST['range_from'], $_POST['range_to']) && is_array($_POST['range_belt'])) {
            $range_lines = array();
            foreach ($_POST['range_belt'] as $range_index => $range_belt) {
                $range_belt = trim($range_belt);
                $range_from = isset($_POST['range_from'][$range_index]) ? (int) $_POST['range_from'][$range_index] : 0;
                $range_to = isset($_POST['range_to'][$range_index]) ? (int) $_POST['range_to'][$range_index] : 0;
                if ($range_belt === '' || $range_from <= 0 || $range_to <= 0) {
                    continue;
                }
                $range_lines[] = $range_belt . '|' . $range_from . '|' . $range_to;
            }
            $belt_ranges = ieum_promotion_parse_belt_ranges(implode("\n", $range_lines));
        } else {
            $belt_ranges = ieum_promotion_parse_belt_ranges(isset($_POST['promotion_belt_ranges']) ? $_POST['promotion_belt_ranges'] : '');
        }
        $belt_ranges_text = ieum_promotion_format_belt_ranges($belt_ranges);
        $fee_rows = isset($_POST['fees']) && is_array($_POST['fees']) ? $_POST['fees'] : array();
        $saved_fee_count = 0;

        if ($error === '') {
            sql_query("
                update " . IEUM_ACADEMY_TABLE . "
                   set promotion_interval_months = '{$cycle}',
                       promotion_notice_days = '{$notice_days}',
                       promotion_belts = '" . sql_escape_string($belts_text) . "',
                       promotion_belt_ranges = '" . sql_escape_string($belt_ranges_text) . "',
                       promotion_certificate_template = '" . sql_escape_string($certificate_template) . "',
                       promotion_certificate_no_rule = '" . sql_escape_string($certificate_no_rule) . "',
                       promotion_certificate_no_prefix = '" . sql_escape_string($certificate_no_prefix) . "',
                       promotion_certificate_seal_path = '" . sql_escape_string($certificate_seal_path) . "',
                       promotion_payment_account = '" . sql_escape_string($payment_account) . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
            ");
            if ($fee_rows) {
                $saved_fee_count = ieum_promotion_save_fee_rows($academy_id, $fee_rows);
            }
            $message = '승급 설정을 저장했습니다.';
            if ($saved_fee_count > 0) {
                $message .= ' 심사비 ' . number_format($saved_fee_count) . '건도 함께 저장했습니다.';
            }
        }
    }
}

$academy = sql_fetch("select * from " . IEUM_ACADEMY_TABLE . " where academy_id = '{$academy_id}'", false);
$csrf_token = ieum_new_csrf_token();
$belts = ieum_promotion_belts($academy);
$belt_ranges = ieum_promotion_belt_ranges($academy);
$cycle = ieum_promotion_cycle_months($academy);
$notice_days = isset($academy['promotion_notice_days']) ? (int) $academy['promotion_notice_days'] : 31;
$certificate_template = ieum_promotion_certificate_template($academy);
$certificate_no_rule = ieum_promotion_certificate_no_rule($academy);
$certificate_no_prefix = ieum_promotion_certificate_no_prefix($academy);
$certificate_seal_url = ieum_promotion_certificate_seal_url($academy);
$payment_account = ieum_promotion_payment_account($academy, false);
$fee_map = ieum_promotion_get_fee_map($academy_id);
$fee_setting_rows = ieum_promotion_fee_setting_rows($academy);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:30px}.meta{color:#667085;margin-bottom:18px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.grid{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start}.field-title{font-weight:900;color:#111827}.help{color:#667085;font-size:13px;line-height:1.6;margin-top:6px}
select,input,textarea{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:11px;font-size:15px;background:#fff}textarea{min-height:180px;line-height:1.6;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #1769c2;border-radius:8px;background:#1769c2;color:#fff;text-decoration:none;padding:9px 14px;font-weight:900;cursor:pointer}
.preview{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.belt-chip{display:inline-flex;align-items:center;min-height:34px;border:1px solid #d9dee7;border-radius:999px;background:#f8fafc;padding:6px 12px;font-weight:900}
.belt-editor{display:grid;gap:8px}.belt-row{display:grid;grid-template-columns:44px minmax(160px,1fr) auto;gap:8px;align-items:center;border:1px solid #d9dee7;border-radius:12px;background:#f8fafc;padding:10px}.belt-order{width:34px;height:34px;border-radius:999px;background:#1769c2;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:1000}.belt-actions{display:flex;gap:4px}.mini-btn{min-width:34px;min-height:34px;border:1px solid #cfd6df;border-radius:8px;background:#fff;font-weight:1000;cursor:pointer}.belt-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.range-preview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}.range-card{border:1px solid #d9dee7;border-radius:10px;background:#f8fafc;padding:10px}.range-card b{display:block;font-size:15px}.range-card span{display:block;color:#667085;font-size:12px;margin-top:3px}
.range-editor{display:grid;gap:10px}.range-row{display:grid;grid-template-columns:minmax(150px,1fr) 130px 130px auto;gap:8px;align-items:end;border:1px solid #d9dee7;border-radius:12px;background:#f8fafc;padding:12px}.range-row label{display:block;color:#667085;font-size:12px;font-weight:900;margin-bottom:5px}.range-row input{background:#fff}.btn.secondary{background:#fff;color:#1769c2}.btn.ghost{background:#fff;border-color:#d9dee7;color:#475569}.btn.danger{background:#fff;border-color:#fca5a5;color:#b91c1c}.range-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.range-sample{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.range-sample span{border:1px solid #f4c27a;border-radius:999px;background:#fffaf0;padding:7px 11px;font-weight:900;color:#7c4a03}
.info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.info-card{border:1px solid #d9dee7;border-radius:10px;background:#f8fbff;padding:14px}.info-card b{display:block;font-size:20px;margin-top:4px}
@media(max-width:900px){.range-preview{grid-template-columns:repeat(2,minmax(0,1fr))}.range-row{grid-template-columns:1fr 1fr}}@media(max-width:800px){.grid,.info,.range-preview{grid-template-columns:1fr}.belt-row{grid-template-columns:40px 1fr}}
.grid{grid-template-columns:1fr 1fr}.grid>div:nth-child(3),.grid>div:nth-child(5){display:none}.grid>div:nth-child(4),.grid>div:nth-child(6){border:1px solid #d9dee7;border-radius:14px;background:#fbfdff;padding:16px;box-shadow:0 8px 18px rgba(15,23,42,.04)}.grid>div:nth-child(4){grid-column:1;grid-row:2}.grid>div:nth-child(6){grid-column:2;grid-row:2}.grid>div:nth-child(4)::before,.grid>div:nth-child(6)::before{display:block;font-size:22px;font-weight:1000;margin-bottom:6px;color:#111827}.grid>div:nth-child(4)::before{content:"띠 순서"}.grid>div:nth-child(6)::before{content:"띠별 급 범위"}.grid>div:nth-child(4)::after{content:"위/아래 버튼으로 실제 승급 흐름을 정합니다.";display:block;color:#667085;font-size:13px;margin:6px 0 12px}.grid>div:nth-child(6)::after{content:"예: 흰띠 18급-16급처럼 각 띠의 급 구간을 정합니다.";display:block;color:#667085;font-size:13px;margin:6px 0 12px}.grid>div:nth-child(7){grid-column:1;grid-row:3}.grid>input[name="promotion_notice_days"]{grid-column:2;grid-row:3}.config-save{margin-top:12px;width:100%}
@media(max-width:1000px){.grid{grid-template-columns:1fr}.grid>div:nth-child(4),.grid>div:nth-child(6),.grid>div:nth-child(7),.grid>input[name="promotion_notice_days"]{grid-column:1;grid-row:auto}}
.wrap{max-width:1280px}.info{margin-bottom:14px}.info-card{background:#fff}.panel{padding:18px}.grid{gap:14px}.grid>div:nth-child(1){grid-column:1;grid-row:1;border:1px solid #d9dee7;border-radius:14px;background:#fff;padding:16px}.grid>select[name="promotion_interval_months"]{grid-column:2;grid-row:1;align-self:center}.grid>div:nth-child(4),.grid>div:nth-child(6){position:relative;background:#fff;padding:56px 16px 16px;overflow:hidden}.grid>div:nth-child(4)::before,.grid>div:nth-child(6)::before{position:absolute;left:18px;top:18px;margin:0;font-size:22px}.grid>div:nth-child(4)::after,.grid>div:nth-child(6)::after{position:absolute;left:18px;right:100px;top:48px;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.config-save{position:absolute;right:16px;top:16px;width:auto;min-width:74px;margin:0;min-height:36px;padding:7px 13px}.belt-row{grid-template-columns:38px minmax(120px,1fr) auto;padding:8px 10px;background:#fff}.belt-order{width:30px;height:30px;font-size:13px}.belt-row input,.range-row input{min-height:38px;padding:8px 10px}.mini-btn{min-width:32px;min-height:32px}.belt-toolbar,.range-toolbar{margin-top:10px}.range-row{grid-template-columns:minmax(130px,1fr) 96px 96px 64px;padding:10px;background:#fff}.range-row label{font-size:11px}.range-preview,.preview{display:none}.grid>div:nth-child(7){border:1px solid #d9dee7;border-radius:14px;background:#fff;padding:16px}.grid>input[name="promotion_notice_days"]{align-self:center}
@media(max-width:1000px){.wrap{max-width:100%}.grid>div:nth-child(1),.grid>select[name="promotion_interval_months"],.grid>div:nth-child(4),.grid>div:nth-child(6),.grid>div:nth-child(7),.grid>input[name="promotion_notice_days"]{grid-column:1;grid-row:auto}.grid>div:nth-child(4)::after,.grid>div:nth-child(6)::after{position:static;display:block;margin:6px 0 12px;white-space:normal}.grid>div:nth-child(4),.grid>div:nth-child(6){padding-top:56px}.range-row{grid-template-columns:1fr 1fr}.range-row .btn{grid-column:1 / -1}}
.wrap{max-width:1240px}
.info{grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}
.info-card{border-color:#e1e7f0;border-radius:14px;padding:12px 14px;box-shadow:none;background:#fff}
.info-card span{display:block;color:#667085;font-size:13px;font-weight:800}
.info-card b{font-size:22px;line-height:1.2}
form.panel{padding:18px}
.certificate-options{grid-column:1 / -1;border:1px solid #dfe6f0;border-radius:16px;background:#fff;padding:18px;margin-top:4px;box-shadow:0 8px 18px rgba(15,23,42,.035)}
.certificate-options-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}
.certificate-options-head h2{margin:0;font-size:22px}
.certificate-options-head p{margin:5px 0 0;color:#667085;font-size:14px;line-height:1.5}
.certificate-grid{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:14px}
.certificate-box{border:1px solid #e3e8f1;border-radius:14px;background:#f8fafc;padding:14px}
.certificate-box h3{margin:0 0 10px;font-size:16px}
.template-options,.rule-options{display:grid;gap:8px}
.option-card{display:grid;grid-template-columns:auto 1fr;gap:9px;align-items:flex-start;border:1px solid #d9e2ef;border-radius:12px;background:#fff;padding:11px;cursor:pointer}
.option-card input{width:auto;margin-top:3px}
.option-card strong{display:block;font-size:14px}
.option-card span{display:block;color:#667085;font-size:12px;line-height:1.45;margin-top:2px}
.seal-preview{display:flex;align-items:center;gap:12px;border:1px dashed #cbd5e1;border-radius:14px;background:#fff;padding:12px;margin-bottom:10px}
.seal-preview img{width:68px;height:68px;object-fit:contain;border-radius:999px;background:#fff}
.seal-placeholder{width:68px;height:68px;border:2px solid #b91c1c;border-radius:999px;color:#b91c1c;display:flex;align-items:center;justify-content:center;font-weight:1000;transform:rotate(-8deg)}
.seal-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}.seal-guide{width:100%;margin:0}
.inline-check{display:inline-flex;align-items:center;gap:6px;color:#475569;font-weight:800}.inline-check input{width:auto}
.certificate-save{margin-top:14px}
.payment-options{grid-column:1 / -1;border:1px solid #dfe6f0;border-radius:16px;background:#fff;padding:16px;margin-top:4px;box-shadow:0 8px 18px rgba(15,23,42,.035)}
.payment-options h2{margin:0 0 6px;font-size:20px}.payment-options p{margin:0 0 12px;color:#667085;font-size:14px;line-height:1.5}.payment-line{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.payment-line .btn{white-space:nowrap}
.fee-setting-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-top:16px;padding-top:16px;border-top:1px solid #e5eaf2}.fee-setting-head h3{margin:0;font-size:18px}.fee-setting-head p{margin:4px 0 0}.fee-grid.compact{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;max-height:360px;overflow:auto;padding-right:4px}.fee-card{border:1px solid #e3e8f1;border-radius:12px;background:#f8fafc;padding:10px}.fee-card strong{display:block;margin-bottom:7px;font-size:14px}.fee-card .line{display:grid;grid-template-columns:1fr 1fr;gap:7px}.fee-card input{min-height:36px;border-radius:8px;font-size:13px;padding:7px 9px}
.grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.grid>div:nth-child(1){grid-column:1;grid-row:1;border:0;background:#f8fafc;padding:14px 16px;box-shadow:none}
.grid>select[name="promotion_interval_months"]{grid-column:2;grid-row:1;max-width:260px;align-self:center}
.grid>div:nth-child(4),.grid>div:nth-child(6){position:relative;grid-row:2;background:#fff;border:1px solid #dfe6f0;border-radius:16px;padding:64px 16px 16px;box-shadow:0 8px 18px rgba(15,23,42,.035)}
.grid>div:nth-child(4){grid-column:1}
.grid>div:nth-child(6){grid-column:2}
.grid>div:nth-child(4)::before,.grid>div:nth-child(6)::before{position:absolute;left:18px;top:18px;margin:0;color:#111827;font-size:20px;line-height:1.2}
.grid>div:nth-child(4)::before{content:"\B760 \C21C\C11C"}
.grid>div:nth-child(6)::before{content:"\B760\BCC4 \AE09 \BC94\C704"}
.grid>div:nth-child(4)::after,.grid>div:nth-child(6)::after{display:none}
.config-save{position:absolute;right:16px;top:16px;min-height:34px;min-width:66px;padding:7px 13px;border-radius:999px}
.belt-editor,.range-editor{gap:8px}
.belt-row{grid-template-columns:34px minmax(140px,1fr) 104px;gap:8px;border-color:#e3e8f1;border-radius:10px;background:#fff;padding:8px}
.belt-order{width:28px;height:28px;background:#eef4ff;color:#1769c2;font-size:13px}
.belt-actions{justify-content:flex-end}
.mini-btn{min-width:30px;min-height:30px;border-radius:8px;color:#334155}
.range-row{grid-template-columns:minmax(150px,1fr) 80px 80px 58px;gap:8px;border-color:#e3e8f1;border-radius:10px;background:#fff;padding:8px}
.range-row label{font-size:11px;margin-bottom:3px;color:#667085}
.belt-row input,.range-row input{min-height:36px;border-radius:8px;font-size:14px;padding:7px 9px}
.belt-toolbar,.range-toolbar{margin-top:10px}
.btn.secondary,.btn.ghost{min-height:36px;border-radius:999px;padding:7px 12px}
.btn.danger{min-height:36px;border-radius:8px;padding:7px 10px}
.grid>div:nth-child(7){grid-column:1;grid-row:3;border:0;background:#f8fafc;padding:14px 16px;box-shadow:none}
.grid>input[name="promotion_notice_days"]{grid-column:2;grid-row:3;max-width:260px;align-self:center}
form.panel>p:last-child{display:none}
@media(max-width:1200px){.fee-grid.compact{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1000px){.info{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.grid>div:nth-child(1),.grid>select[name="promotion_interval_months"],.grid>div:nth-child(4),.grid>div:nth-child(6),.grid>div:nth-child(7),.grid>input[name="promotion_notice_days"]{grid-column:1;grid-row:auto;max-width:none}.belt-row{grid-template-columns:34px minmax(100px,1fr);gap:8px}.belt-actions{grid-column:2;justify-content:flex-start}.range-row{grid-template-columns:1fr 1fr}.certificate-options,.payment-options{grid-column:1}.certificate-options-head{display:block}.certificate-grid{grid-template-columns:1fr}.payment-line{grid-template-columns:1fr}.fee-grid.compact{grid-template-columns:1fr}}
body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .ieum-side{width:260px!important;background:#fff!important;border-right:1px solid #e5e7eb!important;box-shadow:none!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .ieum-right-rail{display:none!important}
.promotion-settings-page-tune .side-nav{padding:0 14px 22px!important}
.promotion-settings-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.promotion-settings-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.promotion-settings-page-tune .ieum-shell-top{left:260px!important;right:0!important;height:64px!important;background:#fff!important;border-bottom:1px solid #eef2f7!important;color:#0f172a!important;box-shadow:none!important}
.promotion-settings-page-tune .ieum-shell-link,
.promotion-settings-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.promotion-settings-page-tune .ieum-shell-link::before{display:none!important}
.promotion-settings-page-tune .ieum-shell-meta{color:#0f172a!important}
.promotion-settings-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.promotion-settings-page-tune .dashboard-shell-divider,
.promotion-settings-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .wrap{max-width:none!important;width:auto!important;margin:0 0 0 260px!important;padding:96px 40px 42px!important}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune{--ieum-side-width:0px}
    .promotion-settings-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.promotion-settings-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page promotion-settings-page-tune">
<?php echo ieum_admin_header('promotion_settings', 'side'); ?>
<main class="wrap">
    <h1>승급 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 도장 기본 띠 순서와 승급 주기를 정합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="info">
        <article class="info-card"><span>기본 승급 주기</span><b><?php echo (int) $cycle; ?>개월</b></article>
        <article class="info-card"><span>등록된 띠 단계</span><b><?php echo number_format(count($belts)); ?>개</b></article>
        <article class="info-card"><span>대상자 사전 확인</span><b><?php echo (int) $notice_days; ?>일 전</b></article>
    </section>

    <form method="post" class="panel" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="grid">
            <div>
                <div class="field-title">승급 주기</div>
                <p class="help">원생별 예외가 없으면 이 기본값으로 다음 승급 예정일을 계산합니다.</p>
            </div>
            <select name="promotion_interval_months">
                <?php for ($i = 1; $i <= 4; $i++) { ?>
                <option value="<?php echo $i; ?>" <?php echo get_selected($cycle, $i); ?>><?php echo $i; ?>개월마다</option>
                <?php } ?>
            </select>

            <div>
                <div class="field-title">띠 진행 순서</div>
                <p class="help">쉼표 또는 줄바꿈으로 입력합니다. 도장별로 흰/노란띠, 노랑띠처럼 이름을 자유롭게 바꿀 수 있습니다.</p>
            </div>
            <div>
                <div class="belt-editor" id="beltOrderEditor">
                    <?php foreach ($belts as $belt_index => $belt) { ?>
                    <div class="belt-row">
                        <span class="belt-order"><?php echo (int) $belt_index + 1; ?></span>
                        <input type="text" name="belt_name[]" value="<?php echo get_text($belt); ?>" placeholder="예: 흰띠">
                        <div class="belt-actions">
                            <button type="button" class="mini-btn js-belt-up" aria-label="위로">↑</button>
                            <button type="button" class="mini-btn js-belt-down" aria-label="아래로">↓</button>
                            <button type="button" class="mini-btn js-belt-remove" aria-label="삭제">×</button>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <div class="belt-toolbar">
                    <button type="button" class="btn secondary" id="addBeltOrder">+ 띠 추가</button>
                </div>
                <textarea name="promotion_belts" style="display:none"><?php echo get_text(implode("\n", $belts)); ?></textarea>
                <div class="preview">
                    <?php foreach ($belts as $belt) { ?><span class="belt-chip"><?php echo get_text($belt); ?></span><?php } ?>
                </div>
            </div>

            <div>
                <div class="field-title">띠별 급 범위</div>
                <p class="help">한 줄에 <b>띠|시작급|끝급</b> 형식으로 적습니다. 예: 흰띠|18|16, 노란띠|15|13. 승급 대상 화면에서 이번 달 필요한 띠 수량도 이 기준으로 계산합니다.</p>
            </div>
            <div>
                <div class="range-editor" id="beltRangeEditor">
                    <?php foreach ($belt_ranges as $range) { ?>
                    <div class="range-row">
                        <div>
                            <label>띠 이름</label>
                            <input type="text" name="range_belt[]" value="<?php echo get_text($range['belt']); ?>" placeholder="예: 흰띠">
                        </div>
                        <div>
                            <label>시작 급</label>
                            <input type="number" name="range_from[]" value="<?php echo (int) $range['from']; ?>" min="1" max="18">
                        </div>
                        <div>
                            <label>끝 급</label>
                            <input type="number" name="range_to[]" value="<?php echo (int) $range['to']; ?>" min="1" max="18">
                        </div>
                        <button type="button" class="btn danger js-remove-range">삭제</button>
                    </div>
                    <?php } ?>
                </div>
                <div class="range-toolbar">
                    <button type="button" class="btn secondary" id="addBeltRange">+ 범위 추가</button>
                    <button type="button" class="btn ghost" id="resetDefaultRanges">기본값 불러오기</button>
                </div>
                <textarea name="promotion_belt_ranges" style="display:none"><?php echo get_text(ieum_promotion_format_belt_ranges($belt_ranges)); ?></textarea>
                <div class="range-preview">
                    <?php foreach ($belt_ranges as $range) { ?>
                    <span class="range-card"><b><?php echo get_text($range['belt']); ?></b><span><?php echo (int) $range['from']; ?>급-<?php echo (int) $range['to']; ?>급</span></span>
                    <?php } ?>
                </div>
            </div>

            <div>
                <div class="field-title">대상자 사전 표시</div>
                <p class="help">승급 예정일이 가까운 원생을 대상자 화면에서 미리 보이게 하는 기준입니다.</p>
            </div>
            <input type="number" name="promotion_notice_days" value="<?php echo (int) $notice_days; ?>" min="0" max="120">

            <section class="payment-options">
                <h2>심사비 입금 계좌</h2>
                <p>심사 안내문, 문자/카톡 발송 문구에 함께 들어갈 도장 계좌입니다. 예: 국민 000000-00-000000 아이이음태권도</p>
                <div class="payment-line">
                    <input type="text" name="promotion_payment_account" value="<?php echo get_text($payment_account); ?>" placeholder="예: 국민 000000-00-000000 아이이음태권도">
                    <button type="submit" class="btn">계좌 저장</button>
                </div>
                <div class="fee-setting-head">
                    <div>
                        <h3>심사비 설정</h3>
                        <p>띠별 급 범위와 연결되는 비용입니다. 심사 안내문, 선택 문자/카톡, 선택 인쇄에 자동 반영됩니다.</p>
                    </div>
                    <button type="submit" class="btn">심사비 저장</button>
                </div>
                <div class="fee-grid compact">
                    <?php foreach ($fee_setting_rows as $idx => $fee_row) {
                        $fee_key = ieum_promotion_fee_key($fee_row['exam_type'], $fee_row);
                        $fee_saved = isset($fee_map[$fee_key]) ? $fee_map[$fee_key] : array('fee_amount' => 0, 'title' => '');
                    ?>
                    <div class="fee-card">
                        <strong><?php echo get_text($fee_row['label']); ?></strong>
                        <input type="hidden" name="fees[<?php echo (int) $idx; ?>][exam_type]" value="<?php echo get_text($fee_row['exam_type']); ?>">
                        <input type="hidden" name="fees[<?php echo (int) $idx; ?>][poom_dan]" value="<?php echo (int) $fee_row['poom_dan']; ?>">
                        <input type="hidden" name="fees[<?php echo (int) $idx; ?>][grade_level]" value="<?php echo (int) $fee_row['grade_level']; ?>">
                        <input type="hidden" name="fees[<?php echo (int) $idx; ?>][belt]" value="<?php echo get_text($fee_row['belt']); ?>">
                        <div class="line">
                            <input type="number" name="fees[<?php echo (int) $idx; ?>][amount]" value="<?php echo (int) $fee_saved['fee_amount']; ?>" min="0" placeholder="심사비">
                            <input type="text" name="fees[<?php echo (int) $idx; ?>][title]" value="<?php echo get_text($fee_saved['title']); ?>" placeholder="표시명">
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </section>

            <section class="certificate-options">
                <div class="certificate-options-head">
                    <div>
                        <h2>승급증 설정</h2>
                        <p>도장 직인, 승급증 디자인, 발급번호 규칙을 정합니다. 저장 후 승급증 인쇄 화면에 바로 반영됩니다.</p>
                    </div>
                    <button type="submit" class="btn certificate-save">승급증 설정 저장</button>
                </div>
                <div class="certificate-grid">
                    <article class="certificate-box">
                        <h3>직인 이미지</h3>
                        <div class="seal-preview">
                            <?php if ($certificate_seal_url !== '') { ?>
                            <img src="<?php echo get_text($certificate_seal_url); ?>" alt="등록된 직인">
                            <div>
                                <strong>등록된 직인을 사용합니다.</strong>
                                <p class="help">새 파일을 선택하고 저장하면 기존 직인이 교체됩니다.</p>
                            </div>
                            <?php } else { ?>
                            <span class="seal-placeholder">직인</span>
                            <div>
                                <strong>기본 직인 표시</strong>
                                <p class="help">도장 직인을 올리면 승급증에 실제 직인이 들어갑니다.</p>
                            </div>
                            <?php } ?>
                        </div>
                        <input type="file" name="promotion_certificate_seal" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="seal-actions">
                            <p class="help seal-guide">실사용자는 도장 직인 이미지를 선택한 뒤 <b>승급증 설정 저장</b>을 누르면 됩니다. 권장: 투명 배경 PNG, 정사각형 500px 이상. JPG/PNG/WebP도 사용할 수 있습니다.</p>
                            <label class="inline-check"><input type="checkbox" name="delete_certificate_seal" value="1"> 등록 직인 삭제</label>
                        </div>
                    </article>
                    <article class="certificate-box">
                        <h3>승급증 디자인</h3>
                        <div class="template-options">
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_template" value="official" <?php echo get_checked($certificate_template, 'official'); ?>>
                                <span><strong>공식 금장</strong><span>현재 기본 템플릿. 공문서 느낌의 금장 테두리입니다.</span></span>
                            </label>
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_template" value="classic" <?php echo get_checked($certificate_template, 'classic'); ?>>
                                <span><strong>클래식 문양</strong><span>조금 더 전통적인 배경 문양과 진한 테두리를 사용합니다.</span></span>
                            </label>
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_template" value="clean" <?php echo get_checked($certificate_template, 'clean'); ?>>
                                <span><strong>심플 고급</strong><span>프린터 잉크를 아끼는 깔끔한 고급형입니다.</span></span>
                            </label>
                        </div>
                    </article>
                    <article class="certificate-box">
                        <h3>발급번호</h3>
                        <div class="rule-options">
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_no_rule" value="ieum" <?php echo get_checked($certificate_no_rule, 'ieum'); ?>>
                                <span><strong>아이이음 기본</strong><span>IEUM-년월-도장번호-발급번호</span></span>
                            </label>
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_no_rule" value="academy_month" <?php echo get_checked($certificate_no_rule, 'academy_month'); ?>>
                                <span><strong>도장 월별</strong><span>도장코드-년월-발급번호</span></span>
                            </label>
                            <label class="option-card">
                                <input type="radio" name="promotion_certificate_no_rule" value="custom_prefix" <?php echo get_checked($certificate_no_rule, 'custom_prefix'); ?>>
                                <span><strong>직접 접두어</strong><span>아래 접두어로 발급번호를 시작합니다.</span></span>
                            </label>
                        </div>
                        <input type="text" name="promotion_certificate_no_prefix" value="<?php echo get_text($certificate_no_prefix); ?>" placeholder="예: IEUMTKD" style="margin-top:10px">
                    </article>
                </div>
            </section>
        </div>
        <p style="margin:18px 0 0"><button type="submit" class="btn">설정 저장</button></p>
    </form>
</main>
<script>
(function(){
    var rootSelector = '.promotion-settings-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) homeLink.textContent = '아이이음 교육페이지';
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
(function () {
    var editor = document.getElementById('beltRangeEditor');
    var beltEditor = document.getElementById('beltOrderEditor');
    var beltPanel = beltEditor ? beltEditor.closest('.grid > div') : null;
    var rangePanel = editor ? editor.closest('.grid > div') : null;
    var addBeltButton = document.getElementById('addBeltOrder');
    var addButton = document.getElementById('addBeltRange');
    var resetButton = document.getElementById('resetDefaultRanges');
    var defaults = [
        ['흰띠', 18, 16],
        ['노란띠', 15, 13],
        ['초록띠', 12, 10],
        ['파란띠', 9, 7],
        ['빨간띠', 6, 4],
        ['품띠', 3, 1]
    ];

    function addPanelSave(panel) {
        if (!panel || panel.querySelector('.config-save')) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'submit';
        button.className = 'btn config-save';
        button.textContent = '저장';
        panel.appendChild(button);
    }

    addPanelSave(beltPanel);
    addPanelSave(rangePanel);

    function refreshBeltOrder() {
        beltEditor.querySelectorAll('.belt-row').forEach(function (row, index) {
            row.querySelector('.belt-order').textContent = index + 1;
        });
    }

    function addBeltRow(name) {
        var row = document.createElement('div');
        row.className = 'belt-row';
        row.innerHTML = [
            '<span class="belt-order"></span>',
            '<input type="text" name="belt_name[]" placeholder="예: 흰띠">',
            '<div class="belt-actions">',
            '<button type="button" class="mini-btn js-belt-up" aria-label="위로">↑</button>',
            '<button type="button" class="mini-btn js-belt-down" aria-label="아래로">↓</button>',
            '<button type="button" class="mini-btn js-belt-remove" aria-label="삭제">×</button>',
            '</div>'
        ].join('');
        row.querySelector('[name="belt_name[]"]').value = name || '';
        beltEditor.appendChild(row);
        refreshBeltOrder();
    }

    function addRow(belt, from, to) {
        var row = document.createElement('div');
        row.className = 'range-row';
        row.innerHTML = [
            '<div><label>띠 이름</label><input type="text" name="range_belt[]" placeholder="예: 흰띠"></div>',
            '<div><label>시작 급</label><input type="number" name="range_from[]" min="1" max="18"></div>',
            '<div><label>끝 급</label><input type="number" name="range_to[]" min="1" max="18"></div>',
            '<button type="button" class="btn danger js-remove-range">삭제</button>'
        ].join('');
        row.querySelector('[name="range_belt[]"]').value = belt || '';
        row.querySelector('[name="range_from[]"]').value = from || '';
        row.querySelector('[name="range_to[]"]').value = to || '';
        editor.appendChild(row);
    }

    beltEditor.addEventListener('click', function (event) {
        var row = event.target.closest('.belt-row');
        if (!row) {
            return;
        }
        if (event.target.classList.contains('js-belt-up') && row.previousElementSibling) {
            beltEditor.insertBefore(row, row.previousElementSibling);
            refreshBeltOrder();
        } else if (event.target.classList.contains('js-belt-down') && row.nextElementSibling) {
            beltEditor.insertBefore(row.nextElementSibling, row);
            refreshBeltOrder();
        } else if (event.target.classList.contains('js-belt-remove')) {
            if (beltEditor.querySelectorAll('.belt-row').length <= 1) {
                row.querySelector('input').value = '';
                return;
            }
            row.remove();
            refreshBeltOrder();
        }
    });

    addBeltButton.addEventListener('click', function () {
        addBeltRow('');
    });

    editor.addEventListener('click', function (event) {
        if (!event.target.classList.contains('js-remove-range')) {
            return;
        }
        if (editor.querySelectorAll('.range-row').length <= 1) {
            event.target.closest('.range-row').querySelectorAll('input').forEach(function (input) { input.value = ''; });
            return;
        }
        event.target.closest('.range-row').remove();
    });

    addButton.addEventListener('click', function () {
        addRow('', '', '');
    });
    resetButton.addEventListener('click', function () {
        if (!confirm('기본 띠 범위로 다시 채울까요? 현재 입력 내용은 화면에서 바뀝니다.')) {
            return;
        }
        editor.innerHTML = '';
        defaults.forEach(function (item) {
            addRow(item[0], item[1], item[2]);
        });
    });
})();
</script>
</body>
</html>
