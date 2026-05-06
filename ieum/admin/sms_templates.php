<?php
$sub_menu = '950165';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';

$g5['title'] = '아이이음 문자 템플릿';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';
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
        } elseif ($action === 'template') {
            $template_key = isset($_POST['template_key']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['template_key'])) : '';
            $title = isset($_POST['title']) ? trim($_POST['title']) : '';
            $body = isset($_POST['message']) ? trim($_POST['message']) : '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!isset($defaults[$template_key])) {
                $error = '템플릿 종류를 확인할 수 없습니다.';
            } elseif ($body === '') {
                $error = '문자 문구를 입력하세요.';
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1120px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.grid{display:grid;grid-template-columns:1fr 1fr 150px;gap:10px;align-items:center}.template{display:grid;gap:10px;border-top:1px solid #e2e8f0;padding-top:18px;margin-top:18px}input,textarea{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}textarea{min-height:100px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.tokens{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;color:#344054;font-size:13px;line-height:1.6}.tokens code{background:#eef2f7;border-radius:4px;padding:2px 5px}.row{display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center}@media(max-width:800px){.grid,.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('sms_templates'); ?>
<main class="wrap">
    <h1>문자 템플릿</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 수련비 납부/미납 문구와 자동발송 기준을 도장별로 관리합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <h2>수련비 자동 문자 설정</h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="settings">
            <label><input type="checkbox" name="due_notice_enabled" value="1" <?php echo !empty($settings['due_notice_enabled']) ? 'checked' : ''; ?>> 납부일 당일 자동 안내</label>
            <label><input type="checkbox" name="overdue_notice_enabled" value="1" <?php echo !empty($settings['overdue_notice_enabled']) ? 'checked' : ''; ?>> 미납 자동 안내</label>
            <label>납부일 <input type="number" name="overdue_after_days" value="<?php echo (int) $settings['overdue_after_days']; ?>" min="1" max="30" style="width:70px">일 초과</label>
            <button type="submit" class="btn primary">설정 저장</button>
        </form>
    </section>

    <section class="panel">
        <h2>사용 가능한 변수</h2>
        <div class="tokens">
            <code>{academy_name}</code> 도장명 · <code>{student_name}</code> 학생명 · <code>{billing_month}</code> 청구월 · <code>{amount_due}</code> 청구액 · <code>{amount_paid}</code> 입금액 · <code>{balance}</code> 잔액 · <code>{due_date}</code> 납부일
        </div>
        <?php foreach ($defaults as $key => $default) { $template = ieum_tuition_get_sms_template($academy_id, $key); ?>
        <form method="post" class="template">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="template">
            <input type="hidden" name="template_key" value="<?php echo get_text($key); ?>">
            <div class="row">
                <label><?php echo get_text($default['title']); ?></label>
                <input type="text" name="title" value="<?php echo get_text($template['title']); ?>">
            </div>
            <textarea name="message"><?php echo get_text($template['message']); ?></textarea>
            <label><input type="checkbox" name="is_active" value="1" <?php echo !empty($template['is_active']) ? 'checked' : ''; ?>> 사용</label>
            <button type="submit" class="btn primary">템플릿 저장</button>
        </form>
        <?php } ?>
    </section>
</main>
</body>
</html>
