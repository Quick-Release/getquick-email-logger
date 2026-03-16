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
 * Remove all plugin-created data for the current site.
 */
function getquick_email_logger_cleanup_current_site(): void
{
    global $wpdb;

    $logTable = (new \GetQuick\EmailLogger\Database\LogRepository())->getTableName();
    $bounceTable = (new \GetQuick\EmailLogger\Database\BounceRepository())->getTableName();
    $tables = array_values(array_unique([$logTable, $bounceTable]));

    foreach ($tables as $table) {
        $sanitizedTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        if (! is_string($sanitizedTable) || $sanitizedTable === '') {
            continue;
        }

        $wpdb->query("DROP TABLE IF EXISTS `{$sanitizedTable}`");
    }

    delete_option('getquick_email_logger_discord_settings');
    delete_option('getquick_email_logger_blocked_domains');

    $schemaOption = defined('GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION')
        ? (string) constant('GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION')
        : 'getquick_email_logger_schema_version';
    delete_option($schemaOption);

    $schemaLockKey = defined('GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY')
        ? (string) constant('GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY')
        : 'getquick_email_logger_schema_lock';
    delete_transient($schemaLockKey);
    if (is_multisite()) {
        delete_site_transient($schemaLockKey);
    }

    $cleanupHook = defined('GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK')
        ? (string) constant('GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK')
        : 'getquick_email_logger_cleanup_event';
    wp_clear_scheduled_hook($cleanupHook);

    if (function_exists('as_unschedule_all_actions')) {
        $asyncAction = defined('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION')
            ? (string) constant('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION')
            : 'getquick_email_logger_write_event';
        $asyncGroup = defined('GETQUICK_EMAIL_LOGGER_ASYNC_GROUP')
            ? (string) constant('GETQUICK_EMAIL_LOGGER_ASYNC_GROUP')
            : 'getquick-email-logger';

        call_user_func('as_unschedule_all_actions', $asyncAction, [], $asyncGroup);
    }
}

/**
 * Remove plugin data from either the current site or all sites in a network deactivation.
 */
function getquick_email_logger_cleanup_all_sites(bool $networkWide = false): void
{
    if (! is_multisite() || ! $networkWide) {
        getquick_email_logger_cleanup_current_site();
        return;
    }

    $siteIds = get_sites([
        'fields' => 'ids',
    ]);

    if (! is_array($siteIds)) {
        getquick_email_logger_cleanup_current_site();
        return;
    }

    foreach ($siteIds as $siteId) {
        switch_to_blog((int) $siteId);
        getquick_email_logger_cleanup_current_site();
        restore_current_blog();
    }
}

/**
 * On plugin deactivation, remove all plugin-created data.
 */
function getquick_email_logger_on_deactivate(bool $networkWide = false): void
{
    getquick_email_logger_cleanup_all_sites($networkWide);
}

/**
 * On plugin uninstall, remove all plugin-created data.
 */
function getquick_email_logger_on_uninstall(): void
{
    $networkWide = is_multisite();
    getquick_email_logger_cleanup_all_sites($networkWide);
}

register_deactivation_hook(__FILE__, 'getquick_email_logger_on_deactivate');
register_uninstall_hook(__FILE__, 'getquick_email_logger_on_uninstall');

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

if (! defined('WP_UNINSTALL_PLUGIN')) {
    getquick_email_logger_init();
}

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
