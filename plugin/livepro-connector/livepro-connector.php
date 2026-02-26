<?php
/**
 * Plugin Name: LivePro Connector
 * Description: Conecta WooCommerce con el backoffice de LivePro e inyecta el widget de live shopping.
 * Version: 0.1.14
 * Author: LivePro
 */

defined('ABSPATH') || exit;

final class LiveProConnector
{
    private const OPTION_KEY = 'livepro_connector_settings';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueWidgetAssets']);
        add_action('admin_post_livepro_test_connection', [$this, 'handleTestConnection']);
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            'LivePro',
            'LivePro',
            'manage_options',
            'livepro-connector',
            [$this, 'renderSettingsPage'],
            'dashicons-video-alt3'
        );
    }

    public function registerSettings(): void
    {
        register_setting('livepro_connector_group', self::OPTION_KEY, [$this, 'sanitizeSettings']);

        add_settings_section('livepro_main_section', 'Configuración LivePro', null, 'livepro-connector');

        $fields = [
            'backoffice_url' => 'Backoffice URL',
            'api_key' => 'API Key',
            'api_secret' => 'API Secret',
            'store_id' => 'Store ID',
            'store_name' => 'Nombre Tienda (opcional)',
            'preview_image_url' => 'Preview imagen cerrada (URL)',
            'preview_viewers' => 'Preview viewers (opcional)',
            'order_status' => 'Estado pedido (pending/on-hold)',
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                function () use ($key): void {
                    $settings = $this->getSettings();
                    $value = isset($settings[$key]) ? (string) $settings[$key] : '';
                    $type = $key === 'api_secret' ? 'password' : 'text';
                    if ($key === 'order_status') {
                        echo '<select name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '">';
                        foreach (['pending', 'on-hold'] as $option) {
                            $selected = selected($value ?: 'pending', $option, false);
                            echo '<option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html($option) . '</option>';
                        }
                        echo '</select>';
                        return;
                    }

                    if ($key === 'preview_image_url') {
                        echo '<input class="regular-text" id="livepro_preview_image_url" type="text" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($value) . '" placeholder="https://...">';
                        echo ' <button type="button" class="button" id="livepro_pick_preview_image">Seleccionar imagen</button>';
                        if ($value !== '') {
                            echo '<div style="margin-top:10px;"><img id="livepro_preview_image_tag" src="' . esc_url($value) . '" alt="Preview" style="max-width:180px;height:auto;border-radius:8px;border:1px solid #ccd0d4;"></div>';
                        } else {
                            echo '<div style="margin-top:10px;"><img id="livepro_preview_image_tag" src="" alt="Preview" style="display:none;max-width:180px;height:auto;border-radius:8px;border:1px solid #ccd0d4;"></div>';
                        }
                        echo '<p class="description">Imagen del widget minimizado. Si queda vacío, usa miniatura del live de YouTube.</p>';
                        return;
                    }

                    echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($value) . '">';
                },
                'livepro-connector',
                'livepro_main_section'
            );
        }
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_livepro-connector') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'livepro-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            '0.1.14',
            true
        );
    }

    public function sanitizeSettings(array $input): array
    {
        return [
            'backoffice_url' => esc_url_raw(trim((string) ($input['backoffice_url'] ?? ''))),
            'api_key' => sanitize_text_field((string) ($input['api_key'] ?? '')),
            'api_secret' => sanitize_text_field((string) ($input['api_secret'] ?? '')),
            'store_id' => sanitize_text_field((string) ($input['store_id'] ?? '')),
            'store_name' => sanitize_text_field((string) ($input['store_name'] ?? '')),
            'preview_image_url' => esc_url_raw(trim((string) ($input['preview_image_url'] ?? ''))),
            'preview_viewers' => sanitize_text_field((string) ($input['preview_viewers'] ?? '')),
            'order_status' => in_array(($input['order_status'] ?? ''), ['pending', 'on-hold'], true)
                ? (string) $input['order_status']
                : 'pending',
        ];
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->getSettings();
        ?>
        <div class="wrap">
            <h1>LivePro Connector</h1>
            <p>Conecta tu WooCommerce con el backoffice para publicar live y crear pedidos pendientes desde el widget.</p>

            <form method="post" action="options.php">
                <?php
                settings_fields('livepro_connector_group');
                do_settings_sections('livepro-connector');
                submit_button('Guardar configuración');
                ?>
            </form>

            <hr>
            <h2>Probar conexión</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('livepro_test_connection'); ?>
                <input type="hidden" name="action" value="livepro_test_connection">
                <?php submit_button('Conectar y validar', 'secondary'); ?>
            </form>

            <?php if (!empty($_GET['livepro_msg'])): ?>
                <div class="notice notice-info"><p><?php echo esc_html(sanitize_text_field((string) $_GET['livepro_msg'])); ?></p></div>
            <?php endif; ?>

            <h2>Estado actual</h2>
            <ul>
                <li>Backoffice URL: <?php echo esc_html((string) ($settings['backoffice_url'] ?? '-')); ?></li>
                <li>Store ID: <?php echo esc_html((string) ($settings['store_id'] ?? '-')); ?></li>
                <li>Preview imagen: <?php echo esc_html((string) ($settings['preview_image_url'] ?? '-')); ?></li>
                <li>Estado pedido: <?php echo esc_html((string) ($settings['order_status'] ?? 'pending')); ?></li>
            </ul>
        </div>
        <?php
    }

    public function handleTestConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('livepro_test_connection');

        $settings = $this->getSettings();
        $url = rtrim((string) ($settings['backoffice_url'] ?? ''), '/');
        $apiKey = (string) ($settings['api_key'] ?? '');
        $apiSecret = (string) ($settings['api_secret'] ?? '');

        if ($url === '' || $apiKey === '' || $apiSecret === '') {
            $this->redirectWithMessage('Completa backoffice_url, api_key y api_secret antes de conectar.');
        }

        $payload = [
            'site_url' => home_url('/'),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'name' => (string) ($settings['store_name'] ?? get_bloginfo('name')),
            'slug' => sanitize_title((string) ($settings['store_name'] ?? get_bloginfo('name'))),
            'order_status' => (string) ($settings['order_status'] ?? 'pending'),
        ];

        $response = wp_remote_post($url . '/api/v1/plugin/connect', [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->redirectWithMessage('Error conectando con backoffice: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($statusCode >= 300 || !is_array($body) || empty($body['ok'])) {
            $message = is_array($body) && !empty($body['error']) ? (string) $body['error'] : 'Conexión rechazada.';
            $this->redirectWithMessage($message);
        }

        $settings['store_id'] = (string) $body['store_id'];
        update_option(self::OPTION_KEY, $settings);

        $this->redirectWithMessage('Conexión validada. Store ID asignado: ' . $settings['store_id']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('livepro/v1', '/order-pending', [
            'methods' => 'POST',
            'callback' => [$this, 'createPendingOrder'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function createPendingOrder(WP_REST_Request $request)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $settings = $this->getSettings();
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

    public function enqueueWidgetAssets(): void
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->getSettings();

        if (empty($settings['backoffice_url']) || empty($settings['store_id'])) {
            return;
        }

        wp_enqueue_style(
            'livepro-widget',
            plugin_dir_url(__FILE__) . 'assets/widget.css',
            [],
            '0.1.14'
        );

        wp_enqueue_script(
            'livepro-widget',
            plugin_dir_url(__FILE__) . 'assets/widget.js',
            [],
            '0.1.14',
            true
        );

        wp_localize_script('livepro-widget', 'LiveProWidgetConfig', [
            'backofficeUrl' => esc_url_raw((string) $settings['backoffice_url']),
            'storeId' => (string) $settings['store_id'],
            'pollMs' => 5000,
            'previewImageUrl' => esc_url_raw((string) ($settings['preview_image_url'] ?? '')),
            'previewViewers' => (string) ($settings['preview_viewers'] ?? ''),
        ]);
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

        $now = time();
        $ts = (int) $timestamp;
        if (abs($now - $ts) > 300) {
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

    private function getSettings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
    }

    private function redirectWithMessage(string $message): void
    {
        $url = add_query_arg('livepro_msg', rawurlencode($message), admin_url('admin.php?page=livepro-connector'));
        wp_safe_redirect($url);
        exit;
    }
}

$liveProConnector = new LiveProConnector();
$liveProConnector->boot();
