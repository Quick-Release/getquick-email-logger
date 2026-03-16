<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Database;

class LogRepository
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

        return $name;
    }

    public function insert(array $event): bool
    {
        global $wpdb;

        $table = $this->getTableName();
        $payloadFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $toList = is_array($event['to'] ?? null) ? $event['to'] : [];
        $ccList = is_array($event['cc'] ?? null) ? $event['cc'] : [];
        $bccList = is_array($event['bcc'] ?? null) ? $event['bcc'] : [];
        $headers = is_array($event['headers'] ?? null) ? $event['headers'] : [];
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        $data = [
            'created_at_utc' => (string) ($event['created_at_utc'] ?? gmdate('Y-m-d H:i:s')),
            'status' => (string) ($event['status'] ?? 'failed'),
            'provider' => (string) ($event['provider'] ?? 'ses'),
            'provider_message_id' => (string) ($event['provider_message_id'] ?? ''),
            'subject' => (string) ($event['subject'] ?? '(no subject)'),
            'to_count' => count($toList),
            'to_list_json' => wp_json_encode($toList, $payloadFlags),
            'from_email' => (string) ($event['from_email'] ?? ''),
            'reply_to' => (string) ($event['reply_to'] ?? ''),
            'cc_list_json' => wp_json_encode($ccList, $payloadFlags),
            'bcc_list_json' => wp_json_encode($bccList, $payloadFlags),
            'headers_json' => wp_json_encode($headers, $payloadFlags),
            'body_text' => (string) ($event['body_text'] ?? ''),
            'body_html' => (string) ($event['body_html'] ?? ''),
            'error_code' => (string) ($event['error_code'] ?? ''),
            'error_message' => (string) ($event['error_message'] ?? ''),
            'client_ref' => (string) ($event['client_ref'] ?? ''),
            'context_json' => wp_json_encode($context, $payloadFlags),
        ];

        $formats = [
            '%s', '%s', '%s', '%s', '%s',
            '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s',
        ];

        $inserted = $wpdb->insert($table, $data, $formats);
        if ($inserted === false) {
            do_action('getquick_email_logger_insert_failed', $wpdb->last_error, $event);
            return false;
        }

        do_action('getquick_email_logger_inserted', (int) $wpdb->insert_id, $event);
        return true;
    }

    public function deleteBefore(string $cutoff, int $limit): int
    {
        global $wpdb;

        $table = $this->getTableName();
        $query = $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at_utc < %s ORDER BY created_at_utc ASC LIMIT %d",
            $cutoff,
            $limit,
        );

        if (! is_string($query)) {
            return 0;
        }

        $deleted = $wpdb->query($query);
        return $deleted === false ? 0 : (int) $deleted;
    }

    public function fetchById(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $table = $this->getTableName();
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id);

        if (! is_string($query)) {
            return null;
        }

        $row = $wpdb->get_row($query, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function fetchLogs(int $page, int $perPage): array
    {
        global $wpdb;

        $offset = max(0, ($page - 1) * $perPage);
        $table = $this->getTableName();

        $query = $wpdb->prepare(
            "SELECT id, created_at_utc, status, subject, to_list_json, provider, provider_message_id, body_text, body_html
            FROM {$table}
            ORDER BY created_at_utc DESC, id DESC
            LIMIT %d OFFSET %d",
            $perPage,
            $offset,
        );

        if (! is_string($query)) {
            return [];
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function countLogs(): int
    {
        global $wpdb;

        $table = $this->getTableName();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return is_numeric($count) ? (int) $count : 0;
    }

    public function truncate(): bool
    {
        global $wpdb;

        $table = $this->getTableName();
        $result = $wpdb->query("TRUNCATE TABLE {$table}");

        return $result !== false;
    }
}
