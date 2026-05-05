<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_json_response($ok, $message, $data = array(), $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode(array(
        'ok' => (bool) $ok,
        'message' => $message,
        'data' => $data,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
