<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/sms_queue.php';

function ieum_tuition_billing_month($time = null)
{
    return date('Y-m', $time ? (int) $time : strtotime(G5_TIME_YMDHIS));
}

function ieum_tuition_status_options()
{
    return array(
        'unpaid' => '미결제',
        'paid' => '결제완료',
    );
}

function ieum_tuition_status_label($status)
{
    if ($status === 'partial') {
        return '미결제';
    }

    $options = ieum_tuition_status_options();
    return isset($options[$status]) ? $options[$status] : $status;
}

function ieum_tuition_default_sms_templates()
{
    return array(
        'tuition_due' => array(
            'title' => '수련비 납부 안내',
            'message' => '[{도장명}] 안녕하세요. {원생명} 원생의 {청구월} 수련비 안내드립니다. 이번 달 납부 예정 금액은 {금액}원입니다. 편하실 때 확인 부탁드리며, 이미 납부하셨다면 이 안내는 지나쳐 주세요. 감사합니다.',
        ),
        'tuition_overdue' => array(
            'title' => '수련비 미납 안내',
            'message' => '[{도장명}] 안녕하세요. {원생명} 원생의 {청구월} 수련비 확인 안내드립니다. 현재 확인이 필요한 금액은 {금액}원입니다. 납부 내역 확인을 위해 한 번 더 안내드리며, 이미 납부하셨다면 이 안내는 지나쳐 주세요. 감사합니다.',
        ),
    );
}

function ieum_tuition_get_settings($academy_id)
{
    $academy_id = (int) $academy_id;
    $row = sql_fetch("
        select *
          from " . IEUM_TUITION_SETTING_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    if (isset($row['academy_id'])) {
        return $row;
    }

    return array(
        'academy_id' => $academy_id,
        'due_notice_enabled' => 1,
        'overdue_notice_enabled' => 0,
        'overdue_after_days' => 5,
        'bill_auto_send_enabled' => 0,
        'bill_auto_send_day' => 5,
        'bill_auto_send_scope' => 'all',
        'bill_auto_include_arrears' => 1,
    );
}

function ieum_tuition_render_template($template, $vars)
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }

    return $template;
}

