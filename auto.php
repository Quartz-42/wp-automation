<?php
/**
 * Plugin Name: Learning Automation
 * Description: Plugin de formation au Marketing Automation
 * Version: 1.0.0
 * Author: BB
 */

if (!defined('ABSPATH')) {
    exit;
}

// Log simple au chargement
add_action('wp_loaded', function () {
    error_log('Plugin Learning Automation chargé');
});

/**
 * Tracker centralisé : standardise tous les events.
 */
function log_event(string $event_name, array $payload = [])
{
    $event = [
        'event'     => $event_name,
        'timestamp' => current_time('mysql'),
        'site_url'  => get_site_url(),
        'payload'   => $payload,
    ];

    // logger standard WP
    error_log('TRACKED EVENT : ' . json_encode($event, JSON_UNESCAPED_UNICODE));
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
 * EVENT 1 : inscription utilisateur.
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
 * EVENT 2 : connexion utilisateur.
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
            'last_login'  => $now,
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


/**
 * Envoi d'un événement vers Klaviyo
 * API v3 (Revision 2024-10-15)
 */
function send_to_klaviyo_event($event_name, $customer_email, $properties = [])
{
    if (!defined('KLAVIYO_API_PRIVATE_KEY')) return;

    $url = KLAVIYO_URL;

    $body = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'properties' => $properties, // Les détails (Prix, ID commande...)
                'metric' => [
                    'data' => [
                        'type' => 'metric',
                        'attributes' => [
                            'name' => $event_name // ex: "Placed Order"
                        ]
                    ]
                ],
                'profile' => [
                    'data' => [
                        'type' => 'profile',
                        'attributes' => [
                            'email' => $customer_email
                        ]
                    ]
                ]
            ]
        ]
    ];

    $args = [
        'headers' => [
            'Authorization' => 'Klaviyo-API-Key ' . KLAVIYO_API_PRIVATE_KEY,
            'Content-Type'  => 'application/json',
            'Revision'      => '2024-10-15'
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 10,
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('Klaviyo Error: ' . $response->get_error_message());
    } else {
        error_log('Klaviyo Event Sent [' . wp_remote_retrieve_response_code($response) . ']');
    }
}

/**
 * EVENT 3 : Commande WooCommerce créée
 */
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    
    // On évite les doublons (si l'utilisateur rafraîchit la page de remerciement)
    if (get_post_meta($order_id, '_klaviyo_sent', true)) {
        return;
    }

    $payload = [
        'order_id' => $order_id,
        'total'    => $order->get_total(),
        'currency' => $order->get_currency(),
        'items'    => []
    ];

    // Récupérer les produits de la commande
    foreach ($order->get_items() as $item) {
        $payload['items'][] = $item->get_name();
    }

    // 1. Log local
    log_event('order_placed', $payload);

    // 2. Envoi Klaviyo
    send_to_klaviyo_event('Placed Order', $order->get_billing_email(), [
        'OrderId'   => $order_id,
        'Value'     => $order->get_total(),
        'ItemNames' => $payload['items'],
        'Currency'  => $order->get_currency()
    ]);

    // Marquer comme envoyé
    update_post_meta($order_id, '_klaviyo_sent', true);

}, 10, 1);

/**
 * EVENT 4 : Produit ajouté au panier
 */
 // $cart_item_key est requis par la signature du hook mais inutilisé ici
add_action('woocommerce_add_to_cart', function($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    
    // 1. On ne tracke que si l'utilisateur est identifié (pour avoir son email)
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    $user_email = $user->user_email;

    // 2. Récupérer le bon produit (Variation ou Produit simple)
    $target_product_id = $variation_id ? $variation_id : $product_id;
    $product = wc_get_product($target_product_id);

    if (!$product) {
        return;
    }

    // 3. Récupérer les catégories (pour segmentation)
    $categories = [];
    $term_ids = $product->get_category_ids();
    foreach ($term_ids as $term_id) {
        $term = get_term_by('id', $term_id, 'product_cat');
        if ($term) {
            $categories[] = $term->name;
        }
    }

    // 4. Préparer les données pour Klaviyo
    // On suit la structure standard "Added to Cart" de Klaviyo
    $properties = [
        'AddedItemProductName' => $product->get_name(),
        'AddedItemProductID'   => (string) $target_product_id,
        'AddedItemSKU'         => $product->get_sku(),
        'AddedItemPrice'       => (float) $product->get_price(),
        'AddedItemQuantity'    => (int) $quantity,
        'AddedItemImageURL'    => wp_get_attachment_url($product->get_image_id()),
        'AddedItemURL'         => $product->get_permalink(),
        'AddedItemCategories'  => $categories,
        'Value'                => (float) $product->get_price() * $quantity // Valeur totale de l'ajout
    ];

    // 5. Log local
    log_event('add_to_cart', [
        'product' => $product->get_name(),
        'quantity' => $quantity,
        'email' => $user_email
    ]);

    // 6. Envoi à Klaviyo
    send_to_klaviyo_event('Added to Cart', $user_email, $properties);

}, 10, 6);