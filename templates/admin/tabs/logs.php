<?php
/**
 * Logs tab template.
 *
 * @var array $logs
 * @var int   $currentPage
 * @var int   $totalPages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<p>Browse captured email logs and resend entries that have stored message payload.</p>

<?php if ($logs === []) : ?>
    <p>No email logs found yet.</p>
<?php else : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col">Date (UTC)</th>
                <th scope="col">Status</th>
                <th scope="col">Subject</th>
                <th scope="col">Recipients</th>
                <th scope="col">Provider</th>
                <th scope="col">Message ID</th>
                <th scope="col">Resend</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log) : ?>
                <?php
                $logId = (int) ($log['id'] ?? 0);
                $recipients = json_decode((string) ($log['to_list_json'] ?? '[]'), true);
                if (! is_array($recipients)) {
                    $recipients = [];
                }
                
                $status = (string) ($log['status'] ?? 'failed');
                $label = strtoupper($status === 'sent' ? 'sent' : 'failed');
                $color = $status === 'sent' ? '#1f7a1f' : '#b42318';
                $statusBadge = sprintf(
                    '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%1$s;color:#fff;font-size:11px;font-weight:600;">%2$s</span>',
                    esc_attr($color),
                    esc_html($label)
                );

                $canResend = ! empty($log['body_text']) || ! empty($log['body_html']);
                ?>
                <tr>
                    <td><?php echo esc_html((string) ($log['created_at_utc'] ?? '')); ?></td>
                    <td><?php echo $statusBadge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    <td><?php echo esc_html((string) ($log['subject'] ?? '(no subject)')); ?></td>
                    <td><?php echo esc_html($recipients === [] ? '(empty)' : implode(', ', $recipients)); ?></td>
                    <td><?php echo esc_html((string) ($log['provider'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($log['provider_message_id'] ?? '')); ?></td>
                    <td>
                        <?php if ($canResend) : ?>
                            <?php
                            $resendUrl = wp_nonce_url(
                                add_query_arg(
                                    [
                                        'action' => 'getquick_email_logger_resend_email',
                                        'log_id' => $logId,
                                    ],
                                    admin_url('admin-post.php')
                                ),
                                'getquick_email_logger_resend_email_' . $logId
                            );
                            ?>
                            <a href="<?php echo esc_url($resendUrl); ?>" class="button button-small" title="<?php esc_attr_e('Resend email', 'default'); ?>">
                                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Resend email', 'default'); ?></span>
                            </a>
                        <?php else : ?>
                            <span aria-hidden="true">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1) : ?>
        <?php
        $pagination = paginate_links([
            'base' => add_query_arg(
                [
                    'page' => 'getquick-email-logger',
                    'tab' => 'logs',
                    'paged' => '%#%',
                ],
                admin_url('options-general.php')
            ),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
            'type' => 'list',
        ]);
        ?>
        <?php if (is_string($pagination)) : ?>
            <div class="tablenav"><div class="tablenav-pages"><?php echo $pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
