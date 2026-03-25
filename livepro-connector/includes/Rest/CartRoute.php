<?php

namespace LiveProConnector\Rest;

use LiveProConnector\Support\CartPayloadBuilder;
use WP_REST_Request;
use WP_REST_Response;

final class CartRoute
{
    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('livepro/v1', '/cart', [
            'methods' => 'GET',
            'callback' => [$this, 'handleGet'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('livepro/v1', '/cart/add', [
            'methods' => 'POST',
            'callback' => [$this, 'handleAdd'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('livepro/v1', '/cart/update', [
            'methods' => 'POST',
            'callback' => [$this, 'handleUpdate'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('livepro/v1', '/cart/remove', [
            'methods' => 'POST',
            'callback' => [$this, 'handleRemove'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        CartPayloadBuilder::ensureCart();

        return new WP_REST_Response([
            'ok' => true,
            'cart' => CartPayloadBuilder::build(),
        ], 200);
    }

    public function handleAdd(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $productId = absint($request->get_param('product_id'));
        $variationId = absint($request->get_param('variation_id'));
        $quantity = absint($request->get_param('quantity'));

        if ($productId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'product_id inválido'], 422);
        }

        if ($quantity <= 0) {
            $quantity = 1;
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Producto no encontrado'], 404);
        }

        CartPayloadBuilder::ensureCart();

        $variationAttributes = [];
        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            if (!$variation || !$variation->is_type('variation')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Variación no encontrada'], 404);
            }
            if ((int) $variation->get_parent_id() !== $productId) {
                return new WP_REST_Response(['ok' => false, 'error' => 'La variación no pertenece al producto'], 422);
            }
            foreach ($variation->get_variation_attributes() as $key => $value) {
                $attributeKey = str_starts_with($key, 'attribute_') ? $key : 'attribute_' . $key;
                $variationAttributes[$attributeKey] = $value;
            }
        }

        wc_clear_notices();

        $existingKey = WC()->cart->find_product_in_cart(
            WC()->cart->generate_cart_id($productId, $variationId, $variationAttributes)
        );

        if ($existingKey) {
            $existingItem = WC()->cart->get_cart_item($existingKey);
            $newQty = (int) ($existingItem['quantity'] ?? 0) + $quantity;
            WC()->cart->set_quantity($existingKey, $newQty);
            $cartItemKey = $existingKey;
        } else {
            $cartItemKey = WC()->cart->add_to_cart($productId, $quantity, $variationId, $variationAttributes);
        }

        if (!$cartItemKey) {
            $errors = wc_get_notices('error');
            wc_clear_notices();

            $errorMessage = 'No se pudo agregar al carrito';
            if (!empty($errors)) {
                $messages = array_map(function ($notice) {
                    return is_array($notice) ? wp_strip_all_tags($notice['notice'] ?? '') : wp_strip_all_tags((string) $notice);
                }, $errors);
                $errorMessage = implode('. ', array_filter($messages));
            }

            return new WP_REST_Response(['ok' => false, 'error' => $errorMessage], 422);
        }

        $cartItem = WC()->cart->get_cart_item($cartItemKey);
        $addedItem = $cartItem
            ? CartPayloadBuilder::formatItem($cartItemKey, $cartItem)
            : null;

        return new WP_REST_Response([
            'ok' => true,
            'cart' => CartPayloadBuilder::build(),
            'added_item' => $addedItem,
        ], 200);
    }

    public function handleUpdate(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $cartItemKey = sanitize_text_field((string) $request->get_param('cart_item_key'));
        $quantity = (int) $request->get_param('quantity');

        if ($cartItemKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'cart_item_key requerido'], 422);
        }

        if ($quantity < 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Cantidad inválida'], 422);
        }

        CartPayloadBuilder::ensureCart();

        $result = WC()->cart->set_quantity($cartItemKey, $quantity);

        if ($result === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'No se pudo actualizar la cantidad'], 422);
        }

        return new WP_REST_Response([
            'ok' => true,
            'cart' => CartPayloadBuilder::build(),
        ], 200);
    }

    public function handleRemove(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $cartItemKey = sanitize_text_field((string) $request->get_param('cart_item_key'));

        if ($cartItemKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'cart_item_key requerido'], 422);
        }

        CartPayloadBuilder::ensureCart();

        $result = WC()->cart->remove_cart_item($cartItemKey);

        if (!$result) {
            return new WP_REST_Response(['ok' => false, 'error' => 'No se pudo eliminar del carrito'], 422);
        }

        return new WP_REST_Response([
            'ok' => true,
            'cart' => CartPayloadBuilder::build(),
        ], 200);
    }
}
