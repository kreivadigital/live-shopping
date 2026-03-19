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
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'toplevel_page_' . PluginConfig::PAGE_SLUG) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style(
            'livepro-admin',
            plugin_dir_url($this->pluginFile) . 'assets/admin/admin.css',
            [],
            PluginConfig::VERSION
        );
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
        $statusItems = $this->buildStatusItems($settings);
        ?>
        <div class="wrap livepro-admin-page">
            <div class="livepro-admin-page__hero">
                <div>
                    <span class="livepro-admin-page__eyebrow">Live shopping widget</span>
                    <h1>LivePro Connector</h1>
                    <p>Configura la conexión con LivePro y define cómo se verá el widget flotante en storefront antes de entrar a la etapa visual del nuevo frontend.</p>
                </div>
                <div class="livepro-admin-page__hero-status">
                    <span class="livepro-admin-page__status-dot <?php echo !empty($settings['store_id']) ? 'is-online' : 'is-offline'; ?>"></span>
                    <?php echo !empty($settings['store_id']) ? 'Tienda conectada' : 'Configuración pendiente'; ?>
                </div>
            </div>

            <?php if (!empty($_GET[PluginConfig::MESSAGE_QUERY_ARG])): ?>
                <div class="livepro-admin-page__flash">
                    <?php echo esc_html(sanitize_text_field((string) $_GET[PluginConfig::MESSAGE_QUERY_ARG])); ?>
                </div>
            <?php endif; ?>

            <div class="livepro-admin-page__layout">
                <form method="post" action="options.php" class="livepro-admin-page__main">
                    <?php settings_fields(PluginConfig::SETTINGS_GROUP); ?>

                    <div class="livepro-admin-page__section-grid">
                        <?php foreach ($this->sections() as $section): ?>
                            <section class="livepro-card">
                                <div class="livepro-card__header">
                                    <h2><?php echo esc_html($section['title']); ?></h2>
                                    <p><?php echo esc_html($section['description']); ?></p>
                                </div>

                                <div class="livepro-card__fields">
                                    <?php foreach ($section['fields'] as $fieldKey): ?>
                                        <?php $this->renderField($fieldKey, $settings); ?>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="livepro-admin-page__actions">
                        <?php submit_button('Guardar configuración', 'primary livepro-admin-page__save', 'submit', false); ?>
                    </div>
                </form>

                <aside class="livepro-admin-page__sidebar">
                    <section class="livepro-card livepro-card--compact">
                        <div class="livepro-card__header">
                            <h2>Estado actual</h2>
                            <p>Resumen rápido de la configuración guardada.</p>
                        </div>

                        <div class="livepro-status-list">
                            <?php foreach ($statusItems as $item): ?>
                                <div class="livepro-status-list__item">
                                    <span class="livepro-status-list__label"><?php echo esc_html($item['label']); ?></span>
                                    <span class="livepro-status-list__value"><?php echo esc_html($item['value']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="livepro-card livepro-card--compact">
                        <div class="livepro-card__header">
                            <h2>Probar conexión</h2>
                            <p>Valida que WordPress pueda registrar la tienda con el backoffice.</p>
                        </div>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="livepro-inline-form">
                            <?php wp_nonce_field(PluginConfig::TEST_CONNECTION_ACTION); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr(PluginConfig::TEST_CONNECTION_ACTION); ?>">
                            <?php submit_button('Conectar y validar', 'secondary', 'submit', false); ?>
                        </form>
                    </section>

                    <section class="livepro-card livepro-card--compact">
                        <div class="livepro-card__header">
                            <h2>Checklist</h2>
                            <p>Base recomendada antes de avanzar con el rediseño del widget.</p>
                        </div>

                        <ul class="livepro-checklist">
                            <li>Backoffice URL, API Key y API Secret cargados.</li>
                            <li>Store ID asignado después de validar conexión.</li>
                            <li>Orientación, posición y medidas iniciales definidas.</li>
                            <li>Strategy de datos lista para LivePro y fallback Woo.</li>
                        </ul>
                    </section>
                </aside>
            </div>
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

    private function renderField(string $key, array $settings): void
    {
        $field = $this->fieldDefinitions()[$key];
        $value = isset($settings[$key]) ? (string) $settings[$key] : '';

        echo '<div class="livepro-field livepro-field--' . esc_attr($field['type']) . '">';
        echo '<label class="livepro-field__label" for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label>';

        if (!empty($field['description'])) {
            echo '<p class="livepro-field__description">' . esc_html($field['description']) . '</p>';
        }

        $name = PluginConfig::OPTION_KEY . '[' . $key . ']';
        $inputId = $key;
        $placeholder = isset($field['placeholder']) ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';

        switch ($field['type']) {
            case 'select':
                echo '<select id="' . esc_attr($inputId) . '" name="' . esc_attr($name) . '">';
                foreach ($field['options'] as $optionValue => $optionLabel) {
                    echo '<option value="' . esc_attr($optionValue) . '" ' . selected($value, (string) $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
                }
                echo '</select>';
                break;

            case 'toggle':
                echo '<label class="livepro-toggle" for="' . esc_attr($inputId) . '">';
                echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
                echo '<input id="' . esc_attr($inputId) . '" type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '>';
                echo '<span class="livepro-toggle__track"><span class="livepro-toggle__knob"></span></span>';
                echo '<span class="livepro-toggle__text">' . esc_html($field['toggle_label']) . '</span>';
                echo '</label>';
                break;

            case 'media':
                echo '<div class="livepro-media-field">';
                echo '<input class="regular-text" id="livepro_preview_image_url" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $placeholder . '>';
                echo '<button type="button" class="button button-secondary" id="livepro_pick_preview_image">Seleccionar imagen</button>';
                echo '</div>';
                echo '<div class="livepro-media-preview">';
                echo '<img id="livepro_preview_image_tag" src="' . esc_url($value) . '" alt="Preview" style="' . esc_attr($value !== '' ? '' : 'display:none;') . '">';
                echo '</div>';
                break;

            case 'number':
                $min = isset($field['min']) ? ' min="' . esc_attr((string) $field['min']) . '"' : '';
                $max = isset($field['max']) ? ' max="' . esc_attr((string) $field['max']) . '"' : '';
                $step = isset($field['step']) ? ' step="' . esc_attr((string) $field['step']) . '"' : ' step="1"';
                echo '<input id="' . esc_attr($inputId) . '" type="number" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $placeholder . $min . $max . $step . '>';
                break;

            case 'color':
                echo '<input id="' . esc_attr($inputId) . '" type="color" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
                break;

            default:
                $type = $field['type'] === 'password' ? 'password' : ($field['type'] === 'url' ? 'url' : 'text');
                echo '<input id="' . esc_attr($inputId) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $placeholder . '>';
                break;
        }

        echo '</div>';
    }

    private function sections(): array
    {
        return [
            [
                'title' => 'Conexión',
                'description' => 'Datos necesarios para vincular el sitio WordPress con el backoffice de LivePro.',
                'fields' => ['backoffice_url', 'api_key', 'api_secret', 'store_name', 'store_id', 'order_status'],
            ],
            [
                'title' => 'Preview y contenido',
                'description' => 'Ajustes del estado minimizado y estrategia de datos para producto.',
                'fields' => ['preview_image_url', 'preview_viewers', 'widget_preview_cta_label', 'widget_product_cta_label', 'widget_product_tag_label', 'widget_live_badge_label', 'widget_product_data_strategy'],
            ],
            [
                'title' => 'Apariencia de producto',
                'description' => 'Personaliza los colores del precio y del CTA del dock de producto.',
                'fields' => ['widget_product_price_color', 'widget_product_tag_color', 'widget_product_cta_background_color', 'widget_product_cta_text_color'],
            ],
            [
                'title' => 'Layout del widget',
                'description' => 'Control inicial de tamaño, orientación y posición del widget flotante.',
                'fields' => ['widget_width_desktop', 'widget_width_mobile', 'widget_mobile_presentation_mode', 'widget_orientation', 'widget_position_vertical', 'widget_position_horizontal', 'widget_offset_x', 'widget_offset_y'],
            ],
            [
                'title' => 'Comportamiento',
                'description' => 'Toggles base para playback e indicadores visibles del widget.',
                'fields' => ['widget_autoplay', 'widget_start_muted', 'widget_show_live_badge', 'widget_show_viewers'],
            ],
        ];
    }

    private function fieldDefinitions(): array
    {
        return [
            'backoffice_url' => [
                'label' => 'Backoffice URL',
                'type' => 'url',
                'placeholder' => 'https://livepro.kreivadigital.com',
                'description' => 'URL base del backoffice que expone el estado público del live.',
            ],
            'api_key' => [
                'label' => 'API Key',
                'type' => 'text',
                'description' => 'Clave pública asignada a la tienda para validar la conexión.',
            ],
            'api_secret' => [
                'label' => 'API Secret',
                'type' => 'password',
                'description' => 'Clave privada usada en la comunicación firmada con LivePro.',
            ],
            'store_name' => [
                'label' => 'Nombre tienda',
                'type' => 'text',
                'description' => 'Nombre descriptivo a informar al backoffice durante el registro.',
            ],
            'store_id' => [
                'label' => 'Store ID',
                'type' => 'text',
                'description' => 'Se completa al validar la conexión. No debería cambiarse manualmente salvo soporte.',
            ],
            'order_status' => [
                'label' => 'Estado pedido',
                'type' => 'select',
                'description' => 'Se mantiene por compatibilidad con el endpoint actual de pedido pendiente.',
                'options' => [
                    'pending' => 'pending',
                    'on-hold' => 'on-hold',
                ],
            ],
            'preview_image_url' => [
                'label' => 'Imagen preview cerrada',
                'type' => 'media',
                'placeholder' => 'https://...',
                'description' => 'Si queda vacía, el widget puede usar miniatura del live de YouTube como fallback.',
            ],
            'preview_viewers' => [
                'label' => 'Preview viewers',
                'type' => 'text',
                'description' => 'Valor opcional para mostrar cuando el backend no envía viewers.',
            ],
            'widget_preview_cta_label' => [
                'label' => 'CTA preview',
                'type' => 'text',
                'description' => 'Texto visible en el estado cerrado del widget.',
            ],
            'widget_product_cta_label' => [
                'label' => 'CTA producto',
                'type' => 'text',
                'description' => 'Texto del botón que abrirá el bottom sheet de producto.',
            ],
            'widget_product_tag_label' => [
                'label' => 'Texto destacado',
                'type' => 'text',
                'description' => 'Texto visible en el tag superior del dock de producto.',
            ],
            'widget_live_badge_label' => [
                'label' => 'Label en vivo',
                'type' => 'text',
                'description' => 'Texto del badge superior del estado cerrado y abierto.',
            ],
            'widget_product_price_color' => [
                'label' => 'Color del precio',
                'type' => 'color',
                'description' => 'Se aplica al precio del producto tanto en el dock como en el sheet.',
            ],
            'widget_product_tag_color' => [
                'label' => 'Color destacado',
                'type' => 'color',
                'description' => 'Color del texto del tag livepro-product-dock__tag.',
            ],
            'widget_product_cta_background_color' => [
                'label' => 'Fondo CTA producto',
                'type' => 'color',
                'description' => 'Color de fondo del botón livepro-product-dock__cta.',
            ],
            'widget_product_cta_text_color' => [
                'label' => 'Texto CTA producto',
                'type' => 'color',
                'description' => 'Color del texto e icono del botón livepro-product-dock__cta.',
            ],
            'widget_product_data_strategy' => [
                'label' => 'Estrategia de datos',
                'type' => 'select',
                'description' => 'Define si el widget confía solo en LivePro o si habilita fallback a Woo.',
                'options' => [
                    'livepro_only' => 'Solo LivePro',
                    'livepro_with_fallback' => 'LivePro + fallback Woo',
                ],
            ],
            'widget_width_desktop' => [
                'label' => 'Ancho desktop (px)',
                'type' => 'number',
                'description' => 'Base del widget en desktop. El shell nuevo lo tomará como ancho principal.',
                'min' => 280,
                'max' => 640,
            ],
            'widget_width_mobile' => [
                'label' => 'Ancho mobile (px)',
                'type' => 'number',
                'description' => 'Déjalo vacío para usar auto/full width según el layout mobile.',
                'min' => 120,
                'max' => 480,
                'placeholder' => 'Auto',
            ],
            'widget_mobile_presentation_mode' => [
                'label' => 'Presentación mobile',
                'type' => 'select',
                'description' => 'Define si en mobile el widget se comporta como tarjeta flotante o como vista inmersiva casi a pantalla completa, sin overlay oscuro.',
                'options' => [
                    'floating' => 'Flotante',
                    'immersive' => 'Inmersivo',
                ],
            ],
            'widget_orientation' => [
                'label' => 'Orientación',
                'type' => 'select',
                'description' => 'Orientación objetivo del contenedor del live para la próxima fase visual.',
                'options' => [
                    'vertical' => 'Vertical',
                    'horizontal' => 'Horizontal',
                ],
            ],
            'widget_position_vertical' => [
                'label' => 'Anclaje vertical',
                'type' => 'select',
                'description' => 'Ubicación del widget respecto del viewport.',
                'options' => [
                    'top' => 'Arriba',
                    'bottom' => 'Abajo',
                ],
            ],
            'widget_position_horizontal' => [
                'label' => 'Anclaje horizontal',
                'type' => 'select',
                'description' => 'Ubicación lateral del widget respecto del viewport.',
                'options' => [
                    'left' => 'Izquierda',
                    'right' => 'Derecha',
                ],
            ],
            'widget_offset_x' => [
                'label' => 'Offset horizontal (px)',
                'type' => 'number',
                'description' => 'Separación lateral respecto del borde elegido.',
                'min' => 0,
                'max' => 96,
            ],
            'widget_offset_y' => [
                'label' => 'Offset vertical (px)',
                'type' => 'number',
                'description' => 'Separación superior o inferior respecto del borde elegido.',
                'min' => 0,
                'max' => 96,
            ],
            'widget_autoplay' => [
                'label' => 'Autoplay',
                'type' => 'toggle',
                'description' => 'Permite que el live intente iniciar automáticamente al abrir el widget.',
                'toggle_label' => 'Activar autoplay del live',
            ],
            'widget_start_muted' => [
                'label' => 'Audio inicial',
                'type' => 'toggle',
                'description' => 'Controla si el widget inicia muteado por defecto.',
                'toggle_label' => 'Iniciar el live muteado',
            ],
            'widget_show_live_badge' => [
                'label' => 'Badge en vivo',
                'type' => 'toggle',
                'description' => 'Mantiene visible el badge de estado durante el live.',
                'toggle_label' => 'Mostrar badge “en vivo”',
            ],
            'widget_show_viewers' => [
                'label' => 'Indicador viewers',
                'type' => 'toggle',
                'description' => 'Muestra viewers reales o placeholder según disponibilidad del backend.',
                'toggle_label' => 'Mostrar viewers',
            ],
        ];
    }

    private function buildStatusItems(array $settings): array
    {
        return [
            [
                'label' => 'Backoffice',
                'value' => !empty($settings['backoffice_url']) ? (string) $settings['backoffice_url'] : 'No configurado',
            ],
            [
                'label' => 'Store ID',
                'value' => !empty($settings['store_id']) ? (string) $settings['store_id'] : 'Sin validar',
            ],
            [
                'label' => 'Layout',
                'value' => sprintf(
                    '%s / %spx / %s',
                    ucfirst((string) ($settings['widget_orientation'] ?? 'vertical')),
                    (string) ($settings['widget_width_desktop'] ?? 392),
                    ($settings['widget_mobile_presentation_mode'] ?? 'floating') === 'immersive' ? 'Mobile inmersivo' : 'Mobile flotante'
                ),
            ],
            [
                'label' => 'Posición',
                'value' => ucfirst((string) ($settings['widget_position_vertical'] ?? 'bottom')) . ' - ' . ucfirst((string) ($settings['widget_position_horizontal'] ?? 'left')),
            ],
            [
                'label' => 'Datos producto',
                'value' => ($settings['widget_product_data_strategy'] ?? 'livepro_with_fallback') === 'livepro_only'
                    ? 'Solo LivePro'
                    : 'LivePro + Woo fallback',
            ],
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
