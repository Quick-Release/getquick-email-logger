<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Mail;

use GetQuick\EmailLogger\Database\LogRepository;

class Resender
{
    private LogRepository $repository;

    public function __construct(LogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function registerHooks(): void
    {
        if (is_admin()) {
            add_action('admin_post_getquick_email_logger_resend_email', [$this, 'handleResendAction']);
        }
    }

    public function handleResendAction(): void
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

        $log = $this->repository->fetchById($logId);
        if ($log === null) {
            wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'log_not_found', $redirectUrl));
            exit;
        }

        if (! $this->canResend($log)) {
            wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'resend_unavailable', $redirectUrl));
            exit;
        }

        $mailArgs = $this->prepareMailArgs($log);
        $result = wp_mail($mailArgs['to'], $mailArgs['subject'], $mailArgs['message'], $mailArgs['headers']);

        wp_safe_redirect(add_query_arg('getquick_email_logger_notice', $result ? 'resent' : 'resend_failed', $redirectUrl));
        exit;
    }

    public function canResend(array $log): bool
    {
        $recipients = $this->decodeEmailList((string) ($log['to_list_json'] ?? ''));

        if ($recipients === []) {
            return false;
        }

        return ((string) ($log['body_text'] ?? '') !== '') || ((string) ($log['body_html'] ?? '') !== '');
    }

    private function prepareMailArgs(array $log): array
    {
        $to = $this->decodeEmailList((string) ($log['to_list_json'] ?? ''));
        $subject = (string) ($log['subject'] ?? '(no subject)');
        $message = (string) ($log['body_html'] ?? '');
        $headers = $this->decodeHeaders((string) ($log['headers_json'] ?? ''));

        if ($message === '') {
            $message = (string) ($log['body_text'] ?? '');
        }

        if ($headers === []) {
            $headers = $this->buildHeadersFromRow($log);
        }

        if ((string) ($log['body_html'] ?? '') !== '' && ! $this->headersContain($headers, 'Content-Type')) {
            $headers[] = 'Content-Type: text/html; charset=' . get_bloginfo('charset');
        }

        if ((string) ($log['body_html'] ?? '') === '' && ! $this->headersContain($headers, 'Content-Type')) {
            $headers[] = 'Content-Type: text/plain; charset=' . get_bloginfo('charset');
        }

        return [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ];
    }

    private function decodeEmailList(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function decodeHeaders(string $json): array
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
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    private function buildHeadersFromRow(array $log): array
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

        $cc = $this->decodeEmailList((string) ($log['cc_list_json'] ?? ''));
        if ($cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }

        $bcc = $this->decodeEmailList((string) ($log['bcc_list_json'] ?? ''));
        if ($bcc !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $bcc);
        }

        return $headers;
    }

    private function headersContain(array $headers, string $headerName): bool
    {
        $needle = strtolower($headerName . ':');

        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim((string) $header)), $needle)) {
                return true;
            }
        }

        return false;
    }
}
