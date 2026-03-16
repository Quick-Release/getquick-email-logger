<?php

declare(strict_types=1);

if (! defined('GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY')) {
    define('GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY', 'getquick_email_logger_discord_settings');
}

add_action('getquick_email_logger_inserted', 'getquick_email_logger_discord_handle_log', 10, 2);

function getquick_email_logger_discord_handle_log(int $insertId, array $event): void
{
    unset($insertId);

    if (! getquick_email_logger_discord_is_enabled()) {
        return;
    }

    $status = (string) ($event['status'] ?? 'failed');

    if ($status === 'sent' && ! getquick_email_logger_discord_get_notify_sent()) {
        return;
    }

    if ($status !== 'sent' && ! getquick_email_logger_discord_get_notify_failed()) {
        return;
    }

    $webhookUrl = getquick_email_logger_discord_get_webhook_url();
    if ($webhookUrl === '') {
        return;
    }

    $payload = [
        'content' => getquick_email_logger_discord_build_message($event),
        'allowed_mentions' => ['parse' => []],
    ];

    $username = getquick_email_logger_discord_get_username();
    if ($username !== '') {
        $payload['username'] = $username;
    }

    $timeout = max(1, min(15, getquick_email_logger_discord_get_timeout_seconds()));

    $response = wp_remote_post($webhookUrl, [
        'timeout' => $timeout,
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    if (is_wp_error($response)) {
        do_action('getquick_email_logger_discord_delivery_failed', $response, $event, $payload);
        return;
    }

    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode < 200 || $statusCode >= 300) {
        do_action('getquick_email_logger_discord_delivery_failed', $response, $event, $payload);
    }
}

function getquick_email_logger_discord_build_message(array $event): string
{
    $status = (string) ($event['status'] ?? 'failed');
    $siteHost = wp_parse_url(home_url(), PHP_URL_HOST) ?? home_url();

    $lines = [
        sprintf('Site: %s', $siteHost),
        sprintf('Status: %s', $status === 'sent' ? 'SENT' : 'FAILED'),
        sprintf('Time (UTC): %s', (string) ($event['created_at_utc'] ?? gmdate('Y-m-d H:i:s'))),
    ];

    if (getquick_email_logger_discord_get_include_subject()) {
        $subject = wp_strip_all_tags((string) ($event['subject'] ?? '(no subject)'));
        $lines[] = sprintf('Subject: %s', $subject === '' ? '(no subject)' : $subject);
    }

    if (getquick_email_logger_discord_get_include_recipients()) {
        $to = [];
        if (isset($event['to']) && is_array($event['to'])) {
            $to = array_values(array_filter(array_map(static fn($recipient): string => trim((string) $recipient), $event['to'])));
        }

        $lines[] = sprintf('To: %s', $to === [] ? '(empty)' : implode(', ', $to));
    }

    $providerMessageId = trim((string) ($event['provider_message_id'] ?? ''));
    if ($providerMessageId !== '') {
        $lines[] = sprintf('Provider Message ID: %s', $providerMessageId);
    }

    if ($status !== 'sent') {
        $errorCode = wp_strip_all_tags((string) ($event['error_code'] ?? 'unknown'));
        $errorMessage = wp_strip_all_tags((string) ($event['error_message'] ?? 'unknown error'));
        $lines[] = sprintf('Error: [%s] %s', $errorCode, $errorMessage);
    }

    $message = implode("\n", $lines);
    $message = (string) apply_filters('getquick_email_logger_discord_message', $message, $event);

    return getquick_email_logger_discord_truncate($message, 1900);
}

function getquick_email_logger_discord_truncate(string $message, int $maxLength): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return mb_substr($message, 0, $maxLength - 3) . '...';
    }

    if (strlen($message) <= $maxLength) {
        return $message;
    }

    return substr($message, 0, $maxLength - 3) . '...';
}

