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

        $recipients = Normalizer::emailList($atts['to'] ?? []);
        $suppressed = [];

        foreach ($recipients as $email) {
            if ($this->bounceRepository->isSuppressed($email)) {
                $suppressed[] = $email;
            }
        }

        if ($suppressed === []) {
            return null; // Continue sending
        }

        // If all recipients are suppressed, cancel the mail
        if (count($suppressed) === count($recipients)) {
            $this->logSuppression($atts, $suppressed);
            return false; // Cancel send
        }

        // Optional: If some are suppressed, we could modify $atts['to'] 
        // but pre_wp_mail doesn't allow modifying $atts for the subsequent flow,
        // it only allows short-circuiting.
        
        return null;
    }

    private function logSuppression(array $atts, array $suppressed): void
    {
        $event = [
            'created_at_utc' => gmdate('Y-m-d H:i:s'),
            'status' => 'suppressed',
            'provider' => 'local',
            'subject' => Normalizer::subject((string) ($atts['subject'] ?? '')),
            'to' => $suppressed,
            'error_code' => 'recipient_suppressed',
            'error_message' => 'Email was not sent because the recipient is in the suppression list.',
            'context' => ['suppressed_emails' => $suppressed],
        ];

        // Trigger the async write event
        do_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, $event);
    }
}
