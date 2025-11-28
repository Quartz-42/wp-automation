<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Envoi d'un event vers Brevo 
 */
function send_to_brevo_event(array $data)
{
    // 1. Vérification de sécurité
    if (!defined('BREVO_API_KEY') || !defined('BREVO_API_URL')) {
        error_log('Erreur configuration');
        return;
    }

    // 2. Récupération des variables
    $api_key = BREVO_API_KEY;
    $url     = BREVO_API_URL;

    // 3. Préparer la requête
    $args = [
        'headers' => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'    => wp_json_encode($data),
        'timeout' => 10,
    ];

    // 4. Appel API
    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('Erreur : ' . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    error_log('Brevo response [' . $code . '] : ' . $body);
}

/**
 * EVENT 1 : inscription utilisateur
 */
add_action('user_register', function ($user_id) {
    $user = get_userdata($user_id);

    if (!$user instanceof WP_User) {
        return;
    }

    $primary_role = is_array($user->roles) && !empty($user->roles) ? $user->roles[0] : null;

    $payload = [
        'user' => [
            'id'           => $user->ID,
            'email'        => $user->user_email,
            'username'     => $user->user_login,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
        ]
    ];

    log_event('user_registered', $payload);

        // 2. Envoi à Brevo : création / mise à jour du contact
    $brevo_data = [
        'email'      => $user->user_email,
        'attributes' => [
            'FIRSTNAME'   => $user->first_name,
            'LASTNAME'    => $user->last_name,
            'WP_USERID'   => (string) $user->ID,
            'WP_ROLE'     => $primary_role,
            'WP_SITE'     => get_bloginfo('name'),
            'WP_URL'      => get_site_url(),
            'WP_REGISTERED_AT' => $user->user_registered,
        ],
        'updateEnabled' => true, // true = met à jour si le contact existe déjà
    ];

    send_to_brevo_event($brevo_data);

}, 10, 1);

/**
 * EVENT 2 : connexion utilisateur
 */
add_action('wp_login', function ($user_login, $user) {
    if (!$user instanceof WP_User) {
        return;
    }

    $now = current_time('mysql');

    //upsert de la propriété : si elle existe deja on l'update, sinon on la crée
    //dans table wp_usermeta
    update_user_meta($user->ID, 'last_login', $now);

    $payload = [
        'user' => [
            'id'           => $user->ID,
            'email'        => $user->user_email,
            'username'     => $user_login,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'last_login'   => $now,
        ]
    ];

    log_event('user_login', $payload);

    //envoi a Brevo : mise à jour du contact
    $brevo_data = [
        'email'      => $user->user_email,
        'attributes' => [
            'LAST_LOGIN' => $now,
        ],
        'updateEnabled' => true,
    ];

    send_to_brevo_event($brevo_data);

}, 10, 2);