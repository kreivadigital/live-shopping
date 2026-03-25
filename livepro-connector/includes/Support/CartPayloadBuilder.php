<?php

namespace LiveProConnector\Support;

final class CartPayloadBuilder
{
    public static function ensureCart(): void
    {
        if (!function_exists('WC') || !WC()) {
            return;
        }

        if (null === WC()->session) {
            $sessionClass = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            WC()->session = new $sessionClass();
            WC()->session->init();
        }

        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        if (null === WC()->customer) {
            WC()->customer = new \WC_Customer(get_current_user_id(), true);
        }

        if (null === WC()->cart) {
            WC()->cart = new \WC_Cart();
            WC()->cart->get_cart();
        }
    }

    public static function build(): array
    {
        self::ensureCart();

        $items = [];

        foreach (WC()->cart->get_cart() as $cartItemKey => $cartItem) {
            $items[] = self::formatItem($cartItemKey, $cartItem);
        }

        return [
            'items' => $items,
            'item_count' => WC()->cart->get_cart_contents_count(),
            'subtotal' => self::stripPriceHtml(WC()->cart->get_cart_subtotal()),
            'total' => self::stripPriceHtml(WC()->cart->get_cart_total()),
        ];
    }

    public static function formatItem(string $cartItemKey, array $cartItem): array
    {
        $productId = (int) ($cartItem['product_id'] ?? 0);
        $variationId = (int) ($cartItem['variation_id'] ?? 0);
        $quantity = (int) ($cartItem['quantity'] ?? 1);

        $product = $variationId > 0
            ? wc_get_product($variationId)
            : wc_get_product($productId);

        $parentProduct = $variationId > 0
            ? wc_get_product($productId)
            : $product;

        $name = $parentProduct ? $parentProduct->get_name() : '';
        $image = '';
        $price = '';
        $priceRaw = 0.0;

        if ($product) {
            $imageId = $product->get_image_id();
            if (!$imageId && $parentProduct && $parentProduct->get_id() !== $product->get_id()) {
                $imageId = $parentProduct->get_image_id();
            }
            $image = $imageId
                ? (string) wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail')
                : '';
            $priceRaw = (float) $product->get_price();
            $price = self::stripPriceHtml((string) wc_price($priceRaw));
        }

        $lineSubtotal = (float) ($cartItem['line_subtotal'] ?? 0.0);
        $subtotal = self::stripPriceHtml((string) wc_price($lineSubtotal));

        $attributes = self::extractAttributes($cartItem);

        return [
            'cart_item_key' => $cartItemKey,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'name' => $name,
            'image' => $image,
            'price' => $price,
            'price_raw' => $priceRaw,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'attributes' => $attributes,
        ];
    }

    private static function extractAttributes(array $cartItem): array
    {
        $attributes = [];
        $variation = $cartItem['variation'] ?? [];

        if (!is_array($variation) || empty($variation)) {
            return $attributes;
        }

        foreach ($variation as $key => $value) {
            $cleanKey = str_replace('attribute_', '', $key);

            if (strpos($cleanKey, 'pa_') === 0) {
                $taxonomySlug = $cleanKey;
                $label = wc_attribute_label($taxonomySlug);
                $termValue = get_term_by('slug', $value, $taxonomySlug);
                $attributes[$label] = $termValue ? $termValue->name : $value;
            } else {
                $label = ucfirst(str_replace(['-', '_'], ' ', $cleanKey));
                $attributes[$label] = $value;
            }
        }

        return $attributes;
    }

    private static function stripPriceHtml(string $html): string
    {
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return trim($text);
    }
}
