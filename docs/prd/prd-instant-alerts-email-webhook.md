# PRD: Instant Alerts — Email & Webhook Notifications

- **Status:** Proposed
- **Priority:** P1 (highest-value gap; flagship for next minor)
- **Suggested milestone:** 1.5.0
- **Date:** 2026-07-28

## 1. Summary

When a fatal error occurs today, the site owner finds out only when they next log in to wp-admin (admin notice) or open Tools → Fatal Plugin Log. Add proactive notifications: an email and/or a webhook (generic JSON + Slack-compatible) fired when the plugin detects a fatal — including which plugin was deactivated (or protected/logged-only) — with rate-limiting so a looping fatal cannot flood a channel, and a delivery design that works from the partially-loaded shutdown context.

## 2. Background & market evidence

- [Fatal Error Notify](https://wordpress.org/plugins/fatal-error-notify/) (~6K installs, 4.8★) exists solely to email on fatals; its Pro tier charges for Slack notifications, error logging, out-of-memory handling, and multisite. Demand for fatal-error alerting is proven, and the free tier of that plugin does *less* than ours already does (no deactivation, no log) — alerting is our missing piece, not theirs.
- WordPress core's own recovery-mode email is [well documented as unreliable](https://wordpress.org/documentation/article/recovery-mode/): it is sent via the server's raw `mail()` when SMTP plugins can't load (the crash may have happened *before* the mail plugin loaded), so it commonly lands in spam or is never sent ([DreamHost](https://help.dreamhost.com/hc/en-us/articles/360030636332-Fixing-the-White-Screen-of-Death), [Cinch](https://cinchws.com/wordpress-recovery-mode-without-access-to-admin-email/)). A webhook channel (HTTP POST) bypasses email deliverability entirely.
- Agency tools ([WP Remote](https://wpremote.com/php-error-monitoring/), [WP Debug Toolkit](https://wpdebugtoolkit.com/tool/site-monitor/)) market PHP-fatal alerting as a paid feature; WP Debug Toolkit specifically advertises out-of-memory resilience via a reserved memory block.

**Differentiator:** competing alerts say "an error happened." Ours say *what action was taken* — "WooCommerce Extension X was automatically deactivated" vs "…is protected and still crashing; act now." That severity distinction (site self-healed vs site still broken) is unique to this plugin.

## 3. Problem statement

1. A fatal at 2 a.m. on a storefront goes unnoticed until someone logs in; the plugin silently fixed (or couldn't fix) the site and nobody knew.
2. Protected-plugin and log-only fatals are *more* urgent (site may still be broken) yet produce no signal at all outside wp-admin.
3. The one channel core offers (recovery email) is unreliable and doesn't reflect this plugin's actions.

## 4. Goals

- Notify within seconds of the fatal (same request when possible; next request/cron otherwise).
- ≥1 channel deliverable even when `wp_mail` is unavailable or email is spam-filtered.
- Zero notification spam: identical fatals rate-limited per fingerprint.
- Shutdown-safe: notification code can never break the handler (same guarantee as `handle()` today).

Success metrics: alert delivered for ≥95% of distinct fatals in manual test matrix; no duplicate alert within the rate-limit window; support forum shows "I got the email/Slack ping" confirmations.

## 5. Non-goals

- No SMS/push/paid gateway integrations (webhook covers Zapier/Make/ntfy/etc.).
- No digest/summary emails (future).
- No admin-configurable HTML email templating (plain, readable default).
- Not a general PHP error monitor — only the fatal types the handler already detects.

## 6. User stories

- As a **site owner**, I get an email naming the deactivated plugin and the error, so I can react without discovering breakage days later.
- As an **agency/freelancer**, I point all client sites' webhooks at one Slack channel, so my team sees `site.com: deactivated plugin-x (E_ERROR …)` in real time.
- As a **WooCommerce store owner** with a protected payments plugin, I get an *urgent*-worded alert when that protected plugin fatals, because the site did not self-heal.
- As a **cautious admin**, I click "Send test notification" and verify both channels before trusting them.

## 7. Functional requirements

### FR-1: Settings (admin side)

Extend the existing Settings tab (`FPAD_Admin::render_settings_tab()` / `handle_settings_save()`) with a **Notifications** section stored in the existing `fpad_settings` option (new keys, defaults shown):

```php
'notify_email'          => false,          // master toggle, email channel
'notify_email_to'       => '',             // comma-separated; empty ⇒ get_option('admin_email')
'notify_webhook'        => false,          // master toggle, webhook channel
'notify_webhook_url'    => '',             // https URL
'notify_webhook_format' => 'json',         // 'json' | 'slack'
'notify_statuses'       => array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' ), // which outcomes alert
'notify_cooldown'       => 900,            // seconds; per-fingerprint rate limit (min 60, max 86400)
```

Validation on save: emails via `sanitize_email` per item (drop invalid); webhook URL via `wp_http_validate_url` + require `https://` (allow `http://` only for localhost); format whitelist; cooldown `absint` clamped. Both toggles off = feature fully dormant (default → zero behavior change on upgrade).

### FR-2: Capture at shutdown (handler side)

In `FPAD_Fatal_Error_Handler::handle()`, after `add_to_deactivation_log()` and **before** `display_custom_error_page()`, call new protected method `maybe_notify( $error, $plugin_result )`:

1. Read settings via existing guarded `get_settings()` (extend it to return the new keys with defaults).
2. Bail if no channel enabled, or the entry's status is not in `notify_statuses`.
3. Rate limit: compute the entry fingerprint with the existing `log_fingerprint()`; read option `fpad_alert_state` (`array( fingerprint => last_sent_ts )`); bail if `time() - last_sent < notify_cooldown`. Prune entries older than 7 days while writing.
4. **Attempt direct send** (best effort, each wrapped in its own guard):
   - Webhook: only if `function_exists( 'wp_remote_post' )` — `wp_remote_post( $url, array( 'timeout' => 3, 'blocking' => false, 'body' => $payload_json, 'headers' => array( 'Content-Type' => 'application/json' ) ) )`. Non-blocking so the error page is not delayed.
   - Email: only if `function_exists( 'wp_mail' )` — plain-text body (FR-4).
5. **Queue fallback:** if a channel was enabled but its transport function did not exist (very early fatal, e.g. parse error in a plugin loaded before pluggables), append the rendered payload to option `fpad_alert_queue` (cap 20 entries, oldest dropped) for FR-3 to drain. On successful direct send, update `fpad_alert_state` immediately.
6. Whole method wrapped in the same silence guarantee as `handle()` — any Throwable inside must not prevent the error page. (It already runs inside `handle()`'s try/catch; still avoid `exit`/output here.)

Shutdown-context rule applies in full: every WP function guarded by `function_exists()`; no new class dependencies loaded from the drop-in path — `maybe_notify()` lives inside `class-fatal-error-handler.php`.

### FR-3: Queue drain (normal-request side)

New file `includes/class-notifier.php`, class `FPAD_Notifier` (static, required from the main bootstrap file, `init()` called there):

- Hook: `init` (priority 20), plus a scheduled event `fpad_notifier_drain` (hourly) as backstop for front-end-only traffic patterns. Schedule on activation (`FPAD_Plugin_Lifecycle::activate()`), clear on deactivation (`wp_clear_scheduled_hook`).
- Drain: read `fpad_alert_queue`; for each queued item send via `wp_mail` / `wp_remote_post` (now guaranteed loaded); respect `fpad_alert_state` cooldown again (the queue may contain items already sent directly on a later request); delete sent items; persist remainder. Concurrency guard: `get_transient/set_transient( 'fpad_notifier_lock', 1, 60 )` — skip if locked.
- Also exposes `FPAD_Notifier::send_test( $channel )` used by FR-5.

### FR-4: Message content

**Email** — subject: `[{site_name}] Fatal error: {plugin_name|unattributed} {status_verb}`, where status verb is one of `deactivated` / `NOT deactivated (protected)` / `logged only` / `could not be deactivated`. Plain-text body reusing the exact field layout of `FPAD_Admin::build_report()` (extract that formatting into a shared static helper `FPAD_Admin::build_report()` stays; the handler embeds its own minimal formatter since it cannot call the admin class — same field order, documented as a sync pair) plus a link to `tools.php?page=fpad-log`.

**Webhook `json` format** — single JSON object:

```json
{
  "event": "fpad.fatal_error",
  "site_url": "https://example.com",
  "status": "deactivated",
  "deactivated": true,
  "plugin": "bad-plugin/bad-plugin.php",
  "plugin_name": "Bad Plugin",
  "error": { "type": "E_ERROR", "message": "…", "file": "…", "line": 42 },
  "request_uri": "/checkout/",
  "php_version": "8.2.0",
  "wp_version": "7.0.2",
  "occurred_at": 1753700000,
  "fpad_version": "1.5.0"
}
```

**Webhook `slack` format** — `{"text": "🔴 example.com: Fatal error in Bad Plugin — automatically deactivated.\nE_ERROR: … in …/file.php:42"}` (works verbatim for Slack & Discord `/slack` and Mattermost incoming webhooks). Emoji/severity: 🟠 for `deactivated` (self-healed), 🔴 for `protected`/`log_only`/`unavailable`/`unattributed` (still broken).

`site_url`: use `home_url()` when available, else scheme+`$_SERVER['HTTP_HOST']` fallback, else `''`.

### FR-5: Test send

"Send test notification" buttons (one per enabled channel) on the Settings tab → `admin_post_fpad_test_alert` handler in `FPAD_Admin` (cap `manage_options`, nonce `fpad_test_alert`), calls `FPAD_Notifier::send_test()`, redirects back with a success/failure `add_settings_error` message including the webhook HTTP response code on failure.

### FR-6: Out-of-memory resilience (stretch, same release if cheap)

An OOM fatal can prevent the handler from doing anything. In the drop-in (both the tracked source `includes/fatal-error-handler-dropin.php` **and** the generator heredoc in `FPAD_Dropin_Manager::create_dropin_source()` — sync pair), reserve a buffer: `$GLOBALS['fpad_reserved_memory'] = str_repeat( 'x', 256 * 1024 );`. First statement of `handle()`: `unset( $GLOBALS['fpad_reserved_memory'] );`. This frees ~256 KB headroom so logging + notification survive `Allowed memory size exhausted` fatals (technique used by [WP Debug Toolkit](https://wpdebugtoolkit.com/tool/site-monitor/); paywalled in Fatal Error Notify Pro).

## 8. Data model changes

| Option | New/changed | Contents |
|--------|-------------|----------|
| `fpad_settings` | extended | keys from FR-1 (both `get_settings()` mirrors must add identical defaults — sync pair) |
| `fpad_alert_state` | new | `array( md5-fingerprint => unix-ts-last-sent )`, pruned > 7 days |
| `fpad_alert_queue` | new | array of ≤20 pending payloads: `array( 'channel' => 'email'\|'webhook', 'payload' => …, 'fingerprint' => …, 'time' => ts )` |

All new options deleted in `FPAD_Plugin_Lifecycle::uninstall()`. Cron hook `fpad_notifier_drain` cleared on deactivation and uninstall.

## 9. UI spec

Settings tab, new `<h2>Notifications</h2>` section under the existing table: email toggle + recipients text field (placeholder = current admin email); webhook toggle + URL field + format radio (Generic JSON / Slack-compatible); "Notify about" checkbox group (5 statuses, all checked by default); cooldown number input (seconds, description explains per-identical-error throttling); per-channel "Send test" buttons. All strings translatable, text domain `fatal-plugin-auto-deactivator`.

## 10. Technical constraints

- PHP 7.0 syntax only. WPCS style (tabs, Yoda, escaping). No Composer deps.
- `maybe_notify()` must obey the [shutdown-context rule](../architecture.md#shutdown-context-constraints-critical) — treat every WP symbol as possibly undefined.
- The webhook direct-send must be non-blocking (`'blocking' => false`) so visitors' error page isn't delayed by a slow endpoint.
- Alert strings sent from shutdown are intentionally untranslated (consistent with the error page); admin-side test sends may translate.

## 11. Security & privacy

- Error messages/paths may contain sensitive server paths: documented in readme FAQ ("alerts contain the same detail as the log; send them only to endpoints you control").
- Webhook URL is admin-supplied; require https (localhost exception), never render it unescaped (`esc_url` in the form).
- SSRF surface: URL is set only by `manage_options` users and only POSTed to on fatals/test — equivalent trust level to existing admin-configured integrations across the ecosystem; still validate with `wp_http_validate_url` (which blocks some internal hosts) and document.
- No PII beyond what the log already stores.

## 12. Acceptance criteria

1. Fresh upgrade with defaults: behavior identical to 1.4.0 (no sends, no queue writes, no cron scheduled until activation runs — schedule the drain event lazily on first save enabling a channel, or on activation; either, but never for users who never enable the feature: acceptance = zero new cron rows for disabled config).
2. Email enabled → crash test plugin fatals → email arrives naming plugin + status + file:line + log link.
3. Webhook (json) enabled → endpoint receives the FR-4 schema exactly once per cooldown window even when the fatal fires 20× (rate limit works).
4. Slack format posts render correctly in a real Slack incoming webhook.
5. Parse error in an early-loading plugin (transport unavailable at shutdown) → alert queued and delivered on the next normal request (`init` drain) — verify by checking `fpad_alert_queue` empties.
6. Protected-plugin fatal → alert uses the "NOT deactivated" urgent wording.
7. Statuses unchecked in "Notify about" produce no alert.
8. "Send test" delivers to both channels and reports webhook HTTP status on failure.
9. With FR-6: a `memory_limit`-exhaustion fatal still produces a log entry and an alert.
10. Deactivate plugin → cron event gone; uninstall → all three options gone.
11. `php -l` passes on PHP 7.0 for all touched files; no unguarded WP calls added to the handler (manual review against the sync-pairs table).

## 13. Docs & release tasks

- Update `docs/feature-map.md` (new rows: notifications; new sync pairs: `get_settings()` mirrors gain keys, report format handler↔admin, drop-in source↔generator if FR-6 lands), `docs/reference.md` (settings schema, new options, new hooks `init`, `fpad_notifier_drain`, `admin_post_fpad_test_alert`), `docs/architecture.md` (entry-points table + communication table), `docs/development.md` (test scenarios), `readme.txt` (feature bullets, FAQ "Will I be notified…?" rewrite — it currently only mentions admin notices — changelog, version bump ×3).

## 14. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Notification code crashes the crash handler | Lives inside `handle()`'s Throwable guard; every call `function_exists()`-guarded; no output/exit |
| Alert flood from looping fatal | Per-fingerprint cooldown (default 15 min) + queue cap 20 |
| `update_option` write races between shutdown and drain | Acceptable: worst case one duplicate alert; state check runs on both paths |
| Users expect delivery guarantees | Readme wording: "best effort — webhook recommended over email for reliability" |

## 15. Open questions

1. Should the cooldown also collapse *different* fatals from the same plugin into one alert? (Proposed: no for v1 — fingerprint granularity matches the log.)
2. Auto-enable email (to admin_email) by default for new installs? (Proposed: no — opt-in avoids surprise emails; revisit after feedback.)
