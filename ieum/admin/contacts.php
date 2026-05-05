<?php
$sub_menu = '950160';
require_once './_common.php';

$g5['title'] = '아이이음 알림 담당자';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_contact_clean_phone($phone)
{
    return preg_replace('/[^0-9+\-]/', '', trim($phone));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $contact_id = isset($_POST['contact_id']) ? (int) $_POST['contact_id'] : 0;
        $contact_name = isset($_POST['contact_name']) ? trim($_POST['contact_name']) : '';
        $contact_phone = isset($_POST['contact_phone']) ? ieum_contact_clean_phone($_POST['contact_phone']) : '';
        $sms_absent_alert = isset($_POST['sms_absent_alert']) ? 1 : 0;
        $sms_system_alert = isset($_POST['sms_system_alert']) ? 1 : 0;
        $sort_order = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($contact_name === '') {
            $error = '담당자 이름을 입력하세요.';
        } elseif ($contact_phone === '') {
            $error = '담당자 연락처를 입력하세요.';
        } else {
            $contact_name_sql = sql_escape_string($contact_name);
            $contact_phone_sql = sql_escape_string($contact_phone);

            if ($contact_id) {
                sql_query("
                    update " . IEUM_ACADEMY_CONTACT_TABLE . "
                       set contact_name = '{$contact_name_sql}',
                           contact_phone = '{$contact_phone_sql}',
                           sms_absent_alert = '{$sms_absent_alert}',
                           sms_system_alert = '{$sms_system_alert}',
                           sort_order = '{$sort_order}',
                           is_active = '{$is_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where contact_id = '{$contact_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = '담당자 정보가 수정되었습니다.';
            } else {
                sql_query("
                    insert into " . IEUM_ACADEMY_CONTACT_TABLE . "
                        set academy_id = '{$academy_id}',
                            contact_name = '{$contact_name_sql}',
                            contact_phone = '{$contact_phone_sql}',
                            sms_absent_alert = '{$sms_absent_alert}',
                            sms_system_alert = '{$sms_system_alert}',
                            sort_order = '{$sort_order}',
                            is_active = '{$is_active}',
                            created_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '담당자가 등록되었습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$rows = sql_query("
    select *
      from " . IEUM_ACADEMY_CONTACT_TABLE . "
     where academy_id = '{$academy_id}'
  order by sort_order asc, contact_id asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1120px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid{display:grid;grid-template-columns:1fr 1fr 120px 150px 150px 90px;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php echo ieum_admin_header('contacts'); ?>
<main class="wrap">
    <h1>알림 담당자</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 미등원/시스템 알림을 받을 사범님 연락처입니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="text" name="contact_name" placeholder="담당자명" required>
            <input type="text" name="contact_phone" placeholder="010-0000-0000" required>
            <input type="number" name="sort_order" value="1" min="0" title="정렬">
            <label><input type="checkbox" name="sms_absent_alert" value="1" checked> 미등원 알림</label>
            <label><input type="checkbox" name="sms_system_alert" value="1" checked> 시스템 알림</label>
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>

    <section class="panel">
        <table>
            <thead><tr><th>담당자</th><th>연락처</th><th>정렬</th><th>미등원</th><th>시스템</th><th>상태</th><th>수정</th></tr></thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($rows)) { $i++; ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="contact_id" value="<?php echo (int) $row['contact_id']; ?>">
                    <td><input type="text" name="contact_name" value="<?php echo get_text($row['contact_name']); ?>"></td>
                    <td><input type="text" name="contact_phone" value="<?php echo get_text($row['contact_phone']); ?>"></td>
                    <td><input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>"></td>
                    <td><label><input type="checkbox" name="sms_absent_alert" value="1" <?php echo $row['sms_absent_alert'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td><label><input type="checkbox" name="sms_system_alert" value="1" <?php echo $row['sms_system_alert'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td><button type="submit" class="btn">저장</button></td>
                </form>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="7">등록된 담당자가 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
