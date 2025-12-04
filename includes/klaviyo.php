<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Envoi d'un événement vers Klaviyo
 * API v3 (Revision 2024-10-15)
 */
function send_to_klaviyo_event($event_name, $customer_email, $properties = [])
{
        //Vérification de sécurité
    if (!defined('KLAVIYO_API_PRIVATE_KEY') || !defined('KLAVIYO_API_URL')) {
        error_log('Erreur configuration');
        return;
    }

    $url = KLAVIYO_API_URL;

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
 * Creation commande woocommerce
 */
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;
    
    // On évite les doublons
    if (get_post_meta($order_id, '_klaviyo_sent', true)) {
        return;
    }

    $order = wc_get_order($order_id);

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

    //log local
    log_event('order_placed', $payload);

    // 1. Planifier l'envoi pour le CLIENT
    automation_schedule_event('automation_process_klaviyo_event', [
        'Placed Order',
        $order->get_billing_email(),
        [
            'OrderId'   => $order_id,
            'Value'     => $order->get_total(),
            'ItemNames' => $payload['items'],
            'Currency'  => $order->get_currency(),
            'CustomerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        ]
    ]);

    // 2. Planifier l'envoi pour les GESTIONNAIRES
    $shop_managers = get_users(['role' => 'shop_manager']);
    if (empty($shop_managers)) {
        $shop_managers = [get_user_by('email', get_option('admin_email'))];
    }

    foreach ($shop_managers as $manager) {
        if ($manager && !empty($manager->user_email)) {
            automation_schedule_event('automation_process_klaviyo_event', [
                'Admin New Sale Notification',
                $manager->user_email,
                [
                    'SaleAmount'   => $order->get_total(),
                    'Currency'     => $order->get_currency(),
                    'CustomerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'CustomerEmail'=> $order->get_billing_email(),
                    'OrderId'      => $order_id,
                    'Items'        => $payload['items']
                ]
            ]);
        }
    }

    // Marquer comme "Mis en file d'attente"
    update_post_meta($order_id, '_klaviyo_queued', true);

}, 10, 1);


/**
 * Produit ajouté au panier
 * $cart_item_key est requis par la signature du hook mais inutilisé ici
 */
add_action('woocommerce_add_to_cart', function($cart_item_key, $product_id, $quantity, $variation_id) {
    
    // php : on ne tracke que si l'utilisateur est identifié (pour avoir son email)
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    // Récupérer le bon produit (Variation ou Produit simple)
    $target_product_id = $variation_id ? $variation_id : $product_id;
    $product = wc_get_product($target_product_id);

    if (!$product) {
        return;
    }

    // Récupérer les catégories (pour segmentation)
    $categories = [];
    $term_ids = $product->get_category_ids();
    foreach ($term_ids as $term_id) {
        $term = get_term_by('id', $term_id, 'product_cat');
        if ($term) {
            $categories[] = $term->name;
        }
    }

    // Préparer les données pour Klaviyo
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

    //log local
    log_event('add_to_cart', [
        'product' => $product->get_name(),
        'quantity' => $quantity,
        'email' => $user->user_email
    ]);

    // Planifier l'envoi
    automation_schedule_event('automation_process_klaviyo_event', [
        'Added to Cart',
        $user->user_email,
        $properties
    ]);

}, 10, 4);