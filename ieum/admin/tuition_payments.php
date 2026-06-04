<?php
$sub_menu = '950175';
require_once './_common.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/hq_billing.php';
require_once IEUM_PATH . '/lib/paymint.php';

$g5['title'] = '아이이음 수련비 납부';
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
$preview_filter = isset($_GET['preview_filter']) ? preg_replace('/[^0-9a-z_]/', '', trim($_GET['preview_filter'])) : 'sendable';
if (!in_array($preview_filter, array('all', 'sendable', 'arrears', 'paid', 'already_sent', 'auto_off', 'zero_amount', 'no_recipient'), true)) {
    $preview_filter = 'sendable';
}
$preview_per_page = isset($_GET['preview_per_page']) ? (int) $_GET['preview_per_page'] : 25;
if (!in_array($preview_per_page, array(10, 25, 50), true)) {
    $preview_per_page = 25;
}
$preview_page = isset($_GET['preview_page']) ? max(1, (int) $_GET['preview_page']) : 1;
$payment_per_page = isset($_GET['payment_per_page']) ? (int) $_GET['payment_per_page'] : 25;
if (!in_array($payment_per_page, array(10, 25, 50), true)) {
    $payment_per_page = 25;
}
$payment_page = isset($_GET['payment_page']) ? max(1, (int) $_GET['payment_page']) : 1;
$payment_filter = isset($_GET['payment_filter']) ? preg_replace('/[^0-9a-z_]/', '', trim($_GET['payment_filter'])) : 'all';
if (!in_array($payment_filter, array('all', 'unpaid', 'paid', 'due_today', 'due_upcoming', 'overdue_long', 'auto_on', 'family'), true)) {
    $payment_filter = 'all';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'update';
        $payment_id = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;
        if ($action === 'billing_settings') {
            $due_notice_enabled = isset($_POST['due_notice_enabled']) ? 1 : 0;
            $overdue_notice_enabled = isset($_POST['overdue_notice_enabled']) ? 1 : 0;
            $overdue_after_days = isset($_POST['overdue_after_days']) ? (int) $_POST['overdue_after_days'] : 5;
            $overdue_after_days = max(1, min(30, $overdue_after_days));
            $bill_auto_send_enabled = isset($_POST['bill_auto_send_enabled']) ? 1 : 0;
            $bill_auto_send_day = isset($_POST['bill_auto_send_day']) ? (int) $_POST['bill_auto_send_day'] : 5;
            $bill_auto_send_day = max(1, min(31, $bill_auto_send_day));
            $bill_auto_send_scope = isset($_POST['bill_auto_send_scope']) && $_POST['bill_auto_send_scope'] === 'selected' ? 'selected' : 'all';
            $bill_auto_include_arrears = isset($_POST['bill_auto_include_arrears']) ? 1 : 0;
            sql_query("
                insert into " . IEUM_TUITION_SETTING_TABLE . "
                    set academy_id = '{$academy_id}',
                        due_notice_enabled = '{$due_notice_enabled}',
                        overdue_notice_enabled = '{$overdue_notice_enabled}',
                        overdue_after_days = '{$overdue_after_days}',
                        bill_auto_send_enabled = '{$bill_auto_send_enabled}',
                        bill_auto_send_day = '{$bill_auto_send_day}',
                        bill_auto_send_scope = '" . sql_escape_string($bill_auto_send_scope) . "',
                        bill_auto_include_arrears = '{$bill_auto_include_arrears}',
                        updated_at = '" . G5_TIME_YMDHIS . "'
                on duplicate key update
                        due_notice_enabled = values(due_notice_enabled),
                        overdue_notice_enabled = values(overdue_notice_enabled),
                        overdue_after_days = values(overdue_after_days),
                        bill_auto_send_enabled = values(bill_auto_send_enabled),
                        bill_auto_send_day = values(bill_auto_send_day),
                        bill_auto_send_scope = values(bill_auto_send_scope),
                        bill_auto_include_arrears = values(bill_auto_include_arrears),
                        updated_at = values(updated_at)
            ");
            $message = '청구서 자동 발송 설정을 저장했습니다.';
        } elseif ($action === 'refresh_amounts') {
            $refresh = ieum_tuition_refresh_month_amounts($academy_id, $billing_month);
            $message = '이번 달 청구액을 최신 수련비 정책으로 확인했습니다.';
            if ((int) $refresh['updated'] > 0) {
                $message .= ' 변경 ' . number_format((int) $refresh['updated']) . '명';
            } else {
                $message .= ' 변경할 원생은 없습니다';
            }
            if ((int) $refresh['skipped_paid'] > 0 || (int) $refresh['skipped_sent'] > 0) {
                $message .= ' · 제외: 결제/입금 확인 ' . number_format((int) $refresh['skipped_paid']) . '명, 청구서 발송 완료 ' . number_format((int) $refresh['skipped_sent']) . '명';
            }
        } elseif ($action === 'send_month_bills') {
            $confirm_bill_send = isset($_POST['confirm_bill_send']) ? (int) $_POST['confirm_bill_send'] : 0;
            $preview = ieum_hq_tuition_bill_preview($academy_id, $billing_month);
            if (!$confirm_bill_send) {
                $error = '청구서 발송 전 최종 확인이 필요합니다.';
            } elseif (empty($preview['details'])) {
                $error = '이번 달 청구서 발송 대상이 없습니다.';
            } elseif (!ieum_paymint_academy_is_ready($academy_id)) {
                $error = '결제선생 도장 결제 계정 연동 완료 후 청구서 발송이 가능합니다.';
            } else {
                $created = 0;
                $fee = 0;
                $failed = 0;
                foreach ($preview['details'] as $detail) {
                    $result = ieum_hq_send_tuition_bill((int) $detail['payment_id'], isset($member['mb_id']) ? $member['mb_id'] : '');
                    if ((int) $result['created'] > 0) {
                        $created += (int) $result['created'];
                        $fee += (int) $result['fee'];
                    } elseif (isset($result['message']) && $result['message'] === 'already_paid') {
                        continue;
                    } else {
                        $failed++;
                    }
                }
                if ($created > 0) {
                    $message = '이번 달 청구서 ' . number_format($created) . '건을 발송 처리했습니다.';
                    if ($failed > 0) {
                        $message .= ' 확인 필요 ' . number_format($failed) . '건이 있습니다.';
                    }
                    if ($is_admin === 'super') {
                        $message .= ' 본사 쌤포인트에서 ' . number_format($fee) . 'P가 사용되었습니다.';
                    }
                } else {
                    $error = '청구서 발송 처리된 건이 없습니다. 보호자 연락처, 결제 상태, 본사 쌤포인트 잔액을 확인하세요.';
                }
            }
        } elseif ($action === 'bulk_mark_paid') {
            $payment_ids = isset($_POST['payment_ids']) && is_array($_POST['payment_ids']) ? $_POST['payment_ids'] : array();
            $ids = array();
            foreach ($payment_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                $error = '일괄 완납 처리할 원생을 선택하세요.';
            } else {
                $id_sql = implode(',', $ids);
                sql_query("
                    update " . IEUM_TUITION_PAYMENT_TABLE . "
                       set status = 'paid',
                           amount_paid = amount_due,
                           paid_at = '" . G5_TIME_YMDHIS . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and payment_id in ({$id_sql})
                ");
                $message = '선택한 수련비 ' . number_format(count($ids)) . '건을 결제완료 처리했습니다.';
            }
        } elseif ($action === 'bulk_send_bill') {
            $confirm_bill_send = isset($_POST['confirm_bill_send']) ? (int) $_POST['confirm_bill_send'] : 0;
            $payment_ids = isset($_POST['payment_ids']) && is_array($_POST['payment_ids']) ? $_POST['payment_ids'] : array();
            $ids = array();
            foreach ($payment_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
            if (!$confirm_bill_send) {
                $error = '청구서 발송 전 최종 확인이 필요합니다.';
            } elseif (!$ids) {
                $error = '청구서를 발송할 원생을 선택하세요.';
            } elseif (!ieum_paymint_academy_is_ready($academy_id)) {
                $error = '결제선생 도장 결제 계정 연동 완료 후 청구서 발송이 가능합니다.';
            } else {
                $created = 0;
                $fee = 0;
                $failed = 0;
                foreach ($ids as $id) {
                    $row = sql_fetch("
                        select payment_id
                          from " . IEUM_TUITION_PAYMENT_TABLE . "
                         where academy_id = '{$academy_id}'
                           and payment_id = '{$id}'
                         limit 1
                    ", false);
                    if (!isset($row['payment_id'])) {
                        $failed++;
                        continue;
                    }
                    $result = ieum_hq_send_tuition_bill($id, isset($member['mb_id']) ? $member['mb_id'] : '');
                    if ((int) $result['created'] > 0) {
                        $created += (int) $result['created'];
                        $fee += (int) $result['fee'];
                    } elseif (isset($result['message']) && $result['message'] === 'already_paid') {
                        continue;
                    } else {
                        $failed++;
                    }
                }
                if ($created > 0) {
                    $message = '선택한 청구서 ' . number_format($created) . '건을 발송 처리했습니다.';
                    if ($failed > 0) {
                        $message .= ' 확인 필요 ' . number_format($failed) . '건이 있습니다.';
                    }
                    if ($is_admin === 'super') {
                        $message .= ' 본사 쌤포인트에서 ' . number_format($fee) . 'P가 사용되었습니다.';
                    }
                } else {
                    $error = '청구서 발송 처리된 건이 없습니다. 보호자 연락처, 결제 상태, 본사 쌤포인트 잔액을 확인하세요.';
                }
            }
        } elseif ($action === 'mark_paid') {
            $payment = sql_fetch("
                select amount_due
                  from " . IEUM_TUITION_PAYMENT_TABLE . "
                 where academy_id = '{$academy_id}'
                   and payment_id = '{$payment_id}'
                 limit 1
            ", false);
            if (isset($payment['amount_due'])) {
                sql_query("
                    update " . IEUM_TUITION_PAYMENT_TABLE . "
                       set status = 'paid',
                           amount_paid = '" . (int) $payment['amount_due'] . "',
                           paid_at = '" . G5_TIME_YMDHIS . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and payment_id = '{$payment_id}'
                ");
                $message = '결제완료 처리했습니다.';
            }
        } elseif ($action === 'send_notice') {
            $notice_type = isset($_POST['notice_type']) && $_POST['notice_type'] === 'overdue' ? 'overdue' : 'due';
            $notice_template_key = $notice_type === 'overdue' ? 'tuition_overdue' : 'tuition_due';
            $notice_template_override = isset($_POST['notice_message_template']) ? trim((string) $_POST['notice_message_template']) : '';
            if ($notice_template_override !== '' && !empty($_POST['save_notice_template'])) {
                ieum_tuition_save_sms_template($academy_id, $notice_template_key, $notice_template_override);
            }
            $result = ieum_tuition_send_payment_notice($payment_id, $notice_type, true, $notice_template_override);
            if ($result['created'] > 0) {
                $message = '수련비 안내 문자 ' . number_format((int) $result['created']) . '건을 발송 준비했습니다.';
                if ($notice_template_override !== '' && !empty($_POST['save_notice_template'])) {
                    $message .= ' 문구도 다음 발송용으로 저장했습니다.';
                }
            } elseif ($result['message'] === 'no_recipient') {
                $error = '문자를 받을 보호자 연락처가 없습니다.';
            } else {
                $error = '문자를 발송 준비하지 못했습니다.';
            }
        } elseif ($action === 'send_bill') {
            $confirm_bill_send = isset($_POST['confirm_bill_send']) ? (int) $_POST['confirm_bill_send'] : 0;
            if (!$confirm_bill_send) {
                $error = '청구서 발송 전 최종 확인이 필요합니다.';
            } else {
            $result = ieum_hq_send_tuition_bill($payment_id, isset($member['mb_id']) ? $member['mb_id'] : '');
            if ($result['created'] > 0) {
                $message = '청구서 ' . number_format((int) $result['created']) . '건을 발송 처리했습니다.';
                if ($is_admin === 'super') {
                    $message .= ' 본사 쌤포인트에서 ' . number_format((int) $result['fee']) . 'P가 사용되었습니다.';
                }
            } elseif ($result['message'] === 'insufficient_balance') {
                $error = $is_admin === 'super' ? '본사 쌤포인트 잔액이 부족합니다.' : '청구서 발송 준비금 확인이 필요합니다. 본사에 문의해 주세요.';
            } elseif ($result['message'] === 'no_recipient') {
                $error = '청구서를 받을 보호자 연락처가 없습니다.';
            } elseif ($result['message'] === 'already_paid') {
                $error = '이미 결제완료된 수련비입니다.';
            } elseif ($result['message'] === 'paymint_not_ready') {
                $error = '결제선생 도장 결제 계정 연동 완료 후 청구서 발송이 가능합니다.';
            } else {
                $error = '청구서 발송 처리를 완료하지 못했습니다.';
            }
            }
        } elseif ($action === 'resend_paymint_bill') {
            $paymint_bill_row_id = isset($_POST['paymint_bill_row_id']) ? (int) $_POST['paymint_bill_row_id'] : 0;
            $bill = ieum_paymint_get_bill($paymint_bill_row_id, $academy_id);
            if (!$bill) {
                $error = '청구서 정보를 찾을 수 없습니다.';
            } elseif (ieum_hq_billing_available_balance() < IEUM_BILLING_SEND_FEE) {
                $error = $is_admin === 'super' ? '본사 쌤포인트 잔액이 부족합니다.' : '청구서 재발송 준비금 확인이 필요합니다. 본사에 문의해 주세요.';
            } else {
                $result = ieum_paymint_resend_bill($paymint_bill_row_id, isset($member['mb_id']) ? $member['mb_id'] : '');
                if (!empty($result['ok'])) {
                    ieum_hq_billing_use_send_fee(IEUM_BILLING_SEND_FEE, $academy_id, (int) $bill['payment_id'], $academy['academy_name'] . ' 청구서 재발송 ' . $bill['bill_id'], isset($member['mb_id']) ? $member['mb_id'] : '');
                    $message = '청구서를 재발송했습니다.';
                } elseif (isset($result['message']) && $result['message'] === 'bill_not_resendable') {
                    $error = '이미 결제완료/파기된 청구서는 재발송할 수 없습니다.';
                } else {
                    $error = '청구서 재발송을 완료하지 못했습니다.';
                }
            }
        } elseif ($action === 'destroy_paymint_bill') {
            $paymint_bill_row_id = isset($_POST['paymint_bill_row_id']) ? (int) $_POST['paymint_bill_row_id'] : 0;
            $bill = ieum_paymint_get_bill($paymint_bill_row_id, $academy_id);
            if (!$bill) {
                $error = '청구서 정보를 찾을 수 없습니다.';
            } else {
                $result = ieum_paymint_destroy_bill($paymint_bill_row_id, isset($member['mb_id']) ? $member['mb_id'] : '');
                if (!empty($result['ok'])) {
                    $message = '청구서를 파기했습니다. 같은 수련비는 다시 청구서 발송이 가능합니다.';
                } elseif (isset($result['message']) && $result['message'] === 'bill_already_paid') {
                    $error = '이미 결제완료된 청구서는 파기할 수 없습니다.';
                } elseif (isset($result['message']) && $result['message'] === 'bill_already_destroyed') {
                    $error = '이미 파기된 청구서입니다.';
                } else {
                    $error = '청구서 파기를 완료하지 못했습니다.';
                }
            }
        } elseif ($action === 'read_paymint_bill') {
            $paymint_bill_row_id = isset($_POST['paymint_bill_row_id']) ? (int) $_POST['paymint_bill_row_id'] : 0;
            $bill = ieum_paymint_get_bill($paymint_bill_row_id, $academy_id);
            if (!$bill) {
                $error = '청구서 정보를 찾을 수 없습니다.';
            } else {
                $result = ieum_paymint_read_bill($paymint_bill_row_id);
                if (!empty($result['ok'])) {
                    if (isset($result['appr_state']) && $result['appr_state'] === 'F') {
                        $message = '결제선생 결제완료를 확인했고 수련비를 완납 처리했습니다.';
                    } else {
                        $message = '결제선생 청구서 상태를 확인했습니다. 현재 상태: ' . ieum_paymint_bill_status_label($result['status'], $result['appr_state']);
                    }
                } else {
                    $error = '결제선생 청구서 상태 확인에 실패했습니다. ' . (isset($result['message']) ? $result['message'] : '');
                }
            }
        } else {
            $amount_due = isset($_POST['amount_due']) ? max(0, (int) $_POST['amount_due']) : 0;
            $amount_paid = isset($_POST['amount_paid']) ? max(0, (int) $_POST['amount_paid']) : 0;
            $bill_auto_send_enabled = isset($_POST['bill_auto_send_enabled']) ? 1 : 0;
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
            $status = ieum_tuition_payment_auto_status($amount_due, $amount_paid);

            $paid_at_sql = $status === 'paid' ? "'" . G5_TIME_YMDHIS . "'" : 'null';
            sql_query("
                update " . IEUM_TUITION_PAYMENT_TABLE . "
                   set amount_due = '{$amount_due}',
                       status = '" . sql_escape_string($status) . "',
                       amount_paid = '{$amount_paid}',
                       bill_auto_send_enabled = '{$bill_auto_send_enabled}',
                       memo = '" . sql_escape_string($memo) . "',
                       paid_at = {$paid_at_sql},
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and payment_id = '{$payment_id}'
            ");
            $message = '수련비 납부 상태를 저장했습니다.';
        }
    }
}

$created = ieum_tuition_ensure_month($academy_id, $billing_month);
if ($created > 0 && $message === '') {
    $message = $billing_month . ' 수련비 대상 ' . number_format($created) . '명을 생성했습니다.';
}

$csrf_token = ieum_new_csrf_token();
$hq_wallet_balance = ieum_hq_billing_available_balance();
$paymint_ready = ieum_paymint_academy_is_ready($academy_id);
$paymint_readiness_label = ieum_paymint_academy_readiness_label($academy_id);
$settings = ieum_tuition_get_settings($academy_id);
$overdue_days = max(1, min(30, (int) (isset($settings['overdue_after_days']) ? $settings['overdue_after_days'] : 5)));
$bill_preview = ieum_hq_tuition_bill_preview($academy_id, $billing_month);
$bill_excluded_total = (int) $bill_preview['paid_excluded_count']
    + (int) $bill_preview['already_sent_excluded_count']
    + (int) $bill_preview['selected_excluded_count']
    + (int) $bill_preview['zero_amount_excluded_count']
    + (int) $bill_preview['no_recipient_count'];
$bill_confirm_summary = array(
    'ok:발송 예정 ' . number_format((int) $bill_preview['target_count']) . '건',
    'info:수신자 ' . number_format((int) $bill_preview['recipient_count']) . '건',
    'info:청구 예정 ' . number_format((int) $bill_preview['bill_amount']) . '원',
    ($bill_excluded_total > 0 ? 'warn:' : 'muted:') . '제외 ' . number_format($bill_excluded_total) . '명',
);
if ((int) $bill_preview['paid_excluded_count'] > 0) {
    $bill_confirm_summary[] = 'ok:완납 제외 ' . number_format((int) $bill_preview['paid_excluded_count']) . '명';
}
if ((int) $bill_preview['already_sent_excluded_count'] > 0) {
    $bill_confirm_summary[] = 'muted:이미 발송 ' . number_format((int) $bill_preview['already_sent_excluded_count']) . '명';
}
if ((int) $bill_preview['zero_amount_excluded_count'] > 0) {
    $bill_confirm_summary[] = 'muted:잔액 0원 ' . number_format((int) $bill_preview['zero_amount_excluded_count']) . '명';
}
if ((int) $bill_preview['selected_excluded_count'] > 0) {
    $bill_confirm_summary[] = 'warn:자동청구 꺼짐 ' . number_format((int) $bill_preview['selected_excluded_count']) . '명';
}
if ((int) $bill_preview['no_recipient_count'] > 0) {
    $bill_confirm_summary[] = 'danger:연락처 없음 ' . number_format((int) $bill_preview['no_recipient_count']) . '명';
}
if ((int) $bill_preview['arrears_student_count'] > 0) {
    $bill_confirm_summary[] = 'warn:이전 미납 포함 ' . number_format((int) $bill_preview['arrears_student_count']) . '명';
}
$preview_filter_options = array(
    'all' => array('label' => '전체', 'count' => count($bill_preview['details']) + count($bill_preview['excluded_details'])),
    'sendable' => array('label' => '발송 예정', 'count' => count($bill_preview['details'])),
    'arrears' => array('label' => '이전 미납 포함', 'count' => (int) $bill_preview['arrears_student_count']),
    'paid' => array('label' => '완납 제외', 'count' => (int) $bill_preview['paid_excluded_count']),
    'already_sent' => array('label' => '이미 발송', 'count' => (int) $bill_preview['already_sent_excluded_count']),
    'auto_off' => array('label' => '자동청구 꺼짐', 'count' => (int) $bill_preview['selected_excluded_count']),
    'zero_amount' => array('label' => '잔액 0원', 'count' => (int) $bill_preview['zero_amount_excluded_count']),
    'no_recipient' => array('label' => '연락처 없음', 'count' => (int) $bill_preview['no_recipient_count']),
);
$preview_rows = $bill_preview['details'];
if ($preview_filter === 'all') {
    $preview_rows = array_merge($bill_preview['details'], isset($bill_preview['excluded_details']) ? $bill_preview['excluded_details'] : array());
} elseif ($preview_filter === 'sendable') {
    $preview_rows = $bill_preview['details'];
} elseif ($preview_filter === 'arrears') {
    $preview_rows = array_values(array_filter($bill_preview['details'], function ($row) {
        return (int) $row['arrears_amount'] > 0;
    }));
} else {
    $preview_rows = array_values(array_filter(isset($bill_preview['excluded_details']) ? $bill_preview['excluded_details'] : array(), function ($row) use ($preview_filter) {
        return isset($row['reason_code']) && $row['reason_code'] === $preview_filter;
    }));
}
$preview_total = count($preview_rows);
$preview_total_pages = max(1, (int) ceil($preview_total / $preview_per_page));
if ($preview_page > $preview_total_pages) {
    $preview_page = $preview_total_pages;
}
$preview_offset = ($preview_page - 1) * $preview_per_page;
$preview_page_rows = array_slice($preview_rows, $preview_offset, $preview_per_page);
$month_sql = sql_escape_string($billing_month);
$summary = sql_fetch("
    select
        count(*) as total_count,
        sum(case when p.status = 'paid' then 1 else 0 end) as paid_count,
        sum(case when p.status in ('unpaid', 'partial') then 1 else 0 end) as unpaid_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date = '" . G5_TIME_YMD . "' then 1 else 0 end) as due_today_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date >= '" . G5_TIME_YMD . "' then 1 else 0 end) as due_upcoming_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date < '" . G5_TIME_YMD . "' and datediff('" . G5_TIME_YMD . "', p.due_date) <= '{$overdue_days}' then 1 else 0 end) as overdue_5_count,
        sum(case when p.status in ('unpaid', 'partial') and p.due_date < '" . G5_TIME_YMD . "' and datediff('" . G5_TIME_YMD . "', p.due_date) > '{$overdue_days}' then 1 else 0 end) as overdue_long_count,
        sum(case when p.status in ('unpaid', 'partial') and p.bill_auto_send_enabled = 1 then 1 else 0 end) as auto_on_count,
        sum(case when s.family_billing_enabled = 1 then 1 else 0 end) as family_count,
        coalesce(sum(p.amount_due), 0) as due_amount,
        coalesce(sum(p.amount_paid), 0) as paid_amount
      from " . IEUM_TUITION_PAYMENT_TABLE . " p
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
       and s.academy_id = p.academy_id
       and s.is_active = 1
     where p.academy_id = '{$academy_id}'
       and p.billing_month = '{$month_sql}'
", false);
$total_due_amount = (int) (isset($summary['due_amount']) ? $summary['due_amount'] : 0);
$total_paid_amount = (int) (isset($summary['paid_amount']) ? $summary['paid_amount'] : 0);
$remaining_amount = max(0, $total_due_amount - $total_paid_amount);
$collection_rate = $total_due_amount > 0 ? round(($total_paid_amount / $total_due_amount) * 100) : 0;
$payment_filter_options = array(
    'all' => array('label' => '전체', 'count' => (int) $summary['total_count']),
    'unpaid' => array('label' => '확인 필요', 'count' => (int) $summary['unpaid_count']),
    'paid' => array('label' => '완납', 'count' => (int) $summary['paid_count']),
    'due_upcoming' => array('label' => '납부 예정', 'count' => (int) $summary['due_upcoming_count']),
    'due_today' => array('label' => '오늘 납부일', 'count' => (int) $summary['due_today_count']),
    'overdue_long' => array('label' => '미납 ' . (int) $overdue_days . '일 초과', 'count' => (int) $summary['overdue_long_count']),
    'auto_on' => array('label' => '자동청구', 'count' => (int) $summary['auto_on_count']),
    'family' => array('label' => '가족청구', 'count' => (int) $summary['family_count']),
);
$payment_where = "p.academy_id = '{$academy_id}' and p.billing_month = '{$month_sql}'";
if ($payment_filter === 'unpaid') {
    $payment_where .= " and p.status in ('unpaid','partial')";
} elseif ($payment_filter === 'paid') {
    $payment_where .= " and p.status = 'paid'";
} elseif ($payment_filter === 'due_today') {
    $payment_where .= " and p.status in ('unpaid','partial') and p.due_date = '" . G5_TIME_YMD . "'";
} elseif ($payment_filter === 'due_upcoming') {
    $payment_where .= " and p.status in ('unpaid','partial') and p.due_date >= '" . G5_TIME_YMD . "'";
} elseif ($payment_filter === 'overdue_long') {
    $payment_where .= " and p.status in ('unpaid','partial') and p.due_date < '" . G5_TIME_YMD . "' and datediff('" . G5_TIME_YMD . "', p.due_date) > '{$overdue_days}'";
} elseif ($payment_filter === 'auto_on') {
    $payment_where .= " and p.status in ('unpaid','partial') and p.bill_auto_send_enabled = 1";
} elseif ($payment_filter === 'family') {
    $payment_where .= " and s.family_billing_enabled = 1";
}
$payment_total_row = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TUITION_PAYMENT_TABLE . " p
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
       and s.academy_id = p.academy_id
       and s.is_active = 1
     where {$payment_where}
", false);
$payment_total = (int) (isset($payment_total_row['cnt']) ? $payment_total_row['cnt'] : 0);
$payment_total_pages = max(1, (int) ceil($payment_total / $payment_per_page));
if ($payment_page > $payment_total_pages) {
    $payment_page = $payment_total_pages;
}
$payment_offset = ($payment_page - 1) * $payment_per_page;
$payment_start = $payment_total ? ($payment_offset + 1) : 0;
$payment_end = min($payment_total, $payment_offset + $payment_per_page);

$payments = sql_query("
    select p.*, s.student_name, s.student_code, s.tuition_note,
           s.family_billing_enabled, s.family_billing_key, s.family_billing_label, s.family_billing_primary,
           c.class_name, c.start_time,
           (select pb.status from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_status,
           (select pb.appr_state from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_appr_state,
           (select pb.paymint_bill_id from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_bill_row_id,
           (select pb.bill_id from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_bill_id,
           (select pb.short_url from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_short_url,
           (select pb.created_at from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id order by pb.paymint_bill_id desc limit 1) as paymint_created_at,
           (select count(*) from " . IEUM_PAYMINT_BILL_TABLE . " pb where pb.payment_id = p.payment_id) as paymint_bill_count
      from " . IEUM_TUITION_PAYMENT_TABLE . " p
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
       and s.academy_id = p.academy_id
       and s.is_active = 1
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where {$payment_where}
  order by p.due_date asc, c.sort_order asc, c.start_time asc, s.student_name asc
     limit {$payment_offset}, {$payment_per_page}
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.wrap.is-loading{opacity:.55;pointer-events:none}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}.meta{color:#667085;margin-top:6px}.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:13px;color:#667085}.num{font-size:26px;font-weight:900;margin-top:4px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}.soft{background:#eef2f7}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.right{text-align:right}.status-paid{color:#176b2c;font-weight:900}.status-partial{color:#9a5b00;font-weight:900}.status-unpaid{color:#a4262c;font-weight:900}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.help{color:#667085;font-size:13px;margin:8px 0 0}.balance{font-weight:900;color:#a4262c}.sent{color:#176b2c;font-size:12px;font-weight:800}@media(max-width:1100px){.cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){table{display:block;overflow-x:auto;white-space:nowrap}.ieum-user{margin-left:0}}@media(max-width:520px){.cards{grid-template-columns:1fr}}
</style>
<style>
.status-partial{color:#a4262c}.payment-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}.payment-flow span{display:flex;align-items:center;gap:8px;border:1px solid #d9dee7;border-radius:10px;background:#fff;padding:12px;font-weight:900}.payment-flow b{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;background:#1769c2;color:#fff}.billing-settings{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:10px;align-items:end;margin-bottom:18px;padding:14px;border:1px solid #d9dee7;border-radius:8px;background:#fbfcff}.billing-settings label{font-weight:800;color:#344054}.billing-settings .field{display:grid;gap:6px}.billing-settings .check{display:flex;align-items:center;gap:7px;min-height:38px;border:1px solid #e3e8f0;border-radius:8px;background:#fff;padding:9px 10px}.billing-settings input[type=checkbox]{width:auto}.billing-settings .settings-title{grid-column:1/-1;color:#174a8b;font-size:15px;font-weight:900}.preview-box{border:1px solid #c7d8f2;border-radius:8px;background:#f7fbff;margin-bottom:18px;padding:16px}.preview-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px}.preview-title{font-size:18px;font-weight:900}.preview-meta{color:#667085;font-size:13px;margin-top:4px}.preview-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.preview-item{background:#fff;border:1px solid #d9e5f8;border-radius:8px;padding:12px}.preview-item strong{display:block;font-size:22px;margin-top:4px}.preview-item .label{font-size:12px;color:#667085}.preview-warn{margin-top:12px;color:#8a5200;background:#fff8e6;border:1px solid #f5d48a;border-radius:8px;padding:10px;font-size:13px}.preview-actions{display:flex;gap:8px;flex-wrap:wrap}.preview-detail{margin-top:14px;border:1px solid #d9e5f8;border-radius:8px;overflow:hidden;background:#fff}.preview-detail summary{cursor:pointer;font-weight:900;padding:12px 14px;background:#eef5ff}.preview-tools{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #d9e5f8}.preview-tools form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.preview-pages{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.preview-page{padding:6px 9px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;font-weight:800}.preview-page.active{background:#1769c2;color:#fff;border-color:#1769c2}.preview-detail table{margin:0}.preview-detail th{background:#5f7393}.preview-detail td,.preview-detail th{font-size:13px;padding:8px}.bulk-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}.bulk-bar label{font-weight:800;color:#344054}.table-scroll{overflow-x:auto;border:1px solid #d8dee9;border-radius:8px}.payment-table{min-width:1260px;border:0}.payment-table th,.payment-table td{line-height:1.35;padding:8px 7px}.payment-table th:first-child,.payment-table td:first-child{width:48px}.payment-table th:nth-child(2),.payment-table td:nth-child(2){width:94px}.payment-table th:nth-child(3),.payment-table td:nth-child(3){width:140px;white-space:nowrap}.payment-table th:nth-child(4),.payment-table td:nth-child(4){width:92px}.payment-table th:nth-child(5),.payment-table td:nth-child(5),.payment-table th:nth-child(6),.payment-table td:nth-child(6){width:128px}.payment-table th:nth-child(7),.payment-table td:nth-child(7){width:92px;white-space:nowrap}.payment-table th:nth-child(8),.payment-table td:nth-child(8){width:82px;white-space:nowrap}.payment-table th:nth-child(9),.payment-table td:nth-child(9){width:86px;white-space:nowrap}.payment-table th:nth-child(10),.payment-table td:nth-child(10){width:84px}.payment-table th:nth-child(11),.payment-table td:nth-child(11){width:220px}.payment-table th:nth-child(12),.payment-table td:nth-child(12){width:132px}.payment-table input[type=number]{width:100%;text-align:right}.payment-table input[name=memo]{width:100%}.payment-table .actions{min-width:112px}.payment-table .btn{min-height:34px;padding:6px 10px}.payment-table .sent{white-space:normal;line-height:1.25}.auto-bill{display:inline-flex;align-items:center;gap:4px;font-weight:800;color:#344054}@media(max-width:1100px){.preview-grid{grid-template-columns:repeat(2,1fr)}.billing-settings{grid-template-columns:repeat(3,1fr)}}@media(max-width:980px){.billing-settings{grid-template-columns:1fr 1fr}.billing-settings button{grid-column:1/-1}.payment-flow{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.billing-settings,.preview-grid,.payment-flow{grid-template-columns:1fr}}
</style>
<style>
.preview-groups{margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
.preview-group{background:#fff;border:1px solid #d9e5f8;border-radius:8px;padding:12px}
.preview-group strong{display:block;font-size:15px}
.preview-group .group-line{margin-top:5px;color:#667085;font-size:12px}
.preview-group .group-amount{margin-top:8px;font-size:18px;font-weight:900;color:#174a8b}
.send-review{margin:14px 0;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:14px;display:grid;grid-template-columns:1.2fr 1fr;gap:12px;align-items:center}
.send-review h3{margin:0 0 6px;font-size:17px}
.send-review p{margin:0;color:#475467;line-height:1.5;font-size:13px}
.send-review ul{margin:0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.send-review li{background:#fff;border:1px solid #d9e5f8;border-radius:999px;padding:8px 10px;font-weight:900;text-align:center}
.send-review .send-safe{color:#176b2c}
.send-review .send-warn{color:#9a5b00}
.detail-sub{display:block;color:#667085;font-size:12px;margin-top:3px}
.payment-list-tools{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0 10px;color:#475467;font-size:13px;font-weight:900}
.payment-list-tools form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.payment-filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.payment-filter-tab{display:inline-flex;align-items:center;gap:7px;border:1px solid #d7e2f0;border-radius:999px;background:#fff;color:#1f2937;text-decoration:none;padding:8px 12px;font-weight:900}.payment-filter-tab strong{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:24px;border-radius:999px;background:#eef2f7;color:#344054;padding:0 8px}.payment-filter-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}.payment-filter-tab.active strong{background:#fff;color:#1769c2}.payment-filter-tab.empty{opacity:.55}
.bill-state{display:grid;gap:3px;justify-items:center;font-size:12px;line-height:1.25}.bill-state .bill-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 8px;background:#eef2f7;color:#344054;font-weight:900}.bill-state .bill-status.sent,.bill-state .bill-status.mock_sent,.bill-state .bill-status.created{background:#eff6ff;color:#174a8b}.bill-state .bill-status.paid{background:#ecfdf3;color:#176b2c}.bill-state .bill-status.failed,.bill-state .bill-status.canceled,.bill-state .bill-status.destroyed{background:#fff5f5;color:#a4262c}.bill-state .bill-meta{color:#667085}
.payment-pages{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:12px}
.payment-page{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-weight:900;padding:0 10px}
.payment-page.active{background:#1769c2;border-color:#1769c2;color:#fff}
.payment-page.disabled{opacity:.45;pointer-events:none}
.tuition-brief{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;margin:16px 0}.brief-card{border:1px solid #d9dee7;border-radius:12px;background:#fff;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06)}a.brief-card,a.card{color:inherit;text-decoration:none}a.brief-card:hover,a.card:hover{border-color:#1769c2;box-shadow:0 12px 26px rgba(23,105,194,.12);transform:translateY(-1px)}.brief-card.primary-brief{background:linear-gradient(135deg,#153f7a,#1769c2);color:#fff}.brief-card.warn-brief{border-color:#ffd39a;background:#fffaf1}.brief-title{font-size:14px;font-weight:900;color:#667085}.primary-brief .brief-title{color:#dbeafe}.brief-main{margin-top:8px;font-size:28px;font-weight:950;letter-spacing:0}.brief-sub{margin-top:6px;color:#667085;font-size:13px;line-height:1.45}.primary-brief .brief-sub{color:#e8f1ff}.brief-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.brief-meter{height:10px;border-radius:999px;background:#e7edf7;overflow:hidden;margin-top:12px}.brief-meter span{display:block;height:100%;background:#1769c2}.primary-brief .brief-meter{background:rgba(255,255,255,.25)}.primary-brief .brief-meter span{background:#fff}.billing-settings .settings-title small{display:block;margin-top:4px;color:#667085;font-weight:600}.auto-billing-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}.auto-billing-head h2{margin:0;font-size:20px}.auto-billing-head p{margin:6px 0 0;color:#667085;font-size:13px;line-height:1.45}.settings-modal[hidden]{display:none}.settings-modal{position:fixed;inset:0;z-index:3000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.52)}.settings-modal-card{width:min(980px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(15,23,42,.28);padding:20px}.settings-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:14px}.settings-modal-head h3{margin:0;font-size:22px}.settings-modal-head p{margin:6px 0 0;color:#667085;font-size:13px}.settings-modal .billing-settings{margin-bottom:0}.pay-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:22px 0 10px}.pay-section-head h2{margin:0;font-size:20px}.bulk-bar{background:#f8fafc;border:1px solid #e3e8f0;border-radius:10px;padding:10px 12px}.bulk-bar .bulk-copy{color:#667085;font-size:13px;font-weight:700}.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;background:#f1f5f9;font-size:12px;font-weight:900}.status-pill.paid{background:#eaf8ef;color:#176b2c}.status-pill.unpaid{background:#fff0f0;color:#a4262c}.status-pill.partial{background:#fff7e6;color:#9a5b00}.paymint-gate{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:14px 0;border:1px solid #d9e5f8;border-radius:12px;background:#f7fbff;padding:14px 16px}.paymint-gate.locked{border-color:#ffd39a;background:#fffaf1}.paymint-gate strong{display:block;font-size:16px}.paymint-gate p{margin:4px 0 0;color:#667085;font-size:13px;line-height:1.45}.paymint-gate .gate-badge{display:inline-flex;align-items:center;border-radius:999px;padding:7px 11px;font-weight:900;background:#eaf8ef;color:#176b2c;white-space:nowrap}.paymint-gate.locked .gate-badge{background:#fff0f0;color:#a4262c}.family-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef5ff;color:#174a8b;border:1px solid #c7d8f2;padding:3px 8px;font-size:12px;font-weight:900;margin-top:4px}.family-badge.primary{background:#eaf8ef;color:#176b2c;border-color:#b9e5c5}@media(max-width:980px){.tuition-brief,.send-review{grid-template-columns:1fr}.send-review ul{grid-template-columns:1fr}.paymint-gate{align-items:flex-start;flex-direction:column}}
.preview-filter-tabs{display:flex;gap:7px;flex-wrap:wrap;padding:12px;border-top:1px solid #d9e5f8;background:#fbfdff}
.preview-filter-tab{display:inline-flex;align-items:center;gap:6px;border:1px solid #d7e2f0;border-radius:999px;background:#fff;color:#1f2937;text-decoration:none;padding:7px 10px;font-weight:900;font-size:13px}
.preview-filter-tab strong{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;border-radius:999px;background:#eef2f7;color:#344054;padding:0 7px}
.preview-filter-tab.active{background:#1769c2;border-color:#1769c2;color:#fff}
.preview-filter-tab.active strong{background:#fff;color:#1769c2}
.preview-filter-tab.empty{opacity:.5}
.preview-status{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:900;background:#eef5ff;color:#174a8b}
.preview-status.excluded{background:#fff7e6;color:#9a5b00}
.preview-status.danger{background:#fff0f0;color:#a4262c}
</style>
<link rel="stylesheet" href="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.css?v=20260530b">
<style>
.tuition-page-tune .hero{align-items:flex-start;margin-bottom:18px}
.tuition-page-tune .hero h1{font-size:30px;letter-spacing:-.01em;margin:0}
.tuition-page-tune .hero .actions{gap:8px}
.tuition-page-tune .cards{grid-template-columns:repeat(4,minmax(0,1fr))}
.tuition-page-tune .card,.tuition-page-tune .panel,.tuition-page-tune .brief-card{border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.tuition-page-tune .card .num{font-size:28px}
.tuition-page-tune .tuition-brief{grid-template-columns:repeat(3,minmax(0,1fr));margin-top:18px}
.tuition-page-tune .filters,.tuition-page-tune .billing-settings{border-radius:16px}
.tuition-page-tune .pay-section-head{padding:0 2px}
.tuition-page-tune .table-scroll{border-radius:14px;background:#fff}
.tuition-page-tune .payment-table th{background:#71809b}
.tuition-page-tune .payment-table{min-width:1420px}
.tuition-page-tune .payment-table th:nth-child(12),
.tuition-page-tune .payment-table td:nth-child(12){width:240px}
.tuition-page-tune .payment-table .actions{min-width:220px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;align-items:center}
.tuition-page-tune .payment-table .actions form{display:flex;min-width:0}
.tuition-page-tune .payment-table .actions .btn{width:100%;min-width:0}
.tuition-page-tune .btn{border-radius:10px}
@media(max-width:1200px){.tuition-page-tune .cards{grid-template-columns:repeat(2,minmax(0,1fr))}.tuition-page-tune .tuition-brief{grid-template-columns:1fr}}
@media(max-width:680px){.tuition-page-tune .cards{grid-template-columns:1fr}}
/* 2026-05-31 easy-mode pass: 수련비는 실행/확인 중심, 세부 설정은 접힌 상태로 */
.tuition-page-tune .payment-flow,
.tuition-page-tune .cards{
    display:none!important;
}
.tuition-page-tune .hero{
    margin-bottom:12px!important;
}
.tuition-page-tune .filters{
    margin:10px 0 12px!important;
    padding:10px 12px!important;
    background:#fff!important;
    border:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
.tuition-page-tune .tuition-brief{
    gap:10px!important;
    margin:12px 0!important;
}
.tuition-page-tune .brief-card{
    padding:14px!important;
    box-shadow:none!important;
}
.tuition-page-tune .brief-main{
    font-size:26px!important;
}
.tuition-page-tune #autoBilling{
    padding:18px!important;
}
.tuition-page-tune .preview-box{
    background:#fff!important;
    border-color:#e2e8f0!important;
    box-shadow:none!important;
}
.tuition-page-tune .preview-grid{
    grid-template-columns:repeat(5,minmax(0,1fr))!important;
}
.tuition-page-tune .preview-item{
    box-shadow:none!important;
    background:#fbfcfe!important;
}
.tuition-page-tune .preview-detail:not([open]){
    border-style:dashed!important;
}
.tuition-page-tune .preview-detail:not([open]) summary{
    background:#f8fafc!important;
}
.tuition-page-tune .pay-section-head{
    margin-top:16px!important;
}
.tuition-page-tune .payment-filter-tab{
    padding:7px 10px!important;
}
@media(max-width:680px){
    .tuition-page-tune .preview-box{
        overflow:hidden!important;
    }
    .tuition-page-tune .preview-grid{
        grid-template-columns:1fr!important;
    }
    .tuition-page-tune .preview-item strong,
    .tuition-page-tune .brief-main{
        overflow-wrap:anywhere!important;
        word-break:keep-all!important;
    }
    .tuition-page-tune .preview-detail{
        max-width:100%!important;
        overflow-x:auto!important;
    }
    .tuition-page-tune .preview-detail table{
        min-width:720px!important;
    }
    .tuition-page-tune .preview-filter-tabs{
        flex-wrap:nowrap!important;
        overflow-x:auto!important;
        -webkit-overflow-scrolling:touch!important;
    }
    .tuition-page-tune .preview-tools,
    .tuition-page-tune .preview-tools form{
        display:grid!important;
        grid-template-columns:1fr!important;
        width:100%!important;
    }
    .tuition-page-tune .preview-tools select,
    .tuition-page-tune .preview-tools .btn{
        width:100%!important;
    }
}
</style>
<style>
/* Dashboard shell alignment: tuition payment keeps the dashboard navigation frame. */
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-brand{
    display:flex!important;
    height:144px!important;
    min-height:144px!important;
    padding:0 28px!important;
    background:#fff!important;
    color:#0f172a!important;
    font-size:29px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-menu>summary{
    min-height:42px!important;
    border-radius:6px!important;
    padding:0 12px!important;
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-menu>summary:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-nav-label{
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-nav-icon{
    width:18px!important;
    height:18px!important;
    color:#334155!important;
    opacity:1!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-sub{
    margin:2px 0 8px!important;
    padding:0 0 0 28px!important;
    background:transparent!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-sub a{
    min-height:34px!important;
    border-radius:6px!important;
    color:#475569!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .side-sub a.active{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    width:auto!important;
    height:64px!important;
    padding:0 40px!important;
    background:#fff!important;
    border-bottom:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-link{
    flex:0 0 auto!important;
    color:#0f172a!important;
    font-weight:900!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-meta{
    margin-left:auto!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-meta-inner{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-help-dot{
    color:#94a3b8!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-support-link{
    color:#0f172a!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-support-link:hover,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:4px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .hero{
    display:flex!important;
    align-items:flex-start!important;
    justify-content:space-between!important;
    gap:16px!important;
    margin-bottom:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .hero .actions{
    justify-content:flex-end!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .meta{
    margin:8px 0 18px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .brief-card,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .filters,
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .paymint-gate{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune td{
    border-color:#e5ebf3!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.tuition-page-tune .hero{
        display:block!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page tuition-page-tune">
<?php echo ieum_admin_header('tuition_payments', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>수련비 납부</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($billing_month); ?></div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition.php">수련비 정책</a>
            <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/family_billing.php?billing_month=<?php echo urlencode($billing_month); ?>">형제/자매 청구 묶음</a>
            <form method="post" class="send-confirm-form" data-send-title="청구액 최신화 확인" data-send-message="이번 달 미발송/미입금 원생의 청구액을 현재 원생 정보와 수련비 정책 기준으로 다시 맞춥니다." data-send-count-label="정책 최신화" data-send-submit-label="최신화" data-send-summary="info:수련비 정책 변경 반영|info:형제할인 변경 반영|warn:이미 입금/발송된 건은 제외" data-send-help="청구서를 이미 보냈거나 입금이 확인된 원생은 금액이 바뀌지 않습니다.">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="refresh_amounts">
                <button type="submit" class="btn soft">청구액 최신화</button>
            </form>
            <a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php">문자 발송현황</a>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="paymint-gate <?php echo $paymint_ready ? '' : 'locked'; ?>" aria-label="결제선생 연동 상태">
        <div>
            <strong>결제선생 청구서 발송 상태</strong>
            <p>
                <?php if ($paymint_ready) { ?>
                    이 도장은 결제선생 도장 결제 계정 연동이 완료되어 청구서 발송을 진행할 수 있습니다.
                <?php } else { ?>
                    청구서 발송은 도장 결제 계정 연동 완료 후 가능합니다. 본사 관리자에서 도장 결제연동 상태를 먼저 완료로 변경해 주세요.
                <?php } ?>
            </p>
        </div>
        <div class="actions">
            <span class="gate-badge"><?php echo get_text($paymint_readiness_label); ?></span>
            <?php if ($is_admin === 'super') { ?><a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/paymint_merchants.php">도장 결제연동</a><?php } ?>
        </div>
    </section>

    <form method="get" class="filters">
        <input type="month" name="billing_month" value="<?php echo get_text($billing_month); ?>">
        <button type="submit" class="btn primary">조회</button>
    </form>

    <section class="tuition-brief" aria-label="수련비 월간 핵심 요약">
        <a class="brief-card primary-brief" href="#paymentList">
            <div class="brief-title">이번 달 수련비 진행률</div>
            <div class="brief-main"><?php echo number_format($collection_rate); ?>%</div>
            <div class="brief-sub">
                입금 <?php echo number_format($total_paid_amount); ?>원 / 청구 <?php echo number_format($total_due_amount); ?>원
            </div>
            <div class="brief-meter" aria-hidden="true"><span style="width:<?php echo min(100, max(0, (int) $collection_rate)); ?>%"></span></div>
        </a>
        <a class="brief-card warn-brief" href="#paymentList">
            <div class="brief-title">오늘 확인할 미납</div>
            <div class="brief-main"><?php echo number_format((int) $summary['unpaid_count']); ?>명</div>
            <div class="brief-sub">
                남은 금액 <?php echo number_format($remaining_amount); ?>원 ·
                <?php echo (int) $overdue_days; ?>일 초과 <?php echo number_format((int) $summary['overdue_long_count']); ?>명
            </div>
        </a>
        <article class="brief-card">
            <div class="brief-title">청구서 발송 예정</div>
            <div class="brief-main"><?php echo number_format((int) $bill_preview['target_count']); ?>건</div>
            <div class="brief-sub">
                대상 원생 <?php echo number_format((int) (isset($bill_preview['target_student_count']) ? $bill_preview['target_student_count'] : $bill_preview['target_count'])); ?>명 · 결제완료자는 자동 제외되고, 형제/자매 청구 묶음은 1건으로 발송됩니다.
                <?php if (!empty($bill_preview['include_arrears'])) { ?> 이전 미납 잔액은 함께 청구됩니다.<?php } ?>
            </div>
            <div class="brief-actions">
                <button type="button" class="btn soft js-open-settings">⚙ 자동발송 설정</button>
                <a class="btn soft" href="#billPreview">발송 대상</a>
            </div>
        </article>
    </section>

    <section class="payment-flow" aria-label="수련비 처리 흐름">
        <span><b>1</b> 미결제 확인</span>
        <span><b>2</b> 청구서 발송</span>
        <span><b>3</b> 입금 확인</span>
        <span><b>4</b> 완납 처리</span>
    </section>

    <section class="cards">
        <a class="card" href="#paymentList"><div class="label">이번 달 대상</div><div class="num"><?php echo number_format((int) $summary['total_count']); ?>명</div></a>
        <a class="card" href="#paymentList"><div class="label">완납</div><div class="num"><?php echo number_format((int) $summary['paid_count']); ?>명</div></a>
        <a class="card" href="#paymentList"><div class="label">확인 필요</div><div class="num"><?php echo number_format((int) $summary['unpaid_count']); ?>명</div></a>
        <a class="card" href="#paymentList"><div class="label">미납 <?php echo (int) $overdue_days; ?>일 이하</div><div class="num"><?php echo number_format((int) $summary['overdue_5_count']); ?>명</div></a>
        <a class="card" href="#paymentList"><div class="label">미납 <?php echo (int) $overdue_days; ?>일 초과</div><div class="num"><?php echo number_format((int) $summary['overdue_long_count']); ?>명</div></a>
        <a class="card" href="#paymentList"><div class="label">남은 금액</div><div class="num"><?php echo number_format($remaining_amount); ?>원</div></a>
        <?php if ($is_admin === 'super') { ?><article class="card"><div class="label">본사 쌤포인트</div><div class="num"><?php echo number_format($hq_wallet_balance); ?>P</div></article><?php } ?>
    </section>

    <section class="panel" id="autoBilling">
        <div class="auto-billing-head">
            <div>
                <h2>청구서 발송 대상</h2>
                <p>설정은 필요할 때만 열고, 이 화면에서는 이번 달 발송 가능 대상과 제외 사유를 먼저 확인합니다.</p>
            </div>
            <button type="button" class="btn soft js-open-settings">⚙ 자동발송 설정</button>
        </div>
        <div class="settings-modal" id="autoBillingSettingsModal" hidden>
            <div class="settings-modal-card" role="dialog" aria-modal="true" aria-labelledby="autoBillingSettingsTitle">
                <div class="settings-modal-head">
                    <div>
                        <h3 id="autoBillingSettingsTitle">자동 청구서 발송 설정</h3>
                        <p>도장별 납부일에 맞춰 미결제 원생에게만 발송됩니다. 이미 완납된 원생은 발송 대상에서 빠집니다.</p>
                    </div>
                    <button type="button" class="btn soft js-close-settings">닫기</button>
                </div>
        <form method="post" class="billing-settings">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="billing_settings">
            <div class="settings-title">자동 청구서 발송 설정<small>도장별 납부일에 맞춰 미결제 원생에게만 발송됩니다. 이미 완납된 원생은 발송 대상에서 빠집니다.</small></div>
            <label class="check"><input type="checkbox" name="bill_auto_send_enabled" value="1" <?php echo !empty($settings['bill_auto_send_enabled']) ? 'checked' : ''; ?>> 자동발송 사용</label>
            <div class="field">
                <label>자동 발송일</label>
                <select name="bill_auto_send_day">
                    <?php for ($day = 1; $day <= 31; $day++) { ?>
                    <option value="<?php echo $day; ?>" <?php echo (int) (isset($settings['bill_auto_send_day']) ? $settings['bill_auto_send_day'] : 5) === $day ? 'selected' : ''; ?>>매월 <?php echo $day; ?>일</option>
                    <?php } ?>
                </select>
            </div>
            <div class="field">
                <label>발송 대상</label>
                <select name="bill_auto_send_scope">
                    <option value="all" <?php echo isset($settings['bill_auto_send_scope']) && $settings['bill_auto_send_scope'] === 'all' ? 'selected' : ''; ?>>전체 미결제 원생</option>
                    <option value="selected" <?php echo isset($settings['bill_auto_send_scope']) && $settings['bill_auto_send_scope'] === 'selected' ? 'selected' : ''; ?>>자동청구 체크 원생만</option>
                </select>
            </div>
            <label class="check"><input type="checkbox" name="bill_auto_include_arrears" value="1" <?php echo !isset($settings['bill_auto_include_arrears']) || !empty($settings['bill_auto_include_arrears']) ? 'checked' : ''; ?>> 이전 미납 함께 청구</label>
            <label class="check"><input type="checkbox" name="due_notice_enabled" value="1" <?php echo !isset($settings['due_notice_enabled']) || !empty($settings['due_notice_enabled']) ? 'checked' : ''; ?>> 납부일 안내 문자</label>
            <label class="check"><input type="checkbox" name="overdue_notice_enabled" value="1" <?php echo !empty($settings['overdue_notice_enabled']) ? 'checked' : ''; ?>> 미납 안내 문자</label>
            <div class="field">
                <label>미납 기준</label>
                <select name="overdue_after_days">
                    <?php for ($day = 1; $day <= 30; $day++) { ?>
                    <option value="<?php echo $day; ?>" <?php echo (int) (isset($settings['overdue_after_days']) ? $settings['overdue_after_days'] : 5) === $day ? 'selected' : ''; ?>><?php echo $day; ?>일 초과</option>
                    <?php } ?>
                </select>
            </div>
            <button type="submit" class="btn primary">설정 저장</button>
        </form>
            </div>
        </div>
        <section class="preview-box" id="billPreview">
            <div class="preview-head">
                <div>
                    <div class="preview-title">자동발송 대상 미리보기</div>
                    <div class="preview-meta">
                        <?php echo get_text($billing_month); ?> ·
                        <?php echo !empty($bill_preview['auto_enabled']) ? '자동발송 사용' : '자동발송 꺼짐'; ?> ·
                        매월 <?php echo number_format((int) $bill_preview['auto_send_day']); ?>일 ·
                        <?php echo $bill_preview['scope'] === 'selected' ? '자동청구 체크 원생만' : '전체 미결제 원생'; ?> ·
                        <?php echo !empty($bill_preview['include_arrears']) ? '이전 미납 함께 청구' : '이번 달 수련비만'; ?>
                    </div>
                </div>
                <div class="preview-actions">
                    <form method="post" class="send-confirm-form" data-send-title="청구서 발송 확인" data-send-message="미리보기 대상에게 결제선생 청구서를 발송 처리합니다." data-send-count="<?php echo (int) $bill_preview['target_count']; ?>" data-send-summary="<?php echo get_text(implode('|', $bill_confirm_summary)); ?>" data-send-help="완납, 잔액 0원, 이미 발송, 연락처 없음 대상은 발송되지 않습니다. 발송을 누르기 전에 제외 사유를 확인해 주세요.">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="send_month_bills">
                        <input type="hidden" name="confirm_bill_send" value="1">
                        <button type="submit" class="btn primary" <?php echo (empty($bill_preview['details']) || !$paymint_ready) ? 'disabled' : ''; ?>>미리보기 대상 발송</button>
                    </form>
                    <?php if ($is_admin === 'super') { ?><a class="btn soft" href="<?php echo IEUM_URL; ?>/admin/billing_wallet.php">본사 쌤포인트 확인</a><?php } ?>
                </div>
            </div>
            <div class="preview-grid">
                <article class="preview-item"><span class="label">발송 예정</span><strong><?php echo number_format((int) $bill_preview['target_count']); ?>건</strong></article>
                <article class="preview-item"><span class="label">대상 원생</span><strong><?php echo number_format((int) (isset($bill_preview['target_student_count']) ? $bill_preview['target_student_count'] : $bill_preview['target_count'])); ?>명</strong></article>
                <article class="preview-item"><span class="label">결제완료 제외</span><strong><?php echo number_format((int) $bill_preview['paid_excluded_count']); ?>명</strong></article>
                <article class="preview-item"><span class="label">이전 미납 포함</span><strong><?php echo number_format((int) $bill_preview['arrears_student_count']); ?>명</strong></article>
                <article class="preview-item"><span class="label">총 청구 예정</span><strong><?php echo number_format((int) $bill_preview['bill_amount']); ?>원</strong></article>
                <?php if ($is_admin === 'super') { ?><article class="preview-item"><span class="label">본사 예상 사용 포인트</span><strong><?php echo number_format((int) $bill_preview['send_fee']); ?>P</strong></article><?php } ?>
            </div>
            <div class="send-review" aria-label="청구서 발송 전 최종 확인">
                <div>
                    <h3>발송 전 최종 확인</h3>
                    <p>완납자, 잔액 0원, 이미 발송된 원생, 수신 보호자가 없는 원생은 자동으로 제외됩니다. 형제/자매 가족 청구는 원생별 금액을 합산해 1건으로 발송됩니다.</p>
                </div>
                <ul>
                    <li class="send-safe">발송 <?php echo number_format((int) $bill_preview['target_count']); ?>건</li>
                    <li>원생 <?php echo number_format((int) (isset($bill_preview['target_student_count']) ? $bill_preview['target_student_count'] : $bill_preview['target_count'])); ?>명</li>
                    <li>수신 <?php echo number_format((int) $bill_preview['recipient_count']); ?>건</li>
                    <li>청구 <?php echo number_format((int) $bill_preview['bill_amount']); ?>원</li>
                    <li class="<?php echo $bill_excluded_total > 0 ? 'send-warn' : 'send-safe'; ?>">제외 <?php echo number_format($bill_excluded_total); ?>명</li>
                </ul>
            </div>
            <?php if (!empty($bill_preview['group_summary'])) { ?>
            <div class="preview-groups" aria-label="학년별 자동발송 요약">
                <?php foreach ($bill_preview['group_summary'] as $group) { ?>
                <article class="preview-group">
                    <strong><?php echo get_text($group['grade_label']); ?></strong>
                    <div class="group-line">
                        발송 <?php echo number_format((int) $group['student_count']); ?>명 ·
                        수신 <?php echo number_format((int) $group['recipient_count']); ?>건 ·
                        미납포함 <?php echo number_format((int) $group['arrears_student_count']); ?>명
                    </div>
                    <div class="group-amount"><?php echo number_format((int) $group['bill_amount']); ?>원</div>
                </article>
                <?php } ?>
            </div>
            <?php } ?>
            <?php
            $preview_warnings = array();
            if ((int) $bill_preview['no_recipient_count'] > 0) {
                $preview_warnings[] = '수련비 문자 수신 보호자가 없는 원생 ' . number_format((int) $bill_preview['no_recipient_count']) . '명';
            }
            if ((int) $bill_preview['already_sent_excluded_count'] > 0) {
                $preview_warnings[] = '이미 청구서가 발송되어 제외된 원생 ' . number_format((int) $bill_preview['already_sent_excluded_count']) . '명';
            }
            if ((int) $bill_preview['selected_excluded_count'] > 0) {
                $preview_warnings[] = '자동청구 체크가 꺼져 제외된 원생 ' . number_format((int) $bill_preview['selected_excluded_count']) . '명';
            }
            if ((int) $bill_preview['zero_amount_excluded_count'] > 0) {
                $preview_warnings[] = '잔액 0원으로 제외된 원생 ' . number_format((int) $bill_preview['zero_amount_excluded_count']) . '명';
            }
            ?>
            <?php if ($preview_warnings) { ?><div class="preview-warn"><?php echo get_text(implode(' · ', $preview_warnings)); ?></div><?php } ?>
            <?php if (!empty($bill_preview['details']) || !empty($bill_preview['excluded_details'])) { ?>
            <details class="preview-detail">
                <summary>상세 목록 <?php echo get_text($preview_filter_options[$preview_filter]['label']); ?> <?php echo number_format($preview_total); ?>건 보기</summary>
                <div class="preview-filter-tabs" aria-label="청구서 미리보기 필터">
                    <?php foreach ($preview_filter_options as $filter_key => $filter_item) {
                        $filter_url = IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&preview_filter=' . urlencode($filter_key) . '&preview_per_page=' . (int) $preview_per_page . '&preview_page=1#billPreview';
                    ?>
                    <a class="preview-filter-tab <?php echo $preview_filter === $filter_key ? 'active' : ''; ?> <?php echo (int) $filter_item['count'] <= 0 ? 'empty' : ''; ?>" href="<?php echo $filter_url; ?>">
                        <?php echo get_text($filter_item['label']); ?>
                        <strong><?php echo number_format((int) $filter_item['count']); ?></strong>
                    </a>
                    <?php } ?>
                </div>
                <div class="preview-tools">
                    <form method="get">
                        <input type="hidden" name="billing_month" value="<?php echo get_text($billing_month); ?>">
                        <input type="hidden" name="preview_filter" value="<?php echo get_text($preview_filter); ?>">
                        <label>보기
                            <select name="preview_per_page">
                                <option value="10" <?php echo $preview_per_page === 10 ? 'selected' : ''; ?>>10명</option>
                                <option value="25" <?php echo $preview_per_page === 25 ? 'selected' : ''; ?>>25명</option>
                                <option value="50" <?php echo $preview_per_page === 50 ? 'selected' : ''; ?>>50명</option>
                            </select>
                        </label>
                        <button type="submit" class="btn soft">적용</button>
                    </form>
                    <div class="preview-pages">
                        <?php for ($page = 1; $page <= $preview_total_pages; $page++) { ?>
                        <a class="preview-page <?php echo $page === $preview_page ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo urlencode($billing_month); ?>&preview_filter=<?php echo urlencode($preview_filter); ?>&preview_per_page=<?php echo (int) $preview_per_page; ?>&preview_page=<?php echo $page; ?>"><?php echo $page; ?></a>
                        <?php } ?>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr><th>원생</th><th>학년/부</th><th>이번 달</th><th>이전 미납</th><th>청구 예정</th><th>발송 상태</th><th>미납월</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview_page_rows as $detail) { ?>
                        <?php
                        $is_excluded_detail = isset($detail['reason']);
                        $status_class = $is_excluded_detail ? 'excluded' : '';
                        if ($is_excluded_detail && isset($detail['reason_code']) && $detail['reason_code'] === 'no_recipient') {
                            $status_class = 'danger';
                        }
                        $status_text = $is_excluded_detail ? $detail['reason'] : '발송 예정 · ' . number_format((int) $detail['recipient_count']) . '명';
                        ?>
                        <tr>
                            <td>
                                <?php echo get_text($detail['student_name'] . ' (' . $detail['student_code'] . ')'); ?>
                                <?php if (!empty($detail['family_billing_enabled'])) { ?>
                                <span class="detail-sub">가족 청구: <?php echo get_text($detail['family_billing_label']); ?> · <?php echo number_format((int) $detail['family_student_count']); ?>명 묶음</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php echo get_text(isset($detail['grade_label']) ? $detail['grade_label'] : '학년 미지정'); ?>
                                <span class="detail-sub"><?php echo get_text(isset($detail['class_label']) ? $detail['class_label'] : '부 미지정'); ?></span>
                            </td>
                            <td class="right"><?php echo number_format((int) $detail['current_amount']); ?>원</td>
                            <td class="right"><?php echo number_format((int) $detail['arrears_amount']); ?>원</td>
                            <td class="right balance"><?php echo number_format((int) $detail['bill_amount']); ?>원</td>
                            <td><span class="preview-status <?php echo $status_class; ?>"><?php echo get_text($status_text); ?></span></td>
                            <td><?php echo $detail['arrears_months'] ? get_text(implode(', ', $detail['arrears_months'])) : '-'; ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$preview_page_rows) { ?>
                        <tr><td colspan="7">선택한 조건의 상세 목록이 없습니다.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </details>
            <?php } ?>
        </section>
        <div class="pay-section-head" id="paymentList">
            <div>
                <h2>월별 납부 목록</h2>
                <div class="help">입금 확인 후 바로 완납 처리하거나, 선택한 원생만 청구서를 보낼 수 있습니다.</div>
            </div>
        </div>
        <nav class="payment-filter-tabs" aria-label="월별 납부 목록 필터">
            <?php foreach ($payment_filter_options as $filter_key => $filter_item) {
                $filter_query = array(
                    'billing_month' => $billing_month,
                    'preview_filter' => $preview_filter,
                    'preview_per_page' => $preview_per_page,
                    'preview_page' => $preview_page,
                    'payment_filter' => $filter_key,
                    'payment_per_page' => $payment_per_page,
                    'payment_page' => 1,
                );
            ?>
            <a class="payment-filter-tab <?php echo $payment_filter === $filter_key ? 'active' : ''; ?> <?php echo (int) $filter_item['count'] <= 0 ? 'empty' : ''; ?>" href="?<?php echo http_build_query($filter_query); ?>#paymentList">
                <?php echo get_text($filter_item['label']); ?>
                <strong><?php echo number_format((int) $filter_item['count']); ?></strong>
            </a>
            <?php } ?>
        </nav>
        <div class="payment-list-tools">
            <div>
                <?php echo get_text($payment_filter_options[$payment_filter]['label']); ?>
                <?php echo number_format($payment_total); ?>명 중
                <?php echo number_format($payment_start); ?>-<?php echo number_format($payment_end); ?>명 표시 ·
                <?php echo number_format($payment_page); ?>/<?php echo number_format($payment_total_pages); ?>페이지
            </div>
            <form method="get">
                <input type="hidden" name="billing_month" value="<?php echo get_text($billing_month); ?>">
                <input type="hidden" name="preview_filter" value="<?php echo get_text($preview_filter); ?>">
                <input type="hidden" name="preview_per_page" value="<?php echo (int) $preview_per_page; ?>">
                <input type="hidden" name="preview_page" value="<?php echo (int) $preview_page; ?>">
                <input type="hidden" name="payment_filter" value="<?php echo get_text($payment_filter); ?>">
                <input type="hidden" name="payment_page" value="1">
                <span>보기</span>
                <select name="payment_per_page" onchange="this.form.submit()">
                    <?php foreach (array(10, 25, 50) as $size) { ?>
                    <option value="<?php echo (int) $size; ?>" <?php echo get_selected($payment_per_page, $size); ?>><?php echo (int) $size; ?>명</option>
                    <?php } ?>
                </select>
            </form>
        </div>
        <form method="post" id="bulkActionForm" class="bulk-bar">
            <input type="hidden" name="confirm_bill_send" value="1">
            <div>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <label><input type="checkbox" id="checkAllPayments"> 전체 선택</label>
                <span class="bulk-copy">완납 원생은 선택되지 않습니다.</span>
            </div>
            <div class="actions">
                <button type="submit" name="action" value="bulk_mark_paid" class="btn primary" onclick="return confirm('선택한 원생을 결제완료 처리할까요?');">선택 완납</button>
                <button type="submit" name="action" value="bulk_send_bill" class="btn soft send-confirm-trigger" data-send-title="선택 청구서 발송 확인" data-send-message="선택한 원생에게 결제선생 청구서를 발송 처리합니다." data-send-summary="warn:완납 원생은 자동 제외|info:수신 보호자는 서버에서 재확인|ok:선택한 원생만 청구서 발송" data-send-help="선택한 원생 중 발송 조건에 맞는 원생만 처리됩니다. 서버에서 완납, 잔액 0원, 연락처 여부를 한 번 더 확인합니다." <?php echo !$paymint_ready ? 'disabled' : ''; ?>>선택 청구서 발송</button>
            </div>
        </form>
        <div class="table-scroll">
        <table class="payment-table">
            <thead>
                <tr><th>선택</th><th>납부일</th><th>원생</th><th>수업부</th><th>청구액</th><th>입금액</th><th>잔액</th><th>상태</th><th>자동청구</th><th>문자</th><th>메모</th><th>관리</th></tr>
            </thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($payments)) { $i++; ?>
                <tr>
                    <?php
                    $balance = max(0, (int) $row['amount_due'] - (int) $row['amount_paid']);
                    $notice_type = $row['due_date'] < G5_TIME_YMD ? 'overdue' : 'due';
                    $bill_status = isset($row['paymint_status']) ? trim((string) $row['paymint_status']) : '';
                    $bill_appr_state = isset($row['paymint_appr_state']) ? trim((string) $row['paymint_appr_state']) : '';
                    $bill_status_class = $bill_appr_state === 'F' ? 'paid' : ($bill_status !== '' ? preg_replace('/[^0-9a-z_]/', '', $bill_status) : 'none');
                    $bill_status_label = $bill_status !== '' ? ieum_paymint_bill_status_label($bill_status, $bill_appr_state) : '';
                    $bill_created_label = !empty($row['paymint_created_at']) ? substr($row['paymint_created_at'], 5, 11) : '';
                    $paymint_bill_row_id = isset($row['paymint_bill_row_id']) ? (int) $row['paymint_bill_row_id'] : 0;
                    $paymint_manageable = $paymint_bill_row_id > 0
                        && !in_array($bill_status, array('paid', 'destroyed', 'canceled'), true)
                        && !in_array($bill_appr_state, array('F', 'D', 'C'), true);
                    $paymint_link = isset($row['paymint_short_url']) ? trim((string) $row['paymint_short_url']) : '';
                    ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                        <td><input type="checkbox" form="bulkActionForm" name="payment_ids[]" value="<?php echo (int) $row['payment_id']; ?>" <?php echo $row['status'] === 'paid' ? 'disabled' : ''; ?>></td>
                        <td><?php echo get_text($row['due_date']); ?></td>
                        <td class="left">
                            <?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?>
                            <?php if (!empty($row['family_billing_enabled'])) { ?>
                            <div><span class="family-badge <?php echo !empty($row['family_billing_primary']) ? 'primary' : ''; ?>"><?php echo get_text($row['family_billing_label'] ?: '가족 청구'); ?> · <?php echo !empty($row['family_billing_primary']) ? '대표' : '묶음'; ?></span></div>
                            <?php } ?>
                        </td>
                        <td><?php echo get_text(trim(($row['class_name'] ?: '미지정') . ' ' . ($row['start_time'] ?: ''))); ?></td>
                        <td><input type="number" name="amount_due" value="<?php echo (int) $row['amount_due']; ?>" min="0" style="width:110px;text-align:right"></td>
                        <td><input type="number" name="amount_paid" value="<?php echo (int) $row['amount_paid']; ?>" min="0" style="width:110px"></td>
                        <td class="right balance"><?php echo number_format($balance); ?></td>
                        <td>
                            <span class="status-pill <?php echo get_text($row['status']); ?>"><?php echo get_text(ieum_tuition_status_label($row['status'])); ?></span>
                        </td>
                        <td><label class="auto-bill"><input type="checkbox" name="bill_auto_send_enabled" value="1" <?php echo !empty($row['bill_auto_send_enabled']) ? 'checked' : ''; ?>> 발송</label></td>
                        <td>
                            <?php if (!empty($row['notice_sent_at'])) { ?><div class="sent"><?php echo get_text(substr($row['notice_sent_at'], 5, 11)); ?> 발송</div><?php } ?>
                            <div><?php echo number_format((int) (isset($row['notice_count']) ? $row['notice_count'] : 0)); ?>회</div>
                            <?php if (!empty($row['bill_sent_at']) || $bill_status_label !== '') { ?>
                            <div class="bill-state">
                                <span class="bill-status <?php echo get_text($bill_status_class); ?>"><?php echo get_text($bill_status_label !== '' ? $bill_status_label : '청구서 발송'); ?></span>
                                <span class="bill-meta">
                                    <?php echo number_format((int) max((int) $row['bill_send_count'], (int) $row['paymint_bill_count'])); ?>건
                                    <?php echo $bill_created_label !== '' ? ' · ' . get_text($bill_created_label) : ''; ?>
                                </span>
                            </div>
                            <?php } ?>
                        </td>
                        <td><input type="text" name="memo" value="<?php echo get_text($row['memo']); ?>" placeholder="예: 형제할인, 현금, 카드"></td>
                        <td>
                            <div class="actions">
                                <button type="submit" class="btn">저장</button>
                    </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn primary">완납</button>
                                </form>
                                <?php
                                $notice_sent_at = (!empty($row['notice_sent_at']) && $row['notice_sent_at'] !== '0000-00-00 00:00:00') ? $row['notice_sent_at'] : '';
                                $notice_sent_ts = $notice_sent_at ? strtotime($notice_sent_at) : 0;
                                $notice_sent_label = $notice_sent_ts ? date('m월 d일 H:i', $notice_sent_ts) : '';
                                $notice_count = (int) (isset($row['notice_count']) ? $row['notice_count'] : 0);
                                $notice_is_resend = $notice_sent_label !== '';
                                $notice_send_title = $notice_is_resend ? '수련비 문자 재발송 확인' : '수련비 문자 발송 확인';
                                $notice_send_message = $notice_is_resend
                                    ? '미납 안내가 ' . $notice_sent_label . '에 이미 발송되었습니다. 아직 결제가 확인되지 않았다면 재발송을 진행할까요?'
                                    : '선택한 원생의 보호자에게 수련비 안내 문자를 발송 준비합니다.';
                                $notice_send_help = $notice_is_resend
                                    ? '관리자가 발송 이력을 확인하고, 필요할 때만 다시 발송할 수 있도록 한 번 더 확인합니다.'
                                    : '문자 수신 체크가 된 보호자 연락처를 다시 확인한 뒤 문자 발송을 준비합니다.';
                                $notice_send_submit_label = $notice_is_resend ? '재발송' : '발송';
                                $notice_send_summary = array(
                                    'info:' . $row['student_name'],
                                    ($balance > 0 ? 'warn:' : 'ok:') . '잔액 ' . number_format($balance) . '원',
                                    $notice_is_resend ? 'warn:이전 발송 ' . $notice_sent_label : 'info:첫 발송',
                                    $notice_is_resend ? 'muted:누적 ' . number_format($notice_count) . '회' : 'info:문자 수신 보호자 재확인',
                                );
                                $notice_template_key = $notice_type === 'overdue' ? 'tuition_overdue' : 'tuition_due';
                                $notice_template = ieum_tuition_get_sms_template($academy_id, $notice_template_key);
                                $notice_message_values = array(
                                    'academy_name' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
                                    'student_name' => (string) $row['student_name'],
                                    'billing_month' => $billing_month,
                                    'balance' => number_format($balance),
                                    'due_date' => (string) $row['due_date'],
                                    '도장명' => isset($academy['academy_name']) ? (string) $academy['academy_name'] : '',
                                    '학생명' => (string) $row['student_name'],
                                    '원생명' => (string) $row['student_name'],
                                    '청구월' => $billing_month,
                                    '금액' => number_format($balance),
                                    '납부일' => (string) $row['due_date'],
                                );
                                $notice_message_preview = strtr((string) $notice_template['message'], array(
                                    '{academy_name}' => $notice_message_values['academy_name'],
                                    '{student_name}' => $notice_message_values['student_name'],
                                    '{billing_month}' => $notice_message_values['billing_month'],
                                    '{balance}' => $notice_message_values['balance'],
                                    '{due_date}' => $notice_message_values['due_date'],
                                    '{도장명}' => $notice_message_values['도장명'],
                                    '{학생명}' => $notice_message_values['학생명'],
                                    '{원생명}' => $notice_message_values['원생명'],
                                    '{청구월}' => $notice_message_values['청구월'],
                                    '{금액}' => $notice_message_values['금액'],
                                    '{납부일}' => $notice_message_values['납부일'],
                                ));
                                $notice_message_values_json = htmlspecialchars(json_encode($notice_message_values, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                ?>
                                <form method="post" class="send-confirm-form" data-send-title="<?php echo get_text($notice_send_title); ?>" data-send-message="<?php echo get_text($notice_send_message); ?>" data-send-count="1" data-send-summary="<?php echo get_text(implode('|', $notice_send_summary)); ?>" data-send-help="<?php echo get_text($notice_send_help); ?>" data-send-submit-label="<?php echo get_text($notice_send_submit_label); ?>" data-message-editable="1" data-message-template="<?php echo get_text($notice_template['message']); ?>" data-message-preview="<?php echo get_text($notice_message_preview); ?>" data-message-values="<?php echo $notice_message_values_json; ?>" data-message-tokens="도장명·원생명·청구월·금액·납부일은 발송할 때 자동으로 바뀝니다.">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="send_notice">
                                    <input type="hidden" name="notice_type" value="<?php echo get_text($notice_type); ?>">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn <?php echo $notice_type === 'overdue' ? 'danger' : 'soft'; ?>"><?php echo $notice_is_resend ? '재발송' : '문자'; ?></button>
                                </form>
                                <form method="post" class="send-confirm-form" data-send-title="청구서 발송 확인" data-send-message="선택한 원생의 보호자에게 결제선생 청구서를 발송 처리합니다." data-send-count="1" data-send-summary="info:<?php echo get_text($row['student_name']); ?>|<?php echo $balance > 0 ? 'warn:' : 'ok:'; ?>잔액 <?php echo number_format($balance); ?>원|info:완납 여부 서버 재확인" data-send-help="완납 또는 잔액 0원인 경우 서버에서 발송을 막습니다. 실제 발송 전 수신 보호자도 다시 확인합니다.">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="send_bill">
                                    <input type="hidden" name="confirm_bill_send" value="1">
                                    <input type="hidden" name="payment_id" value="<?php echo (int) $row['payment_id']; ?>">
                                    <button type="submit" class="btn soft" <?php echo !$paymint_ready ? 'disabled' : ''; ?>>청구서</button>
                                </form>
                                <?php if ($paymint_manageable) { ?>
                                <form method="post" class="send-confirm-form" data-send-title="청구서 재발송 확인" data-send-message="이미 만든 결제선생 청구서를 보호자에게 다시 보냅니다." data-send-count="1" data-send-summary="info:<?php echo get_text($row['student_name']); ?>|warn:보호자가 아직 결제하지 않은 경우에만 사용|info:기존 청구서 번호 유지" data-send-help="보호자가 아직 결제하지 않았거나 링크를 다시 요청한 경우에만 사용해 주세요." data-send-submit-label="재발송">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="resend_paymint_bill">
                                    <input type="hidden" name="paymint_bill_row_id" value="<?php echo $paymint_bill_row_id; ?>">
                                    <button type="submit" class="btn soft" <?php echo !$paymint_ready ? 'disabled' : ''; ?>>재발송</button>
                                </form>
                                <form method="post" class="send-confirm-form" data-send-title="청구서 파기 확인" data-send-message="보낸 청구서를 결제할 수 없도록 파기합니다." data-send-count="1" data-send-summary="warn:파기 후 같은 수련비 재청구 가능|danger:이미 보호자에게 보낸 링크는 사용할 수 없음" data-send-help="금액을 잘못 보냈거나 청구 대상을 바꿔야 할 때 사용합니다." data-send-submit-label="파기">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="destroy_paymint_bill">
                                    <input type="hidden" name="paymint_bill_row_id" value="<?php echo $paymint_bill_row_id; ?>">
                                    <button type="submit" class="btn danger" <?php echo !$paymint_ready ? 'disabled' : ''; ?>>파기</button>
                                </form>
                                <?php } ?>
                                <?php if ($paymint_bill_row_id > 0) { ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="read_paymint_bill">
                                    <input type="hidden" name="paymint_bill_row_id" value="<?php echo $paymint_bill_row_id; ?>">
                                    <button type="submit" class="btn soft" <?php echo !$paymint_ready ? 'disabled' : ''; ?>>상태확인</button>
                                </form>
                                <?php } ?>
                                <?php if ($paymint_link !== '' && $bill_status !== 'destroyed' && $bill_appr_state !== 'D') { ?>
                                <a class="btn soft" href="<?php echo get_text($paymint_link); ?>" target="_blank" rel="noopener">링크</a>
                                <?php } ?>
                            </div>
                        </td>
                </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="12">이번 달 수련비 대상이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
        </div>
        <?php if ($payment_total_pages > 1) { ?>
        <?php
        $payment_page_query = array(
            'billing_month' => $billing_month,
            'preview_filter' => $preview_filter,
            'preview_per_page' => $preview_per_page,
            'preview_page' => $preview_page,
            'payment_filter' => $payment_filter,
            'payment_per_page' => $payment_per_page,
        );
        ?>
        <nav class="payment-pages" aria-label="수련비 납부 목록 페이지">
            <?php
            $payment_page_query['payment_page'] = max(1, $payment_page - 1);
            $prev_disabled = $payment_page <= 1 ? ' disabled' : '';
            ?>
            <a class="payment-page<?php echo $prev_disabled; ?>" href="?<?php echo http_build_query($payment_page_query); ?>">이전</a>
            <?php
            $page_from = max(1, $payment_page - 2);
            $page_to = min($payment_total_pages, $payment_page + 2);
            if ($page_to - $page_from < 4) {
                $page_from = max(1, min($page_from, $page_to - 4));
                $page_to = min($payment_total_pages, max($page_to, $page_from + 4));
            }
            for ($page_no = $page_from; $page_no <= $page_to; $page_no++) {
                $payment_page_query['payment_page'] = $page_no;
                $active = $page_no === $payment_page ? ' active' : '';
            ?>
            <a class="payment-page<?php echo $active; ?>" href="?<?php echo http_build_query($payment_page_query); ?>"><?php echo (int) $page_no; ?></a>
            <?php } ?>
            <?php
            $payment_page_query['payment_page'] = min($payment_total_pages, $payment_page + 1);
            $next_disabled = $payment_page >= $payment_total_pages ? ' disabled' : '';
            ?>
            <a class="payment-page<?php echo $next_disabled; ?>" href="?<?php echo http_build_query($payment_page_query); ?>">다음</a>
        </nav>
        <?php } ?>
        <p class="help">입금액이 청구액 이상이면 결제완료, 부족하면 미결제로 자동 정리됩니다. 자동발송은 이 화면에서 설정한 날짜에 실행되고, 미납 포함을 켜면 이전 달 미납 잔액을 이번 달 청구서에 합산합니다.</p>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.tuition-page-tune.ieum-dashboard-page';
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
<script>
const checkAllPayments = document.getElementById('checkAllPayments');
if (checkAllPayments) {
    checkAllPayments.addEventListener('change', () => {
        document.querySelectorAll('input[name="payment_ids[]"]:not(:disabled)').forEach((item) => {
            item.checked = checkAllPayments.checked;
        });
    });
}
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('main.wrap');
            if (!next) {
                window.location.href = url;
                return;
            }
            main.innerHTML = next.innerHTML;
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };
    const buildFormUrl = (form) => {
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url.toString();
    };
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters, .preview-tools form');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        loadView(buildFormUrl(form), true);
    });
    main.addEventListener('change', (event) => {
        if (event.target && event.target.id === 'checkAllPayments') {
            document.querySelectorAll('input[name="payment_ids[]"]:not(:disabled)').forEach((item) => {
                item.checked = event.target.checked;
            });
            return;
        }
        const control = event.target.closest('form.filters input, form.filters select, .preview-tools form select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        loadView(buildFormUrl(form), true);
    });
    main.addEventListener('click', (event) => {
        const openSettings = event.target.closest('.js-open-settings');
        if (openSettings) {
            event.preventDefault();
            const modal = document.getElementById('autoBillingSettingsModal');
            if (modal) modal.hidden = false;
            return;
        }
        const closeSettings = event.target.closest('.js-close-settings');
        if (closeSettings) {
            event.preventDefault();
            const modal = document.getElementById('autoBillingSettingsModal');
            if (modal) modal.hidden = true;
            return;
        }
        const settingsBackdrop = event.target.classList && event.target.classList.contains('settings-modal') ? event.target : null;
        if (settingsBackdrop) {
            settingsBackdrop.hidden = true;
            return;
        }
        const link = event.target.closest('.preview-page');
        if (!link) return;
        event.preventDefault();
        loadView(link.href, true);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const modal = document.getElementById('autoBillingSettingsModal');
        if (modal && !modal.hidden) modal.hidden = true;
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
<script src="<?php echo IEUM_URL; ?>/assets/admin-send-confirm.js?v=20260530d"></script>
</body>
</html>
