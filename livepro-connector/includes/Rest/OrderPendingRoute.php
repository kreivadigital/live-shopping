<?php

namespace LiveProConnector\Rest;

use LiveProConnector\Support\SettingsRepository;
use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class OrderPendingRoute
{
    private SettingsRepository $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('livepro/v1', '/order-pending', [
            'methods' => 'POST',
            'callback' => [$this, 'createPendingOrder'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function createPendingOrder(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $settings = $this->settings->all();
        $rawBody = (string) $request->get_body();
        $apiKey = (string) ($settings['api_key'] ?? '');
        $apiSecret = (string) ($settings['api_secret'] ?? '');

        if (!$this->validateSignature($request, $rawBody, $apiKey, $apiSecret)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Firma inválida'], 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Payload inválido'], 400);
        }

        $customerName = sanitize_text_field((string) ($data['customer_name'] ?? ''));
        $phone = sanitize_text_field((string) ($data['phone'] ?? ''));
        if ($customerName === '' || $phone === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'customer_name y phone son requeridos'], 422);
        }

        $productId = absint($data['product_id'] ?? 0);
        $variationId = absint($data['variation_id'] ?? 0);
        $qty = max(1, absint($data['qty'] ?? 1));

        if ($productId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'product_id inválido'], 422);
        }

        $productForCart = $variationId > 0 ? $variationId : $productId;
        $product = wc_get_product($productForCart);
        if (!$product) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Producto no encontrado'], 404);
        }

        try {
            $order = wc_create_order();
            $addResult = $order->add_product($product, $qty);

            if (!$addResult) {
                throw new RuntimeException('No se pudo agregar el producto a la orden');
            }

            [$firstName, $lastName] = $this->splitName($customerName);

            $order->set_billing_first_name($firstName);
            $order->set_billing_last_name($lastName);
            $order->set_billing_phone($phone);
            $order->set_billing_email(sanitize_email((string) ($data['email'] ?? '')));
            $order->set_billing_city(sanitize_text_field((string) ($data['city'] ?? '')));

            if (!empty($data['notes'])) {
                $order->set_customer_note(sanitize_textarea_field((string) $data['notes']));
            }

            $order->update_meta_data('source', 'livepro_widget');
            $order->update_meta_data('live_session_id', sanitize_text_field((string) ($data['live_session_id'] ?? '')));
            $order->update_meta_data('widget_session_id', sanitize_text_field((string) ($data['widget_session_id'] ?? '')));

            $order->calculate_totals();
            $targetStatus = in_array((string) ($settings['order_status'] ?? 'pending'), ['pending', 'on-hold'], true)
                ? (string) $settings['order_status']
                : 'pending';

            $order->update_status($targetStatus, 'Creado desde LivePro widget');
            $order->save();

            return new WP_REST_Response([
                'ok' => true,
                'order_id' => $order->get_id(),
                'status' => $order->get_status(),
            ], 200);
        } catch (Throwable $error) {
            return new WP_REST_Response(['ok' => false, 'error' => $error->getMessage()], 500);
        }
    }

    private function validateSignature(WP_REST_Request $request, string $rawBody, string $expectedKey, string $secret): bool
    {
        $key = sanitize_text_field((string) $request->get_header('x-livepro-key'));
        $timestamp = sanitize_text_field((string) $request->get_header('x-livepro-timestamp'));
        $signature = sanitize_text_field((string) $request->get_header('x-livepro-signature'));

        if ($key === '' || $timestamp === '' || $signature === '' || $secret === '') {
            return false;
        }

        if (!hash_equals($expectedKey, $key)) {
            return false;
        }

        $ts = (int) $timestamp;
        if (abs(time() - $ts) > 300) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($computed, $signature);
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [$name, ''];
        }

        $first = array_shift($parts);
        return [$first ?: $name, implode(' ', $parts)];
    }
}
