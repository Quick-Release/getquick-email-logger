<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\Database;

class Schema
{
    private LogRepository $repository;

    public function __construct(LogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function maybeInstall(): void
    {
        $currentVersion = (string) get_option(GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION, '');
        if ($currentVersion === GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION) {
            return;
        }

        if (get_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY)) {
            return;
        }

        set_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY, '1', MINUTE_IN_SECONDS);

        try {
            $this->install();
            update_option(GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION, GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION, false);
        } finally {
            delete_transient(GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY);
        }
    }

    private function install(): void
    {
        global $wpdb;

        $tableLogs = $this->repository->getTableName();
        $bounceRepo = new BounceRepository();
        $tableBounces = $bounceRepo->getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sqlLogs = "CREATE TABLE {$tableLogs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at_utc DATETIME NOT NULL,
            status VARCHAR(16) NOT NULL,
            provider VARCHAR(32) NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            subject VARCHAR(255) NOT NULL,
            to_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            to_list_json LONGTEXT NULL,
            from_email VARCHAR(191) NULL,
            reply_to VARCHAR(191) NULL,
            cc_list_json LONGTEXT NULL,
            bcc_list_json LONGTEXT NULL,
            headers_json LONGTEXT NULL,
            body_text LONGTEXT NULL,
            body_html LONGTEXT NULL,
            error_code VARCHAR(100) NULL,
            error_message TEXT NULL,
            client_ref VARCHAR(100) NULL,
            context_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_created_at_utc (created_at_utc),
            KEY idx_status_created_at (status, created_at_utc),
            KEY idx_provider_message_id (provider_message_id),
            KEY idx_client_ref_created_at (client_ref, created_at_utc)
        ) {$charsetCollate};";

        $sqlBounces = "CREATE TABLE {$tableBounces} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL,
            bounce_type VARCHAR(16) NOT NULL DEFAULT 'hard',
            reason TEXT NULL,
            created_at_utc DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_email (email),
            KEY idx_created_at (created_at_utc)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sqlLogs);
        dbDelta($sqlBounces);
    }
}
