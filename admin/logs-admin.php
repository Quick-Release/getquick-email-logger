<?php

declare(strict_types=1);

function getquick_email_logger_render_logs_tab(): void
{
    $currentPage = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $perPage = 25;
    $logs = getquick_email_logger_fetch_logs($currentPage, $perPage);
    $totalItems = getquick_email_logger_count_logs();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));

    echo '<p>Browse captured email logs and resend entries that have stored message payload.</p>';

    if ($logs === []) {
        echo '<p>No email logs found yet.</p>';
        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th scope="col">Date (UTC)</th>';
    echo '<th scope="col">Status</th>';
    echo '<th scope="col">Subject</th>';
    echo '<th scope="col">Recipients</th>';
    echo '<th scope="col">Provider</th>';
    echo '<th scope="col">Message ID</th>';
    echo '<th scope="col">Resend</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($logs as $log) {
        $logId = (int) ($log['id'] ?? 0);
        $recipients = getquick_email_logger_decode_email_list((string) ($log['to_list_json'] ?? ''));
        $canResend = getquick_email_logger_can_resend_log($log);

        echo '<tr>';
        printf('<td>%s</td>', esc_html((string) ($log['created_at_utc'] ?? '')));
        printf('<td>%s</td>', wp_kses_post(getquick_email_logger_format_status_badge((string) ($log['status'] ?? 'failed'))));
        printf('<td>%s</td>', esc_html((string) ($log['subject'] ?? '(no subject)')));
        printf('<td>%s</td>', esc_html($recipients === [] ? '(empty)' : implode(', ', $recipients)));
        printf('<td>%s</td>', esc_html((string) ($log['provider'] ?? '')));
        printf('<td>%s</td>', esc_html((string) ($log['provider_message_id'] ?? '')));
        echo '<td>';

        if ($canResend) {
            $resendUrl = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'getquick_email_logger_resend_email',
                        'log_id' => $logId,
                    ],
                    admin_url('admin-post.php'),
                ),
                'getquick_email_logger_resend_email_' . $logId,
            );

            printf(
                '<a href="%1$s" class="button button-small" title="%2$s"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
                esc_url($resendUrl),
                esc_attr__('Resend email', 'default'),
            );
        } else {
            echo '<span aria-hidden="true">-</span>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    if ($totalPages > 1) {
        $pagination = paginate_links([
            'base' => add_query_arg(
                [
                    'page' => 'getquick-email-logger',
                    'tab' => 'logs',
                    'paged' => '%#%',
                ],
                admin_url('options-general.php'),
            ),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
            'type' => 'list',
        ]);

        if (is_string($pagination)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
        }
    }
}

function getquick_email_logger_fetch_logs(int $page, int $perPage): array
{
    global $wpdb;

    $offset = max(0, ($page - 1) * $perPage);
    $table = getquick_email_logger_table_name();

    $query = $wpdb->prepare(
        "SELECT id, created_at_utc, status, subject, to_list_json, provider, provider_message_id, body_text, body_html
        FROM {$table}
        ORDER BY created_at_utc DESC, id DESC
        LIMIT %d OFFSET %d",
        $perPage,
        $offset,
    );

    if (! is_string($query)) {
        return [];
    }

    $rows = $wpdb->get_results($query, ARRAY_A);

    return is_array($rows) ? $rows : [];
}

function getquick_email_logger_count_logs(): int
{
    global $wpdb;

    $table = getquick_email_logger_table_name();
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    return is_numeric($count) ? (int) $count : 0;
}

function getquick_email_logger_decode_email_list(string $json): array
{
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn($email): string => trim((string) $email), $decoded)));
}

function getquick_email_logger_format_status_badge(string $status): string
{
    $label = strtoupper($status === 'sent' ? 'sent' : 'failed');
    $color = $status === 'sent' ? '#1f7a1f' : '#b42318';

    return sprintf(
        '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%1$s;color:#fff;font-size:11px;font-weight:600;">%2$s</span>',
        esc_attr($color),
        esc_html($label),
    );
}
