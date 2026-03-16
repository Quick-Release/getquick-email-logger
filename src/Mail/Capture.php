<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Mail;

use GetQuick\EmailLogger\Utils\Normalizer;
use WP_Error;

class Capture
{
    public function registerHooks(): void
    {
        add_action('aws_ses_wp_mail_ses_sent_message', [$this, 'captureSesSent'], 20, 3);
        add_action('aws_ses_wp_mail_ses_error_sending_message', [$this, 'captureSesFailed'], 20, 3);

        add_action('wp_mail_succeeded', [$this, 'captureNonSesSent'], 20, 1);
        add_action('wp_mail_failed', [$this, 'captureNonSesFailed'], 20, 2);
    }

    public function captureSesSent(mixed $result, array $args, array $messageArgs): void
    {
        unset($args);

        $providerMessageId = '';
        if (is_object($result) && method_exists($result, 'get')) {
            $providerMessageId = (string) $result->get('MessageId');
        } elseif (is_array($result) && isset($result['MessageId'])) {
            $providerMessageId = (string) $result['MessageId'];
        }

        $event = $this->buildEvent('sent', $messageArgs, [
            'provider' => 'ses',
            'provider_message_id' => $providerMessageId,
            'context' => ['hook' => 'aws_ses_wp_mail_ses_sent_message'],
        ]);

        $this->enqueueWrite($event);
    }

    public function captureSesFailed(mixed $exception, array $args, array $messageArgs): void
    {
        unset($args);

        $errorMessage = 'Unknown SES error';
        $errorCode = 'ses_error';
        if (is_object($exception) && method_exists($exception, 'getMessage')) {
            $errorMessage = (string) $exception->getMessage();
            $errorCode = $exception::class;
        }

        $event = $this->buildEvent('failed', $messageArgs, [
            'provider' => 'ses',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'context' => ['hook' => 'aws_ses_wp_mail_ses_error_sending_message'],
        ]);

        $this->enqueueWrite($event);

        // Auto-suppress if the error indicates a hard bounce or blacklist
        if (stripos($errorMessage, 'Address is blacklisted') !== false || stripos($errorMessage, 'Hard bounce') !== false) {
            $bounceRepo = new \GetQuick\EmailLogger\Database\BounceRepository();
            $recipients = Normalizer::emailList($messageArgs['to'] ?? []);
            foreach ($recipients as $email) {
                $bounceRepo->add($email, 'hard', $errorMessage);
            }
        }
    }

    public function captureNonSesSent(array $mailData): void
    {
        if ($this->isSesMailerActive()) {
            return;
        }

        $event = $this->buildEvent('sent', $mailData, [
            'provider' => 'wp_mail',
            'context' => ['hook' => 'wp_mail_succeeded'],
        ]);

        $this->enqueueWrite($event);
    }

    public function captureNonSesFailed(WP_Error $error, array $mailData = []): void
    {
        if ($this->isSesMailerActive()) {
            return;
        }

        if ($mailData === []) {
            $errorData = $error->get_error_data();
            if (is_array($errorData)) {
                $mailData = $errorData;
            }
        }

        $event = $this->buildEvent('failed', $mailData, [
            'provider' => 'wp_mail',
            'error_code' => (string) $error->get_error_code(),
            'error_message' => $error->get_error_message(),
            'context' => ['hook' => 'wp_mail_failed'],
        ]);

        $this->enqueueWrite($event);
    }

    private function isSesMailerActive(): bool
    {
        return defined('AWS_SES_WP_MAIL_REGION') || defined('AWS_SES_WP_MAIL_USE_INSTANCE_PROFILE');
    }

    private function buildEvent(string $status, array $mailData, array $overrides = []): array
    {
        $headers = Normalizer::headers($mailData['headers'] ?? []);

        $to = Normalizer::emailList($mailData['to'] ?? []);
        $cc = Normalizer::emailList($headers['Cc'] ?? []);
        $bcc = Normalizer::emailList($headers['Bcc'] ?? []);
        $replyToList = Normalizer::emailList($headers['Reply-To'] ?? []);
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

        $subject = Normalizer::subject((string) ($mailData['subject'] ?? ''));

        $event = [
            'created_at_utc' => gmdate('Y-m-d H:i:s'),
            'status' => $status,
            'provider' => (string) ($overrides['provider'] ?? 'ses'),
            'provider_message_id' => (string) ($overrides['provider_message_id'] ?? ''),
            'subject' => $subject,
            'to' => $to,
            'from_email' => Normalizer::extractEmail((string) ($headers['From'] ?? '')),
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

    private function enqueueWrite(array $event): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, [$event], GETQUICK_EMAIL_LOGGER_ASYNC_GROUP);
            return;
        }

        do_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, $event);
    }
}
