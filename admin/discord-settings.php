<?php

declare(strict_types=1);

if (is_admin()) {
    add_action('admin_init', 'getquick_email_logger_discord_register_settings');
}

function getquick_email_logger_discord_register_settings(): void
{
    register_setting(
        'getquick_email_logger_discord',
        GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY,
        [
            'type' => 'array',
            'sanitize_callback' => 'getquick_email_logger_discord_sanitize_settings',
            'default' => getquick_email_logger_discord_defaults(),
            'show_in_rest' => false,
        ],
    );

    add_settings_section(
        'getquick_email_logger_discord_main',
        'Discord Logging Settings',
        static function (): void {
            echo '<p>Control when email logs are sent to Discord and configure the webhook.</p>';
        },
        'getquick-email-logger',
    );

    add_settings_field(
        'enabled',
        'Enable Discord logs',
        'getquick_email_logger_discord_render_checkbox_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'enabled',
            'label' => 'Send email logs to Discord',
        ],
    );

    add_settings_field(
        'webhook_url',
        'Webhook URL',
        'getquick_email_logger_discord_render_text_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'webhook_url',
            'placeholder' => 'https://discord.com/api/webhooks/...',
        ],
    );

    add_settings_field(
        'username',
        'Bot username',
        'getquick_email_logger_discord_render_text_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'username',
            'placeholder' => 'GetQuick Mail Bot',
        ],
    );

    add_settings_field(
        'notify_sent',
        'Notify for sent emails',
        'getquick_email_logger_discord_render_checkbox_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'notify_sent',
            'label' => 'Post successful sends',
        ],
    );

    add_settings_field(
        'notify_failed',
        'Notify for failed emails',
        'getquick_email_logger_discord_render_checkbox_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'notify_failed',
            'label' => 'Post failures',
        ],
    );

    add_settings_field(
        'include_subject',
        'Include subject',
        'getquick_email_logger_discord_render_checkbox_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'include_subject',
            'label' => 'Include email subject in messages',
        ],
    );

    add_settings_field(
        'include_recipients',
        'Include recipients',
        'getquick_email_logger_discord_render_checkbox_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'include_recipients',
            'label' => 'Include recipients in messages',
        ],
    );

    add_settings_field(
        'timeout_seconds',
        'Request timeout (seconds)',
        'getquick_email_logger_discord_render_number_field',
        'getquick-email-logger',
        'getquick_email_logger_discord_main',
        [
            'key' => 'timeout_seconds',
            'min' => 1,
            'max' => 15,
        ],
    );
}

function getquick_email_logger_discord_render_checkbox_field(array $args): void
{
    $key = (string) ($args['key'] ?? '');
    $label = (string) ($args['label'] ?? '');
    $settings = getquick_email_logger_discord_get_settings();
    $checked = ! empty($settings[$key]);

    printf(
        '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
        esc_attr(GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY),
        esc_attr($key),
        checked($checked, true, false),
        esc_html($label),
    );
}

function getquick_email_logger_discord_render_text_field(array $args): void
{
    $key = (string) ($args['key'] ?? '');
    $placeholder = (string) ($args['placeholder'] ?? '');
    $settings = getquick_email_logger_discord_get_settings();
    $value = (string) ($settings[$key] ?? '');

    printf(
        '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" autocomplete="off">',
        esc_attr(GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY),
        esc_attr($key),
        esc_attr($value),
        esc_attr($placeholder),
    );
}

function getquick_email_logger_discord_render_number_field(array $args): void
{
    $key = (string) ($args['key'] ?? '');
    $min = max(1, (int) ($args['min'] ?? 1));
    $max = max($min, (int) ($args['max'] ?? $min));
    $settings = getquick_email_logger_discord_get_settings();
    $value = max($min, min($max, (int) ($settings[$key] ?? 5)));

    printf(
        '<input type="number" name="%1$s[%2$s]" value="%3$d" min="%4$d" max="%5$d" step="1">',
        esc_attr(GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY),
        esc_attr($key),
        $value,
        $min,
        $max,
    );
}
