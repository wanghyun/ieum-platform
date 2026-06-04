<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

define('IEUM_PAYMINT_SANDBOX_BASE_URL', 'https://sandbox.paymint.co.kr/partner');
define('IEUM_PAYMINT_PRODUCTION_BASE_URL', 'https://api.paymint.co.kr/partner');

function ieum_paymint_default_cost_policy()
{
    return array(
        'exclude_paid_students' => 1,
        'prevent_duplicate_bill' => 1,
        'include_arrears_default' => 1,
        'resend_cooldown_hours' => 24,
        'low_balance_threshold' => 5000,
        'monthly_send_cap_per_academy' => 0,
        'bill_expire_days' => 7,
        'use_url_mode_for_test' => 1,
    );
}

function ieum_paymint_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_PAYMINT_SETTING_TABLE . " (
            setting_id tinyint unsigned not null default 1,
            environment varchar(20) not null default 'sandbox',
            partner_mode varchar(30) not null default 'partner_managed',
            partner_member_id varchar(80) not null default '',
            partner_merchant_id varchar(80) not null default '',
            api_key varchar(160) not null default '',
            api_secret varchar(160) not null default '',
            callback_token varchar(120) not null default '',
            callback_base_url varchar(255) not null default '',
            default_send_type varchar(20) not null default 'TALK',
            use_url_mode_for_test tinyint(1) not null default 1,
            prevent_duplicate_bill tinyint(1) not null default 1,
            exclude_paid_students tinyint(1) not null default 1,
            include_arrears_default tinyint(1) not null default 1,
            resend_cooldown_hours smallint unsigned not null default 24,
            low_balance_threshold int unsigned not null default 5000,
            monthly_send_cap_per_academy int unsigned not null default 0,
            bill_expire_days tinyint unsigned not null default 7,
            memo varchar(255) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (setting_id)
        ) engine={$engine} default charset={$charset}
    ", false);

    sql_query("
        create table if not exists " . IEUM_PAYMINT_MERCHANT_TABLE . " (
            merchant_map_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            member_id varchar(80) not null default '',
            merchant_id varchar(80) not null default '',
            business_number varchar(30) not null default '',
            mapping_status varchar(30) not null default 'pending',
            mapping_url varchar(500) not null default '',
            mapping_status_raw varchar(60) not null default '',
            company_name varchar(120) not null default '',
            branch_name varchar(120) not null default '',
            ceo_name varchar(80) not null default '',
            email varchar(120) not null default '',
            remote_balance int not null default -1,
            charge_url varchar(500) not null default '',
            last_sync_at datetime null,
            last_error varchar(255) not null default '',
            callback_payload mediumtext null,
            mapped_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (merchant_map_id),
            unique key uq_academy (academy_id),
            key idx_member_merchant (member_id, merchant_id),
            key idx_status (mapping_status)
        ) engine={$engine} default charset={$charset}
    ", false);

    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_SETTING_TABLE, 'callback_base_url', "varchar(255) not null default '' after callback_token");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_SETTING_TABLE, 'remote_balance', 'int not null default -1 after memo');
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_SETTING_TABLE, 'charge_url', "varchar(500) not null default '' after remote_balance");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_SETTING_TABLE, 'balance_checked_at', 'datetime null after charge_url');
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_SETTING_TABLE, 'last_api_error', "varchar(255) not null default '' after balance_checked_at");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'mapping_url', "varchar(500) not null default '' after mapping_status");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'mapping_status_raw', "varchar(60) not null default '' after mapping_url");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'company_name', "varchar(120) not null default '' after mapping_status_raw");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'branch_name', "varchar(120) not null default '' after company_name");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'ceo_name', "varchar(80) not null default '' after branch_name");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'email', "varchar(120) not null default '' after ceo_name");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'remote_balance', 'int not null default -1 after email');
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'charge_url', "varchar(500) not null default '' after remote_balance");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'last_sync_at', 'datetime null after charge_url');
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_MERCHANT_TABLE, 'last_error', "varchar(255) not null default '' after last_sync_at");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_BILL_TABLE . " (
            paymint_bill_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            payment_id int unsigned not null default 0,
            student_id int unsigned not null default 0,
            billing_month char(7) not null default '',
            bill_id varchar(20) not null default '',
            paymint_hash varchar(160) not null default '',
            short_url varchar(255) not null default '',
            send_type varchar(20) not null default 'TALK',
            product_name varchar(120) not null default '',
            bill_amount int unsigned not null default 0,
            current_amount int unsigned not null default 0,
            arrears_amount int unsigned not null default 0,
            arrears_months varchar(120) not null default '',
            recipient_name varchar(80) not null default '',
            recipient_phone varchar(30) not null default '',
            appr_state char(1) not null default 'W',
            status varchar(30) not null default 'created',
            sent_at datetime null,
            paid_at datetime null,
            canceled_at datetime null,
            destroyed_at datetime null,
            expires_at datetime null,
            last_error varchar(255) not null default '',
            raw_response mediumtext null,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (paymint_bill_id),
            unique key uq_bill_id (bill_id),
            key idx_payment (payment_id),
            key idx_student_month (academy_id, student_id, billing_month),
            key idx_status_created (status, created_at)
        ) engine={$engine} default charset={$charset}
    ", false);

    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_BILL_TABLE, 'appr_num', "varchar(80) not null default '' after appr_state");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_BILL_TABLE, 'appr_dt', "varchar(30) not null default '' after appr_num");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_BILL_TABLE, 'appr_pay_type', "varchar(40) not null default '' after appr_dt");
    ieum_paymint_add_column_if_missing(IEUM_PAYMINT_BILL_TABLE, 'last_synced_at', 'datetime null after updated_at');

    sql_query("
        create table if not exists " . IEUM_PAYMINT_BILL_LOG_TABLE . " (
            bill_log_id int unsigned not null auto_increment,
            paymint_bill_id int unsigned not null default 0,
            academy_id int unsigned not null default 0,
            payment_id int unsigned not null default 0,
            log_type varchar(30) not null default '',
            message varchar(255) not null default '',
            payload mediumtext null,
            created_at datetime not null,
            primary key (bill_log_id),
            key idx_bill (paymint_bill_id),
            key idx_academy_created (academy_id, created_at),
            key idx_payment (payment_id)
        ) engine={$engine} default charset={$charset}
    ", false);

    sql_query("
        create table if not exists " . IEUM_PAYMINT_CALLBACK_TABLE . " (
            callback_id int unsigned not null auto_increment,
            callback_type varchar(30) not null default '',
            bill_id varchar(20) not null default '',
            academy_id int unsigned not null default 0,
            payment_id int unsigned not null default 0,
            appr_state char(1) not null default '',
            payload mediumtext null,
            result_code varchar(20) not null default '',
            result_message varchar(255) not null default '',
            received_at datetime not null,
            primary key (callback_id),
            key idx_bill_id (bill_id),
            key idx_type_received (callback_type, received_at),
            key idx_academy_received (academy_id, received_at)
        ) engine={$engine} default charset={$charset}
    ", false);

    sql_query("
        create table if not exists " . IEUM_PAYMINT_USAGE_MONTHLY_TABLE . " (
            usage_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            usage_month char(7) not null,
            bill_send_count int unsigned not null default 0,
            paid_count int unsigned not null default 0,
            failed_count int unsigned not null default 0,
            destroyed_count int unsigned not null default 0,
            bill_amount int unsigned not null default 0,
            updated_at datetime null,
            primary key (usage_id),
            unique key uq_academy_month (academy_id, usage_month)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_paymint_add_column_if_missing($table, $column, $definition)
{
    $table_sql = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $column_sql = preg_replace('/[^A-Za-z0-9_]/', '', (string) $column);
    if ($table_sql === '' || $column_sql === '') {
        return;
    }

    $exists = sql_fetch("show columns from {$table_sql} like '" . sql_escape_string($column_sql) . "'", false);
    if (!isset($exists['Field'])) {
        sql_query("alter table {$table_sql} add {$column_sql} {$definition}", false);
    }
}

function ieum_paymint_get_settings()
{
    ieum_paymint_ensure_tables();

    $row = sql_fetch("
        select *
          from " . IEUM_PAYMINT_SETTING_TABLE . "
         where setting_id = 1
         limit 1
    ", false);

    if (isset($row['setting_id'])) {
        return $row;
    }

    $token = md5(uniqid('paymint', true));
    sql_query("
        insert into " . IEUM_PAYMINT_SETTING_TABLE . "
            set setting_id = 1,
                environment = 'sandbox',
                partner_mode = 'partner_managed',
                callback_token = '" . sql_escape_string($token) . "',
                callback_base_url = '',
                default_send_type = 'TALK',
                use_url_mode_for_test = 1,
                prevent_duplicate_bill = 1,
                exclude_paid_students = 1,
                include_arrears_default = 1,
                resend_cooldown_hours = 24,
                low_balance_threshold = 5000,
                monthly_send_cap_per_academy = 0,
                bill_expire_days = 7,
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    return ieum_paymint_get_settings();
}

function ieum_paymint_base_url($settings = null)
{
    if ($settings === null) {
        $settings = ieum_paymint_get_settings();
    }

    return isset($settings['environment']) && $settings['environment'] === 'production'
        ? IEUM_PAYMINT_PRODUCTION_BASE_URL
        : IEUM_PAYMINT_SANDBOX_BASE_URL;
}

function ieum_paymint_service_base_url($settings = null)
{
    if ($settings === null) {
        $settings = ieum_paymint_get_settings();
    }

    $base_url = isset($settings['callback_base_url']) ? trim((string) $settings['callback_base_url']) : '';
    if ($base_url === '') {
        $base_url = IEUM_URL;
    }

    return rtrim($base_url, '/');
}

function ieum_paymint_mask_secret($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function ieum_paymint_make_bill_id($academy_id, $payment_id, $sequence = 1)
{
    $academy_id = (int) $academy_id;
    $payment_id = (int) $payment_id;
    $sequence = max(1, (int) $sequence);
    $prefix = 'I' . str_pad((string) ($academy_id % 1000), 3, '0', STR_PAD_LEFT);
    $tail = strtoupper(
        substr(base_convert((string) time(), 10, 36), -5)
        . substr(base_convert((string) max(1, $payment_id), 10, 36), -4)
        . substr(base_convert((string) $sequence, 10, 36), -2)
    );
    return substr($prefix . $tail, 0, 20);
}

function ieum_paymint_hash_source($bill_id, $phone, $price)
{
    $bill_id = trim((string) $bill_id);
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    $price = (int) $price;

    if ($phone !== '') {
        return hash('sha256', $bill_id . ',' . $phone . ',' . $price);
    }

    return hash('sha256', $bill_id . ',' . $price);
}

function ieum_paymint_bill_callback_url($settings = null)
{
    if ($settings === null) {
        $settings = ieum_paymint_get_settings();
    }

    $token = isset($settings['callback_token']) ? trim((string) $settings['callback_token']) : '';
    return ieum_paymint_service_base_url($settings) . '/api/paymint/payment_callback.php?token=' . rawurlencode($token);
}

function ieum_paymint_prepare_bill_payload($payment, $recipient_phone, $bill_amounts, $sequence = 1)
{
    ieum_paymint_ensure_tables();

    $settings = ieum_paymint_get_settings();
    $academy_id = (int) $payment['academy_id'];
    $payment_id = (int) $payment['payment_id'];
    $student_id = (int) $payment['student_id'];
    $merchant = ieum_paymint_get_merchant($academy_id);
    if (!$merchant || !ieum_paymint_academy_is_ready($academy_id)) {
        return array(
            'ok' => false,
            'message' => 'paymint_not_ready',
            'readiness' => ieum_paymint_academy_readiness_label($academy_id),
        );
    }

    $phone = preg_replace('/[^0-9]/', '', (string) $recipient_phone);
    if ($phone === '') {
        return array('ok' => false, 'message' => 'invalid_phone');
    }

    $amount = max(0, (int) $bill_amounts['total_amount']);
    if ($amount <= 0) {
        return array('ok' => false, 'message' => 'empty_amount');
    }

    $bill_id = ieum_paymint_make_bill_id($academy_id, $payment_id, $sequence);
    $exists = sql_fetch("
        select bill_id
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where bill_id = '" . sql_escape_string($bill_id) . "'
         limit 1
    ", false);
    if (isset($exists['bill_id'])) {
        $bill_id = ieum_paymint_make_bill_id($academy_id, $payment_id, $sequence + mt_rand(10, 99));
    }

    $send_type = isset($settings['default_send_type']) && trim((string) $settings['default_send_type']) !== ''
        ? strtoupper(trim((string) $settings['default_send_type']))
        : 'TALK';
    if (!empty($settings['use_url_mode_for_test'])) {
        $send_type = 'URL';
    }

    $billing_month = isset($payment['billing_month']) ? (string) $payment['billing_month'] : date('Y-m');
    $student_name = isset($payment['student_name']) ? (string) $payment['student_name'] : '';
    $product_name = $billing_month . ' ' . $student_name . ' 수련비';
    $expire_days = isset($settings['bill_expire_days']) ? max(1, (int) $settings['bill_expire_days']) : 7;
    $expires_at = date('Y-m-d H:i:s', strtotime('+' . $expire_days . ' days'));
    $hash_source = ieum_paymint_hash_source($bill_id, $phone, $amount);

    $payload = array(
        'apiKey' => (string) $settings['api_key'],
        'member' => (string) $merchant['member_id'],
        'merchant' => (string) $merchant['merchant_id'],
        'bill' => array(
            'billId' => $bill_id,
            'sendType' => $send_type,
            'billIssuer' => isset($payment['academy_name']) ? (string) $payment['academy_name'] : '',
            'productName' => $product_name,
            'price' => (string) $amount,
            'memberName' => $student_name,
            'phone' => $phone,
            'message' => '안녕하세요. ' . $billing_month . ' 수련비 청구서입니다. 확인 부탁드립니다.',
            'expireDt' => date('Y-m-d', strtotime($expires_at)),
            'hash' => $hash_source,
            'callbackUrl' => ieum_paymint_bill_callback_url($settings),
            'pageRedirectUrl' => ieum_paymint_service_base_url($settings) . '/admin/tuition_payments.php?billing_month=' . rawurlencode($billing_month),
        ),
    );

    return array(
        'ok' => true,
        'settings' => $settings,
        'merchant' => $merchant,
        'bill_id' => $bill_id,
        'hash_source' => $hash_source,
        'payload' => $payload,
        'expires_at' => $expires_at,
        'send_type' => $send_type,
        'product_name' => $product_name,
        'recipient_phone' => $phone,
        'recipient_name' => $student_name,
        'academy_id' => $academy_id,
        'payment_id' => $payment_id,
        'student_id' => $student_id,
        'billing_month' => $billing_month,
    );
}

function ieum_paymint_api_request($settings, $path, $payload)
{
    $base_url = rtrim(ieum_paymint_base_url($settings), '/');
    $url = $base_url . '/' . ltrim($path, '/');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = array(
        'Content-Type: application/json; charset=UTF-8',
        'Accept: application/json',
    );
    $response_body = '';
    $http_code = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response_body = curl_exec($ch);
        if ($response_body === false) {
            $error = curl_error($ch);
            $response_body = '';
        }
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ),
        ));
        $response_body = file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                    $http_code = (int) $m[1];
                    break;
                }
            }
        }
        if ($response_body === false) {
            $response_body = '';
            $error = 'HTTP request failed';
        }
    }

    $decoded = json_decode($response_body, true);
    if (!is_array($decoded)) {
        $decoded = array();
    }

    return array(
        'url' => $url,
        'http_code' => $http_code,
        'request' => $payload,
        'response' => $decoded,
        'response_body' => $response_body,
        'error' => $error,
    );
}

function ieum_paymint_bill_short_url($api_response)
{
    if (!is_array($api_response)) {
        return '';
    }
    if (isset($api_response['data']) && is_array($api_response['data']) && !empty($api_response['data']['shortUrl'])) {
        return (string) $api_response['data']['shortUrl'];
    }
    if (!empty($api_response['shortUrl'])) {
        return (string) $api_response['shortUrl'];
    }
    return '';
}

function ieum_paymint_api_success($api_result)
{
    if (!is_array($api_result)) {
        return false;
    }
    $response = isset($api_result['response']) && is_array($api_result['response']) ? $api_result['response'] : array();
    $code = isset($response['code']) ? (string) $response['code'] : '';
    return (int) $api_result['http_code'] === 200 && $code === '0000';
}

function ieum_paymint_response_data($api_result)
{
    if (!is_array($api_result) || !isset($api_result['response']) || !is_array($api_result['response'])) {
        return array();
    }

    return isset($api_result['response']['data']) && is_array($api_result['response']['data'])
        ? $api_result['response']['data']
        : array();
}

function ieum_paymint_api_message($api_result)
{
    if (isset($api_result['response']['message'])) {
        return (string) $api_result['response']['message'];
    }
    if (!empty($api_result['error'])) {
        return (string) $api_result['error'];
    }
    if (!empty($api_result['response_body'])) {
        return mb_substr((string) $api_result['response_body'], 0, 180);
    }
    return '결제선생 API 응답을 확인할 수 없습니다.';
}

function ieum_paymint_normalize_mapping_status($status)
{
    $status = strtoupper(trim((string) $status));
    if (in_array($status, array('COMPLETED', 'LEGACY', 'MAPPED'), true)) {
        return 'mapped';
    }
    if (in_array($status, array('INITIALIZED', 'MEMBER_MAPPED', 'MERCHANT_OPENING', 'PENDING'), true)) {
        return 'pending';
    }
    if (in_array($status, array('PAUSED'), true)) {
        return 'paused';
    }
    if (in_array($status, array('FAILED', 'REJECTED'), true)) {
        return 'failed';
    }
    return $status === '' ? 'pending' : 'failed';
}

function ieum_paymint_create_mapping_url($academy_id, $created_by = '')
{
    ieum_paymint_ensure_tables();

    $academy_id = (int) $academy_id;
    $academy_columns = array();
    $academy_column_result = sql_query("show columns from " . IEUM_ACADEMY_TABLE, false);
    while ($column = sql_fetch_array($academy_column_result)) {
        if (isset($column['Field'])) {
            $academy_columns[$column['Field']] = true;
        }
    }
    $business_select = isset($academy_columns['business_number']) ? ', business_number' : '';
    $academy = sql_fetch("
        select academy_id, academy_code, academy_name {$business_select}
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);
    if (!isset($academy['academy_id'])) {
        return array('ok' => false, 'message' => 'academy_not_found');
    }

    $settings = ieum_paymint_get_settings();
    if (trim((string) $settings['api_key']) === '') {
        return array('ok' => false, 'message' => 'api_key_required');
    }

    $merchant = ieum_paymint_get_merchant($academy_id);
    $member_id = $merchant && trim((string) $merchant['member_id']) !== ''
        ? trim((string) $merchant['member_id'])
        : 'IEUM-MEMBER-' . str_pad((string) $academy_id, 6, '0', STR_PAD_LEFT);
    $merchant_id = $merchant && trim((string) $merchant['merchant_id']) !== ''
        ? trim((string) $merchant['merchant_id'])
        : 'IEUM-MERCHANT-' . str_pad((string) $academy_id, 6, '0', STR_PAD_LEFT);
    $business_number = $merchant && trim((string) $merchant['business_number']) !== ''
        ? preg_replace('/[^0-9]/', '', (string) $merchant['business_number'])
        : (isset($academy['business_number']) ? preg_replace('/[^0-9]/', '', (string) $academy['business_number']) : '');

    $payload = array(
        'apiKey' => (string) $settings['api_key'],
        'memberId' => $member_id,
        'merchantId' => $merchant_id,
        'callbackUrl' => ieum_paymint_service_base_url($settings) . '/api/paymint/merchant_callback.php?token=' . rawurlencode($settings['callback_token']),
        'redirectUrl' => ieum_paymint_service_base_url($settings) . '/admin/paymint_merchants.php?q=' . rawurlencode($academy['academy_code']),
    );
    if ($business_number !== '') {
        $payload['businessNumber'] = $business_number;
    }

    $api_result = ieum_paymint_api_request($settings, '/auth/mapping', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $data = ieum_paymint_response_data($api_result);
    $mapping_url = isset($data['url']) ? (string) $data['url'] : '';
    $error = $ok ? '' : ieum_paymint_api_message($api_result);

    sql_query("
        insert into " . IEUM_PAYMINT_MERCHANT_TABLE . "
            set academy_id = '{$academy_id}',
                member_id = '" . sql_escape_string($member_id) . "',
                merchant_id = '" . sql_escape_string($merchant_id) . "',
                business_number = '" . sql_escape_string($business_number) . "',
                mapping_status = '" . ($ok ? 'pending' : 'failed') . "',
                mapping_url = '" . sql_escape_string($mapping_url) . "',
                callback_payload = '" . sql_escape_string(json_encode($api_result, JSON_UNESCAPED_UNICODE)) . "',
                last_sync_at = '" . G5_TIME_YMDHIS . "',
                last_error = '" . sql_escape_string($error) . "',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                member_id = values(member_id),
                merchant_id = values(merchant_id),
                business_number = values(business_number),
                mapping_status = values(mapping_status),
                mapping_url = values(mapping_url),
                callback_payload = values(callback_payload),
                last_sync_at = values(last_sync_at),
                last_error = values(last_error),
                updated_at = values(updated_at)
    ");

    return array(
        'ok' => $ok,
        'message' => $ok ? 'mapping_url_created' : 'paymint_api_failed',
        'mapping_url' => $mapping_url,
        'member_id' => $member_id,
        'merchant_id' => $merchant_id,
        'raw_response' => $api_result,
    );
}

function ieum_paymint_sync_mapping_list()
{
    ieum_paymint_ensure_tables();

    $settings = ieum_paymint_get_settings();
    if (trim((string) $settings['api_key']) === '') {
        return array('ok' => false, 'message' => 'api_key_required', 'updated' => 0);
    }

    $api_result = ieum_paymint_api_request($settings, '/read/mapping', array('apiKey' => (string) $settings['api_key']));
    if (!ieum_paymint_api_success($api_result)) {
        return array('ok' => false, 'message' => ieum_paymint_api_message($api_result), 'updated' => 0, 'raw_response' => $api_result);
    }

    $data = ieum_paymint_response_data($api_result);
    $updated = 0;
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $member_id = isset($item['partnerMember']) ? trim((string) $item['partnerMember']) : '';
        $merchant_id = isset($item['partnerMerchant']) ? trim((string) $item['partnerMerchant']) : '';
        if ($member_id === '' && $merchant_id === '') {
            continue;
        }
        $status_raw = isset($item['mappingStatus']) ? trim((string) $item['mappingStatus']) : '';
        $status = ieum_paymint_normalize_mapping_status($status_raw);
        $member_sql = sql_escape_string($member_id);
        $merchant_sql = sql_escape_string($merchant_id);
        $where = array();
        if ($member_id !== '') {
            $where[] = "member_id = '{$member_sql}'";
        }
        if ($merchant_id !== '') {
            $where[] = "merchant_id = '{$merchant_sql}'";
        }
        $where_sql = implode(' or ', $where);
        sql_query("
            update " . IEUM_PAYMINT_MERCHANT_TABLE . "
               set mapping_status = '" . sql_escape_string($status) . "',
                   mapping_status_raw = '" . sql_escape_string($status_raw) . "',
                   company_name = '" . sql_escape_string(isset($item['companyNm']) ? (string) $item['companyNm'] : '') . "',
                   branch_name = '" . sql_escape_string(isset($item['branchNm']) ? (string) $item['branchNm'] : '') . "',
                   business_number = if('" . sql_escape_string(isset($item['businessNumber']) ? (string) $item['businessNumber'] : '') . "' <> '', '" . sql_escape_string(isset($item['businessNumber']) ? (string) $item['businessNumber'] : '') . "', business_number),
                   ceo_name = '" . sql_escape_string(isset($item['ceoName']) ? (string) $item['ceoName'] : '') . "',
                   email = '" . sql_escape_string(isset($item['email']) ? (string) $item['email'] : '') . "',
                   callback_payload = '" . sql_escape_string(json_encode($item, JSON_UNESCAPED_UNICODE)) . "',
                   mapped_at = " . ($status === 'mapped' ? "coalesce(mapped_at, '" . G5_TIME_YMDHIS . "')" : "mapped_at") . ",
                   last_sync_at = '" . G5_TIME_YMDHIS . "',
                   last_error = '',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where {$where_sql}
        ", false);
        $updated++;
    }

    return array('ok' => true, 'message' => 'synced', 'updated' => $updated, 'raw_response' => $api_result);
}

function ieum_paymint_read_partner_balance()
{
    ieum_paymint_ensure_tables();

    $settings = ieum_paymint_get_settings();
    if (trim((string) $settings['api_key']) === '') {
        return array('ok' => false, 'message' => 'api_key_required');
    }

    $api_result = ieum_paymint_api_request($settings, '/read/remain_count', array('apiKey' => (string) $settings['api_key']));
    $ok = ieum_paymint_api_success($api_result);
    $data = ieum_paymint_response_data($api_result);
    $balance = isset($data['balance']) ? (int) $data['balance'] : -1;
    $charge_url = isset($data['chargeUrl']) ? (string) $data['chargeUrl'] : '';
    $error = $ok ? '' : ieum_paymint_api_message($api_result);

    sql_query("
        update " . IEUM_PAYMINT_SETTING_TABLE . "
           set remote_balance = '{$balance}',
               charge_url = '" . sql_escape_string($charge_url) . "',
               balance_checked_at = '" . G5_TIME_YMDHIS . "',
               last_api_error = '" . sql_escape_string($error) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where setting_id = 1
    ");

    return array('ok' => $ok, 'message' => $ok ? 'balance_read' : $error, 'balance' => $balance, 'charge_url' => $charge_url, 'raw_response' => $api_result);
}

function ieum_paymint_read_merchant_balance($academy_id)
{
    ieum_paymint_ensure_tables();

    $academy_id = (int) $academy_id;
    $settings = ieum_paymint_get_settings();
    $merchant = ieum_paymint_get_merchant($academy_id);
    if (!$merchant || trim((string) $merchant['member_id']) === '' || trim((string) $merchant['merchant_id']) === '') {
        return array('ok' => false, 'message' => 'merchant_not_ready');
    }
    if (trim((string) $settings['api_key']) === '') {
        return array('ok' => false, 'message' => 'api_key_required');
    }

    $payload = array(
        'apiKey' => (string) $settings['api_key'],
        'member' => (string) $merchant['member_id'],
        'merchant' => (string) $merchant['merchant_id'],
    );
    $api_result = ieum_paymint_api_request($settings, '/read/merchant/remain_count', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $data = ieum_paymint_response_data($api_result);
    $balance = isset($data['balance']) ? (int) $data['balance'] : -1;
    $charge_url = isset($data['chargeUrl']) ? (string) $data['chargeUrl'] : '';
    $error = $ok ? '' : ieum_paymint_api_message($api_result);

    sql_query("
        update " . IEUM_PAYMINT_MERCHANT_TABLE . "
           set remote_balance = '{$balance}',
               charge_url = '" . sql_escape_string($charge_url) . "',
               last_sync_at = '" . G5_TIME_YMDHIS . "',
               last_error = '" . sql_escape_string($error) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
    ", false);

    return array('ok' => $ok, 'message' => $ok ? 'merchant_balance_read' : $error, 'balance' => $balance, 'charge_url' => $charge_url, 'raw_response' => $api_result);
}

function ieum_paymint_apply_successful_payment($bill, $paid_at = '')
{
    if (!$bill || empty($bill['payment_id'])) {
        return false;
    }

    $paid_at = $paid_at !== '' ? $paid_at : G5_TIME_YMDHIS;
    $academy_id = (int) $bill['academy_id'];
    $payment_id = (int) $bill['payment_id'];
    $student_id = (int) $bill['student_id'];

    $payment = sql_fetch("
        select *
          from " . IEUM_TUITION_PAYMENT_TABLE . "
         where payment_id = '{$payment_id}'
         limit 1
    ", false);
    if (isset($payment['payment_id'])) {
        $current_bill_amount = isset($bill['current_amount']) ? (int) $bill['current_amount'] : 0;
        if ($current_bill_amount <= 0) {
            $current_bill_amount = max(0, (int) $bill['bill_amount'] - (int) $bill['arrears_amount']);
        }

        $amount_due = (int) $payment['amount_due'];
        $amount_paid = (int) $payment['amount_paid'];
        $balance = max(0, $amount_due - $amount_paid);
        $new_paid = $current_bill_amount >= $balance ? $amount_due : min($amount_due, $amount_paid + $current_bill_amount);
        $new_status = $new_paid >= $amount_due ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');
        $paid_at_sql = $new_status === 'paid' ? "coalesce(paid_at, '" . sql_escape_string($paid_at) . "')" : 'paid_at';

        sql_query("
            update " . IEUM_TUITION_PAYMENT_TABLE . "
               set amount_paid = '{$new_paid}',
                   status = '{$new_status}',
                   payment_provider = 'paymint',
                   external_bill_id = '" . sql_escape_string($bill['bill_id']) . "',
                   provider_status = 'paid',
                   paid_at = {$paid_at_sql},
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where payment_id = '{$payment_id}'
        ", false);
    }

    if (!empty($bill['arrears_months']) && $student_id > 0) {
        $months = array();
        foreach (explode(',', $bill['arrears_months']) as $month) {
            $month = trim($month);
            if (preg_match('/^\d{4}\-\d{2}$/', $month)) {
                $months[] = "'" . sql_escape_string($month) . "'";
            }
        }
        if ($months) {
            sql_query("
                update " . IEUM_TUITION_PAYMENT_TABLE . "
                   set amount_paid = amount_due,
                       status = 'paid',
                       payment_provider = 'paymint',
                       provider_status = 'paid',
                       paid_at = coalesce(paid_at, '" . sql_escape_string($paid_at) . "'),
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and student_id = '{$student_id}'
                   and billing_month in (" . implode(',', $months) . ")
                   and status in ('unpaid','partial')
            ", false);
        }
    }

    return true;
}

function ieum_paymint_payload_value($payload, $keys, $default = '')
{
    if (!is_array($payload)) {
        return $default;
    }

    foreach ($keys as $key) {
        if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
            return trim((string) $payload[$key]);
        }
    }

    foreach (array('data', 'bill', 'payment') as $child_key) {
        if (isset($payload[$child_key]) && is_array($payload[$child_key])) {
            foreach ($keys as $key) {
                if (isset($payload[$child_key][$key]) && trim((string) $payload[$child_key][$key]) !== '') {
                    return trim((string) $payload[$child_key][$key]);
                }
            }
        }
    }

    return $default;
}

function ieum_paymint_status_from_appr_state($appr_state, $current_status = '')
{
    $appr_state = trim((string) $appr_state);
    $current_status = trim((string) $current_status);

    if ($appr_state === 'F') {
        return 'paid';
    }
    if ($appr_state === 'C') {
        return 'canceled';
    }
    if ($appr_state === 'D') {
        return 'destroyed';
    }
    if ($appr_state === 'W') {
        return $current_status === 'sent' ? 'sent' : 'unpaid';
    }

    return $current_status !== '' ? $current_status : 'callback_received';
}

function ieum_paymint_apply_bill_state($bill, $appr_state, $payload = null, $source = 'callback')
{
    if (!$bill || empty($bill['paymint_bill_id'])) {
        return array('ok' => false, 'message' => 'bill_not_found');
    }

    $payload_array = is_array($payload) ? $payload : array();
    $appr_state = trim((string) $appr_state);
    if ($appr_state === '') {
        $appr_state = isset($bill['appr_state']) ? trim((string) $bill['appr_state']) : 'W';
    }

    $status = ieum_paymint_status_from_appr_state($appr_state, isset($bill['status']) ? $bill['status'] : '');
    $appr_num = ieum_paymint_payload_value($payload_array, array('apprNum', 'appr_num'), isset($bill['appr_num']) ? $bill['appr_num'] : '');
    $appr_dt = ieum_paymint_payload_value($payload_array, array('apprDt', 'appr_dt', 'paidAt', 'paid_at'), isset($bill['appr_dt']) ? $bill['appr_dt'] : '');
    $appr_pay_type = ieum_paymint_payload_value($payload_array, array('apprPayType', 'appr_pay_type', 'payType', 'pay_type'), isset($bill['appr_pay_type']) ? $bill['appr_pay_type'] : '');
    $raw_response = is_string($payload) ? $payload : json_encode($payload_array, JSON_UNESCAPED_UNICODE);

    sql_query("
        update " . IEUM_PAYMINT_BILL_TABLE . "
           set status = '" . sql_escape_string($status) . "',
               appr_state = '" . sql_escape_string($appr_state) . "',
               appr_num = '" . sql_escape_string($appr_num) . "',
               appr_dt = '" . sql_escape_string($appr_dt) . "',
               appr_pay_type = '" . sql_escape_string($appr_pay_type) . "',
               paid_at = " . ($appr_state === 'F' ? "coalesce(paid_at, '" . G5_TIME_YMDHIS . "')" : "paid_at") . ",
               canceled_at = " . ($appr_state === 'C' ? "coalesce(canceled_at, '" . G5_TIME_YMDHIS . "')" : "canceled_at") . ",
               destroyed_at = " . ($appr_state === 'D' ? "coalesce(destroyed_at, '" . G5_TIME_YMDHIS . "')" : "destroyed_at") . ",
               raw_response = '" . sql_escape_string($raw_response) . "',
               last_synced_at = '" . G5_TIME_YMDHIS . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where paymint_bill_id = '" . (int) $bill['paymint_bill_id'] . "'
    ", false);

    if ($appr_state === 'F') {
        $bill['appr_state'] = $appr_state;
        $bill['status'] = $status;
        ieum_paymint_apply_successful_payment($bill);
    }

    ieum_paymint_log((int) $bill['paymint_bill_id'], (int) $bill['academy_id'], (int) $bill['payment_id'], $source, $source . ': ' . $appr_state, $payload_array);
    ieum_paymint_update_monthly_usage((int) $bill['academy_id'], substr(isset($bill['created_at']) ? $bill['created_at'] : G5_TIME_YMDHIS, 0, 7));

    return array(
        'ok' => true,
        'message' => 'bill_state_applied',
        'paymint_bill_id' => (int) $bill['paymint_bill_id'],
        'bill_id' => $bill['bill_id'],
        'appr_state' => $appr_state,
        'status' => $status,
    );
}

function ieum_paymint_handle_payment_callback($raw, $payload)
{
    $payload = is_array($payload) ? $payload : array();
    $bill_id = ieum_paymint_payload_value($payload, array('billId', 'bill_id', 'billID'));
    $appr_state = ieum_paymint_payload_value($payload, array('apprState', 'appr_state', 'paymentStatus', 'status'));

    $bill = array();
    if ($bill_id !== '') {
        $bill = sql_fetch("
            select *
              from " . IEUM_PAYMINT_BILL_TABLE . "
             where bill_id = '" . sql_escape_string($bill_id) . "'
             limit 1
        ", false);
    }

    $academy_id = isset($bill['academy_id']) ? (int) $bill['academy_id'] : 0;
    $payment_id = isset($bill['payment_id']) ? (int) $bill['payment_id'] : 0;

    sql_query("
        insert into " . IEUM_PAYMINT_CALLBACK_TABLE . "
            set callback_type = 'payment',
                bill_id = '" . sql_escape_string($bill_id) . "',
                academy_id = '{$academy_id}',
                payment_id = '{$payment_id}',
                appr_state = '" . sql_escape_string($appr_state) . "',
                payload = '" . sql_escape_string($raw) . "',
                result_code = '0000',
                result_message = 'received',
                received_at = '" . G5_TIME_YMDHIS . "'
    ", false);

    if (!isset($bill['paymint_bill_id'])) {
        return array('ok' => true, 'message' => 'received_unknown_bill', 'bill_id' => $bill_id, 'appr_state' => $appr_state);
    }

    return ieum_paymint_apply_bill_state($bill, $appr_state, $payload, 'callback');
}

function ieum_paymint_read_bill($paymint_bill_id)
{
    $bill = ieum_paymint_get_bill($paymint_bill_id);
    if (!$bill) {
        return array('ok' => false, 'message' => 'bill_not_found');
    }

    $settings = ieum_paymint_get_settings();
    $merchant = ieum_paymint_get_merchant((int) $bill['academy_id']);
    if (!$merchant || !ieum_paymint_academy_is_ready((int) $bill['academy_id'])) {
        return array('ok' => false, 'message' => 'merchant_not_ready');
    }

    $payload = array(
        'apiKey' => $settings['api_key'],
        'member' => $merchant['member_id'],
        'merchant' => $merchant['merchant_id'],
        'bill' => array('billId' => $bill['bill_id']),
    );
    $api_result = ieum_paymint_api_request($settings, '/bill/read', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $data = ieum_paymint_response_data($api_result);
    $appr_state = isset($data['apprState']) ? (string) $data['apprState'] : (isset($data['appr_state']) ? (string) $data['appr_state'] : $bill['appr_state']);
    $appr_num = isset($data['apprNum']) ? (string) $data['apprNum'] : (isset($data['appr_num']) ? (string) $data['appr_num'] : '');
    $appr_dt = isset($data['apprDt']) ? (string) $data['apprDt'] : (isset($data['appr_dt']) ? (string) $data['appr_dt'] : '');
    $appr_pay_type = isset($data['apprPayType']) ? (string) $data['apprPayType'] : (isset($data['appr_pay_type']) ? (string) $data['appr_pay_type'] : '');
    $status = ieum_paymint_status_from_appr_state($appr_state, $bill['status']);
    $error = $ok ? '' : ieum_paymint_api_message($api_result);

    if ($ok) {
        ieum_paymint_apply_bill_state($bill, $appr_state, array_merge($api_result, array(
            'apprNum' => $appr_num,
            'apprDt' => $appr_dt,
            'apprPayType' => $appr_pay_type,
        )), 'read');
    } else {
        sql_query("
            update " . IEUM_PAYMINT_BILL_TABLE . "
               set last_error = '" . sql_escape_string($error) . "',
                   raw_response = '" . sql_escape_string(json_encode($api_result, JSON_UNESCAPED_UNICODE)) . "',
                   last_synced_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where paymint_bill_id = '" . (int) $bill['paymint_bill_id'] . "'
        ");
    }
    ieum_paymint_log((int) $bill['paymint_bill_id'], (int) $bill['academy_id'], (int) $bill['payment_id'], 'read', $ok ? $appr_state : 'failed', $api_result);
    ieum_paymint_update_monthly_usage((int) $bill['academy_id'], substr($bill['created_at'], 0, 7));

    return array('ok' => $ok, 'message' => $ok ? 'bill_read' : $error, 'paymint_bill_id' => (int) $bill['paymint_bill_id'], 'bill_id' => $bill['bill_id'], 'appr_state' => $appr_state, 'status' => $status, 'raw_response' => $api_result);
}

function ieum_paymint_cancel_bill($paymint_bill_id, $cancel_reason = '', $created_by = '')
{
    $bill = ieum_paymint_get_bill($paymint_bill_id);
    if (!$bill) {
        return array('ok' => false, 'message' => 'bill_not_found');
    }
    if ($bill['appr_state'] !== 'F' && $bill['status'] !== 'paid') {
        return array('ok' => false, 'message' => 'bill_not_paid');
    }

    $settings = ieum_paymint_get_settings();
    $merchant = ieum_paymint_get_merchant((int) $bill['academy_id']);
    if (!$merchant || !ieum_paymint_academy_is_ready((int) $bill['academy_id'])) {
        return array('ok' => false, 'message' => 'merchant_not_ready');
    }

    $payload = array(
        'apiKey' => $settings['api_key'],
        'member' => $merchant['member_id'],
        'merchant' => $merchant['merchant_id'],
        'bill' => array(
            'billId' => $bill['bill_id'],
            'price' => (string) (int) $bill['bill_amount'],
            'cancelReason' => $cancel_reason !== '' ? $cancel_reason : '아이이음 관리자 취소',
            'hash' => ieum_paymint_hash_source($bill['bill_id'], '', (int) $bill['bill_amount']),
        ),
    );
    $api_result = ieum_paymint_api_request($settings, '/bill/cancel', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $error = $ok ? '' : ieum_paymint_api_message($api_result);

    sql_query("
        update " . IEUM_PAYMINT_BILL_TABLE . "
           set status = '" . ($ok ? 'canceled' : 'failed') . "',
               appr_state = '" . ($ok ? 'C' : sql_escape_string($bill['appr_state'])) . "',
               canceled_at = " . ($ok ? "'" . G5_TIME_YMDHIS . "'" : "canceled_at") . ",
               last_error = '" . sql_escape_string($error) . "',
               raw_response = '" . sql_escape_string(json_encode($api_result, JSON_UNESCAPED_UNICODE)) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where paymint_bill_id = '" . (int) $bill['paymint_bill_id'] . "'
    ");
    ieum_paymint_log((int) $bill['paymint_bill_id'], (int) $bill['academy_id'], (int) $bill['payment_id'], 'cancel', $ok ? 'canceled' : 'failed', $api_result);
    ieum_paymint_update_monthly_usage((int) $bill['academy_id'], substr($bill['created_at'], 0, 7));

    return array('ok' => $ok, 'message' => $ok ? 'canceled' : $error, 'paymint_bill_id' => (int) $bill['paymint_bill_id'], 'bill_id' => $bill['bill_id'], 'raw_response' => $api_result);
}

function ieum_paymint_create_bill_record($prepared, $bill_amounts, $status, $short_url, $raw_response, $created_by = '')
{
    ieum_paymint_ensure_tables();

    $arrears_months = isset($bill_amounts['arrears_months']) && is_array($bill_amounts['arrears_months'])
        ? implode(',', $bill_amounts['arrears_months'])
        : '';

    sql_query("
        insert into " . IEUM_PAYMINT_BILL_TABLE . "
            set academy_id = '" . (int) $prepared['academy_id'] . "',
                payment_id = '" . (int) $prepared['payment_id'] . "',
                student_id = '" . (int) $prepared['student_id'] . "',
                billing_month = '" . sql_escape_string($prepared['billing_month']) . "',
                bill_id = '" . sql_escape_string($prepared['bill_id']) . "',
                paymint_hash = '" . sql_escape_string($prepared['hash_source']) . "',
                short_url = '" . sql_escape_string($short_url) . "',
                send_type = '" . sql_escape_string($prepared['send_type']) . "',
                product_name = '" . sql_escape_string($prepared['product_name']) . "',
                bill_amount = '" . (int) $bill_amounts['total_amount'] . "',
                current_amount = '" . (int) $bill_amounts['current_amount'] . "',
                arrears_amount = '" . (int) $bill_amounts['arrears_amount'] . "',
                arrears_months = '" . sql_escape_string($arrears_months) . "',
                recipient_name = '" . sql_escape_string($prepared['recipient_name']) . "',
                recipient_phone = '" . sql_escape_string($prepared['recipient_phone']) . "',
                appr_state = 'W',
                status = '" . sql_escape_string($status) . "',
                sent_at = '" . G5_TIME_YMDHIS . "',
                expires_at = '" . sql_escape_string($prepared['expires_at']) . "',
                raw_response = '" . sql_escape_string(is_string($raw_response) ? $raw_response : json_encode($raw_response, JSON_UNESCAPED_UNICODE)) . "',
                created_by = '" . sql_escape_string($created_by) . "',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    $paymint_bill_id = (int) sql_insert_id();
    ieum_paymint_log($paymint_bill_id, (int) $prepared['academy_id'], (int) $prepared['payment_id'], 'send', $status, $raw_response);
    ieum_paymint_update_monthly_usage((int) $prepared['academy_id'], date('Y-m'));

    return $paymint_bill_id;
}

function ieum_paymint_send_bill($payment, $recipient_phone, $bill_amounts, $created_by = '', $sequence = 1)
{
    $prepared = ieum_paymint_prepare_bill_payload($payment, $recipient_phone, $bill_amounts, $sequence);
    if (empty($prepared['ok'])) {
        return $prepared;
    }

    $api_result = ieum_paymint_api_request($prepared['settings'], '/bill', $prepared['payload']);
    $payment_link = ieum_paymint_bill_short_url(isset($api_result['response']) ? $api_result['response'] : array());
    if ($payment_link === '') {
        $payment_link = ieum_paymint_service_base_url($prepared['settings']) . '/admin/tuition_payments.php?billing_month=' . urlencode($prepared['billing_month']);
    }
    $status = ieum_paymint_api_success($api_result) ? 'sent' : 'failed';

    $paymint_bill_id = ieum_paymint_create_bill_record($prepared, $bill_amounts, $status, $payment_link, $api_result, $created_by);
    if ($status !== 'sent') {
        return array(
            'ok' => false,
            'message' => 'paymint_api_failed',
            'paymint_bill_id' => $paymint_bill_id,
            'bill_id' => $prepared['bill_id'],
            'provider' => 'payssam',
            'status' => $status,
            'payment_link' => $payment_link,
            'payload' => $prepared['payload'],
            'raw_response' => $api_result,
        );
    }

    return array(
        'ok' => true,
        'paymint_bill_id' => $paymint_bill_id,
        'bill_id' => $prepared['bill_id'],
        'provider' => 'payssam',
        'status' => $status,
        'payment_link' => $payment_link,
        'payload' => $prepared['payload'],
        'raw_response' => $api_result,
    );
}

function ieum_paymint_get_merchant($academy_id)
{
    ieum_paymint_ensure_tables();

    $academy_id = (int) $academy_id;
    if ($academy_id <= 0) {
        return null;
    }

    $row = sql_fetch("
        select *
          from " . IEUM_PAYMINT_MERCHANT_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    return isset($row['academy_id']) ? $row : null;
}

function ieum_paymint_academy_is_ready($academy_id)
{
    $merchant = ieum_paymint_get_merchant($academy_id);
    if (!$merchant) {
        return false;
    }

    return isset($merchant['mapping_status'])
        && $merchant['mapping_status'] === 'mapped'
        && trim((string) $merchant['member_id']) !== ''
        && trim((string) $merchant['merchant_id']) !== '';
}

function ieum_paymint_academy_readiness_label($academy_id)
{
    $merchant = ieum_paymint_get_merchant($academy_id);
    if (!$merchant) {
        return '도장 결제 계정 준비 필요';
    }

    if ($merchant['mapping_status'] === 'mapped') {
        if (trim((string) $merchant['member_id']) === '' || trim((string) $merchant['merchant_id']) === '') {
            return '연동값 확인 필요';
        }
        return '결제선생 연동 완료';
    }

    $labels = array(
        'not_ready' => '도장 결제 계정 준비 필요',
        'pending' => '도장 결제 계정 등록 대기',
        'failed' => '연동 확인 필요',
        'paused' => '결제연동 중지',
    );

    return isset($labels[$merchant['mapping_status']]) ? $labels[$merchant['mapping_status']] : '도장 결제 계정 준비 필요';
}

function ieum_paymint_bill_status_label($status, $appr_state = '')
{
    $status = trim((string) $status);
    $appr_state = trim((string) $appr_state);

    if ($appr_state === 'F' || $status === 'paid') {
        return '납부 완료';
    }

    $labels = array(
        'mock_sent' => '발송 준비',
        'sent' => '발송완료',
        'created' => '생성',
        'unpaid' => '미납',
        'callback_received' => '상태수신',
        'canceled' => '취소',
        'destroyed' => '폐기',
        'failed' => '실패',
    );

    return isset($labels[$status]) ? $labels[$status] : ($status !== '' ? $status : '-');
}

function ieum_paymint_has_duplicate_bill($academy_id, $payment_id, $student_id, $billing_month)
{
    $academy_id = (int) $academy_id;
    $payment_id = (int) $payment_id;
    $student_id = (int) $student_id;
    $billing_month_sql = sql_escape_string($billing_month);

    $row = sql_fetch("
        select paymint_bill_id
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and billing_month = '{$billing_month_sql}'
           and payment_id = '{$payment_id}'
           and status not in ('destroyed','failed')
         limit 1
    ", false);

    return isset($row['paymint_bill_id']);
}

function ieum_paymint_can_resend($paymint_bill_id, $cooldown_hours = 24)
{
    $paymint_bill_id = (int) $paymint_bill_id;
    $cooldown_hours = max(0, (int) $cooldown_hours);
    if ($paymint_bill_id <= 0 || $cooldown_hours <= 0) {
        return true;
    }

    $row = sql_fetch("
        select created_at
          from " . IEUM_PAYMINT_BILL_LOG_TABLE . "
         where paymint_bill_id = '{$paymint_bill_id}'
           and log_type in ('send','resend')
      order by bill_log_id desc
         limit 1
    ", false);

    if (!isset($row['created_at']) || $row['created_at'] === '') {
        return true;
    }

    return strtotime($row['created_at']) <= (time() - ($cooldown_hours * 3600));
}

function ieum_paymint_get_bill($paymint_bill_id, $academy_id = 0)
{
    ieum_paymint_ensure_tables();

    $paymint_bill_id = (int) $paymint_bill_id;
    $academy_id = (int) $academy_id;
    if ($paymint_bill_id <= 0) {
        return array();
    }

    $where = "paymint_bill_id = '{$paymint_bill_id}'";
    if ($academy_id > 0) {
        $where .= " and academy_id = '{$academy_id}'";
    }

    $row = sql_fetch("
        select *
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where {$where}
         limit 1
    ", false);

    return isset($row['paymint_bill_id']) ? $row : array();
}

function ieum_paymint_resend_bill($paymint_bill_id, $created_by = '')
{
    $bill = ieum_paymint_get_bill($paymint_bill_id);
    if (!$bill) {
        return array('ok' => false, 'message' => 'bill_not_found');
    }

    if (in_array($bill['status'], array('paid', 'destroyed', 'canceled'), true) || in_array($bill['appr_state'], array('F', 'D', 'C'), true)) {
        return array('ok' => false, 'message' => 'bill_not_resendable');
    }

    $settings = ieum_paymint_get_settings();
    $merchant = ieum_paymint_get_merchant((int) $bill['academy_id']);
    if (!$merchant || !ieum_paymint_academy_is_ready((int) $bill['academy_id'])) {
        return array('ok' => false, 'message' => 'merchant_not_ready');
    }

    $payload = array(
        'apiKey' => $settings['api_key'],
        'member' => $merchant['member_id'],
        'merchant' => $merchant['merchant_id'],
        'bill' => array(
            'billId' => $bill['bill_id'],
        ),
    );

    $api_result = ieum_paymint_api_request($settings, '/bill/resend', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $status = $ok ? 'sent' : 'failed';
    $error = '';
    if (!$ok && isset($api_result['response']['message'])) {
        $error = (string) $api_result['response']['message'];
    }

    sql_query("
        update " . IEUM_PAYMINT_BILL_TABLE . "
           set status = '" . sql_escape_string($status) . "',
               sent_at = '" . G5_TIME_YMDHIS . "',
               last_error = '" . sql_escape_string($error) . "',
               raw_response = '" . sql_escape_string(json_encode($api_result, JSON_UNESCAPED_UNICODE)) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where paymint_bill_id = '" . (int) $bill['paymint_bill_id'] . "'
    ");

    ieum_paymint_log((int) $bill['paymint_bill_id'], (int) $bill['academy_id'], (int) $bill['payment_id'], 'resend', $ok ? 'sent' : 'failed', $api_result);
    ieum_paymint_update_monthly_usage((int) $bill['academy_id'], substr($bill['created_at'], 0, 7));

    return array(
        'ok' => $ok,
        'message' => $ok ? 'resent' : 'paymint_api_failed',
        'paymint_bill_id' => (int) $bill['paymint_bill_id'],
        'bill_id' => $bill['bill_id'],
        'status' => $status,
        'raw_response' => $api_result,
    );
}

function ieum_paymint_destroy_bill($paymint_bill_id, $created_by = '')
{
    $bill = ieum_paymint_get_bill($paymint_bill_id);
    if (!$bill) {
        return array('ok' => false, 'message' => 'bill_not_found');
    }

    if ($bill['status'] === 'paid' || $bill['appr_state'] === 'F') {
        return array('ok' => false, 'message' => 'bill_already_paid');
    }
    if ($bill['status'] === 'destroyed' || $bill['appr_state'] === 'D') {
        return array('ok' => false, 'message' => 'bill_already_destroyed');
    }

    $settings = ieum_paymint_get_settings();
    $merchant = ieum_paymint_get_merchant((int) $bill['academy_id']);
    if (!$merchant || !ieum_paymint_academy_is_ready((int) $bill['academy_id'])) {
        return array('ok' => false, 'message' => 'merchant_not_ready');
    }

    $payload = array(
        'apiKey' => $settings['api_key'],
        'member' => $merchant['member_id'],
        'merchant' => $merchant['merchant_id'],
        'bill' => array(
            'billId' => $bill['bill_id'],
            'price' => (string) (int) $bill['bill_amount'],
            'hash' => ieum_paymint_hash_source($bill['bill_id'], '', (int) $bill['bill_amount']),
        ),
    );

    $api_result = ieum_paymint_api_request($settings, '/bill/destroy', $payload);
    $ok = ieum_paymint_api_success($api_result);
    $status = $ok ? 'destroyed' : 'failed';
    $appr_state = $ok ? 'D' : $bill['appr_state'];
    $error = '';
    if (!$ok && isset($api_result['response']['message'])) {
        $error = (string) $api_result['response']['message'];
    }

    sql_query("
        update " . IEUM_PAYMINT_BILL_TABLE . "
           set status = '" . sql_escape_string($status) . "',
               appr_state = '" . sql_escape_string($appr_state) . "',
               destroyed_at = " . ($ok ? "'" . G5_TIME_YMDHIS . "'" : "destroyed_at") . ",
               last_error = '" . sql_escape_string($error) . "',
               raw_response = '" . sql_escape_string(json_encode($api_result, JSON_UNESCAPED_UNICODE)) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where paymint_bill_id = '" . (int) $bill['paymint_bill_id'] . "'
    ");

    ieum_paymint_log((int) $bill['paymint_bill_id'], (int) $bill['academy_id'], (int) $bill['payment_id'], 'destroy', $ok ? 'destroyed' : 'failed', $api_result);
    ieum_paymint_update_monthly_usage((int) $bill['academy_id'], substr($bill['created_at'], 0, 7));

    return array(
        'ok' => $ok,
        'message' => $ok ? 'destroyed' : 'paymint_api_failed',
        'paymint_bill_id' => (int) $bill['paymint_bill_id'],
        'bill_id' => $bill['bill_id'],
        'status' => $status,
        'raw_response' => $api_result,
    );
}

function ieum_paymint_log($paymint_bill_id, $academy_id, $payment_id, $log_type, $message = '', $payload = null)
{
    $paymint_bill_id = (int) $paymint_bill_id;
    $academy_id = (int) $academy_id;
    $payment_id = (int) $payment_id;
    $log_type_sql = sql_escape_string($log_type);
    $message_sql = sql_escape_string($message);
    $payload_sql = $payload === null ? 'null' : "'" . sql_escape_string(is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE)) . "'";

    sql_query("
        insert into " . IEUM_PAYMINT_BILL_LOG_TABLE . "
            set paymint_bill_id = '{$paymint_bill_id}',
                academy_id = '{$academy_id}',
                payment_id = '{$payment_id}',
                log_type = '{$log_type_sql}',
                message = '{$message_sql}',
                payload = {$payload_sql},
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_paymint_update_monthly_usage($academy_id, $usage_month)
{
    $academy_id = (int) $academy_id;
    $usage_month = preg_match('/^\d{4}\-\d{2}$/', (string) $usage_month) ? $usage_month : date('Y-m');
    $usage_month_sql = sql_escape_string($usage_month);

    $row = sql_fetch("
        select count(*) as bill_send_count,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when status = 'failed' then 1 else 0 end) as failed_count,
               sum(case when status = 'destroyed' then 1 else 0 end) as destroyed_count,
               coalesce(sum(bill_amount), 0) as bill_amount
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$academy_id}'
           and left(created_at, 7) = '{$usage_month_sql}'
    ", false);

    sql_query("
        insert into " . IEUM_PAYMINT_USAGE_MONTHLY_TABLE . "
            set academy_id = '{$academy_id}',
                usage_month = '{$usage_month_sql}',
                bill_send_count = '" . (int) $row['bill_send_count'] . "',
                paid_count = '" . (int) $row['paid_count'] . "',
                failed_count = '" . (int) $row['failed_count'] . "',
                destroyed_count = '" . (int) $row['destroyed_count'] . "',
                bill_amount = '" . (int) $row['bill_amount'] . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                bill_send_count = values(bill_send_count),
                paid_count = values(paid_count),
                failed_count = values(failed_count),
                destroyed_count = values(destroyed_count),
                bill_amount = values(bill_amount),
                updated_at = values(updated_at)
    ");
}

function ieum_paymint_decode_payload()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    return array($raw, is_array($data) ? $data : array());
}
