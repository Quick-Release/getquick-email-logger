<?php

declare(strict_types=1);

namespace GetQuick\EmailLogger\GraphQL;

use GetQuick\EmailLogger\Database\LogRepository;
use GetQuick\EmailLogger\Utils\Cursor;
use GraphQL\Error\UserError;
use RuntimeException;

class Schema
{
    private LogRepository $repository;

    public function __construct(LogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function registerHooks(): void
    {
        if (GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED === true) {
            add_action('graphql_register_types', [$this, 'registerTypes']);
        }
    }

    public function registerTypes(): void
    {
        if (! function_exists('register_graphql_object_type') || ! function_exists('register_graphql_field')) {
            return;
        }

        register_graphql_object_type('GetQuickSentEmailLog', [
            'description' => 'Single sent email log row from custom email logs table.',
            'fields' => [
                'id' => ['type' => 'ID'],
                'databaseId' => ['type' => 'Int'],
                'createdAtUtc' => ['type' => 'String'],
                'providerMessageId' => ['type' => 'String'],
                'subject' => ['type' => 'String'],
                'to' => ['type' => ['list_of' => 'String']],
                'cursor' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('GetQuickSentEmailLogPageInfo', [
            'description' => 'Pagination metadata for sent email logs query.',
            'fields' => [
                'hasNextPage' => ['type' => 'Boolean'],
                'endCursor' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('GetQuickSentEmailLogConnection', [
            'description' => 'Cursor-based connection for sent email logs.',
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'GetQuickSentEmailLog']],
                'pageInfo' => ['type' => 'GetQuickSentEmailLogPageInfo'],
            ],
        ]);

        register_graphql_field('RootQuery', 'sentEmailLogs', [
            'description' => 'Returns sent emails from the last month with cursor pagination.',
            'type' => 'GetQuickSentEmailLogConnection',
            'args' => [
                'first' => [
                    'type' => 'Int',
                    'description' => 'Number of rows to fetch. Max is controlled by GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE.',
                ],
                'after' => [
                    'type' => 'String',
                    'description' => 'Opaque cursor from the previous page endCursor.',
                ],
            ],
            'resolve' => function (mixed $root, array $args): array {
                unset($root);

                $this->assertAccess();

                $maxPageSize = max(1, (int) GETQUICK_EMAIL_LOGGER_GRAPHQL_MAX_PAGE_SIZE);
                $first = isset($args['first']) ? (int) $args['first'] : 50;
                $first = max(1, min($first, $maxPageSize));

                $windowDays = max(1, (int) GETQUICK_EMAIL_LOGGER_SENT_WINDOW_DAYS);
                $windowStart = gmdate('Y-m-d H:i:s', time() - ($windowDays * DAY_IN_SECONDS));

                $cursor = null;
                if (isset($args['after']) && is_string($args['after']) && $args['after'] !== '') {
                    $cursor = Cursor::decode($args['after']);
                }

                $rows = $this->querySentLastMonth($windowStart, $first + 1, $cursor);
                $hasNextPage = count($rows) > $first;

                if ($hasNextPage) {
                    array_pop($rows);
                }

                $nodes = array_map(static function (array $row): array {
                    $to = [];
                    if (isset($row['to_list_json']) && is_string($row['to_list_json']) && $row['to_list_json'] !== '') {
                        $decoded = json_decode($row['to_list_json'], true);
                        if (is_array($decoded)) {
                            $to = array_values(array_filter(array_map('strval', $decoded)));
                        }
                    }

                    $createdAt = (string) ($row['created_at_utc'] ?? '');
                    $databaseId = (int) ($row['id'] ?? 0);

                    return [
                        'id' => (string) $databaseId,
                        'databaseId' => $databaseId,
                        'createdAtUtc' => $createdAt,
                        'providerMessageId' => (string) ($row['provider_message_id'] ?? ''),
                        'subject' => (string) ($row['subject'] ?? ''),
                        'to' => $to,
                        'cursor' => Cursor::encode($createdAt, $databaseId),
                    ];
                }, $rows);

                $endCursor = null;
                if ($nodes !== []) {
                    $lastNode = $nodes[count($nodes) - 1];
                    $endCursor = (string) ($lastNode['cursor'] ?? '');
                }

                return [
                    'nodes' => $nodes,
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                ];
            },
        ]);
    }

    private function assertAccess(): void
    {
        if (current_user_can('manage_options')) {
            return;
        }

        if (class_exists(UserError::class)) {
            throw new UserError('Not authorized to query sentEmailLogs.');
        }

        throw new RuntimeException('Not authorized to query sentEmailLogs.');
    }

    private function querySentLastMonth(string $windowStart, int $limit, ?array $cursor): array
    {
        global $wpdb;

        $table = $this->repository->getTableName();
        $conditions = ['status = %s', 'created_at_utc >= %s'];
        $values = ['sent', $windowStart];

        if (is_array($cursor)) {
            $conditions[] = '(created_at_utc < %s OR (created_at_utc = %s AND id < %d))';
            $values[] = (string) ($cursor['created_at_utc'] ?? '');
            $values[] = (string) ($cursor['created_at_utc'] ?? '');
            $values[] = (int) ($cursor['id'] ?? 0);
        }

        $values[] = $limit;

        $sql = sprintf(
            'SELECT id, created_at_utc, provider_message_id, subject, to_list_json
            FROM %s
            WHERE %s
            ORDER BY created_at_utc DESC, id DESC
            LIMIT %%d',
            $table,
            implode(' AND ', $conditions),
        );

        $prepared = $wpdb->prepare($sql, $values);
        if (! is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
