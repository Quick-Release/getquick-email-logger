<?php

declare(strict_types=1);

/*
Plugin Name: getquick-email-logger
Description: Persists email delivery logs in a dedicated table and exposes sent logs via WPGraphQL.
*/

if (! defined('GETQUICK_EMAIL_LOGGER_ENABLED')) {
    define('GETQUICK_EMAIL_LOGGER_ENABLED', true);
}

if (! defined('GETQUICK_EMAIL_LOGGER_TABLE_NAME')) {
    define('GETQUICK_EMAIL_LOGGER_TABLE_NAME', 'getquick_email_logs');
}

if (! defined('GETQUICK_EMAIL_LOGGER_RETENTION_DAYS')) {
    define('GETQUICK_EMAIL_LOGGER_RETENTION_DAYS', 90);
}

if (! defined('GETQUICK_EMAIL_LOGGER_CLEANUP_BATCH_SIZE')) {
    define('GETQUICK_EMAIL_LOGGER_CLEANUP_BATCH_SIZE', 1000);
}

if (! defined('GETQUICK_EMAIL_LOGGER_CLEANUP_MAX_BATCHES')) {
    define('GETQUICK_EMAIL_LOGGER_CLEANUP_MAX_BATCHES', 3);
}

if (! defined('GETQUICK_EMAIL_LOGGER_SENT_WINDOW_DAYS')) {
    define('GETQUICK_EMAIL_LOGGER_SENT_WINDOW_DAYS', 30);
}

if (! defined('GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED')) {
    define('GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED', true);
}

if (! defined('GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE')) {
    define('GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE', 100);
}

if (! defined('GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION')) {
    define('GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION', '1.1.0');
}

if (! defined('GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION')) {
    define('GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION', 'getquick_email_logger_schema_version');
}

if (! defined('GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY')) {
    define('GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY', 'getquick_email_logger_schema_lock');
}

if (! defined('GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK')) {
    define('GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK', 'getquick_email_logger_cleanup_event');
}

if (! defined('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION')) {
    define('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION', 'getquick_email_logger_write_event');
}

if (! defined('GETQUICK_EMAIL_LOGGER_ASYNC_GROUP')) {
    define('GETQUICK_EMAIL_LOGGER_ASYNC_GROUP', 'getquick-email-logger');
}

if (GETQUICK_EMAIL_LOGGER_ENABLED !== true) {
    return;
}

require_once __DIR__ . '/inc/discord-notifier.php';
require_once __DIR__ . '/inc/mail-resender.php';
require_once __DIR__ . '/admin/admin-page.php';
require_once __DIR__ . '/admin/logs-admin.php';
require_once __DIR__ . '/admin/discord-settings.php';
require_once __DIR__ . '/admin/test-email.php';

add_action('init', 'getquick_email_logger_bootstrap', 1);
add_action(GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK, 'getquick_email_logger_run_cleanup');
add_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, 'getquick_email_logger_process_async_event', 10, 1);

add_action('aws_ses_wp_mail_ses_sent_message', 'getquick_email_logger_capture_ses_sent', 20, 3);
add_action('aws_ses_wp_mail_ses_error_sending_message', 'getquick_email_logger_capture_ses_failed', 20, 3);

add_action('wp_mail_succeeded', 'getquick_email_logger_capture_non_ses_sent', 20, 1);
add_action('wp_mail_failed', 'getquick_email_logger_capture_non_ses_failed', 20, 2);

if (GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED === true) {
    add_action('graphql_register_types', 'getquick_email_logger_register_graphql_schema');
}

function getquick_email_logger_bootstrap(): void
{
    getquick_email_logger_maybe_install_schema();
    getquick_email_logger_schedule_cleanup();
}

