<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Planifie un événement asynchrone (Queue)
 * 
 * @param string $hook_name Le nom de l'action à déclencher
 * @param array  $args      Les arguments à passer
 */
function automation_schedule_event($hook_name, $args = []) {
    if (function_exists('as_enqueue_async_action')) {
        // Utilise Action Scheduler (WooCommerce standard)
        // as_enqueue_async_action : Exécute dès que possible en arrière-plan
        as_enqueue_async_action($hook_name, $args, 'automation-test');
    } else {
        // Fallback si probleme (exécution directe)
        do_action($hook_name, ...$args);
    }
}

/**
 * Enregistre les workers (les fonctions qui font le travail réel)
 */
add_action('init', function() {
    //worker klaviyo
    add_action('automation_process_klaviyo_event', 'process_klaviyo_event_worker', 10, 3);
    //worker brevo
    add_action('automation_process_brevo_event', 'process_brevo_event_worker', 10, 1);
});

/**
 * WORKER : Traite l'envoi vers Klaviyo
 * Cette fonction est appelée par le Scheduler en arrière-plan
 */
function process_klaviyo_event_worker($event_name, $email, $properties) {
    if (function_exists('send_to_klaviyo_event')) {
        send_to_klaviyo_event($event_name, $email, $properties);
    } else {
        // Si la fonction n'existe pas, on lance une exception pour que le Scheduler réessaie plus tard
        throw new Exception('Klaviyo queue error');
    }
}

/**
 * WORKER : Traite l'envoi vers Brevo
 * Cette fonction est appelée par le Scheduler en arrière-plan
 */
function process_brevo_event_worker($data) {
    if (function_exists('send_to_brevo_event')) {
        send_to_brevo_event($data);
    } else {
        // Si la fonction n'existe pas, on lance une exception pour que le Scheduler réessaie plus tard
        throw new Exception('Brevo queue error');
    }
}   