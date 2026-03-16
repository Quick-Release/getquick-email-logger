# getquick-email-logger

**getquick-email-logger** is a robust WordPress plugin designed to capture and persist email delivery logs in a dedicated database table. It provides seamless integration for both standard `wp_mail()` and AWS SES (via the `aws-ses-wp-mail` plugin).

## Key Features

- **Centralized Logging:** Persists all sent and failed email attempts into a custom `getquick_email_logs` table.
- **Modern OOP Architecture:** Fully refactored to use PHP 8.1+ features, PSR-4 namespacing, and a clean Singleton-based orchestrator.
- **Discord Integration:** Real-time notifications for email events via Discord webhooks.
- **WPGraphQL Support:** Exposes a `sentEmailLogs` field for external consumption of delivery logs with cursor-based pagination.
- **Admin Log Viewer:** A dedicated interface to browse, search, and resend logged emails.
- **Developer Tools:** Built-in seeders for generating test data and tools for database maintenance.

## Requirements

- **PHP:** 8.1 or higher.
- **WordPress:** 6.0 or higher.
- **Composer:** Required for PSR-4 autoloading.
- **Action Scheduler:** (Optional) If available, the plugin will use it for non-blocking asynchronous database writes.

## Installation

1. Clone or upload the plugin to your `wp-content/plugins` directory.
2. Navigate to the plugin folder and run:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Activate the plugin through the WordPress 'Plugins' menu.

## Architecture

The plugin follows a modern, modular design:
- `src/Admin/`: Management of the WordPress admin interface and settings.
- `src/Database/`: Data access layer (`LogRepository`) and schema management.
- `src/GraphQL/`: Registration of types and fields for WPGraphQL.
- `src/Integrations/`: Services for external platforms like Discord.
- `src/Mail/`: Core logic for capturing email hooks and the resending engine.
- `templates/`: Separation of concerns by isolating HTML UI templates from business logic.

## Configuration Constants

You can customize the plugin behavior by defining these constants in your `wp-config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `GETQUICK_EMAIL_LOGGER_RETENTION_DAYS` | `90` | Number of days to keep logs before cleanup. |
| `GETQUICK_EMAIL_LOGGER_GRAPHQL_ENABLED` | `true` | Toggle WPGraphQL integration. |
| `GETQUICK_EMAIL_LOGGER_TABLE_NAME` | `getquick_email_logs` | Custom table name for logs. |

## Maintenance & Debugging

Located under **Settings > Email Logger > Settings**, you will find the **Debug & Maintenance** section:
- **Reset Logs Table:** Truncates the custom table to clear all captured logs.
- **Add 50 Dummy Logs:** Seeds the database with randomized test data to verify Discord notifications, pagination, and API responses.

## License

GPL-2.0-or-later
