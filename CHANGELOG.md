# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Fulfilment tracking (R1): `fulfilled_at`, `fulfilment_dispatches` and
  `next_fulfilment_dispatch_at` columns on the payments table (new migration;
  existing paid rows are backfilled as fulfilled).
- `PaymentService::markFulfilled(string $reference): bool` — idempotent; throws
  `PaymentNotPaidException` for unpaid payments.
- `PaymentService::redispatchUnfulfilled()` and the
  `payments:redispatch-unfulfilled {--older-than=} {--limit=}` command, scheduled
  every 5 minutes next to `payments:reconcile`. Raises `PaymentSucceeded` again
  for paid, unfulfilled payments with exponential back-off, up to
  `fulfilment.max_dispatches` times.
- `fulfilment` config section (`PAYMENT_GATEWAY_REDISPATCH_UNFULFILLED`).
- `PaymentStatus::awaitsFulfilment()`.
- Return status sync (R3): when the customer returns to a `pending`/`processing`
  payment, `handleReturn()` asks the gateway API once (`getPaymentStatus()`),
  throttled per payment through the cache (`return.sync_status`,
  `return.sync_lock_seconds`). The query string still never changes state.
- Per-payment return redirect (R3): `metadata['return_redirect_url']`, accepted
  only for http(s) URLs on `return.allowed_hosts`
  (`PAYMENT_GATEWAY_RETURN_ALLOWED_HOSTS`); `createPayment()` throws
  `InvalidArgumentException` otherwise. `{status}` and `{gateway}`
  placeholders in redirect templates.
- `MockGateway` handles returns (`payment_reference`, `status` query keys).

### Changed
- `PaymentService` takes an optional cache repository (last constructor
  argument, `cache:`); the service provider passes the default cache store.
- A listener exception no longer propagates out of `PaymentService`: the state
  change is already committed, so it is logged as `payment.listener_failed`
  (critical) and the remaining events are still dispatched. Webhooks whose
  listener fails now answer 200 instead of 500.

### Breaking
- `PaymentRepositoryInterface` has a new method, `findUnfulfilledCandidates()`.
  Migration path: custom repository implementations must add it (see
  `EloquentPaymentRepository` for the reference query).
- Applications must call `markFulfilled()` after handling `PaymentSucceeded`;
  otherwise the event is raised again. Migration path: call it from the
  listener, or set `PAYMENT_GATEWAY_REDISPATCH_UNFULFILLED=false`.
