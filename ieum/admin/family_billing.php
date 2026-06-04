<?php
$sub_menu = '950176';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';

$g5['title'] = '아이이음 형제/자매 청구 관리';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

foreach (array(
    "alter table " . IEUM_STUDENT_TABLE . " add family_billing_enabled tinyint(1) not null default 0 after sibling_discount_amount",
    "alter table " . IEUM_STUDENT_TABLE . " add family_billing_key varchar(80) not null default '' after family_billing_enabled",
    "alter table " . IEUM_STUDENT_TABLE . " add family_billing_label varchar(80) not null default '' after family_billing_key",
    "alter table " . IEUM_STUDENT_TABLE . " add family_billing_primary tinyint(1) not null default 0 after family_billing_label",
) as $schema_sql) {
    sql_query($schema_sql, false);
}

$billing_month = isset($_GET['billing_month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['billing_month'])) : ieum_tuition_billing_month();
if (!preg_match('/^\d{4}\-\d{2}$/', $billing_month)) {
    $billing_month = ieum_tuition_billing_month();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$status_filter = isset($_GET['status']) ? preg_replace('/[^0-9a-z_]/', '', trim($_GET['status'])) : 'all';
if (!in_array($status_filter, array('all', 'need_primary', 'balance', 'sent', 'paid'), true)) {
    $status_filter = 'all';
}
$csrf_token = ieum_new_csrf_token();
$message = '';
$error = '';

function ieum_family_clean_phone($phone)
{
    return preg_replace('/[^0-9]/', '', (string) $phone);
}

function ieum_family_mask_phone($phone)
{
    $digits = ieum_family_clean_phone($phone);
    if (strlen($digits) >= 8) {
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }

    return $phone !== '' ? $phone : '-';
}

function ieum_family_label($label, $fallback_name)
{
    $label = trim((string) $label);
    if ($label !== '') {
        return $label;
    }

    $fallback_name = trim((string) $fallback_name);
    return $fallback_name !== '' ? $fallback_name . ' 형제/자매' : '형제/자매 청구';
}

function ieum_family_get_phone_students($academy_id, $phone_key)
{
    $academy_id = (int) $academy_id;
    $phone_key = ieum_family_clean_phone($phone_key);
    if ($phone_key === '') {
        return array();
    }

    $phone_sql = sql_escape_string($phone_key);
    $rows = array();
    $result = sql_query("
        select distinct s.student_id, s.student_name, s.student_code
          from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = g.student_id and s.academy_id = g.academy_id
         where g.academy_id = '{$academy_id}'
           and g.is_active = 1
           and s.is_active = 1
           and s.student_status <> 'withdrawn'
           and replace(replace(replace(g.guardian_phone, '-', ''), ' ', ''), '+82', '0') = '{$phone_sql}'
      order by g.is_primary desc, s.student_name asc, s.student_code asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $rows[] = $row;
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        if ($action === 'create_by_phone') {
            $phone_key = isset($_POST['phone_key']) ? ieum_family_clean_phone($_POST['phone_key']) : '';
            $students = ieum_family_get_phone_students($academy_id, $phone_key);
            if (count($students) < 2) {
                $error = '같은 보호자 연락처로 묶을 원생이 2명 이상 필요합니다.';
            } else {
                $family_key = 'phone:' . $phone_key;
                $family_label = $students[0]['student_name'] . ' 형제/자매';
                $family_key_sql = sql_escape_string($family_key);
                $family_label_sql = sql_escape_string($family_label);
                foreach ($students as $index => $student) {
                    $student_id = (int) $student['student_id'];
                    $primary = $index === 0 ? 1 : 0;
                    sql_query("
                        update " . IEUM_STUDENT_TABLE . "
                           set family_billing_enabled = 1,
                               family_billing_key = '{$family_key_sql}',
                               family_billing_label = '{$family_label_sql}',
                               family_billing_primary = '{$primary}'
                         where academy_id = '{$academy_id}'
                           and student_id = '{$student_id}'
                         limit 1
                    ");
                }
                $message = $family_label . ' 묶음을 만들었습니다. 청구서는 보호자 기준으로 1회 발송됩니다.';
            }
        } elseif ($action === 'set_primary') {
            $family_key = isset($_POST['family_key']) ? preg_replace('/[^0-9a-zA-Z:_-]/', '', trim($_POST['family_key'])) : '';
            $student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
            if ($family_key === '' || $student_id <= 0) {
                $error = '대표 원생을 선택해 주세요.';
            } else {
                $family_key_sql = sql_escape_string($family_key);
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set family_billing_primary = 0
                     where academy_id = '{$academy_id}'
                       and family_billing_key = '{$family_key_sql}'
                ");
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set family_billing_primary = 1
                     where academy_id = '{$academy_id}'
                       and student_id = '{$student_id}'
                       and family_billing_key = '{$family_key_sql}'
                     limit 1
                ");
                $message = '대표 청구 원생을 변경했습니다.';
            }
        } elseif ($action === 'update_label') {
            $family_key = isset($_POST['family_key']) ? preg_replace('/[^0-9a-zA-Z:_-]/', '', trim($_POST['family_key'])) : '';
            $family_label = isset($_POST['family_label']) ? trim($_POST['family_label']) : '';
            if ($family_key === '' || $family_label === '') {
                $error = '형제/자매 묶음 이름을 입력해 주세요.';
            } else {
                $family_key_sql = sql_escape_string($family_key);
                $family_label_sql = sql_escape_string($family_label);
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set family_billing_label = '{$family_label_sql}'
                     where academy_id = '{$academy_id}'
                       and family_billing_key = '{$family_key_sql}'
                ");
                $message = '형제/자매 묶음 이름을 저장했습니다.';
            }
        } elseif ($action === 'disable_group') {
            $family_key = isset($_POST['family_key']) ? preg_replace('/[^0-9a-zA-Z:_-]/', '', trim($_POST['family_key'])) : '';
            if ($family_key === '') {
                $error = '해제할 형제/자매 묶음을 선택해 주세요.';
            } else {
                $family_key_sql = sql_escape_string($family_key);
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set family_billing_enabled = 0,
                           family_billing_key = '',
                           family_billing_label = '',
                           family_billing_primary = 0
                     where academy_id = '{$academy_id}'
                       and family_billing_key = '{$family_key_sql}'
                ");
                $message = '형제/자매 청구 묶음을 해제했습니다. 원생별 청구 기록은 그대로 유지됩니다.';
            }
        }
    }
}

ieum_tuition_ensure_month($academy_id, $billing_month);
$month_sql = sql_escape_string($billing_month);
$q_sql = sql_escape_string($q);
$search_where = '';
if ($q !== '') {
    $search_where = " and (s.student_name like '%{$q_sql}%' or s.student_code like '%{$q_sql}%' or s.family_billing_label like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " gx where gx.academy_id = s.academy_id and gx.student_id = s.student_id and gx.is_active = 1 and (gx.guardian_name like '%{$q_sql}%' or gx.guardian_phone like '%{$q_sql}%'))) ";
}

$family_rows = array();
$result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_phone, s.family_billing_key, s.family_billing_label, s.family_billing_primary,
           c.class_name, c.start_time,
           coalesce(p.amount_due, 0) as amount_due,
           coalesce(p.amount_paid, 0) as amount_paid,
           coalesce(p.status, 'unpaid') as payment_status,
           p.bill_sent_at,
           (select concat(g.guardian_name, '|', g.guardian_phone)
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1
               and g.guardian_phone <> ''
          order by g.sms_tuition desc, g.is_primary desc, g.sort_order asc, g.guardian_id asc
             limit 1) as guardian_contact
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_TUITION_PAYMENT_TABLE . " p on p.student_id = s.student_id and p.academy_id = s.academy_id and p.billing_month = '{$month_sql}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and s.family_billing_enabled = 1
       and s.family_billing_key <> ''
       {$search_where}
  order by s.family_billing_label asc, s.family_billing_key asc, s.family_billing_primary desc, s.student_name asc
", false);
while ($row = sql_fetch_array($result)) {
    $key = $row['family_billing_key'];
    if (!isset($family_rows[$key])) {
        $family_rows[$key] = array(
            'family_key' => $key,
            'family_label' => ieum_family_label($row['family_billing_label'], $row['student_name']),
            'members' => array(),
            'due' => 0,
            'paid' => 0,
            'sent_count' => 0,
            'primary' => null,
        );
    }
    $family_rows[$key]['due'] += (int) $row['amount_due'];
    $family_rows[$key]['paid'] += (int) $row['amount_paid'];
    if (!empty($row['bill_sent_at'])) {
        $family_rows[$key]['sent_count']++;
    }
    if ((int) $row['family_billing_primary'] === 1 || $family_rows[$key]['primary'] === null) {
        $family_rows[$key]['primary'] = $row;
    }
    $family_rows[$key]['members'][] = $row;
}

function ieum_family_group_meta($group)
{
    $due = (int) $group['due'];
    $paid = (int) $group['paid'];
    $balance = max(0, $due - $paid);
    $member_count = count($group['members']);
    $has_primary = false;
    foreach ($group['members'] as $member_row) {
        if ((int) $member_row['family_billing_primary'] === 1) {
            $has_primary = true;
            break;
        }
    }
    $sent_count = (int) $group['sent_count'];
    $is_paid = $due > 0 && $balance <= 0;
    $is_sent = $sent_count > 0;

    if (!$has_primary) {
        $status = 'need_primary';
        $label = '대표 확인';
        $badge = 'warn';
    } elseif ($is_paid) {
        $status = 'paid';
        $label = '완납';
        $badge = 'primary';
    } elseif ($balance > 0) {
        $status = 'balance';
        $label = '청구 필요';
        $badge = 'danger';
    } elseif ($is_sent) {
        $status = 'sent';
        $label = '발송 완료';
        $badge = 'info';
    } else {
        $status = 'balance';
        $label = '확인 필요';
        $badge = 'warn';
    }

    return array(
        'status' => $status,
        'label' => $label,
        'badge' => $badge,
        'balance' => $balance,
        'member_count' => $member_count,
        'has_primary' => $has_primary,
        'sent_count' => $sent_count,
        'is_paid' => $is_paid,
    );
}

$all_family_rows = $family_rows;
$filter_counts = array('all' => 0, 'need_primary' => 0, 'balance' => 0, 'sent' => 0, 'paid' => 0);
foreach ($all_family_rows as $key => $group) {
    $meta = ieum_family_group_meta($group);
    $all_family_rows[$key]['meta'] = $meta;
    $filter_counts['all']++;
    if (isset($filter_counts[$meta['status']])) {
        $filter_counts[$meta['status']]++;
    }
    if ($meta['sent_count'] > 0) {
        $filter_counts['sent']++;
    }
    if ($meta['is_paid']) {
        $filter_counts['paid']++;
    }
}

$family_rows = array();
foreach ($all_family_rows as $key => $group) {
    if ($status_filter === 'all'
        || $group['meta']['status'] === $status_filter
        || ($status_filter === 'sent' && $group['meta']['sent_count'] > 0)
        || ($status_filter === 'paid' && $group['meta']['is_paid'])) {
        $family_rows[$key] = $group;
    }
}

$group_count = count($family_rows);
$member_count = 0;
$total_due = 0;
$total_paid = 0;
$need_primary_count = 0;
foreach ($family_rows as $group) {
    $member_count += count($group['members']);
    $total_due += (int) $group['due'];
    $total_paid += (int) $group['paid'];
    $has_primary = false;
    foreach ($group['members'] as $member_row) {
        if ((int) $member_row['family_billing_primary'] === 1) {
            $has_primary = true;
            break;
        }
    }
    if (!$has_primary) {
        $need_primary_count++;
    }
}
$total_balance = max(0, $total_due - $total_paid);

$suggestions = array();
$suggest_result = sql_query("
    select replace(replace(replace(g.guardian_phone, '-', ''), ' ', ''), '+82', '0') as phone_key,
           count(distinct s.student_id) as student_count,
           group_concat(distinct concat(s.student_name, '(', s.student_code, ')') order by s.student_name asc separator ', ') as student_names
      from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = g.student_id and s.academy_id = g.academy_id
     where g.academy_id = '{$academy_id}'
       and g.is_active = 1
       and g.guardian_phone <> ''
       and s.is_active = 1
       and s.student_status <> 'withdrawn'
       and (s.family_billing_enabled = 0 or s.family_billing_key = '')
  group by phone_key
    having student_count >= 2
  order by student_count desc, phone_key asc
     limit 20
", false);
while ($row = sql_fetch_array($suggest_result)) {
    $suggestions[] = $row;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px}.hero h1{margin:0;font-size:34px;letter-spacing:0}.meta{color:#667085;margin-top:6px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.soft{background:#eef2f7}.btn.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.notice{padding:12px 14px;border-radius:10px;font-weight:800}.notice.ok{background:#eef9f1;color:#176b2c;border:1px solid #b9e5c5}.notice.err{background:#fdecec;color:#a4262c;border:1px solid #f2b8b8}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 16px}.filters input{min-height:40px}.filters input,.filters select,.panel input{border:1px solid #cfd6df;border-radius:8px;padding:9px 10px;font-size:14px}.brief{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.brief-card{border:1px solid #d9dee7;border-radius:14px;background:#fff;padding:17px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.brief-card.primary{background:linear-gradient(135deg,#163f7a,#1769c2);color:#fff}.brief-label{font-size:13px;font-weight:900;color:#667085}.brief-card.primary .brief-label{color:#dbeafe}.brief-num{font-size:30px;font-weight:1000;margin-top:6px}.brief-help{margin-top:6px;color:#667085;font-size:13px;line-height:1.45}.brief-card.primary .brief-help{color:#e8f1ff}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-top:16px}.panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px}.panel h2{margin:0;font-size:22px}.panel-copy{margin:4px 0 0;color:#667085;font-size:13px;line-height:1.45}.family-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px}.family-card{border:1px solid #d9dee7;border-radius:14px;background:#fbfcff;overflow:hidden}.family-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:15px 16px;border-bottom:1px solid #e3e8f0;background:#fff}.family-title{font-size:19px;font-weight:1000}.family-sub{margin-top:4px;color:#667085;font-size:13px}.family-amount{font-size:22px;font-weight:1000;color:#174a8b;text-align:right}.family-amount small{display:block;color:#667085;font-size:12px;font-weight:800;margin-top:3px}.member-list{display:grid;gap:8px;padding:14px}.member{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid #e4eaf2;border-radius:10px;background:#fff;padding:10px}.member-name{font-weight:1000}.member-meta{margin-top:3px;color:#667085;font-size:12px}.member-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:4px 8px;font-size:12px;font-weight:900}.badge.primary{background:#eaf8ef;color:#176b2c}.badge.warn{background:#fff6df;color:#9a5b00}.badge.danger{background:#fff1f1;color:#a4262c}.badge.info{background:#eaf4ff;color:#1769c2}.member-price{text-align:right;font-weight:1000}.member-price small{display:block;color:#667085;font-size:12px;font-weight:800}.family-tools{display:grid;grid-template-columns:1fr;gap:8px;padding:0 14px 14px}.family-tools form{display:flex;gap:8px;flex-wrap:wrap}.family-tools input[type=text]{flex:1 1 220px}.family-actions{display:flex;gap:8px;flex-wrap:wrap}.suggestions{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}.suggest-card{border:1px solid #bfdbfe;border-radius:12px;background:#f7fbff;padding:14px}.suggest-card strong{display:block;font-size:16px}.suggest-card p{margin:6px 0 12px;color:#475467;line-height:1.45}.empty{border:1px dashed #cfd6df;border-radius:12px;background:#fff;padding:22px;text-align:center;color:#667085;font-weight:900}@media(max-width:900px){.brief{grid-template-columns:1fr 1fr}.family-grid{grid-template-columns:1fr}.member{grid-template-columns:1fr}.member-price{text-align:left}}@media(max-width:560px){.wrap{padding:0 14px}.brief{grid-template-columns:1fr}.hero h1{font-size:28px}.family-card-head{display:grid}.family-amount{text-align:left}}
</style>
<style>
.family-principles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0}
.principle{border:1px solid #d9e5f8;border-radius:12px;background:#f7fbff;padding:14px}
.principle strong{display:block;color:#174a8b;font-size:15px}
.principle span{display:block;margin-top:5px;color:#475467;font-size:13px;line-height:1.45}
.family-filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px}
.family-filter-tabs a{display:inline-flex;align-items:center;gap:6px;border:1px solid #d9dee7;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:8px 12px;font-weight:900}
.family-filter-tabs a.active{background:#1769c2;border-color:#1769c2;color:#fff}
.family-status-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
.family-contact{margin-top:7px;color:#475467;font-size:13px;font-weight:800}
.family-amount .balance-label{display:block;color:#174a8b;font-size:12px;font-weight:900;margin-bottom:3px}
.family-next{border-top:1px solid #e3e8f0;background:#f8fafc;padding:10px 14px;color:#475467;font-size:13px;font-weight:800;line-height:1.45}
@media(max-width:900px){.family-principles{grid-template-columns:1fr}}
</style>
<style>
/* Dashboard shell alignment: family billing is a supporting tuition page. */
body.ieum-side-layout.ieum-dashboard-page.family-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-brand{
    display:flex!important;
    height:144px!important;
    min-height:144px!important;
    padding:0 28px!important;
    background:#fff!important;
    color:#0f172a!important;
    font-size:29px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-menu>summary{
    min-height:42px!important;
    border-radius:6px!important;
    padding:0 12px!important;
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-menu>summary:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-nav-label{
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-nav-icon{
    width:18px!important;
    height:18px!important;
    color:#334155!important;
    opacity:1!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-sub{
    margin:2px 0 8px!important;
    padding:0 0 0 28px!important;
    background:transparent!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-sub a{
    min-height:34px!important;
    border-radius:6px!important;
    color:#475569!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .side-sub a.active{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    width:auto!important;
    height:64px!important;
    padding:0 40px!important;
    background:#fff!important;
    border-bottom:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-link{
    flex:0 0 auto!important;
    color:#0f172a!important;
    font-weight:900!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-meta{
    margin-left:auto!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-meta-inner{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-help-dot{
    color:#94a3b8!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-support-link{
    color:#0f172a!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-support-link:hover,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:4px!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .hero h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .meta{
    margin:8px 0 18px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .brief-card,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .principle,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .family-card,
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .suggest-card{
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.family-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.family-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.family-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page family-page-tune">
<?php echo ieum_admin_header('family_billing', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>형제/자매 청구 관리</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($billing_month); ?></div>
        </div>
        <div class="actions">
            <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo urlencode($billing_month); ?>">수련비 납부</a>
            <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/students.php">원생 관리</a>
        </div>
    </section>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <form method="get" class="filters">
        <input type="month" name="billing_month" value="<?php echo get_text($billing_month); ?>">
        <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="원생명, 원생번호, 묶음명, 연락처 검색">
        <input type="hidden" name="status" value="<?php echo get_text($status_filter); ?>">
        <button type="submit" class="btn primary">조회</button>
        <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/family_billing.php">초기화</a>
    </form>

    <nav class="family-filter-tabs" aria-label="형제/자매 묶음 상태 필터">
        <?php
        $filter_labels = array('all' => '전체', 'balance' => '청구 필요', 'need_primary' => '대표 확인', 'sent' => '발송 완료', 'paid' => '완납');
        foreach ($filter_labels as $filter_key => $filter_label) {
            $filter_url = IEUM_URL . '/admin/family_billing.php?billing_month=' . urlencode($billing_month) . '&status=' . urlencode($filter_key) . '&q=' . urlencode($q);
        ?>
        <a class="<?php echo $status_filter === $filter_key ? 'active' : ''; ?>" href="<?php echo $filter_url; ?>"><?php echo get_text($filter_label); ?> <b><?php echo number_format((int) (isset($filter_counts[$filter_key]) ? $filter_counts[$filter_key] : 0)); ?></b></a>
        <?php } ?>
    </nav>

    <section class="brief" aria-label="형제/자매 청구 요약">
        <article class="brief-card primary">
            <div class="brief-label">형제/자매 청구 묶음</div>
            <div class="brief-num"><?php echo number_format($group_count); ?>건</div>
            <div class="brief-help">청구서가 보호자 기준으로 1회 발송되는 묶음입니다.</div>
        </article>
        <article class="brief-card">
            <div class="brief-label">묶인 원생</div>
            <div class="brief-num"><?php echo number_format($member_count); ?>명</div>
            <div class="brief-help">원생별 수련비 기록은 그대로 유지됩니다.</div>
        </article>
        <article class="brief-card">
            <div class="brief-label">이번 달 합산 청구</div>
            <div class="brief-num"><?php echo number_format($total_due); ?>원</div>
            <div class="brief-help">입금 <?php echo number_format($total_paid); ?>원 · 잔액 <?php echo number_format($total_balance); ?>원</div>
        </article>
        <article class="brief-card">
            <div class="brief-label">확인 필요</div>
            <div class="brief-num"><?php echo number_format($need_primary_count); ?>건</div>
            <div class="brief-help">대표 청구 원생이 없는 묶음입니다.</div>
        </article>
    </section>

    <section class="family-principles" aria-label="형제/자매 청구 원칙">
        <article class="principle"><strong>원생 기록은 각각 유지</strong><span>형제·자매라도 출석, 리포트, 수련비 기록은 원생별로 따로 남깁니다.</span></article>
        <article class="principle"><strong>보호자 청구는 한 번</strong><span>형제/자매 묶음은 대표 원생 기준으로 합산해 청구서를 1회만 보냅니다.</span></article>
        <article class="principle"><strong>완납자는 자동 제외</strong><span>이미 납부된 원생은 발송 대상에서 빠지고, 잔액이 있는 경우만 청구됩니다.</span></article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>현재 형제/자매 청구 묶음</h2>
                <p class="panel-copy">대표 원생 기준으로 보호자에게 1회 청구하고, 구성원별 금액은 아래에서 합산 확인합니다.</p>
            </div>
        </div>
        <?php if ($family_rows) { ?>
        <div class="family-grid">
            <?php foreach ($family_rows as $family_key => $group) {
                $primary = $group['primary'];
                $primary_contact = isset($primary['guardian_contact']) ? explode('|', $primary['guardian_contact']) : array('', '');
                $primary_name = isset($primary_contact[0]) ? $primary_contact[0] : '';
                $primary_phone = isset($primary_contact[1]) ? $primary_contact[1] : '';
                $meta = isset($group['meta']) ? $group['meta'] : ieum_family_group_meta($group);
                $balance = (int) $meta['balance'];
                $has_primary = !empty($meta['has_primary']);
            ?>
            <article class="family-card">
                <div class="family-card-head">
                    <div>
                        <div class="family-title"><?php echo get_text($group['family_label']); ?></div>
                        <div class="family-sub">
                            <?php echo number_format(count($group['members'])); ?>명 묶음 · 대표 <?php echo get_text($primary ? $primary['student_name'] : '미지정'); ?>
                        </div>
                        <div class="family-status-row">
                            <span class="badge <?php echo get_text($meta['badge']); ?>"><?php echo get_text($meta['label']); ?></span>
                            <?php if ((int) $group['sent_count'] > 0) { ?><span class="badge info">청구서 발송 <?php echo number_format((int) $group['sent_count']); ?>명</span><?php } ?>
                            <?php if (!$has_primary) { ?><span class="badge warn">대표 원생 필요</span><?php } ?>
                        </div>
                        <?php if ($primary_phone !== '') { ?>
                        <div class="family-contact">수신 보호자 <?php echo get_text($primary_name); ?> <?php echo get_text(ieum_family_mask_phone($primary_phone)); ?></div>
                        <?php } ?>
                    </div>
                    <div class="family-amount">
                        <span class="balance-label">남은 청구액</span>
                        <?php echo number_format($balance); ?>원
                        <small>청구 <?php echo number_format((int) $group['due']); ?>원 · 입금 <?php echo number_format((int) $group['paid']); ?>원</small>
                    </div>
                </div>
                <div class="family-next">
                    <?php if (!$has_primary) { ?>
                        먼저 대표 원생을 지정하면 보호자 기준 청구서 1건으로 묶을 수 있습니다.
                    <?php } elseif ($balance <= 0) { ?>
                        이번 달 잔액이 없어 청구서 발송 대상에서 자동 제외됩니다.
                    <?php } elseif ((int) $group['sent_count'] > 0) { ?>
                        이미 청구서가 발송된 구성원이 있습니다. 추가 발송 전 납부 상태를 확인해 주세요.
                    <?php } else { ?>
                        수련비 납부 화면에서 이 묶음은 대표 원생 기준으로 합산 청구됩니다.
                    <?php } ?>
                </div>
                <div class="member-list">
                    <?php foreach ($group['members'] as $member_row) {
                        $member_balance = max(0, (int) $member_row['amount_due'] - (int) $member_row['amount_paid']);
                        $class_label = trim((string) $member_row['class_name'] . ' ' . (string) $member_row['start_time']);
                    ?>
                    <div class="member">
                        <div>
                            <div class="member-name"><?php echo get_text($member_row['student_name']); ?> <span class="member-meta">(<?php echo get_text($member_row['student_code']); ?>)</span></div>
                            <div class="member-meta"><?php echo get_text($class_label !== '' ? $class_label : '수업 부 미지정'); ?></div>
                            <div class="member-badges">
                                <?php if ((int) $member_row['family_billing_primary'] === 1) { ?><span class="badge primary">대표</span><?php } ?>
                                <?php if ($member_row['payment_status'] === 'paid') { ?><span class="badge primary">완납</span><?php } else { ?><span class="badge danger">미납 <?php echo number_format($member_balance); ?>원</span><?php } ?>
                                <?php if (!empty($member_row['bill_sent_at'])) { ?><span class="badge info">청구서 발송</span><?php } ?>
                            </div>
                        </div>
                        <div class="member-price">
                            <?php echo number_format((int) $member_row['amount_due']); ?>원
                            <small>입금 <?php echo number_format((int) $member_row['amount_paid']); ?>원</small>
                            <?php if ((int) $member_row['family_billing_primary'] !== 1) { ?>
                            <form method="post" style="margin-top:6px">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="set_primary">
                                <input type="hidden" name="family_key" value="<?php echo get_text($family_key); ?>">
                                <input type="hidden" name="student_id" value="<?php echo (int) $member_row['student_id']; ?>">
                                <button type="submit" class="btn soft">대표 지정</button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <div class="family-tools">
                    <?php if (!$has_primary) { ?><span class="badge warn">대표 원생 확인 필요</span><?php } ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_label">
                        <input type="hidden" name="family_key" value="<?php echo get_text($family_key); ?>">
                        <input type="text" name="family_label" value="<?php echo get_text($group['family_label']); ?>" placeholder="형제/자매 묶음 이름">
                        <button type="submit" class="btn soft">이름 저장</button>
                    </form>
                    <div class="family-actions">
                        <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo urlencode($billing_month); ?>">청구 확인</a>
                        <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/students.php?q=<?php echo urlencode($primary ? $primary['student_code'] : ''); ?>&amp;focus_student_id=<?php echo (int) ($primary ? $primary['student_id'] : 0); ?>">원생 보기</a>
                        <form method="post" onsubmit="return confirm('형제/자매 청구 묶음을 해제할까요? 원생별 수련비 기록은 삭제되지 않습니다.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="disable_group">
                            <input type="hidden" name="family_key" value="<?php echo get_text($family_key); ?>">
                            <button type="submit" class="btn danger">묶음 해제</button>
                        </form>
                    </div>
                </div>
            </article>
            <?php } ?>
        </div>
        <?php } else { ?>
        <div class="empty">아직 형제/자매 청구 묶음이 없습니다. 아래 추천 묶음에서 같은 보호자 연락처 원생을 빠르게 묶을 수 있습니다.</div>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>추천 묶음</h2>
                <p class="panel-copy">같은 보호자 연락처가 등록된 원생을 찾아 형제/자매 청구 후보로 보여줍니다. 버튼 한 번으로 묶음을 만들 수 있습니다.</p>
            </div>
        </div>
        <?php if ($suggestions) { ?>
        <div class="suggestions">
            <?php foreach ($suggestions as $suggestion) { ?>
            <article class="suggest-card">
                <strong><?php echo get_text(ieum_family_mask_phone($suggestion['phone_key'])); ?> · <?php echo number_format((int) $suggestion['student_count']); ?>명</strong>
                <p><?php echo get_text($suggestion['student_names']); ?></p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="create_by_phone">
                    <input type="hidden" name="phone_key" value="<?php echo get_text($suggestion['phone_key']); ?>">
                    <button type="submit" class="btn primary">추천 묶음 생성</button>
                </form>
            </article>
            <?php } ?>
        </div>
        <?php } else { ?>
        <div class="empty">현재 자동으로 추천할 같은 보호자 연락처 원생이 없습니다.</div>
        <?php } ?>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.family-page-tune.ieum-dashboard-page';
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