function getquick_email_logger_capture_ses_sent(mixed $result, array $args, array $messageArgs): void
{
    unset($args);

    $providerMessageId = '';
    if (is_object($result) && method_exists($result, 'get')) {
        $providerMessageId = (string) $result->get('MessageId');
    } elseif (is_array($result) && isset($result['MessageId'])) {
        $providerMessageId = (string) $result['MessageId'];
    }

    $event = getquick_email_logger_build_event('sent', $messageArgs, [
        'provider' => 'ses',
        'provider_message_id' => $providerMessageId,
        'context' => ['hook' => 'aws_ses_wp_mail_ses_sent_message'],
    ]);

    getquick_email_logger_enqueue_write($event);
}

function getquick_email_logger_capture_ses_failed(mixed $exception, array $args, array $messageArgs): void
{
    unset($args);

    $errorMessage = 'Unknown SES error';
    if (is_object($exception) && method_exists($exception, 'getMessage')) {
        $errorMessage = (string) $exception->getMessage();
    }

    $event = getquick_email_logger_build_event('failed', $messageArgs, [
        'provider' => 'ses',
        'error_code' => is_object($exception) ? $exception::class : 'ses_error',
        'error_message' => $errorMessage,
        'context' => ['hook' => 'aws_ses_wp_mail_ses_error_sending_message'],
    ]);

    getquick_email_logger_enqueue_write($event);
}

function getquick_email_logger_capture_non_ses_sent(array $mailData): void
{
    if (getquick_email_logger_is_ses_mailer_active()) {
        return;
    }

    $event = getquick_email_logger_build_event('sent', $mailData, [
        'provider' => 'wp_mail',
        'context' => ['hook' => 'wp_mail_succeeded'],
    ]);

    getquick_email_logger_enqueue_write($event);
}

function getquick_email_logger_capture_non_ses_failed(WP_Error $error, array $mailData = []): void
{
    if (getquick_email_logger_is_ses_mailer_active()) {
        return;
    }

    if ($mailData === []) {
        $errorData = $error->get_error_data();
        if (is_array($errorData)) {
            $mailData = $errorData;
        }
    }

    $event = getquick_email_logger_build_event('failed', $mailData, [
        'provider' => 'wp_mail',
        'error_code' => (string) $error->get_error_code(),
        'error_message' => $error->get_error_message(),
        'context' => ['hook' => 'wp_mail_failed'],
    ]);

    getquick_email_logger_enqueue_write($event);
}

function getquick_email_logger_is_ses_mailer_active(): bool
{
    return defined('AWS_SES_WP_MAIL_REGION') || defined('AWS_SES_WP_MAIL_USE_INSTANCE_PROFILE');
}

function getquick_email_logger_build_event(string $status, array $mailData, array $overrides = []): array
{
    $headers = getquick_email_logger_normalize_headers($mailData['headers'] ?? []);

    $to = getquick_email_logger_normalize_email_list($mailData['to'] ?? []);
    $cc = getquick_email_logger_normalize_email_list($headers['Cc'] ?? []);
    $bcc = getquick_email_logger_normalize_email_list($headers['Bcc'] ?? []);
    $replyToList = getquick_email_logger_normalize_email_list($headers['Reply-To'] ?? []);
    $contentType = (string) ($headers['Content-Type'] ?? 'text/plain');

    $bodyText = '';
    $bodyHtml = '';

    if (isset($mailData['text'])) {
        $bodyText = (string) $mailData['text'];
    }

    if (isset($mailData['html'])) {
        $bodyHtml = (string) $mailData['html'];
    }

    if ($bodyText === '' && $bodyHtml === '' && isset($mailData['message'])) {
        if (stripos($contentType, 'text/html') !== false) {
            $bodyHtml = (string) $mailData['message'];
        } else {
            $bodyText = (string) $mailData['message'];
        }
    }

    $subject = sanitize_text_field((string) ($mailData['subject'] ?? '(no subject)'));
    if (function_exists('mb_substr')) {
        $subject = mb_substr($subject, 0, 255);
    } else {
        $subject = substr($subject, 0, 255);
    }

    $event = [
        'created_at_utc' => gmdate('Y-m-d H:i:s'),
        'status' => $status,
        'provider' => (string) ($overrides['provider'] ?? 'ses'),
        'provider_message_id' => (string) ($overrides['provider_message_id'] ?? ''),
        'subject' => $subject,
        'to' => $to,
        'from_email' => getquick_email_logger_extract_email((string) ($headers['From'] ?? '')),
        'reply_to' => $replyToList[0] ?? '',
        'cc' => $cc,
        'bcc' => $bcc,
        'headers' => $headers,
        'body_text' => $bodyText,
        'body_html' => $bodyHtml,
        'error_code' => sanitize_text_field((string) ($overrides['error_code'] ?? '')),
        'error_message' => sanitize_textarea_field((string) ($overrides['error_message'] ?? '')),
        'client_ref' => sanitize_text_field((string) ($overrides['client_ref'] ?? ($mailData['client_ref'] ?? ''))),
        'context' => is_array($overrides['context'] ?? null) ? $overrides['context'] : [],
    ];

    return apply_filters('getquick_email_logger_event', $event, $status, $mailData, $overrides);
}

