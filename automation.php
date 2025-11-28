<?php
/**
 * Plugin Name: Automation Hub
 * Description: Plugin centralisant Brevo, Klaviyo et les logs
 * Version: 1.0.0
 * Author: BB
 */

if (!defined('ABSPATH')) {
    exit;
}

// on charge d'abord les Loggers (car les autres en ont besoin)
require_once plugin_dir_path(__FILE__) . 'includes/simple-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/central-logger.php';

// puis les autres outils
require_once plugin_dir_path(__FILE__) . 'includes/brevo.php';
require_once plugin_dir_path(__FILE__) . 'includes/klaviyo.php';