<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Admin;

use GetQuick\EmailLogger\Database\LogRepository;
use GetQuick\EmailLogger\Utils\View;

class AdminManager
{
    private LogRepository $repository;

    public function __construct(LogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function registerHooks(): void
    {
        if (! is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_getquick_email_logger_send_test_email', [$this, 'handleTestEmail']);
        add_action('admin_post_getquick_email_logger_reset_logs', [$this, 'handleResetLogs']);
        add_action('admin_post_getquick_email_logger_seed_logs', [$this, 'handleSeedLogs']);
    }

    public function addMenuPages(): void
    {
        add_options_page(
            'GetQuick Email Logger',
            'Email Logger',
            'manage_options',
            'getquick-email-logger',
            [$this, 'renderAdminPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            'getquick_email_logger_discord',
            'getquick_email_logger_discord_settings',
            [
                'sanitize_callback' => 'getquick_email_logger_discord_sanitize_settings',
                'default' => [],
            ]
        );

        add_settings_section(
            'getquick_email_logger_discord_main',
            'Discord Logging Settings',
            static function (): void {
                echo '<p>Control when email logs are sent to Discord and configure the webhook.</p>';
            },
            'getquick-email-logger'
        );

        $fields = [
            'enabled' => ['label' => 'Enable Discord logs', 'type' => 'checkbox', 'text' => 'Send email logs to Discord'],
            'webhook_url' => ['label' => 'Webhook URL', 'type' => 'text', 'placeholder' => 'https://discord.com/api/webhooks/...'],
            'username' => ['label' => 'Bot username', 'type' => 'text', 'placeholder' => 'GetQuick Mail Bot'],
            'notify_sent' => ['label' => 'Notify for sent emails', 'type' => 'checkbox', 'text' => 'Post successful sends'],
            'notify_failed' => ['label' => 'Notify for failed emails', 'type' => 'checkbox', 'text' => 'Post failures'],
            'include_subject' => ['label' => 'Include subject', 'type' => 'checkbox', 'text' => 'Include email subject in messages'],
            'include_recipients' => ['label' => 'Include recipients', 'type' => 'checkbox', 'text' => 'Include recipients in messages'],
            'timeout_seconds' => ['label' => 'Request timeout (seconds)', 'type' => 'number', 'min' => 1, 'max' => 15],
        ];

        foreach ($fields as $id => $args) {
            add_settings_field(
                $id,
                $args['label'],
                [$this, 'renderSettingField'],
                'getquick-email-logger',
                'getquick_email_logger_discord_main',
                array_merge(['id' => $id], $args)
            );
        }
    }

    public function renderSettingField(array $args): void
    {
        $settings = get_option('getquick_email_logger_discord_settings', []);
        $id = $args['id'];
        $value = $settings[$id] ?? '';

        switch ($args['type']) {
            case 'checkbox':
                printf(
                    '<label><input type="checkbox" name="getquick_email_logger_discord_settings[%1$s]" value="1" %2$s> %3$s</label>',
                    esc_attr($id),
                    checked($value, '1', false),
                    esc_html($args['text'] ?? '')
                );
                break;
            case 'number':
                printf(
                    '<input type="number" name="getquick_email_logger_discord_settings[%1$s]" value="%2$s" min="%3$d" max="%4$d" step="1">',
                    esc_attr($id),
                    esc_attr((string) $value),
                    $args['min'] ?? 1,
                    $args['max'] ?? 100
                );
                break;
            case 'text':
            default:
                printf(
                    '<input type="text" class="regular-text" name="getquick_email_logger_discord_settings[%1$s]" value="%2$s" placeholder="%3$s">',
                    esc_attr($id),
                    esc_attr((string) $value),
                    esc_attr($args['placeholder'] ?? '')
                );
                break;
        }
    }

    public function renderAdminPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $currentTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'logs';
        if (! in_array($currentTab, ['logs', 'settings', 'test-email'], true)) {
            $currentTab = 'logs';
        }

        $tabArgs = [];
        if ($currentTab === 'logs') {
            $currentPage = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
            $perPage = 25;
            $tabArgs = [
                'logs' => $this->repository->fetchLogs($currentPage, $perPage),
                'currentPage' => $currentPage,
                'totalPages' => max(1, (int) ceil($this->repository->countLogs() / $perPage)),
            ];
        }

        View::render('admin/main', [
            'title' => 'GetQuick Email Logger',
            'currentTab' => $currentTab,
            'tabs' => [
                'logs' => 'Logs',
                'test-email' => 'Test email',
                'settings' => 'Settings',
            ],
            'notices' => [
                'resent' => ['updated notice', 'Email resent successfully.'],
                'resend_failed' => ['notice notice-error', 'Email resend failed. Check the mail configuration and try again.'],
                'resend_unavailable' => ['notice notice-warning', 'This log entry does not have enough stored payload to resend.'],
                'log_not_found' => ['notice notice-error', 'The requested log entry was not found.'],
                'invalid_test_email' => ['notice notice-error', 'Please enter a valid email address for the test email.'],
                'test_email_sent' => ['updated notice', 'Test email sent successfully.'],
                'test_email_failed' => ['notice notice-error', 'Test email failed to send. Check the mail configuration and try again.'],
                'logs_reset' => ['updated notice', 'Email logs table has been cleared.'],
                'logs_seeded' => ['updated notice', '50 dummy email logs have been added to the database.'],
            ],
            'tabArgs' => $tabArgs,
        ]);
    }

    public function handleTestEmail(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('getquick_email_logger_send_test_email');

        $redirectUrl = add_query_arg(
            [
                'page' => 'getquick-email-logger',
                'tab' => 'test-email',
            ],
            admin_url('options-general.php')
        );

        $recipient = isset($_POST['to']) ? sanitize_email(wp_unslash((string) $_POST['to'])) : '';
        if ($recipient === '' || ! is_email($recipient)) {
            wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'invalid_test_email', $redirectUrl));
            exit;
        }

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash((string) $_POST['subject'])) : self::getTestEmailDefaultSubject();
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['message'])) : self::getTestEmailDefaultMessage();

        $result = wp_mail($recipient, $subject, $message);

        wp_safe_redirect(add_query_arg('getquick_email_logger_notice', $result ? 'test_email_sent' : 'test_email_failed', $redirectUrl));
        exit;
    }

    public function handleResetLogs(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('getquick_email_logger_reset_logs');

        $this->repository->truncate();

        $redirectUrl = add_query_arg(
            [
                'page' => 'getquick-email-logger',
                'tab' => 'settings',
                'getquick_email_logger_notice' => 'logs_reset',
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function handleSeedLogs(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('getquick_email_logger_seed_logs');

        $domains = ['example.com', 'test.com', 'getquick.io', 'wp.org'];
        $subjects = [
            'Order confirmation #',
            'Welcome to our newsletter',
            'Password reset request',
            'Account verification',
            'Daily digest for ',
            'System alert: ',
        ];
        $statuses = ['sent', 'failed'];
        $providers = ['ses', 'wp_mail'];

        for ($i = 1; $i <= 50; $i++) {
            $status = $statuses[array_rand($statuses)];
            $provider = $providers[array_rand($providers)];
            $email = 'user' . $i . '@' . $domains[array_rand($domains)];
            $subject = $subjects[array_rand($subjects)] . ($i + 100);
            
            $event = [
                'created_at_utc' => gmdate('Y-m-d H:i:s', time() - (rand(0, 86400 * 30))),
                'status' => $status,
                'provider' => $provider,
                'provider_message_id' => $provider === 'ses' ? 'ses-' . wp_generate_password(16, false) : '',
                'subject' => $subject,
                'to' => [$email],
                'from_email' => 'noreply@getquick.io',
                'reply_to' => '',
                'cc' => [],
                'bcc' => [],
                'headers' => ['From' => 'noreply@getquick.io'],
                'body_text' => "This is a dummy email body for testing. Seed #$i",
                'body_html' => "<p>This is a dummy <strong>HTML</strong> email body for testing. Seed #$i</p>",
                'error_code' => $status === 'failed' ? 'connection_timeout' : '',
                'error_message' => $status === 'failed' ? 'Failed to connect to the mail server.' : '',
                'client_ref' => 'seed-' . $i,
                'context' => ['is_seed' => true],
            ];

            $this->repository->insert($event);
        }

        $redirectUrl = add_query_arg(
            [
                'page' => 'getquick-email-logger',
                'tab' => 'settings',
                'getquick_email_logger_notice' => 'logs_seeded',
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    public static function getTestEmailDefaultSubject(): string
    {
        return sprintf('Test email from %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
    }

    public static function getTestEmailDefaultMessage(): string
    {
        return sprintf(
            "This is a test email sent from %s.\n\nSent at: %s UTC\nEnvironment check: if you received this email, the current wp_mail() setup is working.",
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            gmdate('Y-m-d H:i:s')
        );
    }
}
