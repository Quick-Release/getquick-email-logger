<?php

declare(strict_types=1);

if (is_admin()) {
    add_action('admin_menu', 'getquick_email_logger_register_options_page');
}

function getquick_email_logger_register_options_page(): void
{
    add_options_page(
        'GetQuick Email Logger',
        'GetQuick Email Logger',
        'manage_options',
        'getquick-email-logger',
        'getquick_email_logger_render_options_page',
    );
}

function getquick_email_logger_render_options_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $currentTab = getquick_email_logger_get_admin_tab();

    echo '<div class="wrap">';
    echo '<h1>GetQuick Email Logger</h1>';
    getquick_email_logger_render_admin_tabs($currentTab);
    getquick_email_logger_render_admin_notice();

    if ($currentTab === 'settings') {
        getquick_email_logger_render_settings_tab();
    } elseif ($currentTab === 'test-email') {
        getquick_email_logger_render_test_email_tab();
    } else {
        getquick_email_logger_render_logs_tab();
    }

    echo '</div>';
}

function getquick_email_logger_get_admin_tab(): string
{
    $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'logs';

    return in_array($tab, ['logs', 'settings', 'test-email'], true) ? $tab : 'logs';
}

function getquick_email_logger_render_admin_tabs(string $currentTab): void
{
    $tabs = [
        'logs' => 'Logs',
        'test-email' => 'Test email',
        'settings' => 'Settings',
    ];

    echo '<nav class="nav-tab-wrapper">';

    foreach ($tabs as $tab => $label) {
        $url = add_query_arg(
            [
                'page' => 'getquick-email-logger',
                'tab' => $tab,
            ],
            admin_url('options-general.php'),
        );

        $className = $currentTab === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';

        printf(
            '<a href="%1$s" class="%2$s">%3$s</a>',
            esc_url($url),
            esc_attr($className),
            esc_html($label),
        );
    }

    echo '</nav>';
}

function getquick_email_logger_render_admin_notice(): void
{
    if (! isset($_GET['getquick_email_logger_notice'])) {
        return;
    }

    $notice = sanitize_key((string) $_GET['getquick_email_logger_notice']);

    $messages = [
        'resent' => ['updated notice', 'Email resent successfully.'],
        'resend_failed' => ['notice notice-error', 'Email resend failed. Check the mail configuration and try again.'],
        'resend_unavailable' => ['notice notice-warning', 'This log entry does not have enough stored payload to resend.'],
        'log_not_found' => ['notice notice-error', 'The requested log entry was not found.'],
        'invalid_test_email' => ['notice notice-error', 'Please enter a valid email address for the test email.'],
        'test_email_sent' => ['updated notice', 'Test email sent successfully.'],
        'test_email_failed' => ['notice notice-error', 'Test email failed to send. Check the mail configuration and try again.'],
    ];

    if (! isset($messages[$notice])) {
        return;
    }

    [$className, $message] = $messages[$notice];

    printf(
        '<div class="%1$s"><p>%2$s</p></div>',
        esc_attr($className),
        esc_html($message),
    );
}

function getquick_email_logger_render_settings_tab(): void
{
    echo '<p>Configure Discord notifications for email logs captured by getquick-email-logger.</p>';
    echo '<form action="options.php" method="post">';

    settings_fields('getquick_email_logger_discord');
    do_settings_sections('getquick-email-logger');
    submit_button('Save settings');

    echo '</form>';
}
