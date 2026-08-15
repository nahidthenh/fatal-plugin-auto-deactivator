# Fatal Plugin Auto Deactivator 1.5.0 — Release Notes

Released 2026-08-15 · Requires WordPress 5.3+ / PHP 7.0+ · [Full changelog](readme.txt)

1.5.0 is about **knowing** — until now the plugin quietly fixed your site and you found out on your next login. Now it tells you the moment something breaks, and it continuously proves that the protection itself is still standing.

## ✨ New: Instant alerts (email & webhook)

Enable under **Tools → Fatal Plugin Log → Settings → Notifications** (everything is opt-in — nothing changes until you turn a channel on):

- **Email alerts** to one or more addresses (defaults to the site admin email).
- **Webhook alerts** as generic JSON for your own tooling, or a **Slack-compatible** message you can point directly at a Slack, Discord (`/slack`), or Mattermost incoming webhook.
- Alerts say **what action was taken**, not just "an error happened": 🟠 *"Fatal error in Plugin X — automatically deactivated"* means your site self-healed; 🔴 *"NOT deactivated (protected)"* means a protected plugin is still crashing and needs you now.
- **Flood-proof:** identical repeated errors are rate-limited by a configurable cooldown (default 15 minutes), so a looping fatal can't bury your inbox or channel.
- **Early-crash safe:** if a fatal happens before WordPress can send mail or HTTP requests, the alert is queued and delivered on the next page load (with an hourly cron backstop).
- **"Send test email" / "Send test webhook"** buttons confirm your setup, reporting the HTTP status code on webhook failures.
- Webhook URLs must be `https://` (plain `http://` is allowed only for `localhost`/`127.0.0.1` targets), redirects are never followed, and URLs are re-validated at send time.

> Alerts contain the same detail as the log — including server file paths — so send them only to inboxes and endpoints you control.

## 🐕 New: Protection watchdog

Previously the protection drop-in was only re-checked when someone opened wp-admin. Now an **hourly background check** verifies protection end-to-end even on sites nobody logs into:

- **Self-heals** a missing drop-in silently, and reclaims one that another plugin overwrote (once per 24 h, so two plugins never fight over the slot).
- Detects two previously invisible failure modes: **`disabled`** (fatal error handling switched off via `WP_DISABLE_FATAL_ERROR_HANDLER` in wp-config.php) and **`stranded`** (the plugin folder was moved or renamed, leaving the protection file pointing at nothing).
- **Notifies you** — through your configured alert channels, or the admin email if none — when protection is lost *and* when it is restored, damped to at most one lost + one restored alert per day even if the status flaps.
- A **"Protection last verified: X ago"** heartbeat appears on the log page and in Site Health debug info.
- Developers can change the cadence with the plugin's first public filter, `fpad_watchdog_interval`.

> WP-Cron runs on site traffic. On a site with literally zero visitors, configure a real server cron for `wp-cron.php` (standard WordPress guidance).

## 💪 Improved resilience

- **Out-of-memory fatals are now handled too:** the drop-in reserves a small memory buffer that the handler frees on entry, so even "Allowed memory size exhausted" crashes get logged, alerted, and auto-deactivated.
- **The error page always comes first:** the branded 500 page is rendered and flushed to the visitor *before* any alert is sent, so a slow or broken mail/HTTP stack can never delay what your visitors see.
- **Drop-in crashes are logged now, not swallowed.** A fatal inside `advanced-cache.php`, `object-cache.php`, `db.php` or `sunrise.php` happens before WordPress has finished loading its own options API — so there was no way to write the log entry, and the incident disappeared entirely. The handler now restores just enough of WordPress (core's own `$wpdb` and object-cache fallbacks, the ones core would have used had the drop-in not crashed) to record it. All four drop-in types verified end to end.
- **`WP_DEBUG_DISPLAY` no longer suppresses the error page.** With it enabled, PHP prints its own raw error — server paths, full stack trace — *before* any shutdown handler runs. That hid the custom page entirely and, worse, left the response as **HTTP 200**: browsers, caches, and uptime monitors all saw a crashed page as a healthy one. The drop-in now takes an output buffer when errors are set to display, so the raw trace is replaced by the branded page and the 500 is sent correctly. Nothing changes when errors are not displayed, which is the usual production setting.
- **Very early fatals are no longer silent:** WordPress registers its fatal error handler before it loads its own options and escaping APIs and before `WP_PLUGIN_DIR` exists, so a crash in a caching (`advanced-cache.php`), object-cache, database (`db.php`) or multisite (`sunrise.php`) drop-in used to leave the handler unable to run — a plain blank page, with nothing logged and nothing sent. Worse, a broken `object-cache.php` aborts WordPress *before* `wp_cache_get()` exists, so `esc_html()` and friends are present but throw the moment they look up an option — a `function_exists()` check cannot see that. The handler now degrades gracefully in that whole window and still renders the branded 500 page (the fatal is reported as unattributed, since no plugin has loaded that early). Each step of the handler is also isolated, so a third-party hook throwing during deactivation or an option write can no longer cost the visitor the page. *Caveat:* when the options API itself is what broke, no log entry or alert is possible — the page is the deliverable.
- **The foreign-drop-in back-off now actually holds.** The watchdog reclaims a drop-in another plugin overwrote at most once per 24 h, but `wp-admin` was reinstalling it on *every* page load, so on any site an administrator uses, two plugins could quietly fight over the slot forever and the conflict was never surfaced. Both paths now share one back-off; when it holds you get the status banner and its one-click **Reinstall protection** action instead (that button is an explicit choice, so it always works).

## Upgrade notes

- **No action required.** The fatal-handling behavior itself is unchanged with default settings, and all alert *channels* are opt-in. Two things are active out of the box: the hourly protection watchdog — which contacts the site admin email **only** if protection is ever lost or restored (at most one of each per day; configure an alert channel to route these elsewhere) — and the drop-in's small reserved memory buffer.
- After the update you may notice one new hourly cron event (`fpad_watchdog_check`); a second (`fpad_notifier_drain`) appears only while a notification channel is enabled.
- Three new options are stored (`fpad_alert_state`, `fpad_alert_queue`, `fpad_watchdog_state`), all non-autoloaded and all removed on uninstall along with the rest.
- The protection drop-in is refreshed automatically during the update, as always.

## For developers

- New filter: `fpad_watchdog_interval` — cron schedule name for the watchdog (default `hourly`).
- Webhook JSON schema, option schemas, and the full architecture are documented in [`docs/`](docs/README.md) (not shipped in the WordPress.org build).
