<?php

namespace LiveProConnector\Support;

final class SettingsRepository
{
    public function all(): array
    {
        $settings = get_option(PluginConfig::OPTION_KEY, []);
        $stored = is_array($settings) ? $settings : [];

        return wp_parse_args($stored, $this->defaults());
    }

    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'backoffice_url' => esc_url_raw(trim((string) ($input['backoffice_url'] ?? ''))),
            'api_key' => sanitize_text_field((string) ($input['api_key'] ?? '')),
            'api_secret' => sanitize_text_field((string) ($input['api_secret'] ?? '')),
            'store_id' => sanitize_text_field((string) ($input['store_id'] ?? '')),
            'store_name' => sanitize_text_field((string) ($input['store_name'] ?? '')),
            'preview_image_url' => esc_url_raw(trim((string) ($input['preview_image_url'] ?? ''))),
            'preview_viewers' => sanitize_text_field((string) ($input['preview_viewers'] ?? '')),
            'order_status' => in_array((string) ($input['order_status'] ?? ''), ['pending', 'on-hold'], true)
                ? (string) $input['order_status']
                : 'pending',
            'widget_width_desktop' => $this->sanitizeNumber($input['widget_width_desktop'] ?? 392, 280, 640, 392),
            'widget_width_mobile' => $this->sanitizeNullableNumber($input['widget_width_mobile'] ?? '', 120, 480),
            'widget_mobile_presentation_mode' => in_array((string) ($input['widget_mobile_presentation_mode'] ?? ''), ['floating', 'immersive'], true)
                ? (string) $input['widget_mobile_presentation_mode']
                : 'floating',
            'widget_orientation' => in_array((string) ($input['widget_orientation'] ?? ''), ['vertical', 'horizontal'], true)
                ? (string) $input['widget_orientation']
                : 'vertical',
            'widget_position_vertical' => in_array((string) ($input['widget_position_vertical'] ?? ''), ['top', 'bottom'], true)
                ? (string) $input['widget_position_vertical']
                : 'bottom',
            'widget_position_horizontal' => in_array((string) ($input['widget_position_horizontal'] ?? ''), ['left', 'right'], true)
                ? (string) $input['widget_position_horizontal']
                : 'left',
            'widget_offset_x' => $this->sanitizeNumber($input['widget_offset_x'] ?? 16, 0, 96, 16),
            'widget_offset_y' => $this->sanitizeNumber($input['widget_offset_y'] ?? 16, 0, 96, 16),
            'widget_autoplay' => !empty($input['widget_autoplay']) ? 1 : 0,
            'widget_start_muted' => !empty($input['widget_start_muted']) ? 1 : 0,
            'widget_preview_cta_label' => sanitize_text_field((string) ($input['widget_preview_cta_label'] ?? 'VER AHORA')),
            'widget_product_cta_label' => sanitize_text_field((string) ($input['widget_product_cta_label'] ?? 'VER PRODUCTO')),
            'widget_product_tag_label' => sanitize_text_field((string) ($input['widget_product_tag_label'] ?? 'DESTACADO')),
            'widget_live_badge_label' => sanitize_text_field((string) ($input['widget_live_badge_label'] ?? 'VIVO')),
            'widget_product_price_color' => $this->sanitizeHexColor($input['widget_product_price_color'] ?? '#4f4bf0', '#4f4bf0'),
            'widget_product_tag_color' => $this->sanitizeHexColor($input['widget_product_tag_color'] ?? '#8b9bbb', '#8b9bbb'),
            'widget_product_tag_background_color' => $this->sanitizeHexColor($input['widget_product_tag_background_color'] ?? '#eef2f8', '#eef2f8'),
            'widget_product_cta_background_color' => $this->sanitizeHexColor($input['widget_product_cta_background_color'] ?? '#4f4bf0', '#4f4bf0'),
            'widget_product_cta_text_color' => $this->sanitizeHexColor($input['widget_product_cta_text_color'] ?? '#ffffff', '#ffffff'),
            'widget_show_live_badge' => !empty($input['widget_show_live_badge']) ? 1 : 0,
            'widget_show_viewers' => !empty($input['widget_show_viewers']) ? 1 : 0,
            'widget_product_data_strategy' => in_array((string) ($input['widget_product_data_strategy'] ?? ''), ['livepro_only', 'livepro_with_fallback'], true)
                ? (string) $input['widget_product_data_strategy']
                : 'livepro_with_fallback',
        ];
    }

    public function update(array $settings): void
    {
        update_option(PluginConfig::OPTION_KEY, $settings);
    }

    public function defaults(): array
    {
        return [
            'backoffice_url' => '',
            'api_key' => '',
            'api_secret' => '',
            'store_id' => '',
            'store_name' => '',
            'preview_image_url' => '',
            'preview_viewers' => '',
            'order_status' => 'pending',
            'widget_width_desktop' => 392,
            'widget_width_mobile' => '',
            'widget_mobile_presentation_mode' => 'floating',
            'widget_orientation' => 'vertical',
            'widget_position_vertical' => 'bottom',
            'widget_position_horizontal' => 'left',
            'widget_offset_x' => 16,
            'widget_offset_y' => 16,
            'widget_autoplay' => 1,
            'widget_start_muted' => 1,
            'widget_preview_cta_label' => 'VER AHORA',
            'widget_product_cta_label' => 'VER PRODUCTO',
            'widget_product_tag_label' => 'DESTACADO',
            'widget_live_badge_label' => 'VIVO',
            'widget_product_price_color' => '#4f4bf0',
            'widget_product_tag_color' => '#8b9bbb',
            'widget_product_tag_background_color' => '#eef2f8',
            'widget_product_cta_background_color' => '#4f4bf0',
            'widget_product_cta_text_color' => '#ffffff',
            'widget_show_live_badge' => 1,
            'widget_show_viewers' => 1,
            'widget_product_data_strategy' => 'livepro_with_fallback',
        ];
    }

    private function sanitizeNumber($value, int $min, int $max, int $fallback): int
    {
        if ($value === '' || $value === null) {
            return $fallback;
        }

        $number = absint($value);
        if ($number < $min || $number > $max) {
            return $fallback;
        }

        return $number;
    }

    private function sanitizeNullableNumber($value, int $min, int $max): string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        $number = absint($value);
        if ($number < $min || $number > $max) {
            return '';
        }

        return (string) $number;
    }

    private function sanitizeHexColor($value, string $fallback): string
    {
        $sanitized = sanitize_hex_color((string) $value);

        return is_string($sanitized) && $sanitized !== '' ? $sanitized : $fallback;
    }
}
