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
        ]);
    }
}
