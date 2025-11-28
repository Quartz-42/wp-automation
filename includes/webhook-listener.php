<?php

if (!defined('ABSPATH')) {
    exit;
}
/**
 * WEBHOOK LISTENER
 * Creation d'une url  que brevo peut appeler
 */
add_action('rest_api_init', function () {
  
    register_rest_route('automation/v1', '/brevo-webhook', [
        'methods'  => 'POST',
        'callback' => 'handle_brevo_webhook',
        'permission_callback' => '__return_true', // Public (pour que Brevo puisse y accéder)
    ]);
});

/**
 * Traitement du Webhook Brevo
 */
function handle_brevo_webhook(WP_REST_Request $request) {
    // 1. Récupérer les données envoyées par Brevo
    $params = $request->get_json_params();

    log_event('webhook_received_brevo', [
        'data' => $params,
    ]);

    // 2. Vérifier si on a les infos minimales
    if (empty($params['email']) || empty($params['event'])) {
        return new WP_REST_Response([
            'status' => 'ignored', 
            'message' => 'Error'
        ], 400);
    }

    $email = sanitize_email($params['email']);
    $event = sanitize_text_field($params['event']);

    // 3. Trouver l'utilisateur WordPress correspondant
    $user = get_user_by('email', $email);

    if (!$user) {
        log_event('webhook_user_not_found', ['email' => $email]);
        return new WP_REST_Response([
            'status' => 'ignored', 
            'message' => 'Error'
        ], 200);
    }

    switch ($event) {
        case 'unsubscribe':
        case 'spam':
            // L'utilisateur ne veut plus d'emails
            update_user_meta($user->ID, 'marketing_consent', 'opt-out');
            update_user_meta($user->ID, 'marketing_optout_date', current_time('mysql'));
            update_user_meta($user->ID, 'marketing_optout_source', 'brevo_webhook');
            
            log_event('user_opt_out', [
                'user_id' => $user->ID,
                'email' => $email,
                'source' => 'brevo_webhook',
                'event' => $event
            ]);
            break;

        case 'hard_bounce':
            // L'email n'existe plus -> Marquer comme invalide
            update_user_meta($user->ID, 'email_status', 'invalid');
            update_user_meta($user->ID, 'email_bounce_date', current_time('mysql'));
            
            log_event('user_email_bounced', [
                'user_id' => $user->ID,
                'email' => $email
            ]);
            break;

        // case 'soft_bounce':
        //     // Problème temporaire (boîte pleine, serveur down...)
        //     update_user_meta($user->ID, 'email_status', 'soft_bounce');
        //     break;
           
        default:
            // Autres events qu'on ne traite pas encore
            log_event('webhook_event_ignored', [
                'event' => $event,
                'email' => $email
            ]);
            break;
    }

    // Toujours répondre une 200 à Brevo (sinon il va réessayer en boucle)
    return new WP_REST_Response([
        'status' => 'success',
        'processed_event' => $event,
        'user_id' => $user->ID
    ], 200);
}