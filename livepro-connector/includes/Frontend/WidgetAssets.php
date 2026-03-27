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
            'nonce' => wp_create_nonce('wp_rest'),
            'checkoutUrl' => function_exists('wc_get_checkout_url')
                ? esc_url_raw((string) wc_get_checkout_url())
                : '',
            'previewImageUrl' => esc_url_raw((string) ($settings['preview_image_url'] ?? '')),
            'previewViewers' => (string) ($settings['preview_viewers'] ?? ''),
            'rest' => [
                'productViewUrl' => esc_url_raw(rest_url('livepro/v1/product-view')),
                'cartGetUrl' => esc_url_raw(rest_url('livepro/v1/cart')),
                'cartAddUrl' => esc_url_raw(rest_url('livepro/v1/cart/add')),
                'cartUpdateUrl' => esc_url_raw(rest_url('livepro/v1/cart/update')),
                'cartRemoveUrl' => esc_url_raw(rest_url('livepro/v1/cart/remove')),
            ],
            'widget' => [
                'widthDesktop' => (int) ($settings['widget_width_desktop'] ?? 392),
                'widthMobile' => ($settings['widget_width_mobile'] ?? '') === ''
                    ? null
                    : (int) $settings['widget_width_mobile'],
                'mobilePresentationMode' => (string) ($settings['widget_mobile_presentation_mode'] ?? 'floating'),
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
                    'productTag' => (string) ($settings['widget_product_tag_label'] ?? 'DESTACADO'),
                    'liveBadge' => (string) ($settings['widget_live_badge_label'] ?? 'VIVO'),
                    'cartCta' => (string) ($settings['widget_cart_cta_label'] ?? 'AGREGAR AL CARRITO'),
                    'continueBtn' => (string) ($settings['widget_continue_btn_label'] ?? 'SEGUIR VIENDO'),
                    'checkoutBtn' => (string) ($settings['widget_checkout_btn_label'] ?? 'TERMINAR COMPRA'),
                ],
                'colors' => [
                    'productPrice' => (string) ($settings['widget_product_price_color'] ?? '#4f4bf0'),
                    'productTagText' => (string) ($settings['widget_product_tag_color'] ?? '#8b9bbb'),
                    'productTagBackground' => (string) ($settings['widget_product_tag_background_color'] ?? '#eef2f8'),
                    'productCtaBackground' => (string) ($settings['widget_product_cta_background_color'] ?? '#4f4bf0'),
                    'productCtaText' => (string) ($settings['widget_product_cta_text_color'] ?? '#ffffff'),
                    'cartCtaBackground' => (string) ($settings['widget_cart_cta_background_color'] ?? '#000000'),
                    'cartCtaText' => (string) ($settings['widget_cart_cta_text_color'] ?? '#ffffff'),
                    'cartIconBackground' => (string) ($settings['widget_cart_icon_background_color'] ?? '#ffffff'),
                    'cartIconColor' => (string) ($settings['widget_cart_icon_color'] ?? '#1e2d49'),
                    'continueBtnBackground' => (string) ($settings['widget_continue_btn_background_color'] ?? '#ffffff'),
                    'continueBtnText' => (string) ($settings['widget_continue_btn_text_color'] ?? '#1e2d49'),
                    'checkoutBtnBackground' => (string) ($settings['widget_checkout_btn_background_color'] ?? '#1e2d49'),
                    'checkoutBtnText' => (string) ($settings['widget_checkout_btn_text_color'] ?? '#ffffff'),
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
