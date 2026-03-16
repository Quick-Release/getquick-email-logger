<?php
/**
 * Settings tab template.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<p>Configure Discord notifications for email logs captured by getquick-email-logger.</p>
<form action="options.php" method="post">
    <?php
    settings_fields('getquick_email_logger_discord');
    do_settings_sections('getquick-email-logger');
    submit_button('Save settings');
    ?>
</form>

<hr>

<h2>Debug & Maintenance</h2>
<p>Use these tools to manage the email logs table for testing or cleanup.</p>

<div style="display: flex; gap: 10px;">
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return confirm('Are you sure you want to delete ALL email logs? This action cannot be undone.');">
        <input type="hidden" name="action" value="getquick_email_logger_reset_logs">
        <?php wp_nonce_field('getquick_email_logger_reset_logs'); ?>
        <?php submit_button('Reset Logs Table', 'delete', 'submit', false); ?>
    </form>

    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="getquick_email_logger_seed_logs">
        <?php wp_nonce_field('getquick_email_logger_seed_logs'); ?>
        <?php submit_button('Add 50 Dummy Logs', 'secondary', 'submit', false); ?>
    </form>
</div>
