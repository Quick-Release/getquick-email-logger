<?php
/*
 * Plugin Name: getquick-email-logger
 * Description: Persists email delivery logs in a dedicated table and exposes sent logs via WPGraphQL.
 * Version: 0.1.0
 * Author: getquick
 * License: GPL2+
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// Prevent double initialization
if (defined('GETQUICK_EMAIL_LOGGER_VERSION')) {
    return;
}
define('GETQUICK_EMAIL_LOGGER_VERSION', '0.1.0');

// Load Composer autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Initialize the plugin using the OOP architecture.
 */
function getquick_email_logger_init(): void
{
    // Safety check: Ensure the main Plugin class is available (autoloaded)
    if (! class_exists('\\GetQuick\\EmailLogger\\Plugin')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p><strong>getquick-email-logger:</strong> The autoloader is missing. Please run <code>composer install</code> in the plugin directory or ensure the site-wide autoloader is configured correctly.</p></div>'
            );
        });
        return;
    }

    \GetQuick\EmailLogger\Plugin::getInstance();
}

getquick_email_logger_init();

/**
 * Compatibility layer for legacy global functions if they are used by other plugins/themes.
 * Ideally, these should be deprecated and removed in future versions.
 */

function getquick_email_logger_table_name(): string
{
    return (new \GetQuick\EmailLogger\Database\LogRepository())->getTableName();
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