function getquick_email_logger_normalize_headers(string|array $headers): array
{
    if ($headers === '' || $headers === []) {
        return [];
    }

    $items = $headers;
    if (! is_array($items)) {
        $items = array_filter(explode("\n", str_replace("\r\n", "\n", (string) $items)));
    }

    $normalized = [];

    foreach ($items as $name => $value) {
        if (is_int($name)) {
            $parts = explode(':', (string) $value, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = $parts[0];
            $value = $parts[1];
        }

        $headerName = ucwords(strtolower(trim((string) $name)), '-');
        $headerValue = trim((string) $value);

        if ($headerName === '' || $headerValue === '') {
            continue;
        }

        $normalized[$headerName] = $headerValue;
    }

    return $normalized;
}

function getquick_email_logger_normalize_email_list(string|array $value): array
{
    $parts = is_array($value) ? $value : [$value];
    $emails = [];

    foreach ($parts as $part) {
        $candidates = explode(',', (string) $part);
        foreach ($candidates as $candidate) {
            $email = getquick_email_logger_extract_email($candidate);
            if ($email === '') {
                continue;
            }

            $emails[] = $email;
        }
    }

    $emails = array_values(array_unique($emails));
    return $emails;
}

function getquick_email_logger_extract_email(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
        $raw = $matches[1];
    }

    $email = sanitize_email($raw);
    return is_email($email) ? strtolower($email) : '';
}

function getquick_email_logger_enqueue_write(array $event): void
{
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, [$event], GETQUICK_EMAIL_LOGGER_ASYNC_GROUP);
        return;
    }

    getquick_email_logger_insert_row($event);
}

function getquick_email_logger_process_async_event(array $event): void
{
    getquick_email_logger_insert_row($event);
}

function getquick_email_logger_insert_row(array $event): bool
{
    global $wpdb;

    $table = getquick_email_logger_table_name();
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

function getquick_email_logger_maybe_install_schema(): void
{
    $currentVersion = (string) get_option(GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION, '');
    if ($currentVersion === GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION) {
        return;
    }

    if (get_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY)) {
        return;
    }

    set_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY, '1', MINUTE_IN_SECONDS);

    try {
        getquick_email_logger_install_schema();
        update_option(GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION, GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION, false);
    } finally {
        delete_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY);
    }
}

function getquick_email_logger_install_schema(): void
{
    global $wpdb;

    $table = getquick_email_logger_table_name();
    $charsetCollate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at_utc DATETIME NOT NULL,
        status VARCHAR(16) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        provider_message_id VARCHAR(191) NULL,
        subject VARCHAR(255) NOT NULL,
        to_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        to_list_json LONGTEXT NULL,
        from_email VARCHAR(191) NULL,
        reply_to VARCHAR(191) NULL,
        cc_list_json LONGTEXT NULL,
        bcc_list_json LONGTEXT NULL,
        headers_json LONGTEXT NULL,
        body_text LONGTEXT NULL,
        body_html LONGTEXT NULL,
        error_code VARCHAR(100) NULL,
        error_message TEXT NULL,
        client_ref VARCHAR(100) NULL,
        context_json LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY idx_created_at_utc (created_at_utc),
        KEY idx_status_created_at (status, created_at_utc),
        KEY idx_provider_message_id (provider_message_id),
        KEY idx_client_ref_created_at (client_ref, created_at_utc)
    ) {$charsetCollate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function getquick_email_logger_table_name(): string
{
    $name = is_string(GETQUICK_EMAIL_LOGGER_TABLE_NAME) ? GETQUICK_EMAIL_LOGGER_TABLE_NAME : 'getquick_email_logs';
    $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);

    if (! is_string($name) || $name === '') {
        return 'getquick_email_logs';
    }

    return $name;
}

