<?php

declare(strict_types=1);

if (is_admin()) {
    add_action('admin_post_getquick_email_logger_send_test_email', 'getquick_email_logger_handle_test_email');
}

function getquick_email_logger_render_test_email_tab(): void
{
    $defaultRecipient = get_option('admin_email');

    echo '<p>Send a test email through the current mail setup to verify delivery is working as expected.</p>';
    echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
    echo '<input type="hidden" name="action" value="getquick_email_logger_send_test_email">';
    wp_nonce_field('getquick_email_logger_send_test_email');
    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';

    echo '<tr>';
    echo '<th scope="row"><label for="getquick-email-logger-test-email-to">Recipient email</label></th>';
    echo '<td>';
    printf(
        '<input type="email" id="getquick-email-logger-test-email-to" name="to" class="regular-text" value="%s" required>',
        esc_attr(is_string($defaultRecipient) ? $defaultRecipient : ''),
    );
    echo '<p class="description">Use a mailbox you can access so you can verify delivery.</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="getquick-email-logger-test-email-subject">Subject</label></th>';
    echo '<td>';
    printf(
        '<input type="text" id="getquick-email-logger-test-email-subject" name="subject" class="regular-text" value="%s">',
        esc_attr(getquick_email_logger_get_test_email_default_subject()),
    );
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="getquick-email-logger-test-email-message">Message</label></th>';
    echo '<td>';
    printf(
        '<textarea id="getquick-email-logger-test-email-message" name="message" rows="8" class="large-text code">%s</textarea>',
        esc_textarea(getquick_email_logger_get_test_email_default_message()),
    );
    echo '<p class="description">This uses <code>wp_mail()</code>, so it exercises the same mail transport and logging flow as the rest of the site.</p>';
    echo '</td>';
    echo '</tr>';

    echo '</tbody>';
    echo '</table>';

    submit_button('Send test email');

    echo '</form>';
}

function getquick_email_logger_handle_test_email(): void
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
        admin_url('options-general.php'),
    );

    $recipient = isset($_POST['to']) ? sanitize_email(wp_unslash((string) $_POST['to'])) : '';
    if ($recipient === '' || ! is_email($recipient)) {
        wp_safe_redirect(add_query_arg('getquick_email_logger_notice', 'invalid_test_email', $redirectUrl));
        exit;
    }

    $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash((string) $_POST['subject'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['message'])) : '';

    if ($subject === '') {
        $subject = getquick_email_logger_get_test_email_default_subject();
    }

    if ($message === '') {
        $message = getquick_email_logger_get_test_email_default_message();
    }

    $result = wp_mail($recipient, $subject, $message);

    wp_safe_redirect(add_query_arg('getquick_email_logger_notice', $result ? 'test_email_sent' : 'test_email_failed', $redirectUrl));
    exit;
}

function getquick_email_logger_get_test_email_default_subject(): string
{
    return sprintf('Test email from %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
}

function getquick_email_logger_get_test_email_default_message(): string
{
    return sprintf(
        "This is a test email sent from %s.\n\nSent at: %s UTC\nEnvironment check: if you received this email, the current wp_mail() setup is working.",
        wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        gmdate('Y-m-d H:i:s'),
    );
}