function ieum_tuition_get_sms_template($academy_id, $template_key)
{
    $academy_id = (int) $academy_id;
    $template_key_sql = sql_escape_string($template_key);
    $row = sql_fetch("
        select *
          from " . IEUM_SMS_TEMPLATE_TABLE . "
         where academy_id = '{$academy_id}'
           and template_key = '{$template_key_sql}'
         limit 1
    ", false);
    if (isset($row['template_id'])) {
        return $row;
    }

    $defaults = ieum_tuition_default_sms_templates();
    $default = isset($defaults[$template_key]) ? $defaults[$template_key] : array('title' => $template_key, 'message' => '');

    return array(
        'template_key' => $template_key,
        'title' => $default['title'],
        'message' => $default['message'],
        'is_active' => 1,
    );
}

function ieum_tuition_save_sms_template($academy_id, $template_key, $message, $title = '')
{
    $academy_id = (int) $academy_id;
    $defaults = ieum_tuition_default_sms_templates();
    if (!isset($defaults[$template_key])) {
        return false;
    }

    $message = trim((string) $message);
    if ($message === '') {
        return false;
    }

    $title = trim((string) $title);
    if ($title === '') {
        $title = $defaults[$template_key]['title'];
    }

    sql_query("
        insert into " . IEUM_SMS_TEMPLATE_TABLE . "
            set academy_id = '{$academy_id}',
                template_key = '" . sql_escape_string($template_key) . "',
                title = '" . sql_escape_string($title) . "',
                message = '" . sql_escape_string($message) . "',
                is_active = 1,
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                title = values(title),
                message = values(message),
                is_active = 1,
                updated_at = values(updated_at)
    ");

    return true;
}

function ieum_tuition_due_date($billing_month, $due_day)
{
    $due_day = (int) $due_day;
    if ($due_day < 1) {
        $due_day = 1;
    } elseif ($due_day > 31) {
        $due_day = 31;
    }

    $last_day = (int) date('t', strtotime($billing_month . '-01'));
    if ($due_day > $last_day) {
        $due_day = $last_day;
    }

    return $billing_month . '-' . str_pad((string) $due_day, 2, '0', STR_PAD_LEFT);
}

function ieum_tuition_student_amount($student)
{
    $amount = isset($student['tuition_amount']) ? (int) $student['tuition_amount'] : 0;
    if (isset($student['academy_id'])) {
        $week_type = isset($student['tuition_week_type']) ? preg_replace('/[^0-9a-z_]/', '', $student['tuition_week_type']) : '';
        $needs_plan_amount = $amount <= 0;
        $needs_plan_discount = !empty($student['sibling_discount_enabled'])
            && (!isset($student['sibling_discount_amount']) || (int) $student['sibling_discount_amount'] <= 0);
        if ($week_type !== '' && ($needs_plan_amount || $needs_plan_discount)) {
            $plan = sql_fetch("
                select monthly_fee, sibling_discount_amount, default_due_day
                  from " . IEUM_TUITION_PLAN_TABLE . "
                 where academy_id = '" . (int) $student['academy_id'] . "'
                   and week_type = '" . sql_escape_string($week_type) . "'
                   and is_active = 1
              order by plan_id asc
                 limit 1
            ", false);
            if (isset($plan['monthly_fee'])) {
                if ($needs_plan_amount) {
                    $amount = (int) $plan['monthly_fee'];
                }
                if ($needs_plan_discount) {
                    $student['sibling_discount_amount'] = (int) $plan['sibling_discount_amount'];
                }
            }
        }
    }

    if (!empty($student['sibling_discount_enabled'])) {
        $amount -= isset($student['sibling_discount_amount']) ? (int) $student['sibling_discount_amount'] : 0;
    }

    return max(0, $amount);
}

function ieum_tuition_ensure_month($academy_id, $billing_month)
{
    $academy_id = (int) $academy_id;
    $billing_month_sql = sql_escape_string($billing_month);
    $created = 0;

    $students = sql_query("
        select student_id, academy_id, tuition_week_type, tuition_amount, sibling_discount_enabled, sibling_discount_amount, tuition_due_day
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
    ", false);

    while ($student = sql_fetch_array($students)) {
        $student_id = (int) $student['student_id'];
        $amount_due = ieum_tuition_student_amount($student);
        $due_date = ieum_tuition_due_date($billing_month, isset($student['tuition_due_day']) ? (int) $student['tuition_due_day'] : 5);
        $due_date_sql = sql_escape_string($due_date);

        $exists = sql_fetch("
            select payment_id
              from " . IEUM_TUITION_PAYMENT_TABLE . "
             where academy_id = '{$academy_id}'
               and student_id = '{$student_id}'
               and billing_month = '{$billing_month_sql}'
             limit 1
        ", false);

        if (isset($exists['payment_id'])) {
            $payment_id = (int) $exists['payment_id'];
            sql_query("
                update " . IEUM_TUITION_PAYMENT_TABLE . "
                   set amount_due = '{$amount_due}',
                       due_date = '{$due_date_sql}',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and payment_id = '{$payment_id}'
                   and status in ('unpaid', 'partial')
                   and amount_paid = 0
                   and (bill_sent_at is null or bill_sent_at = '0000-00-00 00:00:00')
                   and (amount_due <> '{$amount_due}' or due_date <> '{$due_date_sql}')
            ");
            continue;
        }

        sql_query("
            insert into " . IEUM_TUITION_PAYMENT_TABLE . "
                set academy_id = '{$academy_id}',
                    student_id = '{$student_id}',
                    billing_month = '{$billing_month_sql}',
                    due_date = '{$due_date_sql}',
                    amount_due = '{$amount_due}',
                    status = 'unpaid',
                    bill_auto_send_enabled = 1,
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
        $created++;
    }

    return $created;
}

function ieum_tuition_refresh_month_amounts($academy_id, $billing_month)
{
    $academy_id = (int) $academy_id;
    $billing_month_sql = sql_escape_string($billing_month);
    $result = array(
        'updated' => 0,
        'unchanged' => 0,
        'skipped_paid' => 0,
        'skipped_sent' => 0,
    );

    ieum_tuition_ensure_month($academy_id, $billing_month);

    $rows = sql_query("
        select p.payment_id, p.amount_due, p.amount_paid, p.status, p.due_date, p.bill_sent_at,
               s.student_id, s.academy_id, s.tuition_week_type, s.tuition_amount,
               s.sibling_discount_enabled, s.sibling_discount_amount, s.tuition_due_day
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id and s.academy_id = p.academy_id
         where p.academy_id = '{$academy_id}'
           and p.billing_month = '{$billing_month_sql}'
           and s.is_active = 1
    ", false);

    while ($row = sql_fetch_array($rows)) {
        if ((int) $row['amount_paid'] > 0 || $row['status'] === 'paid') {
            $result['skipped_paid']++;
            continue;
        }
        if (!empty($row['bill_sent_at']) && $row['bill_sent_at'] !== '0000-00-00 00:00:00') {
            $result['skipped_sent']++;
            continue;
        }

        $amount_due = ieum_tuition_student_amount($row);
        $due_date = ieum_tuition_due_date($billing_month, isset($row['tuition_due_day']) ? (int) $row['tuition_due_day'] : 5);
        if ((int) $row['amount_due'] === $amount_due && (string) $row['due_date'] === $due_date) {
            $result['unchanged']++;
            continue;
        }

        sql_query("
            update " . IEUM_TUITION_PAYMENT_TABLE . "
               set amount_due = '{$amount_due}',
                   due_date = '" . sql_escape_string($due_date) . "',
                   status = 'unpaid',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and payment_id = '" . (int) $row['payment_id'] . "'
        ");
        $result['updated']++;
    }

    return $result;
}

function ieum_tuition_payment_auto_status($amount_due, $amount_paid)
{
    $amount_due = max(0, (int) $amount_due);
    $amount_paid = max(0, (int) $amount_paid);

    if ($amount_due > 0 && $amount_paid >= $amount_due) {
        return 'paid';
    }

    return 'unpaid';
}

function ieum_tuition_notice_recipients($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $phones = array();

    $primary = sql_query("
        select guardian_phone
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and guardian_phone <> ''
           and sms_tuition = 1
           and is_primary = 1
      order by sort_order asc, guardian_id asc
    ", false);
    while ($row = sql_fetch_array($primary)) {
        $phone = trim($row['guardian_phone']);
        if ($phone !== '' && !in_array($phone, $phones, true)) {
            $phones[] = $phone;
        }
    }

    if ($phones) {
        return $phones;
    }

    $guardians = sql_query("
        select guardian_phone
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and guardian_phone <> ''
           and sms_tuition = 1
      order by sort_order asc, guardian_id asc
    ", false);
    while ($row = sql_fetch_array($guardians)) {
        $phone = trim($row['guardian_phone']);
        if ($phone !== '' && !in_array($phone, $phones, true)) {
            $phones[] = $phone;
        }
    }

    return $phones;
}

function ieum_tuition_build_notice_message($academy_name, $student_name, $billing_month, $amount_due, $amount_paid, $due_date, $notice_type = 'due', $template_override = '')
{
    $balance = max(0, (int) $amount_due - (int) $amount_paid);
    $template_key = $notice_type === 'overdue' ? 'tuition_overdue' : 'tuition_due';
    $academy_id = isset($GLOBALS['ieum_tuition_template_academy_id']) ? (int) $GLOBALS['ieum_tuition_template_academy_id'] : 0;
    $message = trim((string) $template_override);
    $template = null;
    if ($message === '') {
        $template = $academy_id ? ieum_tuition_get_sms_template($academy_id, $template_key) : null;
        $message = $template && !empty($template['is_active']) ? $template['message'] : '';
    }
    if ($message === '') {
        $defaults = ieum_tuition_default_sms_templates();
        $message = isset($defaults[$template_key]) ? $defaults[$template_key]['message'] : '';
    }

    return ieum_tuition_render_template($message, array(
        'academy_name' => $academy_name,
        'student_name' => $student_name,
        'billing_month' => $billing_month,
        'amount_due' => number_format((int) $amount_due),
        'amount_paid' => number_format((int) $amount_paid),
        'balance' => number_format($balance),
        'due_date' => $due_date,
        '도장명' => $academy_name,
        '학생명' => $student_name,
        '원생명' => $student_name,
        '청구월' => $billing_month,
        '청구액' => number_format((int) $amount_due),
        '입금액' => number_format((int) $amount_paid),
        '잔액' => number_format($balance),
        '금액' => number_format($balance),
        '납부일' => $due_date,
    ));
}

function ieum_tuition_render_notice_message_from_template($template_message, $academy_name, $student_name, $billing_month, $amount_due, $amount_paid, $due_date)
{
    $balance = max(0, (int) $amount_due - (int) $amount_paid);

    return ieum_tuition_render_template($template_message, array(
        'academy_name' => $academy_name,
        'student_name' => $student_name,
        'billing_month' => $billing_month,
        'amount_due' => number_format((int) $amount_due),
        'amount_paid' => number_format((int) $amount_paid),
        'balance' => number_format($balance),
        'due_date' => $due_date,
        '도장명' => $academy_name,
        '학생명' => $student_name,
        '원생명' => $student_name,
        '청구월' => $billing_month,
        '청구액' => number_format((int) $amount_due),
        '입금액' => number_format((int) $amount_paid),
        '잔액' => number_format($balance),
        '금액' => number_format($balance),
        '납부일' => $due_date,
    ));
}

function ieum_tuition_sample_payment($academy_id)
{
    $academy_id = (int) $academy_id;

    $payment = sql_fetch("
        select p.*, s.student_name, s.student_code, a.academy_name
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id and s.academy_id = p.academy_id
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = p.academy_id
         where p.academy_id = '{$academy_id}'
           and p.status in ('unpaid', 'partial')
      order by p.due_date asc, p.payment_id asc
         limit 1
    ", false);

    if (isset($payment['payment_id'])) {
        return $payment;
    }

    $student = sql_fetch("
        select s.student_id, s.student_name, s.student_code, s.tuition_amount, s.tuition_due_day, a.academy_name
          from " . IEUM_STUDENT_TABLE . " s
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
      order by s.student_name asc
         limit 1
    ", false);

    if (!isset($student['student_id'])) {
        return null;
    }

    $billing_month = ieum_tuition_billing_month();
    $amount_due = ieum_tuition_student_amount($student);
    return array(
        'payment_id' => 0,
        'academy_id' => $academy_id,
        'student_id' => (int) $student['student_id'],
        'student_name' => $student['student_name'],
        'student_code' => $student['student_code'],
        'academy_name' => $student['academy_name'],
        'billing_month' => $billing_month,
        'due_date' => ieum_tuition_due_date($billing_month, isset($student['tuition_due_day']) ? (int) $student['tuition_due_day'] : 5),
        'amount_due' => $amount_due,
        'amount_paid' => 0,
    );
}

function ieum_tuition_send_payment_notice($payment_id, $notice_type = 'due', $force = false, $template_override = '')
{
    $payment_id = (int) $payment_id;
    $payment = sql_fetch("
        select p.*, s.student_name, s.student_code, a.academy_name
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id and s.academy_id = p.academy_id
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = p.academy_id
         where p.payment_id = '{$payment_id}'
         limit 1
    ", false);

    if (!isset($payment['payment_id']) || $payment['status'] === 'paid') {
        return array('created' => 0, 'sms_ids' => array(), 'message' => '');
    }

    $today_start = G5_TIME_YMD . ' 00:00:00';
    if (!$force && !empty($payment['notice_sent_at']) && $payment['notice_sent_at'] >= $today_start) {
        return array('created' => 0, 'sms_ids' => array(), 'message' => 'already_sent_today');
    }

    $recipients = ieum_tuition_notice_recipients((int) $payment['academy_id'], (int) $payment['student_id']);
    if (!$recipients) {
        return array('created' => 0, 'sms_ids' => array(), 'message' => 'no_recipient');
    }

    $GLOBALS['ieum_tuition_template_academy_id'] = (int) $payment['academy_id'];
    $message = ieum_tuition_build_notice_message(
        $payment['academy_name'],
        $payment['student_name'],
        $payment['billing_month'],
        (int) $payment['amount_due'],
        (int) $payment['amount_paid'],
        $payment['due_date'],
        $notice_type,
        $template_override
    );

    $sms_ids = array();
    foreach ($recipients as $phone) {
        $sms_type = $notice_type === 'overdue' ? 'tuition_overdue' : 'tuition_due';
        $sms_id = ieum_create_direct_sms_queue((int) $payment['academy_id'], $phone, $message, $sms_type, (int) $payment['student_id'], 0);
        if ($sms_id) {
            $sms_ids[] = $sms_id;
        }
    }

    if ($sms_ids) {
        sql_query("
            update " . IEUM_TUITION_PAYMENT_TABLE . "
               set notice_sent_at = '" . G5_TIME_YMDHIS . "',
                   notice_count = notice_count + 1,
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where payment_id = '{$payment_id}'
        ");
    }

    return array('created' => count($sms_ids), 'sms_ids' => $sms_ids, 'message' => $message);
}

function ieum_tuition_send_due_notices($academy_id = 0, $target_date = '', $dry_run = false, $mode = 'due')
{
    $academy_id = (int) $academy_id;
    $target_date = $target_date !== '' ? $target_date : G5_TIME_YMD;
    $target_sql = sql_escape_string($target_date);
    if ($mode === 'overdue') {
        $where = "p.status in ('unpaid','partial')";
    } else {
        $where = "p.due_date = '{$target_sql}' and p.status in ('unpaid','partial')";
    }
    if ($academy_id) {
        $where .= " and p.academy_id = '{$academy_id}'";
    }

    $rows = sql_query("
        select p.payment_id, p.academy_id, p.due_date
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = p.academy_id
         where {$where}
           and a.is_active = 1
           and a.service_status = 'active'
    ", false);

    $created = 0;
    $checked = 0;
    $details = array();
    while ($row = sql_fetch_array($rows)) {
        $settings = ieum_tuition_get_settings((int) $row['academy_id']);
        if ($mode === 'due' && empty($settings['due_notice_enabled'])) {
            continue;
        }
        if ($mode === 'overdue') {
            $after_days = max(1, min(30, (int) $settings['overdue_after_days']));
            if (empty($settings['overdue_notice_enabled']) || (int) floor((strtotime($target_date) - strtotime($row['due_date'])) / 86400) <= $after_days) {
                continue;
            }
        }
        $checked++;
        if ($dry_run) {
            $details[] = array('payment_id' => (int) $row['payment_id'], 'dry_run' => true);
            continue;
        }
        $result = ieum_tuition_send_payment_notice((int) $row['payment_id'], $mode === 'overdue' ? 'overdue' : 'due', false);
        $created += (int) $result['created'];
        $details[] = array('payment_id' => (int) $row['payment_id'], 'created' => (int) $result['created']);
    }

    return array('checked' => $checked, 'created_sms' => $created, 'details' => $details);
}