function getquick_email_logger_schedule_cleanup(): void
{
    if (wp_next_scheduled(GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK) !== false) {
        return;
    }

    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK);
}

function getquick_email_logger_run_cleanup(): void
{
    global $wpdb;

    $table = getquick_email_logger_table_name();
    $retentionDays = max(1, (int) GETQUICK_EMAIL_LOGGER_RETENTION_DAYS);
    $batchSize = max(1, (int) GETQUICK_EMAIL_LOGGER_CLEANUP_BATCH_SIZE);
    $maxBatches = max(1, (int) GETQUICK_EMAIL_LOGGER_CLEANUP_MAX_BATCHES);
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));

    $deletedTotal = 0;

    for ($batch = 0; $batch < $maxBatches; $batch++) {
        $query = $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at_utc < %s ORDER BY created_at_utc ASC LIMIT %d",
            $cutoff,
            $batchSize,
        );

        if (! is_string($query)) {
            break;
        }

        $deleted = $wpdb->query($query);
        if ($deleted === false) {
            do_action('getquick_email_logger_cleanup_failed', $wpdb->last_error, $cutoff);
            break;
        }

        $deletedTotal += (int) $deleted;

        if ((int) $deleted < $batchSize) {
            break;
        }
    }

    do_action('getquick_email_logger_cleanup_completed', $deletedTotal, $cutoff);
}

