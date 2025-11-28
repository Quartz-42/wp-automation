<?php

if (!defined('ABSPATH')) {
    exit;
}

function log_event(string $event_name, array $payload = [])
{
    $event = [
        'event'     => $event_name,
        'timestamp' => current_time('mysql'),
        'site_url'  => get_site_url(),
        'payload'   => $payload,
    ];

    // log l'event dans le journal WP
    error_log('TRACKED EVENT : ' . json_encode($event, JSON_UNESCAPED_UNICODE));
}