# getquick-email-logger - Implementation TODO

## Scope and Goals

- [x] Persist all email send attempts with delivery status in a dedicated table namespace (`getquick_email_logs`).
- [x] Keep table outside WordPress naming pattern (`wp_*`) while staying in the same database.
- [x] Use asynchronous writes when Action Scheduler is available.
- [x] Expose sent emails from the last month via WPGraphQL.
- [x] Restrict API read access to admin users (`manage_options`).
- [x] Apply 90-day retention strategy with batch cleanup.

## Database and Schema

- [x] Create schema bootstrap for MU-plugin lifecycle (no activation hook dependency).
- [x] Add versioned schema option and migration lock.
- [x] Create table with columns for status, provider, provider message id, recipients, headers, and error metadata.
- [x] Add indexes optimized for listing and billing rollups.
- [ ] Validate schema on staging and production with real database engine settings.

## Event Capture and Persistence

- [x] Capture SES success events through `aws_ses_wp_mail_ses_sent_message`.
- [x] Capture SES failure events through `aws_ses_wp_mail_ses_error_sending_message`.
- [x] Add non-SES fallback hooks (`wp_mail_succeeded`, `wp_mail_failed`) for portability.
- [x] Normalize recipients and headers before persistence.
- [x] Store provider message id for future billing reconciliation.
- [ ] Add explicit client reference extraction strategy (`client_ref`) from business context.

## Async and Reliability

- [x] Queue writes using Action Scheduler async action.
- [x] Add synchronous fallback if Action Scheduler is unavailable.
- [x] Emit failure hooks for insert and cleanup failures.
- [ ] Add retry/backoff policy tuning for async worker failures.

## GraphQL Endpoint

- [x] Register custom GraphQL types for sent email log rows.
- [x] Register `sentEmailLogs` query on `RootQuery`.
- [x] Enforce default last-month window (`30` days) and hard sent status filter.
- [x] Implement cursor pagination (`first`, `after`) with stable ordering.
- [x] Enforce admin-only authorization in resolver.
- [ ] Add GraphQL response caching strategy (if Smart Cache policies are required for this field).

## Admin UI

- [x] Split plugin admin screen into `Logs` and `Settings` tabs.
- [x] Show paginated table of email logs in the `Logs` tab.
- [x] Add resend action for rows with stored payload.
- [x] Keep existing Discord preferences in the `Settings` tab.
- [ ] Add filters/search to the logs table if volume grows.

## Retention and Maintenance

- [x] Schedule daily cleanup event.
- [x] Delete rows older than retention window in chunks.
- [ ] Confirm cleanup duration and lock behavior under production load.

## Configuration and Environment

- [x] Add config constants in `config/application.php`.
- [x] Add `.env.example` entries for all toggles and limits.
- [x] Keep defaults safe for test phase (enabled, 90 days retention, 30 days query window).
- [ ] Define production values in deployment secrets manager / environment.

## Tests and Validation

- [ ] Add Pest tests for schema idempotency.
- [ ] Add Pest tests for event normalization and insert payload shape.
- [ ] Add Pest tests for async enqueue and fallback sync path.
- [ ] Add Pest tests for GraphQL auth guard and pagination.
- [ ] Add Pest tests for retention cleanup batches.
- [ ] Run burst email smoke test and measure queue latency.

## Rollout Plan

- [ ] Deploy with logging enabled and monitor insert failure hooks.
- [ ] Validate sample GraphQL query from admin token.
- [ ] Confirm row growth vs retention expectations after first 7 days.
- [ ] Freeze schema version and document migration procedure for v1.1.

## Future Billing Milestones

- [ ] Define billing aggregation job (daily/monthly grouped by `client_ref`).
- [ ] Define invoice export format (CSV/API) for finance workflows.
- [ ] Add immutable ledger snapshot process for closed billing periods.
