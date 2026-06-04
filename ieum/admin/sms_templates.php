<?php
$sub_menu = '950165';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';

$g5['title'] = '아이이음 문자 템플릿';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';
$preview_message = '';
$preview_title = '';
$defaults = ieum_tuition_default_sms_templates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'settings') {
            $due_enabled = isset($_POST['due_notice_enabled']) ? 1 : 0;
            $overdue_enabled = isset($_POST['overdue_notice_enabled']) ? 1 : 0;
            $overdue_after_days = isset($_POST['overdue_after_days']) ? (int) $_POST['overdue_after_days'] : 5;
            $overdue_after_days = max(1, min(30, $overdue_after_days));
            sql_query("
                insert into " . IEUM_TUITION_SETTING_TABLE . "
                    set academy_id = '{$academy_id}',
                        due_notice_enabled = '{$due_enabled}',
                        overdue_notice_enabled = '{$overdue_enabled}',
                        overdue_after_days = '{$overdue_after_days}',
                        updated_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        due_notice_enabled = values(due_notice_enabled),
                        overdue_notice_enabled = values(overdue_notice_enabled),
                        overdue_after_days = values(overdue_after_days),
                        updated_at = values(updated_at)
            ");
            $message = '수련비 문자 자동발송 설정을 저장했습니다.';
        } elseif ($action === 'template' || $action === 'preview' || $action === 'test_queue') {
            $template_key = isset($_POST['template_key']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['template_key'])) : '';
            $title = isset($_POST['title']) ? trim($_POST['title']) : '';
            $body = isset($_POST['message']) ? trim($_POST['message']) : '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!isset($defaults[$template_key])) {
                $error = '템플릿 종류를 확인할 수 없습니다.';
            } elseif ($body === '') {
                $error = '문자 문구를 입력하세요.';
            } elseif ($action === 'preview' || $action === 'test_queue') {
                $sample = ieum_tuition_sample_payment($academy_id);
                if (!$sample) {
                    $error = '미리보기에 사용할 원생 또는 수련비 대상이 없습니다.';
                } else {
                    $preview_title = $title !== '' ? $title : $defaults[$template_key]['title'];
                    $preview_message = ieum_tuition_render_notice_message_from_template(
                        $body,
                        $sample['academy_name'],
                        $sample['student_name'],
                        $sample['billing_month'],
                        (int) $sample['amount_due'],
                        (int) $sample['amount_paid'],
                        $sample['due_date']
                    );

                    if ($action === 'test_queue') {
                        $recipients = ieum_tuition_notice_recipients($academy_id, (int) $sample['student_id']);
                        if (!$recipients) {
                            $error = '테스트 문자를 보낼 보호자 수련비 문자 수신 연락처가 없습니다.';
                        } else {
                            $sms_type = $template_key === 'tuition_overdue' ? 'tuition_overdue' : 'tuition_due';
                            $created = 0;
                            foreach ($recipients as $phone) {
                                if (ieum_create_direct_sms_queue($academy_id, $phone, $preview_message, $sms_type, (int) $sample['student_id'], 0)) {
                                    $created++;
                                }
                            }
                            $message = '테스트 문자 ' . number_format($created) . '건을 발송 준비했습니다. 실제 발송은 문자 발송폰이 실행 중일 때 진행됩니다.';
                        }
                    }
                }
            } else {
                if ($title === '') {
                    $title = $defaults[$template_key]['title'];
                }
                sql_query("
                    insert into " . IEUM_SMS_TEMPLATE_TABLE . "
                        set academy_id = '{$academy_id}',
                            template_key = '" . sql_escape_string($template_key) . "',
                            title = '" . sql_escape_string($title) . "',
                            message = '" . sql_escape_string($body) . "',
                            is_active = '{$is_active}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            title = values(title),
                            message = values(message),
                            is_active = values(is_active),
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '문자 템플릿을 저장했습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$settings = ieum_tuition_get_settings($academy_id);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.grid{display:grid;grid-template-columns:1fr 1fr 150px;gap:10px;align-items:center}.template{display:grid;gap:10px;border-top:1px solid #e2e8f0;padding-top:18px;margin-top:18px}input,textarea{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}textarea{min-height:100px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.tokens{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#344054;font-size:13px;line-height:1.6}.tokens code{background:#eef2f7;border-radius:4px;padding:2px 5px}.row{display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center}@media(max-width:800px){.grid,.row{grid-template-columns:1fr}}
</style>
<style>
.preview{background:#f0f7ff;border:1px solid #b9d7ff;border-radius:12px;padding:16px;margin-bottom:18px;white-space:pre-wrap;font-size:15px;line-height:1.6}.soft{background:#eef2f7}.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.template-actions{display:flex;gap:8px;flex-wrap:wrap}.template-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.template-head h3{margin:0;font-size:18px}.template-head p{margin:5px 0 0;color:#667085;font-size:13px}.template-use{display:inline-flex;align-items:center;gap:6px;border:1px solid #d9e3f2;border-radius:999px;background:#f8fbff;padding:7px 10px;font-size:13px;font-weight:800}input[type=checkbox]{width:auto}.setting-card{border:1px solid #e2e8f0;border-radius:12px;padding:16px;background:#fbfcff}.setting-card strong{display:block;margin-bottom:8px}.setting-card select{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.setting-help{margin:8px 0 0;color:#667085;font-size:13px;line-height:1.55}.template-guide{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}.tokens{border-radius:12px}.tokens-title{display:block;margin-bottom:8px;font-weight:900;color:#111827}.tokens-help{margin:8px 0 0;color:#667085;font-size:13px}.template textarea{border-radius:12px;line-height:1.55}.template .row{grid-template-columns:1fr}.template .row label{font-weight:900;color:#334155}.template-note{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:14px;color:#475467;font-size:14px;line-height:1.55}.template-note strong{display:block;color:#111827;margin-bottom:6px}@media(max-width:1000px){.template-guide{grid-template-columns:1fr}}
</style>
<style>
/* Dashboard shell alignment: SMS templates keep the dashboard navigation frame. */
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-brand{
    display:flex!important;
    height:144px!important;
    min-height:144px!important;
    padding:0 28px!important;
    background:#fff!important;
    color:#0f172a!important;
    font-size:29px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-menu>summary{
    min-height:42px!important;
    border-radius:6px!important;
    padding:0 12px!important;
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-menu>summary:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-nav-label{
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-nav-icon{
    width:18px!important;
    height:18px!important;
    color:#334155!important;
    opacity:1!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-sub{
    margin:2px 0 8px!important;
    padding:0 0 0 28px!important;
    background:transparent!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-sub a{
    min-height:34px!important;
    border-radius:6px!important;
    color:#475569!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .side-sub a.active{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    width:auto!important;
    height:64px!important;
    padding:0 40px!important;
    background:#fff!important;
    border-bottom:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-link{
    flex:0 0 auto!important;
    color:#0f172a!important;
    font-weight:900!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-meta{
    margin-left:auto!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-meta-inner{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-help-dot{
    color:#94a3b8!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-support-link{
    color:#0f172a!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-support-link:hover,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:4px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune h1{
    margin:0 0 8px!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .meta{
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .tokens,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .setting-card,
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .template-note{
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.sms-templates-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
</style>
<link rel="stylesheet" href="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.css?v=20260527b">
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page sms-templates-page-tune">
<?php echo ieum_admin_header('sms_templates', 'side'); ?>
<main class="wrap">
    <h1>문자 템플릿</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 수련비 납부/미납 문구와 자동발송 기준을 도장별로 관리합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <?php if ($preview_message) { ?>
    <section class="preview"><strong><?php echo get_text($preview_title); ?> 미리보기</strong>
<?php echo get_text($preview_message); ?></section>
    <?php } ?>

    <section class="panel">
        <h2>수련비 자동 문자 설정</h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="settings">
            <div class="setting-card">
                <strong>문자 안내</strong>
                <label><input type="checkbox" name="due_notice_enabled" value="1" <?php echo !empty($settings['due_notice_enabled']) ? 'checked' : ''; ?>> 납부일 당일 문자 안내</label><br>
                <label><input type="checkbox" name="overdue_notice_enabled" value="1" <?php echo !empty($settings['overdue_notice_enabled']) ? 'checked' : ''; ?>> 미납 문자 안내</label>
                <p class="setting-help">문자 안내는 문자 발송폰으로 보낼 수 있게 준비됩니다.</p>
            </div>
            <div class="setting-card">
                <strong>미납 기준</strong>
                <label>납부일 <input type="number" name="overdue_after_days" value="<?php echo (int) $settings['overdue_after_days']; ?>" min="1" max="30" style="width:78px">일 초과</label>
                <p class="setting-help">도장 상황에 맞게 1~30일 사이로 조정할 수 있습니다.</p>
            </div>
            <button type="submit" class="btn primary">설정 저장</button>
        </form>
    </section>

    <section class="panel">
        <h2>수련비 문자 기본 문구</h2>
        <div class="template-guide">
            <div class="tokens">
                <span class="tokens-title">자동으로 채워지는 항목</span>
                <code>{도장명}</code> 도장명 · <code>{원생명}</code> 원생명 · <code>{청구월}</code> 청구월 · <code>{청구액}</code> 청구액 · <code>{입금액}</code> 입금액 · <code>{잔액}</code> 잔액 · <code>{납부일}</code> 납부일
                <p class="tokens-help">문구 안에 넣어두면 원생별 발송 시 실제 정보로 자동 변경됩니다.</p>
            </div>
            <div class="template-note">
                <strong>현장에서 빠르게 쓰는 방법</strong>
                평소에는 이 기본 문구를 사용하고, 특정 계절 인사말이나 행사 안내는 수련비 납부 화면의 발송 팝업에서 바로 수정해 저장할 수 있습니다.
            </div>
        </div>
        <?php foreach ($defaults as $key => $default) { $template = ieum_tuition_get_sms_template($academy_id, $key); ?>
        <form method="post" class="template">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="template_key" value="<?php echo get_text($key); ?>">
            <div class="template-head">
                <div>
                    <h3><?php echo get_text($default['title']); ?></h3>
                    <p>보호자에게 보낼 기본 문구입니다. 실제 발송 전 팝업에서 한 번 더 확인할 수 있습니다.</p>
                </div>
                <label class="template-use"><input type="checkbox" name="is_active" value="1" <?php echo !empty($template['is_active']) ? 'checked' : ''; ?>> 사용</label>
            </div>
            <div class="row">
                <label>관리자용 제목</label>
                <input type="text" name="title" value="<?php echo get_text($template['title']); ?>">
            </div>
            <textarea name="message"><?php echo get_text($template['message']); ?></textarea>
            <div class="template-actions">
                <button type="submit" name="action" value="template" class="btn primary">기본 문구 저장</button>
                <button type="submit" name="action" value="preview" class="btn soft">예시 보기</button>
                <button type="submit" name="action" value="test_queue" class="btn danger send-confirm-trigger" data-send-title="테스트 문자 발송 확인" data-send-message="대표 보호자에게 테스트용 수련비 문자를 발송 준비합니다." data-send-count="1" data-send-summary="info:샘플 원생 1명 기준|warn:실제 발송은 문자 발송폰이 실행|ok:문구 확인용 테스트 문자">테스트 문자 발송</button>
            </div>
        </form>
        <?php } ?>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.sms-templates-page-tune.ieum-dashboard-page';
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
<script src="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.js?v=20260530d"></script>
</body>
</html>
