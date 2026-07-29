# Fatal Plugin Auto Deactivator 1.5.0 — Release Notes

Released 2026-07-29 · Requires WordPress 5.3+ / PHP 7.0+ · [Full changelog](readme.txt)

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

## Upgrade notes

- **No action required.** The fatal-handling behavior itself is unchanged with default settings, and all alert *channels* are opt-in. Two things are active out of the box: the hourly protection watchdog — which contacts the site admin email **only** if protection is ever lost or restored (at most one of each per day; configure an alert channel to route these elsewhere) — and the drop-in's small reserved memory buffer.
- After the update you may notice one new hourly cron event (`fpad_watchdog_check`); a second (`fpad_notifier_drain`) appears only while a notification channel is enabled.
- Three new options are stored (`fpad_alert_state`, `fpad_alert_queue`, `fpad_watchdog_state`), all non-autoloaded and all removed on uninstall along with the rest.
- The protection drop-in is refreshed automatically during the update, as always.

## For developers

- New filter: `fpad_watchdog_interval` — cron schedule name for the watchdog (default `hourly`).
- Webhook JSON schema, option schemas, and the full architecture are documented in [`docs/`](docs/README.md) (not shipped in the WordPress.org build).