function getquick_email_logger_register_graphql_schema(): void
{
    if (! function_exists('register_graphql_object_type') || ! function_exists('register_graphql_field')) {
        return;
    }

    register_graphql_object_type('GetQuickSentEmailLog', [
        'description' => 'Single sent email log row from custom email logs table.',
        'fields' => [
            'id' => ['type' => 'ID'],
            'databaseId' => ['type' => 'Int'],
            'createdAtUtc' => ['type' => 'String'],
            'providerMessageId' => ['type' => 'String'],
            'subject' => ['type' => 'String'],
            'to' => ['type' => ['list_of' => 'String']],
            'cursor' => ['type' => 'String'],
        ],
    ]);

    register_graphql_object_type('GetQuickSentEmailLogPageInfo', [
        'description' => 'Pagination metadata for sent email logs query.',
        'fields' => [
            'hasNextPage' => ['type' => 'Boolean'],
            'endCursor' => ['type' => 'String'],
        ],
    ]);

    register_graphql_object_type('GetQuickSentEmailLogConnection', [
        'description' => 'Cursor-based connection for sent email logs.',
        'fields' => [
            'nodes' => ['type' => ['list_of' => 'GetQuickSentEmailLog']],
            'pageInfo' => ['type' => 'GetQuickSentEmailLogPageInfo'],
        ],
    ]);

    register_graphql_field('RootQuery', 'sentEmailLogs', [
        'description' => 'Returns sent emails from the last month with cursor pagination.',
        'type' => 'GetQuickSentEmailLogConnection',
        'args' => [
            'first' => [
                'type' => 'Int',
                'description' => 'Number of rows to fetch. Max is controlled by GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE.',
            ],
            'after' => [
                'type' => 'String',
                'description' => 'Opaque cursor from the previous page endCursor.',
            ],
        ],
        'resolve' => static function (mixed $root, array $args): array {
            unset($root);

            getquick_email_logger_assert_graphql_access();

            $maxPageSize = max(1, (int) GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE);
            $first = isset($args['first']) ? (int) $args['first'] : 50;
            $first = max(1, min($first, $maxPageSize));

            $windowDays = max(1, (int) GETQUICK_EMAIL_LOGGER_SENT_WINDOW_DAYS);
            $windowStart = gmdate('Y-m-d H:i:s', time() - ($windowDays * DAY_IN_SECONDS));

            $cursor = null;
            if (isset($args['after']) && is_string($args['after']) && $args['after'] !== '') {
                $cursor = getquick_email_logger_decode_cursor($args['after']);
            }

            $rows = getquick_email_logger_query_sent_last_month($windowStart, $first + 1, $cursor);
            $hasNextPage = count($rows) > $first;

            if ($hasNextPage) {
                array_pop($rows);
            }

            $nodes = array_map(static function (array $row): array {
                $to = [];
                if (isset($row['to_list_json']) && is_string($row['to_list_json']) && $row['to_list_json'] !== '') {
                    $decoded = json_decode($row['to_list_json'], true);
                    if (is_array($decoded)) {
                        $to = array_values(array_filter(array_map('strval', $decoded)));
                    }
                }

                $createdAt = (string) ($row['created_at_utc'] ?? '');
                $databaseId = (int) ($row['id'] ?? 0);

                return [
                    'id' => (string) $databaseId,
                    'databaseId' => $databaseId,
                    'createdAtUtc' => $createdAt,
                    'providerMessageId' => (string) ($row['provider_message_id'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'to' => $to,
                    'cursor' => getquick_email_logger_encode_cursor($createdAt, $databaseId),
                ];
            }, $rows);

            $endCursor = null;
            if ($nodes !== []) {
                $lastNode = $nodes[count($nodes) - 1];
                $endCursor = (string) ($lastNode['cursor'] ?? '');
            }

            return [
                'nodes' => $nodes,
                'pageInfo' => [
                    'hasNextPage' => $hasNextPage,
                    'endCursor' => $endCursor,
                ],
            ];
        },
    ]);
}

function getquick_email_logger_assert_graphql_access(): void
{
    if (current_user_can('manage_options')) {
        return;
    }

    if (class_exists('GraphQL\\Error\\UserError')) {
        throw new GraphQL\Error\UserError('Not authorized to query sentEmailLogs.');
    }

    throw new RuntimeException('Not authorized to query sentEmailLogs.');
}

function getquick_email_logger_query_sent_last_month(string $windowStart, int $limit, ?array $cursor): array
{
    global $wpdb;

    $table = getquick_email_logger_table_name();
    $conditions = ['status = %s', 'created_at_utc >= %s'];
    $values = ['sent', $windowStart];

    if (is_array($cursor)) {
        $conditions[] = '(created_at_utc < %s OR (created_at_utc = %s AND id < %d))';
        $values[] = (string) ($cursor['created_at_utc'] ?? '');
        $values[] = (string) ($cursor['created_at_utc'] ?? '');
        $values[] = (int) ($cursor['id'] ?? 0);
    }

    $values[] = $limit;

    $sql = sprintf(
        'SELECT id, created_at_utc, provider_message_id, subject, to_list_json
        FROM %s
        WHERE %s
        ORDER BY created_at_utc DESC, id DESC
        LIMIT %%d',
        $table,
        implode(' AND ', $conditions),
    );

    $prepared = $wpdb->prepare($sql, $values);
    if (! is_string($prepared)) {
        return [];
    }

    $rows = $wpdb->get_results($prepared, ARRAY_A);
    return is_array($rows) ? $rows : [];
}

function getquick_email_logger_encode_cursor(string $createdAtUtc, int $id): string
{
    return base64_encode($createdAtUtc . '|' . $id);
}

function getquick_email_logger_decode_cursor(string $cursor): ?array
{
    $decoded = base64_decode($cursor, true);
    if (! is_string($decoded) || $decoded === '') {
        return null;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 2) {
        return null;
    }

    if (! ctype_digit($parts[1])) {
        return null;
    }

    return [
        'created_at_utc' => $parts[0],
        'id' => (int) $parts[1],
    ];
}
