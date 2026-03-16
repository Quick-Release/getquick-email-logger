<?php
/**
 * Test email tab template.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$defaultRecipient = get_option('admin_email');
?>
<p>Send a test email through the current mail setup to verify delivery is working as expected.</p>
<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <input type="hidden" name="action" value="getquick_email_logger_send_test_email">
    <?php wp_nonce_field('getquick_email_logger_send_test_email'); ?>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><label for="getquick-email-logger-test-email-to">Recipient email</label></th>
                <td>
                    <input type="email" id="getquick-email-logger-test-email-to" name="to" class="regular-text" value="<?php echo esc_attr(is_string($defaultRecipient) ? $defaultRecipient : ''); ?>" required>
                    <p class="description">Use a mailbox you can access so you can verify delivery.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="getquick-email-logger-test-email-subject">Subject</label></th>
                <td>
                    <input type="text" id="getquick-email-logger-test-email-subject" name="subject" class="regular-text" value="<?php echo esc_attr(\GetQuick\EmailLogger\Admin\AdminManager::getTestEmailDefaultSubject()); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="getquick-email-logger-test-email-message">Message</label></th>
                <td>
                    <textarea id="getquick-email-logger-test-email-message" name="message" rows="8" class="large-text code"><?php echo esc_textarea(\GetQuick\EmailLogger\Admin\AdminManager::getTestEmailDefaultMessage()); ?></textarea>
                    <p class="description">This uses <code>wp_mail()</code>, so it exercises the same mail transport and logging flow as the rest of the site.</p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button('Send test email'); ?>
</form>
