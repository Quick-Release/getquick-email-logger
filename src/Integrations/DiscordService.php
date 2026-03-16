<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Integrations;

class DiscordService
{
    private const OPTION_KEY = 'getquick_email_logger_discord_settings';

    public function registerHooks(): void
    {
        add_action('getquick_email_logger_inserted', [$this, 'handleLog'], 10, 2);
    }

    public function handleLog(int $insertId, array $event): void
    {
        unset($insertId);

        if (! $this->isEnabled()) {
            return;
        }

        $status = (string) ($event['status'] ?? 'failed');

        if ($status === 'sent' && ! $this->shouldNotifySent()) {
            return;
        }

        if ($status !== 'sent' && ! $this->shouldNotifyFailed()) {
            return;
        }

        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === '') {
            return;
        }

        $payload = [
            'content' => $this->buildMessage($event),
            'allowed_mentions' => ['parse' => []],
        ];

        $username = $this->getUsername();
        if ($username !== '') {
            $payload['username'] = $username;
        }

        $timeout = $this->getTimeout();

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

    private function buildMessage(array $event): string
    {
        $status = (string) ($event['status'] ?? 'failed');
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST) ?? home_url();

        $lines = [
            sprintf('Site: %s', $siteHost),
            sprintf('Status: %s', $status === 'sent' ? 'SENT' : 'FAILED'),
            sprintf('Time (UTC): %s', (string) ($event['created_at_utc'] ?? gmdate('Y-m-d H:i:s'))),
        ];

        if ($this->shouldIncludeSubject()) {
            $subject = wp_strip_all_tags((string) ($event['subject'] ?? '(no subject)'));
            $lines[] = sprintf('Subject: %s', $subject === '' ? '(no subject)' : $subject);
        }

        if ($this->shouldIncludeRecipients()) {
            $to = is_array($event['to'] ?? null) ? $event['to'] : [];
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

        return $this->truncate($message, 1900);
    }

    private function truncate(string $message, int $maxLength): string
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

    public function getSettings(): array
    {
        $defaults = [
            'enabled' => false,
            'webhook_url' => '',
            'username' => '',
            'notify_sent' => true,
            'notify_failed' => true,
            'include_subject' => true,
            'include_recipients' => true,
            'timeout_seconds' => 5,
        ];

        $stored = get_option(self::OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];

        return wp_parse_args($stored, $defaults);
    }

    private function isEnabled(): bool
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_ENABLED')) {
            return GETQUICK_EMAIL_LOGGER_DISCORD_ENABLED === true;
        }

        $settings = $this->getSettings();
        return (bool) ($settings['enabled'] ?? false);
    }

    private function getWebhookUrl(): string
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL') && is_string(GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL) && GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL !== '') {
            return GETQUICK_EMAIL_LOGGER_DISCORD_WEBHOOK_URL;
        }

        $settings = $this->getSettings();
        return (string) ($settings['webhook_url'] ?? '');
    }

    private function getUsername(): string
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME') && is_string(GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME) && GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME !== '') {
            return trim(GETQUICK_EMAIL_LOGGER_DISCORD_USERNAME);
        }

        $settings = $this->getSettings();
        return (string) ($settings['username'] ?? '');
    }

    private function shouldNotifySent(): bool
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_SENT')) {
            return GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_SENT === true;
        }

        $settings = $this->getSettings();
        return (bool) ($settings['notify_sent'] ?? true);
    }

    private function shouldNotifyFailed(): bool
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_FAILED')) {
            return GETQUICK_EMAIL_LOGGER_DISCORD_NOTIFY_FAILED === true;
        }

        $settings = $this->getSettings();
        return (bool) ($settings['notify_failed'] ?? true);
    }

    private function shouldIncludeSubject(): bool
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_SUBJECT')) {
            return GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_SUBJECT === true;
        }

        $settings = $this->getSettings();
        return (bool) ($settings['include_subject'] ?? true);
    }

    private function shouldIncludeRecipients(): bool
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_RECIPIENTS')) {
            return GETQUICK_EMAIL_LOGGER_DISCORD_INCLUDE_RECIPIENTS === true;
        }

        $settings = $this->getSettings();
        return (bool) ($settings['include_recipients'] ?? true);
    }

    private function getTimeout(): int
    {
        if (defined('GETQUICK_EMAIL_LOGGER_DISCORD_TIMEOUT_SECONDS')) {
            return (int) GETQUICK_EMAIL_LOGGER_DISCORD_TIMEOUT_SECONDS;
        }

        $settings = $this->getSettings();
        return max(1, min(15, (int) ($settings['timeout_seconds'] ?? 5)));
    }
}
