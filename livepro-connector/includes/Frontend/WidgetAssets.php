<?php

namespace LiveProConnector\Frontend;

use LiveProConnector\Support\PluginConfig;
use LiveProConnector\Support\SettingsRepository;

final class WidgetAssets
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->settings->all();
        if (empty($settings['backoffice_url']) || empty($settings['store_id'])) {
            return;
        }

        $baseUrl = plugin_dir_url($this->pluginFile);

        wp_enqueue_style(
            'livepro-widget',
            $baseUrl . 'assets/widget/widget.css',
            [],
            PluginConfig::VERSION
        );

        wp_enqueue_script(
            'livepro-widget',
            $baseUrl . 'assets/widget/widget.js',
            [],
            PluginConfig::VERSION,
            true
        );

        wp_localize_script('livepro-widget', 'LiveProWidgetConfig', [
            'backofficeUrl' => esc_url_raw((string) $settings['backoffice_url']),
            'storeId' => (string) $settings['store_id'],
            'pollMs' => 5000,
            'previewImageUrl' => esc_url_raw((string) ($settings['preview_image_url'] ?? '')),
            'previewViewers' => (string) ($settings['preview_viewers'] ?? ''),
            'rest' => [
                'productViewUrl' => esc_url_raw(rest_url('livepro/v1/product-view')),
            ],
            'widget' => [
                'widthDesktop' => (int) ($settings['widget_width_desktop'] ?? 392),
                'widthMobile' => ($settings['widget_width_mobile'] ?? '') === ''
                    ? null
                    : (int) $settings['widget_width_mobile'],
                'orientation' => (string) ($settings['widget_orientation'] ?? 'vertical'),
                'position' => [
                    'vertical' => (string) ($settings['widget_position_vertical'] ?? 'bottom'),
                    'horizontal' => (string) ($settings['widget_position_horizontal'] ?? 'left'),
                ],
                'offset' => [
                    'x' => (int) ($settings['widget_offset_x'] ?? 16),
                    'y' => (int) ($settings['widget_offset_y'] ?? 16),
                ],
                'autoplay' => !empty($settings['widget_autoplay']),
                'startMuted' => !empty($settings['widget_start_muted']),
                'labels' => [
                    'previewCta' => (string) ($settings['widget_preview_cta_label'] ?? 'VER AHORA'),
                    'productCta' => (string) ($settings['widget_product_cta_label'] ?? 'VER PRODUCTO'),
                    'liveBadge' => (string) ($settings['widget_live_badge_label'] ?? 'VIVO'),
                ],
                'indicators' => [
                    'showLiveBadge' => !empty($settings['widget_show_live_badge']),
                    'showViewers' => !empty($settings['widget_show_viewers']),
                ],
                'productDataStrategy' => (string) ($settings['widget_product_data_strategy'] ?? 'livepro_with_fallback'),
            ],
        ]);
    }
}
