<?php

declare(strict_types=1);

if (is_admin()) {
    add_action('admin_post_getquick_email_logger_resend_email', 'getquick_email_logger_handle_resend_email');
}

function getquick_email_logger_can_resend_log(array $log): bool
{
    $recipients = getquick_email_logger_decode_email_list((string) ($log['to_list_json'] ?? ''));

    if ($recipients === []) {
        return false;
    }

    return ((string) ($log['body_text'] ?? '') !== '') || ((string) ($log['body_html'] ?? '') !== '');
}

function getquick_email_logger_handle_resend_email(): void
{
    if (! current_user_can('manage_options')) {
        wp_die('Unauthorized', 403);
    }

    $logId = isset($_GET['log_id']) ? absint($_GET['log_id']) : 0;
    check_admin_referer('getquick_email_logger_resend_email_' . $logId);

    $redirectUrl = add_query_arg(
        [
            'page' => 'getquick-email-logger',
            'tab' => 'logs',
        ],
        admin_url('options-general.php'),
    );

    $log = getquick_email_logger_fetch_log($logId);
    if ($log === null) {
        wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'log_not_found', $redirectUrl));
        exit;
    }

    if (! getquick_email_logger_can_resend_log($log)) {
        wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'resend_unavailable', $redirectUrl));
        exit;
    }

    $mailArgs = getquick_email_logger_prepare_resend_mail_args($log);
    $result = wp_mail($mailArgs['to'], $mailArgs['subject'], $mailArgs['message'], $mailArgs['headers']);

    wp_safe_redirect(add_query_arg('getquick_email_logger_notice', $result ? 'resent' : 'resend_failed', $redirectUrl));
    exit;
}

function getquick_email_logger_fetch_log(int $logId): ?array
{
    global $wpdb;

    if ($logId <= 0) {
        return null;
    }

    $table = getquick_email_logger_table_name();
    $query = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $logId);

    if (! is_string($query)) {
        return null;
    }

    $row = $wpdb->get_row($query, ARRAY_A);

    return is_array($row) ? $row : null;
}

function getquick_email_logger_prepare_resend_mail_args(array $log): array
{
    $to = getquick_email_logger_decode_email_list((string) ($log['to_list_json'] ?? ''));
    $subject = (string) ($log['subject'] ?? '(no subject)');
    $message = (string) ($log['body_html'] ?? '');
    $headers = getquick_email_logger_decode_headers((string) ($log['headers_json'] ?? ''));

    if ($message === '') {
        $message = (string) ($log['body_text'] ?? '');
    }

    if ($headers === []) {
        $headers = getquick_email_logger_build_resend_headers_from_row($log);
    }

    if ((string) ($log['body_html'] ?? '') !== '' && ! getquick_email_logger_headers_contain($headers, 'Content-Type')) {
        $headers[] = 'Content-Type: text/html; charset=' . get_bloginfo('charset');
    }

    if ((string) ($log['body_html'] ?? '') === '' && ! getquick_email_logger_headers_contain($headers, 'Content-Type')) {
        $headers[] = 'Content-Type: text/plain; charset=' . get_bloginfo('charset');
    }

    return [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
    ];
}

function getquick_email_logger_decode_headers(string $json): array
{
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    $headers = [];

    foreach ($decoded as $name => $value) {
        $headerName = trim((string) $name);
        $headerValue = trim((string) $value);

        if ($headerName === '' || $headerValue === '') {
            continue;
        }

        $headers[] = $headerName . ': ' . $headerValue;
    }

    return $headers;
}

function getquick_email_logger_build_resend_headers_from_row(array $log): array
{
    $headers = [];

    $fromEmail = trim((string) ($log['from_email'] ?? ''));
    if ($fromEmail !== '') {
        $headers[] = 'From: ' . $fromEmail;
    }

    $replyTo = trim((string) ($log['reply_to'] ?? ''));
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $cc = getquick_email_logger_decode_email_list((string) ($log['cc_list_json'] ?? ''));
    if ($cc !== []) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }

    $bcc = getquick_email_logger_decode_email_list((string) ($log['bcc_list_json'] ?? ''));
    if ($bcc !== []) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
    }

    return $headers;
}

function getquick_email_logger_headers_contain(array $headers, string $headerName): bool
{
    $needle = strtolower($headerName . ':');

    foreach ($headers as $header) {
        if (str_starts_with(strtolower(trim((string) $header)), $needle)) {
            return true;
        }
    }

    return false;
}
