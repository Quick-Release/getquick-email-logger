<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Database;

class BounceRepository
{
    public function getTableName(): string
    {
        global $wpdb;

        $configuredName = defined('GETQUICK_EMAIL_LOGGER_TABLE_NAME')
            ? constant('GETQUICK_EMAIL_LOGGER_TABLE_NAME')
            : 'getquick_email_logs';
        $name = is_string($configuredName) ? $configuredName : 'getquick_email_logs';
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);

        if (! is_string($name) || $name === '') {
            $name = 'getquick_email_logs';
        }

        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : '';
        if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
            $name = $prefix . ltrim($name, '_');
        }

        $name = str_replace('_logs', '_bounces', $name);

        return $name !== '' ? $name : $prefix . 'getquick_email_bounces';
    }

    public function add(string $email, string $type = 'hard', string $reason = ''): bool
    {
        global $wpdb;

        $email = strtolower(trim($email));
        if (! is_email($email)) {
            return false;
        }

        $table = $this->getTableName();

        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email));
        if ($exists) {
            return true;
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'email' => $email,
                'bounce_type' => $type,
                'reason' => sanitize_text_field($reason),
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    public function isSuppressed(string $email): bool
    {
        global $wpdb;

        $email = strtolower(trim($email));
        if (! is_email($email)) {
            return false;
        }

        $table = $this->getTableName();
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email));

        return ! empty($id);
    }

    public function remove(string $email): bool
    {
        global $wpdb;

        $table = $this->getTableName();
        return (bool) $wpdb->delete($table, ['email' => strtolower(trim($email))], ['%s']);
    }
}
