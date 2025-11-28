<?php

if (!defined('ABSPATH')) {
    exit;
}

// Log simple au chargement
add_action('wp_loaded', function () {
    error_log('Plugin chargé');
});