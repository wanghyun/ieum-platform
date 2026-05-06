<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_tuition_billing_month($time = null)
{
    return date('Y-m', $time ? (int) $time : strtotime(G5_TIME_YMDHIS));
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
    if ($amount <= 0 && isset($student['academy_id'])) {
        $week_type = isset($student['tuition_week_type']) ? preg_replace('/[^0-9a-z_]/', '', $student['tuition_week_type']) : '';
        if ($week_type !== '') {
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
                $amount = (int) $plan['monthly_fee'];
                if (!empty($student['sibling_discount_enabled']) && empty($student['sibling_discount_amount'])) {
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
                   and amount_due = 0
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
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
        $created++;
    }

    return $created;
}
