<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Mail;

use GetQuick\EmailLogger\Database\BounceRepository;
use GetQuick\EmailLogger\Utils\Normalizer;

class Suppression
{
    private BounceRepository $bounceRepository;

    public function __construct(BounceRepository $bounceRepository)
    {
        $this->bounceRepository = $bounceRepository;
    }

    public function registerHooks(): void
    {
        // pre_wp_mail allows us to short-circuit wp_mail()
        add_filter('pre_wp_mail', [$this, 'filterMailBeforeSend'], 10, 2);
    }

    /**
     * @param null|bool $return Returning anything but null short-circuits wp_mail()
     * @param array $atts {
     *     @type string|string[] $to
     *     @type string          $subject
     *     @type string          $message
     *     @type string|string[] $headers
     *     @type string|string[] $attachments
     * }
     */
    public function filterMailBeforeSend(?bool $return, array $atts): ?bool
    {
        if ($return !== null) {
            return $return;
        }

        $headers = Normalizer::headers($atts['headers'] ?? []);
        $recipients = array_values(array_unique(array_merge(
            Normalizer::emailList($atts['to'] ?? []),
            Normalizer::emailList($headers['Cc'] ?? []),
            Normalizer::emailList($headers['Bcc'] ?? []),
        )));

        if ($recipients === []) {
            return null;
        }

        $blockedDomains = $this->getBlockedDomains();

        $suppressed = [];
        $reason = 'recipient_suppressed';

        foreach ($recipients as $email) {
            if ($this->bounceRepository->isSuppressed($email)) {
                $suppressed[] = $email;
                continue;
            }

            $domain = strrchr($email, '@');
            $domain = is_string($domain) ? strtolower(ltrim($domain, '@')) : '';
            if ($domain !== '' && in_array($domain, $blockedDomains, true)) {
                $suppressed[] = $email;
                $reason = 'domain_blocked';
            }
        }

        if ($suppressed === []) {
            return null;
        }

        $this->logSuppression($atts, $suppressed, $reason);

        return false;
    }

    private function getBlockedDomains(): array
    {
        $raw = get_option('getquick_email_logger_blocked_domains', '');
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode("\n", $raw),
        ))));
    }

    private function logSuppression(array $atts, array $suppressed, string $reason): void
    {
        $errorMessage = 'Email was not sent because the recipient is in the suppression list.';
        if ($reason === 'domain_blocked') {
            $errorMessage = 'Email was not sent because the recipient domain is blacklisted.';
        }

        $event = [
            'created_at_utc' => gmdate('Y-m-d H:i:s'),
            'status' => 'suppressed',
            'provider' => 'local',
            'subject' => Normalizer::subject((string) ($atts['subject'] ?? '')),
            'to' => $suppressed,
            'error_code' => $reason,
            'error_message' => $errorMessage,
            'context' => ['suppressed_emails' => $suppressed],
        ];

        $asyncAction = defined('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION')
            ? (string) constant('GETQUICK_EMAIL_LOGGER_ASYNC_ACTION')
            : 'getquick_email_logger_write_event';

        do_action($asyncAction, $event);
    }
}
