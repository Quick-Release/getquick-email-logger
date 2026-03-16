<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger;

class Plugin
{
    private static ?self $instance = null;

    private Database\LogRepository $repository;
    private Database\Schema $schema;
    private Mail\Capture $capture;
    private Mail\Resender $resender;
    private Integrations\DiscordService $discord;
    private GraphQL\Schema $graphql;
    private Admin\AdminManager $admin;

    private function __construct()
    {
        $this->defineConstants();
        $this->initComponents();
        $this->registerHooks();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function defineConstants(): void
    {
        $constants = [
            'GETQUICK_EMAIL_LOGGER_ENABLED' => true,
            'GETQUICK_EMAIL_LOGGER_TABLE_NAME' => 'getquick_email_logs',
            'GETQUICK_EMAIL_LOGGER_RETENTION_DAYS' => 90,
            'GETQUICK_EMAIL_LOGGER_CLEANUP_BATCH_SIZE' => 1000,
            'GETQUICK_EMAIL_LOGGER_CLEANUP_MAX_BATCHES' => 3,
            'GETQUICK_EMAIL_LOGGER_SENT_WINDOW_DAYS' => 30,
            'GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED' => true,
            'GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE' => 100,
            'GETQUICK_EMAIL_LOGGER_SCHEMA_VERSION' => '1.1.0',
            'GETQUICK_EMAIL_LOGGER_SCHEMA_OPTION' => 'getquick_email_logger_schema_version',
            'GETQUICK_EMAIL_LOGGER_SCHEMA_LOCK_KEY' => 'getquick_email_logger_schema_lock',
            'GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK' => 'getquick_email_logger_cleanup_event',
            'GETQUICK_EMAIL_LOGGER_ASYNC_ACTION' => 'getquick_email_logger_write_event',
            'GETQUICK_EMAIL_LOGGER_ASYNC_GROUP' => 'getquick-email-logger',
        ];

        foreach ($constants as $name => $value) {
            if (! defined($name)) {
                define($name, $value);
            }
        }
    }

    private function initComponents(): void
    {
        $this->repository = new Database\LogRepository();
        $this->schema = new Database\Schema($this->repository);
        $this->capture = new Mail\Capture();
        $this->resender = new Mail\Resender($this->repository);
        $this->discord = new Integrations\DiscordService();
        $this->graphql = new GraphQL\Schema($this->repository);
        $this->admin = new Admin\AdminManager($this->repository);
    }

    private function registerHooks(): void
    {
        if (GETQUICK_EMAIL_LOGGER_ENABLED !== true) {
            return;
        }

        add_action('init', [$this, 'bootstrap'], 1);
        add_action(GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK, [$this, 'runCleanup']);
        add_action(GETQUICK_EMAIL_LOGGER_ASYNC_ACTION, [$this, 'processAsyncEvent'], 10, 1);

        $this->capture->registerHooks();
        $this->resender->registerHooks();
        $this->discord->registerHooks();
        $this->graphql->registerHooks();
        $this->admin->registerHooks();
    }

    public function bootstrap(): void
    {
        $this->schema->maybeInstall();
        $this->scheduleCleanup();
    }

    private function scheduleCleanup(): void
    {
        if (wp_next_scheduled(GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', GETQUICK_EMAIL_LOGGER_CLEANUP_HOOK);
    }

    public function runCleanup(): void
    {
        $retentionDays = max(1, (int) GETQUICK_EMAIL_LOGGER_RETENTION_DAYS);
        $batchSize = max(1, (int) GETQUICK_EMAIL_LOGGER_CLEANUP_BATCH_SIZE);
        $maxBatches = max(1, (int) GETQUICK_EMAIL_LOGGER_CLEANUP_MAX_BATCHES);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * DAY_IN_SECONDS));

        $deletedTotal = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $deleted = $this->repository->deleteBefore($cutoff, $batchSize);
            $deletedTotal += $deleted;

            if ($deleted < $batchSize) {
                break;
            }
        }

        do_action('getquick_email_logger_cleanup_completed', $deletedTotal, $cutoff);
    }

    public function processAsyncEvent(array $event): void
    {
        $this->repository->insert($event);
    }
}
