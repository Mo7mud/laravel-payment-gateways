# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Kashier: `cancelPayment()` no longer fails when the session abandon call is
  rejected (E-K4: Kashier test answers the documented
  `PATCH /v3/payment/sessions/{id}` with 404 "merchant not found"). The
  failure is logged as `payment.kashier_abandon_failed` (warning), the session
  is still read back, and an open session is cancelled locally; a later
  capture is a late payment, as with gateways without a cancellation API.

### Added
- Translations (`lang/en`, `lang/ar`) under the `payment-gateway` namespace,
  publishable with `--tag=payment-gateway-translations`. The settings page,
  the 403 message, save warnings and the saved message are translated.
  Config `label`, `display_name` and select options in `admin.packages` are
  now translation keys (`payment-gateway::admin.*`); a value without a
  translation line is shown as written, so published configs keep working.
  `GatewaySettingsManager` returns labels, options and display names already
  translated, and validation errors name fields by their translated label.
- `Admin\GatewaySettingsManager`: UI-agnostic settings API for any admin panel
  (`fields()`, `gateways()`, `values()` with secrets masked, `packages()`,
  `rules()`, `save()` returning `Admin\SettingsSaveResult`, `canAccess()`,
  `authorize()`). The built-in settings controller now delegates to it; its
  routes, view and responses are unchanged.
- Per-mode credentials: `KASHIER_{TEST,LIVE}_{MERCHANT_ID,PAYMENT_API_KEY,SECRET_KEY}`
  and `FAWATERK_{TEST,LIVE}_{CLIENT_ID,CLIENT_SECRET,VENDOR_API_KEY}`.
  `<GATEWAY>_ENVIRONMENT` picks the active set; the other stays saved. The
  unprefixed keys still work as a fallback, and the settings page keeps them
  equal to the active set (emptied when that set has no value).
- `GatewaySettingsManager::gateways()` returns each gateway ready for tabs and
  sections: switch state, `mode`, `credentials.test`, `credentials.live`,
  `settings`, `webhook_url`, `return_url`.
- Settings: submitting the secret placeholder (`__configured__`) keeps the
  stored secret instead of writing the placeholder.
- Settings: keys containing `BASE_URL` are never editable, even when added to
  `admin.packages.*.env`.

### Changed
- Development: `phpstan-baseline.neon` records the 169 level-6 findings that
  predate regular PHPStan runs (array value types, model `@property` gaps,
  redundant checks; none changes runtime behaviour). New code must pass
  without adding to it.
- `admin.packages.*.gateways.*` config: credentials moved from `env` to the new
  `mode` and `credentials` entries. A published config keeps working as
  before (no mode switch). An override of the view in
  `resources/views/vendor/payment-gateway/admin/settings.blade.php` no longer
  shows the credential fields with the new config: render them from the new
  `$gateways` view variable.
- `KashierGateway` takes an optional PSR logger (last constructor argument,
  `logger:`); the service provider passes the package log channel.

## [0.2.0] - 2026-10-22

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
- `PaymentService::cancelPayment(string $reference): PaymentStatus` (R16): closes
  a pending, processing or creation_failed payment as `cancelled`. Gateways
  implementing the new `PaymentCancellationInterface` close the checkout first
  and report its status (a payment captured just before stays paid); others
  cancel locally. A later capture is a late payment.
- Kashier: `cancelPayment()` abandons the session
  (`PATCH /v3/payment/sessions/{id}`) through the new optional
  `KashierSessionCancellationInterface` client capability; the `ABANDONED`
  session status maps to `cancelled`.
- `GatewayCapabilities::$cancellation` / `supportsCancellation()`.
- `creation_failed` → `cancelled` is a valid transition.

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

[Unreleased]: https://github.com/Mo7mud/laravel-payment-gateways/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Mo7mud/laravel-payment-gateways/releases/tag/v0.2.0
