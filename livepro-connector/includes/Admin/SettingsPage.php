<?php

namespace LiveProConnector\Admin;

use LiveProConnector\Support\PluginConfig;
use LiveProConnector\Support\SettingsRepository;

final class SettingsPage
{
    private SettingsRepository $settings;
    private string $pluginFile;

    public function __construct(SettingsRepository $settings, string $pluginFile)
    {
        $this->settings = $settings;
        $this->pluginFile = $pluginFile;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . PluginConfig::TEST_CONNECTION_ACTION, [$this, 'handleTestConnection']);
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            'LivePro',
            'LivePro',
            'manage_options',
            PluginConfig::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-video-alt3'
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            PluginConfig::SETTINGS_GROUP,
            PluginConfig::OPTION_KEY,
            [$this->settings, 'sanitize']
        );

        add_settings_section(
            PluginConfig::SETTINGS_SECTION,
            'Configuración LivePro',
            null,
            PluginConfig::PAGE_SLUG
        );

        foreach ($this->fieldLabels() as $key => $label) {
            add_settings_field(
                $key,
                $label,
                function () use ($key): void {
                    $this->renderField($key);
                },
                PluginConfig::PAGE_SLUG,
                PluginConfig::SETTINGS_SECTION
            );
        }
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . PluginConfig::PAGE_SLUG) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'livepro-admin',
            plugin_dir_url($this->pluginFile) . 'assets/admin/admin.js',
            ['jquery'],
            PluginConfig::VERSION,
            true
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->settings->all();
        ?>
        <div class="wrap">
            <h1>LivePro Connector</h1>
            <p>Conecta tu WooCommerce con el backoffice para publicar live y crear pedidos pendientes desde el widget.</p>

            <form method="post" action="options.php">
                <?php
                settings_fields(PluginConfig::SETTINGS_GROUP);
                do_settings_sections(PluginConfig::PAGE_SLUG);
                submit_button('Guardar configuración');
                ?>
            </form>

            <hr>
            <h2>Probar conexión</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(PluginConfig::TEST_CONNECTION_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(PluginConfig::TEST_CONNECTION_ACTION); ?>">
                <?php submit_button('Conectar y validar', 'secondary'); ?>
            </form>

            <?php if (!empty($_GET[PluginConfig::MESSAGE_QUERY_ARG])): ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html(sanitize_text_field((string) $_GET[PluginConfig::MESSAGE_QUERY_ARG])); ?></p>
                </div>
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

        check_admin_referer(PluginConfig::TEST_CONNECTION_ACTION);

        $settings = $this->settings->all();
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
        $this->settings->update($settings);

        $this->redirectWithMessage('Conexión validada. Store ID asignado: ' . $settings['store_id']);
    }

    private function renderField(string $key): void
    {
        $settings = $this->settings->all();
        $value = isset($settings[$key]) ? (string) $settings[$key] : '';
        $type = $key === 'api_secret' ? 'password' : 'text';

        if ($key === 'order_status') {
            echo '<select name="' . esc_attr(PluginConfig::OPTION_KEY . '[' . $key . ']') . '">';
            foreach (['pending', 'on-hold'] as $option) {
                $selected = selected($value ?: 'pending', $option, false);
                echo '<option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html($option) . '</option>';
            }
            echo '</select>';
            return;
        }

        if ($key === 'preview_image_url') {
            echo '<input class="regular-text" id="livepro_preview_image_url" type="text" name="' . esc_attr(PluginConfig::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($value) . '" placeholder="https://...">';
            echo ' <button type="button" class="button" id="livepro_pick_preview_image">Seleccionar imagen</button>';

            $display = $value !== '' ? '' : 'display:none;';
            echo '<div style="margin-top:10px;">';
            echo '<img id="livepro_preview_image_tag" src="' . esc_url($value) . '" alt="Preview" style="' . esc_attr($display . 'max-width:180px;height:auto;border-radius:8px;border:1px solid #ccd0d4;') . '">';
            echo '</div>';
            echo '<p class="description">Imagen del widget minimizado. Si queda vacío, usa miniatura del live de YouTube.</p>';
            return;
        }

        echo '<input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr(PluginConfig::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr($value) . '">';
    }

    private function fieldLabels(): array
    {
        return [
            'backoffice_url' => 'Backoffice URL',
            'api_key' => 'API Key',
            'api_secret' => 'API Secret',
            'store_id' => 'Store ID',
            'store_name' => 'Nombre Tienda (opcional)',
            'preview_image_url' => 'Preview imagen cerrada (URL)',
            'preview_viewers' => 'Preview viewers (opcional)',
            'order_status' => 'Estado pedido (pending/on-hold)',
        ];
    }

    private function redirectWithMessage(string $message): void
    {
        $url = add_query_arg(
            PluginConfig::MESSAGE_QUERY_ARG,
            rawurlencode($message),
            admin_url('admin.php?page=' . PluginConfig::PAGE_SLUG)
        );

        wp_safe_redirect($url);
        exit;
    }
}
