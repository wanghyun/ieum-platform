<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_report_link_secret()
{
    $parts = array();
    if (defined('IEUM_REPORT_LINK_SECRET')) {
        $parts[] = IEUM_REPORT_LINK_SECRET;
    }
    if (defined('IEUM_SMS_GATEWAY_TOKEN')) {
        $parts[] = IEUM_SMS_GATEWAY_TOKEN;
    }
    if (defined('IEUM_PROJECT_STATUS_TOKEN')) {
        $parts[] = IEUM_PROJECT_STATUS_TOKEN;
    }
    if (defined('G5_MYSQL_PASSWORD')) {
        $parts[] = G5_MYSQL_PASSWORD;
    }

    return hash('sha256', implode('|', $parts));
}

function ieum_report_base64url_encode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ieum_report_base64url_decode($value)
{
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }

    return base64_decode($value);
}

function ieum_character_report_token($academy_id, $student_id, $month, $expires_at = 0)
{
    $payload = array(
        'a' => (int) $academy_id,
        's' => (int) $student_id,
        'm' => preg_replace('/[^0-9\-]/', '', $month),
        'e' => $expires_at ? (int) $expires_at : strtotime('+180 days'),
    );
    $json = json_encode($payload);
    $body = ieum_report_base64url_encode($json);
    $signature = hash_hmac('sha256', $body, ieum_report_link_secret());

    return $body . '.' . $signature;
}

function ieum_character_report_payload($token)
{
    $token = trim((string) $token);
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }

    list($body, $signature) = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $body, ieum_report_link_secret());
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(ieum_report_base64url_decode($body), true);
    if (!is_array($payload)) {
        return null;
    }
    if (empty($payload['a']) || empty($payload['s']) || empty($payload['m']) || empty($payload['e'])) {
        return null;
    }
    if ((int) $payload['e'] < time()) {
        return null;
    }
    if (!preg_match('/^\d{4}\-\d{2}$/', $payload['m'])) {
        return null;
    }

    return $payload;
}

function ieum_character_report_public_url($academy_id, $student_id, $month)
{
    $token = ieum_character_report_token($academy_id, $student_id, $month);
    return IEUM_URL . '/character_parent_report.php?t=' . rawurlencode($token);
}

function ieum_fitness_report_token($academy_id, $student_id, $month, $expires_at = 0)
{
    $payload = array(
        'r' => 'fitness',
        'a' => (int) $academy_id,
        's' => (int) $student_id,
        'm' => preg_replace('/[^0-9\-]/', '', $month),
        'e' => $expires_at ? (int) $expires_at : strtotime('+180 days'),
    );
    $json = json_encode($payload);
    $body = ieum_report_base64url_encode($json);
    $signature = hash_hmac('sha256', $body, ieum_report_link_secret());

    return $body . '.' . $signature;
}

function ieum_fitness_report_payload($token)
{
    $payload = ieum_character_report_payload($token);
    if (!$payload) {
        return null;
    }
    if (!isset($payload['r']) || $payload['r'] !== 'fitness') {
        return null;
    }

    return $payload;
}

function ieum_fitness_report_public_url($academy_id, $student_id, $month)
{
    $token = ieum_fitness_report_token($academy_id, $student_id, $month);
    return IEUM_URL . '/fitness_parent_report.php?t=' . rawurlencode($token);
}
