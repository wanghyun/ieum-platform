<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

define('IEUM_BILLING_SEND_FEE', 55);

function ieum_hq_wallet_ensure()
{
    $row = sql_fetch("
        select *
          from " . IEUM_HQ_BILLING_WALLET_TABLE . "
         where wallet_id = 1
         limit 1
    ", false);

    if (isset($row['wallet_id'])) {
        return $row;
    }

    sql_query("
        insert into " . IEUM_HQ_BILLING_WALLET_TABLE . "
            set wallet_id = 1,
                balance_amount = 0,
                total_charged = 0,
                total_used = 0,
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    return ieum_hq_wallet_ensure();
}

function ieum_hq_wallet_balance()
{
    $wallet = ieum_hq_wallet_ensure();
    return (int) $wallet['balance_amount'];
}

function ieum_hq_wallet_log($log_type, $amount, $balance_after, $academy_id = 0, $payment_id = 0, $description = '', $created_by = '')
{
    $log_type_sql = sql_escape_string($log_type);
    $description_sql = sql_escape_string($description);
    $created_by_sql = sql_escape_string($created_by);
    $amount = (int) $amount;
    $balance_after = (int) $balance_after;
    $academy_id = (int) $academy_id;
    $payment_id = (int) $payment_id;

    sql_query("
        insert into " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . "
            set wallet_id = 1,
                academy_id = '{$academy_id}',
                payment_id = '{$payment_id}',
                log_type = '{$log_type_sql}',
                amount = '{$amount}',
                balance_after = '{$balance_after}',
                description = '{$description_sql}',
                created_by = '{$created_by_sql}',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_hq_wallet_charge($amount, $description = '', $created_by = '')
{
    $amount = max(0, (int) $amount);
    if ($amount <= 0) {
        return false;
    }

    ieum_hq_wallet_ensure();
    sql_query("
        update " . IEUM_HQ_BILLING_WALLET_TABLE . "
           set balance_amount = balance_amount + '{$amount}',
               total_charged = total_charged + '{$amount}',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where wallet_id = 1
    ");

    $balance = ieum_hq_wallet_balance();
    ieum_hq_wallet_log('charge', $amount, $balance, 0, 0, $description, $created_by);
    return true;
}

function ieum_hq_wallet_deduct($amount, $academy_id = 0, $payment_id = 0, $description = '', $created_by = '')
{
    $amount = max(0, (int) $amount);
    if ($amount <= 0) {
        return false;
    }

    $wallet = ieum_hq_wallet_ensure();
    if ((int) $wallet['balance_amount'] < $amount) {
        return false;
    }

    sql_query("
        update " . IEUM_HQ_BILLING_WALLET_TABLE . "
           set balance_amount = balance_amount - '{$amount}',
               total_used = total_used + '{$amount}',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where wallet_id = 1
           and balance_amount >= '{$amount}'
    ");

    $balance = ieum_hq_wallet_balance();
    ieum_hq_wallet_log('bill_send', -1 * $amount, $balance, $academy_id, $payment_id, $description, $created_by);
    return true;
}

function ieum_billing_primary_recipients($academy_id, $student_id)
{
    require_once IEUM_PATH . '/lib/tuition.php';
    $recipients = ieum_tuition_notice_recipients($academy_id, $student_id);
    return $recipients ? $recipients : array();
}

function ieum_hq_tuition_bill_amounts($payment, $include_arrears = true)
{
    $academy_id = (int) $payment['academy_id'];
    $student_id = (int) $payment['student_id'];
    $payment_id = (int) $payment['payment_id'];
    $billing_month_sql = sql_escape_string($payment['billing_month']);
    $current_amount = max(0, (int) $payment['amount_due'] - (int) $payment['amount_paid']);
    $arrears_amount = 0;
    $arrears_months = array();

    if ($include_arrears) {
        $rows = sql_query("
            select billing_month, amount_due, amount_paid
              from " . IEUM_TUITION_PAYMENT_TABLE . "
             where academy_id = '{$academy_id}'
               and student_id = '{$student_id}'
               and payment_id <> '{$payment_id}'
               and billing_month < '{$billing_month_sql}'
               and status in ('unpaid','partial')
          order by billing_month asc
        ", false);

        while ($row = sql_fetch_array($rows)) {
            $balance = max(0, (int) $row['amount_due'] - (int) $row['amount_paid']);
            if ($balance <= 0) {
                continue;
            }
            $arrears_amount += $balance;
            $arrears_months[] = $row['billing_month'];
        }
    }

    return array(
        'current_amount' => $current_amount,
        'arrears_amount' => $arrears_amount,
        'total_amount' => $current_amount + $arrears_amount,
        'arrears_months' => $arrears_months,
    );
}

function ieum_hq_billing_grade_label($value)
{
    $labels = array(
        'kindergarten' => '유치부',
        'elementary_1' => '초등/1학년',
        'elementary_2' => '초등/2학년',
        'elementary_3' => '초등/3학년',
        'elementary_4' => '초등/4학년',
        'elementary_5' => '초등/5학년',
        'elementary_6' => '초등/6학년',
        'middle_1' => '중등/1학년',
        'middle_2' => '중등/2학년',
        'middle_3' => '중등/3학년',
        'high_1' => '고등/1학년',
        'high_2' => '고등/2학년',
        'high_3' => '고등/3학년',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value !== '' ? $value : '학년 미지정');
}

function ieum_hq_billing_class_label($class_name, $start_time)
{
    $class_name = trim((string) $class_name);
    $start_time = trim((string) $start_time);

    if ($class_name === '' && $start_time === '') {
        return '부 미지정';
    }

    return trim($class_name . ' ' . $start_time);
}

function ieum_hq_billing_add_group_summary(&$summary, $detail)
{
    $key = $detail['grade_label'] !== '' ? $detail['grade_label'] : '학년 미지정';
    if (!isset($summary['group_summary'][$key])) {
        $summary['group_summary'][$key] = array(
            'grade_label' => $key,
            'student_count' => 0,
            'recipient_count' => 0,
            'arrears_student_count' => 0,
            'current_amount' => 0,
            'arrears_amount' => 0,
            'bill_amount' => 0,
        );
    }

    $summary['group_summary'][$key]['student_count']++;
    $summary['group_summary'][$key]['recipient_count'] += (int) $detail['recipient_count'];
    $summary['group_summary'][$key]['current_amount'] += (int) $detail['current_amount'];
    $summary['group_summary'][$key]['arrears_amount'] += (int) $detail['arrears_amount'];
    $summary['group_summary'][$key]['bill_amount'] += (int) $detail['bill_amount'];
    if ((int) $detail['arrears_amount'] > 0) {
        $summary['group_summary'][$key]['arrears_student_count']++;
    }
}

function ieum_hq_send_tuition_bill($payment_id, $created_by = '')
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

    if (!isset($payment['payment_id'])) {
        return array('created' => 0, 'fee' => 0, 'message' => 'not_found');
    }

    if ($payment['status'] === 'paid') {
        return array('created' => 0, 'fee' => 0, 'message' => 'already_paid');
    }

    require_once IEUM_PATH . '/lib/tuition.php';
    $settings = ieum_tuition_get_settings((int) $payment['academy_id']);
    $include_arrears = !isset($settings['bill_auto_include_arrears']) || !empty($settings['bill_auto_include_arrears']);
    $bill_amounts = ieum_hq_tuition_bill_amounts($payment, $include_arrears);
    if ((int) $bill_amounts['total_amount'] <= 0) {
        return array('created' => 0, 'fee' => 0, 'message' => 'already_paid');
    }

    $academy_id = (int) $payment['academy_id'];
    $student_id = (int) $payment['student_id'];
    $recipients = ieum_billing_primary_recipients($academy_id, $student_id);
    if (!$recipients) {
        return array('created' => 0, 'fee' => 0, 'message' => 'no_recipient');
    }

    $send_fee = IEUM_BILLING_SEND_FEE;
    $total_fee = count($recipients) * $send_fee;
    if (ieum_hq_wallet_balance() < $total_fee) {
        return array('created' => 0, 'fee' => $total_fee, 'message' => 'insufficient_balance');
    }

    $wallet_description = $payment['academy_name'] . ' 청구서 발송 ' . number_format((int) $bill_amounts['total_amount']) . '원';
    if ((int) $bill_amounts['arrears_amount'] > 0) {
        $wallet_description .= ' (이전 미납 포함)';
    }
    if (!ieum_hq_wallet_deduct($total_fee, $academy_id, $payment_id, $wallet_description, $created_by)) {
        return array('created' => 0, 'fee' => $total_fee, 'message' => 'insufficient_balance');
    }

    $created = 0;
    $provider = 'payssam_mock';
    foreach ($recipients as $phone) {
        $external_bill_id = 'MOCK-' . $payment_id . '-' . date('YmdHis') . '-' . ($created + 1);
        $payment_link = IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($payment['billing_month']);
        $arrears_months_sql = sql_escape_string(implode(',', $bill_amounts['arrears_months']));
        sql_query("
            insert into " . IEUM_BILLING_SEND_LOG_TABLE . "
                set academy_id = '{$academy_id}',
                    payment_id = '{$payment_id}',
                    student_id = '{$student_id}',
                    provider = '{$provider}',
                    external_bill_id = '" . sql_escape_string($external_bill_id) . "',
                    recipient_phone = '" . sql_escape_string($phone) . "',
                    send_type = 'tuition_bill',
                    bill_amount = '" . (int) $bill_amounts['total_amount'] . "',
                    current_amount = '" . (int) $bill_amounts['current_amount'] . "',
                    arrears_amount = '" . (int) $bill_amounts['arrears_amount'] . "',
                    arrears_months = '{$arrears_months_sql}',
                    send_fee = '{$send_fee}',
                    status = 'mock_sent',
                    error_message = '',
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
        $created++;
    }

    sql_query("
        update " . IEUM_TUITION_PAYMENT_TABLE . "
           set payment_provider = '{$provider}',
               external_bill_id = '" . sql_escape_string('MOCK-' . $payment_id) . "',
               payment_link = '" . sql_escape_string(IEUM_URL . '/admin/tuition_payments.php?billing_month=' . $payment['billing_month']) . "',
               provider_status = 'bill_sent',
               bill_sent_at = '" . G5_TIME_YMDHIS . "',
               bill_send_count = bill_send_count + '{$created}',
               bill_send_fee_total = bill_send_fee_total + '{$total_fee}',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where payment_id = '{$payment_id}'
    ");

    return array(
        'created' => $created,
        'fee' => $total_fee,
        'message' => 'sent',
        'bill_amount' => (int) $bill_amounts['total_amount'],
        'current_amount' => (int) $bill_amounts['current_amount'],
        'arrears_amount' => (int) $bill_amounts['arrears_amount'],
        'arrears_months' => $bill_amounts['arrears_months'],
    );
}

function ieum_hq_tuition_bill_preview($academy_id, $billing_month)
{
    require_once IEUM_PATH . '/lib/tuition.php';

    $academy_id = (int) $academy_id;
    $billing_month = preg_match('/^\d{4}\-\d{2}$/', $billing_month) ? $billing_month : ieum_tuition_billing_month();
    $billing_month_sql = sql_escape_string($billing_month);
    $settings = ieum_tuition_get_settings($academy_id);
    $include_arrears = !isset($settings['bill_auto_include_arrears']) || !empty($settings['bill_auto_include_arrears']);
    $scope = isset($settings['bill_auto_send_scope']) ? $settings['bill_auto_send_scope'] : 'all';

    $summary = array(
        'billing_month' => $billing_month,
        'auto_enabled' => !empty($settings['bill_auto_send_enabled']) ? 1 : 0,
        'auto_send_day' => isset($settings['bill_auto_send_day']) ? (int) $settings['bill_auto_send_day'] : 5,
        'scope' => $scope,
        'include_arrears' => $include_arrears ? 1 : 0,
        'total_count' => 0,
        'target_count' => 0,
        'paid_excluded_count' => 0,
        'already_sent_excluded_count' => 0,
        'selected_excluded_count' => 0,
        'zero_amount_excluded_count' => 0,
        'no_recipient_count' => 0,
        'arrears_student_count' => 0,
        'recipient_count' => 0,
        'current_amount' => 0,
        'arrears_amount' => 0,
        'bill_amount' => 0,
        'send_fee' => 0,
        'group_summary' => array(),
        'details' => array(),
        'excluded_details' => array(),
    );

    $rows = sql_query("
        select p.*, s.student_name, s.student_code, s.grade_group,
               c.class_name, c.start_time, c.sort_order
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id and s.academy_id = p.academy_id
     left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
         where p.academy_id = '{$academy_id}'
           and p.billing_month = '{$billing_month_sql}'
      order by p.due_date asc, s.grade_group asc, c.sort_order asc, c.start_time asc, s.student_name asc
    ", false);

    while ($row = sql_fetch_array($rows)) {
        $summary['total_count']++;
        $grade_label = ieum_hq_billing_grade_label($row['grade_group']);
        $class_label = ieum_hq_billing_class_label($row['class_name'], $row['start_time']);
        if ($row['status'] === 'paid') {
            $summary['paid_excluded_count']++;
            continue;
        }
        if (!empty($row['bill_sent_at']) && $row['bill_sent_at'] !== '0000-00-00 00:00:00') {
            $summary['already_sent_excluded_count']++;
            continue;
        }
        if ($scope === 'selected' && empty($row['bill_auto_send_enabled'])) {
            $summary['selected_excluded_count']++;
            continue;
        }

        $amounts = ieum_hq_tuition_bill_amounts($row, $include_arrears);
        if ((int) $amounts['total_amount'] <= 0) {
            $summary['zero_amount_excluded_count']++;
            continue;
        }

        $recipients = ieum_billing_primary_recipients($academy_id, (int) $row['student_id']);
        if (!$recipients) {
            $summary['no_recipient_count']++;
            $summary['excluded_details'][] = array(
                'payment_id' => (int) $row['payment_id'],
                'student_name' => $row['student_name'],
                'student_code' => $row['student_code'],
                'grade_group' => $row['grade_group'],
                'grade_label' => $grade_label,
                'class_label' => $class_label,
                'reason' => '수신 보호자 없음',
                'current_amount' => (int) $amounts['current_amount'],
                'arrears_amount' => (int) $amounts['arrears_amount'],
                'bill_amount' => (int) $amounts['total_amount'],
                'arrears_months' => $amounts['arrears_months'],
            );
            continue;
        }

        $recipient_count = count($recipients);
        $summary['target_count']++;
        $summary['recipient_count'] += $recipient_count;
        $summary['current_amount'] += (int) $amounts['current_amount'];
        $summary['arrears_amount'] += (int) $amounts['arrears_amount'];
        $summary['bill_amount'] += (int) $amounts['total_amount'];
        $summary['send_fee'] += $recipient_count * IEUM_BILLING_SEND_FEE;
        if ((int) $amounts['arrears_amount'] > 0) {
            $summary['arrears_student_count']++;
        }
        $detail = array(
            'payment_id' => (int) $row['payment_id'],
            'student_name' => $row['student_name'],
            'student_code' => $row['student_code'],
            'grade_group' => $row['grade_group'],
            'grade_label' => $grade_label,
            'class_label' => $class_label,
            'recipient_count' => $recipient_count,
            'current_amount' => (int) $amounts['current_amount'],
            'arrears_amount' => (int) $amounts['arrears_amount'],
            'bill_amount' => (int) $amounts['total_amount'],
            'arrears_months' => $amounts['arrears_months'],
        );
        $summary['details'][] = $detail;
        ieum_hq_billing_add_group_summary($summary, $detail);
    }

    return $summary;
}

function ieum_hq_send_due_tuition_bills($academy_id = 0, $target_date = '', $dry_run = false, $created_by = 'auto')
{
    require_once IEUM_PATH . '/lib/tuition.php';

    $academy_id = (int) $academy_id;
    $target_date = $target_date !== '' ? preg_replace('/[^0-9\-]/', '', $target_date) : G5_TIME_YMD;
    if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $target_date)) {
        $target_date = G5_TIME_YMD;
    }
    $target_time = strtotime($target_date);
    $target_month = date('Y-m', $target_time);
    $target_day = (int) date('j', $target_time);
    $target_last_day = (int) date('t', $target_time);
    $target_month_sql = sql_escape_string($target_month);

    $where = "p.billing_month = '{$target_month_sql}' and p.status in ('unpaid','partial') and (p.bill_sent_at is null or p.bill_sent_at = '0000-00-00 00:00:00')";
    if ($academy_id) {
        $where .= " and p.academy_id = '{$academy_id}'";
    }

    $rows = sql_query("
        select p.payment_id, p.academy_id, p.bill_auto_send_enabled
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = p.academy_id
         where {$where}
           and a.is_active = 1
           and a.service_status = 'active'
      order by p.academy_id asc, p.payment_id asc
    ", false);

    $checked = 0;
    $sent = 0;
    $fee = 0;
    $skipped = 0;
    $details = array();
    while ($row = sql_fetch_array($rows)) {
        $settings = ieum_tuition_get_settings((int) $row['academy_id']);
        $enabled = !empty($settings['bill_auto_send_enabled']);
        $send_day = isset($settings['bill_auto_send_day']) ? (int) $settings['bill_auto_send_day'] : 5;
        $send_day = max(1, min(31, $send_day));
        $effective_send_day = min($send_day, $target_last_day);
        $scope = isset($settings['bill_auto_send_scope']) ? $settings['bill_auto_send_scope'] : 'all';
        if (!$enabled) {
            continue;
        }
        if ($target_day !== $effective_send_day) {
            continue;
        }
        if ($scope === 'selected' && empty($row['bill_auto_send_enabled'])) {
            $skipped++;
            continue;
        }

        $checked++;
        if ($dry_run) {
            $details[] = array('payment_id' => (int) $row['payment_id'], 'dry_run' => true);
            continue;
        }

        $result = ieum_hq_send_tuition_bill((int) $row['payment_id'], $created_by);
        if ((int) $result['created'] > 0) {
            $sent += (int) $result['created'];
            $fee += (int) $result['fee'];
        } else {
            $skipped++;
        }
        $details[] = array(
            'payment_id' => (int) $row['payment_id'],
            'created' => (int) $result['created'],
            'fee' => (int) $result['fee'],
            'bill_amount' => isset($result['bill_amount']) ? (int) $result['bill_amount'] : 0,
            'arrears_amount' => isset($result['arrears_amount']) ? (int) $result['arrears_amount'] : 0,
            'arrears_months' => isset($result['arrears_months']) ? $result['arrears_months'] : array(),
            'message' => $result['message'],
        );
    }

    return array(
        'target_date' => $target_date,
        'target_month' => $target_month,
        'checked_payments' => $checked,
        'sent_count' => $sent,
        'used_fee' => $fee,
        'skipped_count' => $skipped,
        'details' => $details,
    );
}
