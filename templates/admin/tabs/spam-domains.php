<?php
/**
 * Spam domains tab template.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$blockedDomains = get_option('getquick_email_logger_blocked_domains', '');
?>
<div class="card" style="max-width: 800px; margin-top: 20px;">
    <h2>Block Known Spam Domains</h2>
    <p>Enter the domains you want to blacklist, one per line (e.g., <code>mail.ru</code>). Emails sent to these domains will be automatically suppressed.</p>
    
    <form action="options.php" method="post">
        <?php settings_fields('getquick_email_logger_blocked_domains'); ?>
        <textarea 
            name="getquick_email_logger_blocked_domains" 
            rows="15" 
            class="large-text code" 
            placeholder="example.com&#10;spam-domain.net"
        ><?php echo esc_textarea($blockedDomains); ?></textarea>
        
        <p class="description">Only valid domain names will be saved. Empty lines and duplicates are removed automatically.</p>
        
        <?php submit_button('Save Blocked Domains'); ?>
    </form>
</div>