function getquick_email_logger_discord_defaults(): array
{
    return [
        'enabled' => false,
        'webhook_url' => '',
        'username' => '',
        'notify_sent' => true,
        'notify_failed' => true,
        'include_subject' => true,
        'include_recipients' => true,
        'timeout_seconds' => 5,
    ];
}

function getquick_email_logger_discord_get_settings(): array
{
    $stored = get_option(GETQUICK_EMAIL_LOGGER_DISCORD_OPTION_KEY, []);
    $stored = is_array($stored) ? $stored : [];

    return wp_parse_args($stored, getquick_email_logger_discord_defaults());
}

function getquick_email_logger_discord_sanitize_settings(mixed $input): array
{
    $source = is_array($input) ? $input : [];

    $settings = [
        'enabled' => ! empty($source['enabled']),
        'webhook_url' => esc_url_raw((string) ($source['webhook_url'] ?? '')),
        'username' => sanitize_text_field((string) ($source['username'] ?? '')),
        'notify_sent' => ! empty($source['notify_sent']),
        'notify_failed' => ! empty($source['notify_failed']),
        'include_subject' => ! empty($source['include_subject']),
        'include_recipients' => ! empty($source['include_recipients']),
        'timeout_seconds' => max(1, min(15, (int) ($source['timeout_seconds'] ?? 5))),
    ];

    if ($settings['username'] !== '') {
        if (function_exists('mb_substr')) {
            $settings['username'] = mb_substr($settings['username'], 0, 80);
        } else {
            $settings['username'] = substr($settings['username'], 0, 80);
        }
    }

    return $settings;
}

function getquick_email_logger_discord_get_webhook_url(): string
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL') && is_string(GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL) && GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL !== '') {
        return GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL;
    }

    return getquick_email_logger_discord_get_string('webhook_url', '');
}

function getquick_email_logger_discord_is_enabled(): bool
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_ENABLED')) {
        return GETQUICK_EMAIL_LOGGER_DISCORD_ENABLED === true;
    }

    return getquick_email_logger_discord_get_bool('enabled', false);
}

function getquick_email_logger_discord_get_username(): string
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME') && is_string(GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME) && GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME !== '') {
        return trim(GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME);
    }

    return getquick_email_logger_discord_get_string('username', '');
}

function getquick_email_logger_discord_get_notify_sent(): bool
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_SENT')) {
        return GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_SENT === true;
    }

    return getquick_email_logger_discord_get_bool('notify_sent', true);
}

function getquick_email_logger_discord_get_notify_failed(): bool
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_FAILED')) {
        return GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_FAILED === true;
    }

    return getquick_email_logger_discord_get_bool('notify_failed', true);
}

function getquick_email_logger_discord_get_include_subject(): bool
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_SUBJECT')) {
        return GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_SUBJECT === true;
    }

    return getquick_email_logger_discord_get_bool('include_subject', true);
}

function getquick_email_logger_discord_get_include_recipients(): bool
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_RECIPIENTS')) {
        return GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_RECIPIENTS === true;
    }

    return getquick_email_logger_discord_get_bool('include_recipients', true);
}

function getquick_email_logger_discord_get_timeout_seconds(): int
{
    if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_TIMEOUT_SECONDS')) {
        return (int) GETQUICK_EMAIL_LOGGER_DISCORD_TIMEOUT_SECONDS;
    }

    return getquick_email_logger_discord_get_int('timeout_seconds', 5);
}

function getquick_email_logger_discord_get_string(string $key, string $default): string
{
    $settings = getquick_email_logger_discord_get_settings();
    $value = (string) ($settings[$key] ?? $default);

    return trim($value);
}

function getquick_email_logger_discord_get_bool(string $key, bool $default): bool
{
    $settings = getquick_email_logger_discord_get_settings();
    if (! array_key_exists($key, $settings)) {
        return $default;
    }

    return (bool) $settings[$key];
}

function getquick_email_logger_discord_get_int(string $key, int $default): int
{
    $settings = getquick_email_logger_discord_get_settings();
    if (! array_key_exists($key, $settings)) {
        return $default;
    }

    return (int) $settings[$key];
}
