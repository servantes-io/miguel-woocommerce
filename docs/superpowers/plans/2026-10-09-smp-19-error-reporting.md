# SMP-19 — Report Caught Errors to Miguel — Implementation Plan

**Goal:** The plugin reports the errors it catches to Miguel (`POST /v2/eshop/errors`), which forwards them to
GlitchTip. Failures of the plugin's own Miguel calls get the shared `MIGUEL_*` codes; the download path's own
failures get plugin codes. Reports wait in a bounded buffer and are sent from WP-Cron, never in a customer's
request.

**Contract (binding):** miguel `docs/client-errors.md` — fields, limits, response handling, client rules.

**Tech Stack:** PHP 8.1, WordPress, WooCommerce, PHPUnit via `docker-compose.test.yml`.

## Global Constraints

- Tests run in Docker only: `docker compose -p miguel-woocommerce -f docker-compose.test.yml run --rm phpunit
  --filter=<X>` for one class; `scripts/phpunit_docker.sh` for the whole suite.
- WordPress coding standards as in the surrounding files: tabs, `array()`, Yoda conditions, docblocks.
- Reporting must never throw into the caller: every public entry point of the reporter catches `Throwable`.
- A failed send is never itself reported: the flush posts with `wp_remote_post` directly, not through the
  reporting paths of `Miguel_V2_Client`.

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Create | `includes/class-miguel-error-reporter.php` | `Miguel_Error_Reporter` (static): `report()`, the WP option buffer (50, drop oldest), `flush()` (≤ 20 per request, ≤ 60 000 bytes, 5 s, `Retry-After`), `schedule_flush()`, `schedule_periodic_flush()`, `deactivate()` |
| Modify | `includes/api/v2/class-miguel-v2-client.php` | Classify each call's failure into a `MIGUEL_*` code and report it; schedule a flush after a 2xx; expose `user_agent()` to the reporter |
| Modify | `includes/class-miguel-download.php` | Report the download path's own failures with plugin codes |
| Modify | `includes/class-miguel.php` | Include the class; register the cron hook, the periodic schedule and the deactivation cleanup |
| Create | `tests/unit/test-error-reporter.php` | Buffer, sanitising, flush and its response handling |
| Modify | `tests/unit/test-v2-client.php`, `tests/unit/test-download-v2.php` | Classification and plugin codes |
| Modify | `CHANGELOG.md` | Unreleased entry |

## Design decisions

- **Buffer entries carry an internal id** (`wp_generate_uuid4()`), stripped before sending, so a 202/400 removes
  exactly the reports it answered even if another request appended (and evicted the oldest) meanwhile.
- **Flush trigger after a successful call** is `wp_schedule_single_event( time(), FLUSH_SOON_HOOK )` — the
  send runs in WP-Cron, not in the request that made the successful call (that may be a customer's download).
- **Periodic flush:** hourly WP-Cron event on `CRON_HOOK`, ensured on `init`, cleared on deactivation. The
  one-off has its own hook: `wp_get_schedule()` reads the earliest event of a hook, so a pending one-off on the
  same hook would hide the hourly event and every request would add another (`wp_schedule_event()` does not
  deduplicate).
- **Removing answered reports** re-reads the option after `wp_cache_delete()`: `get_option()` alone answers
  from this request's cache and would overwrite reports other requests added during the send.
- **`429`:** store `time() + Retry-After` (seconds; 60 when missing or unparsable) in an option; `flush()` does
  nothing before it. `400` drops the batch; every other status and a network error keeps it.
- **Batch size:** ≤ 20 reports and ≤ 60 000 encoded bytes (the endpoint takes 64 KB); a single report always
  fits because every field is truncated (message 1024, operation 200, excerpt 500, context 20 × 256), counted
  in UTF-16 code units as Miguel (.NET) counts them. Since one invalid report gets its whole batch refused, a
  code not matching the contract's pattern is never buffered and an empty message becomes the code.
- **Auth:** `Authorization: Bearer <key>` whenever the key option is non-empty, none otherwise; same
  `MiguelForWooCommerce/…` User-Agent as every other call (`Miguel_V2_Client::user_agent()`, made public static).
- **Codes (plugin's own, download path):** `DOWNLOAD_FILE_INVALID` (Miguel shortcode without id/format),
  `DOWNLOAD_ORDER_NOT_FOUND`, `DOWNLOAD_ORDER_NOT_PAID` (watermark request cannot be built),
  `DOWNLOAD_FAILED` (exception caught in `download()`). Order sync is a pure Miguel call: its failures are the
  `MIGUEL_*` reports, with `orderId` in `context`. Inbound REST routes (product pairing, order create/status)
  answer Miguel's own request, so Miguel already sees those errors; they are not reported.
- **Context:** ids only — `orderId`, `productId`, `downloadId`. Never e-mail, name or address.

## Tasks

### Task 1: `Miguel_Error_Reporter` — buffer

Tests first (`tests/unit/test-error-reporter.php`): a report is stored with `code`, `message`, ISO-8601
`occurredAt`, optional fields; over-long fields are truncated; non-string/over-many context entries are
dropped/capped; the 51st report evicts the oldest; `report()` with an unencodable value does not throw.
Then implement `report()`.

### Task 2: `flush()`

Tests: URL `<server>/v2/eshop/errors`, `POST`, timeout 5, User-Agent prefix, Authorization present with a key
and absent without; at most 20 per request; internal id not sent; `occurredAt` sent unchanged; 202 removes the
sent reports only; 400 drops them; 500 and a `WP_Error` keep them and add no report of their own; 429 keeps
them and a second flush before `Retry-After` sends nothing; byte budget splits a batch of large reports.
Then implement `flush()`.

### Task 3: scheduling

Tests: `schedule_flush()` schedules the cron hook only when the buffer is non-empty;
`schedule_periodic_flush()` schedules an hourly event once; `deactivate()` clears both.
Then implement and wire in `Miguel::init_hooks()` (cron hook and `init` inside the `! MIGUEL_TESTS` block,
deactivation hook beside the activation hook).

### Task 4: client classification

Tests (`test-v2-client.php`): transport `WP_Error` → `MIGUEL_UNREACHABLE`; 401/403 → `MIGUEL_AUTH_REJECTED`
with `httpStatus`; 409/500 → `MIGUEL_HTTP_ERROR`; watermark 200 with invalid JSON → `MIGUEL_RESPONSE_UNPARSABLE`;
watermark 200 without `downloadUrl` → `MIGUEL_RESPONSE_INVALID`; `configuration.not_set` → nothing; `operation`
set; `create_order`/`delete_order` carry `orderId`; a 2xx with a non-empty buffer schedules a flush.
Then implement.

### Task 5: download codes

Tests (`test-download-v2.php`): each of the four plugin codes, with ids-only context. Then implement.

### Task 6: changelog, full suite, phpcs on touched files.
