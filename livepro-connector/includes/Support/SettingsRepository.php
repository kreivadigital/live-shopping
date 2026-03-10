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
        ];
    }
}
