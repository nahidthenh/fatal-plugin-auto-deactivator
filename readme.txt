=== Fatal Plugin Auto Deactivator - Never let a plugin break your site ===
Contributors: rudlinkon
Tags: fatal error, plugin deactivation, error handling, site protection, crash prevention
Requires at least: 5.3
Tested up to: 7.0
Stable tag: 1.5.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically deactivates plugins that cause fatal errors to prevent site crashes and keep your WordPress site running smoothly.

== Description ==

The Fatal Plugin Auto Deactivator plugin is a powerful tool designed to enhance the stability and reliability of your WordPress website. It automatically detects and deactivates plugins that cause fatal errors, preventing your entire site from crashing and becoming inaccessible.

### Key Features

* **Automatic Error Detection**: Monitors for fatal PHP errors in real-time using WordPress drop-in technology
* **Smart Plugin Identification**: Identifies which plugin caused the fatal error by matching the error's file path against your active plugins
* **Instant Deactivation**: Automatically deactivates the problematic plugin during the shutdown phase
* **Protected Plugins Allowlist**: Mark critical plugins (for example a checkout or payments plugin) that must never be deactivated automatically — the fatal is still logged and reported honestly
* **Log-Only Mode**: Optionally detect and log fatal errors without ever deactivating a plugin
* **Instant Email & Webhook Alerts**: Get notified within seconds of a fatal error — by email and/or a webhook (generic JSON or Slack-compatible) — including which plugin was deactivated, or an urgent warning when a protected plugin is still crashing. Identical repeated errors are rate-limited so a looping fatal can't flood your inbox or channel
* **Protection Watchdog**: An hourly background check verifies the protection drop-in end-to-end, silently reinstalls it if it went missing or was overwritten, detects when the plugin folder was moved or fatal error handling was disabled in wp-config.php, and alerts you when protection can't be restored automatically
* **Out-of-Memory Resilience**: A small reserved memory buffer lets the plugin log and alert even when the fatal error is PHP running out of memory
* **Protection-Status Visibility**: Admin warning, status banner with a "last verified" heartbeat, and a Site Health test that tell you when the protection drop-in is missing, replaced, or could not be installed — with one-click reinstall
* **Source-Aware Messaging**: Detects whether a fatal error came from a plugin, theme, must-use plugin, drop-in, or WordPress core, and reports the source honestly — it never claims to have resolved an error it could not act on
* **Detailed Admin Notifications**: Provides clear notifications about which plugin was deactivated and why
* **Persistent Error Logging**: Records every detected fatal error in a permanent log for troubleshooting, even when no plugin could be attributed
* **Error Log Management Page**: Dedicated admin page with an at-a-glance summary, source labels, and status badges to view, manage, and clear error history
* **Filter, Search & Export**: Filter the log by source and status, search by plugin/message/file, delete individual entries, and export everything to CSV or JSON — plus one-click copy of a ready-to-paste bug report
* **Repeat-Aware Logging**: Identical fatals are grouped into one entry with an occurrence count and first/last-seen times, so a looping error can't bury your history; each entry also records the request URL and PHP/WordPress version
* **Zero Configuration**: Works right out of the box with no setup required
* **Custom Error Page**: Displays a user-friendly error page with a reload button instead of the white screen of death
* **Debug-Aware Display**: Shows detailed error information on the front-end error page only when WP_DEBUG is on and WP_DEBUG_DISPLAY is not disabled (overridable with FPAD_SHOW_ERROR_DETAILS); errors are always logged regardless
* **Drop-in Management**: Automatically installs and manages WordPress fatal-error-handler.php drop-in

### How It Works

This plugin uses WordPress's built-in drop-in system to provide the most reliable error handling possible. When activated, it:

1. **Installs a Drop-in**: Creates a `fatal-error-handler.php` file in your wp-content directory
2. **Monitors for Errors**: WordPress automatically uses this drop-in when fatal errors occur
3. **Captures Error Details**: Records the error message, file, and line number during the shutdown phase
4. **Identifies the Plugin**: Matches the error's file path against your active plugins to determine which plugin caused the issue
5. **Deactivates Safely**: Automatically deactivates only the problematic plugin
6. **Logs Everything**: Stores detailed information about every fatal error in a permanent log for troubleshooting
7. **Notifies Admins**: Displays clear admin notices with error details when you next log in
8. **Shows User-Friendly Pages**: Displays a custom error page with reload button instead of the white screen of death

The drop-in approach ensures maximum reliability since it operates at the WordPress core level, even when other plugins fail.

### Use Cases

* **Development Environments**: Test new plugins without worrying about site crashes
* **Production Sites**: Add an extra layer of protection against unexpected plugin conflicts
* **Managed WordPress**: Essential tool for agencies and freelancers managing multiple client sites
* **WooCommerce Stores**: Prevent revenue loss from site downtime due to plugin errors

### Why You Need This Plugin

WordPress fatal errors can make your entire site inaccessible, requiring FTP or hosting panel access to fix. With Fatal Plugin Auto Deactivator, your site remains operational even when a plugin causes a critical error, giving you time to address the issue without emergency measures.

== Installation ==

1. Upload the `fatal-plugin-auto-deactivator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically install the required drop-in file (`fatal-error-handler.php`) in your wp-content directory
4. That's it! The plugin works automatically with no configuration needed

**Note**: The plugin requires write permissions to your wp-content directory to install the drop-in file. If activation fails, check your file permissions.

== Frequently Asked Questions ==

= Does this plugin require any configuration? =

No, Fatal Plugin Auto Deactivator works automatically right after activation with no configuration required.

= Will this plugin slow down my website? =

No, the plugin only activates its core functionality during the PHP shutdown phase and only takes action when a fatal error is detected.

= What types of errors does this plugin catch? =

The plugin catches fatal PHP errors, parse errors, and other critical errors that would normally crash your site.

= Will I be notified when a plugin is deactivated? =

Yes. An admin notice is displayed in your WordPress dashboard showing which plugin was deactivated and the specific error that caused it. Since 1.5.0 you can also enable instant notifications under Tools &rarr; Fatal Plugin Log &rarr; Settings: an email to one or more addresses and/or a webhook (generic JSON, or a Slack-compatible message you can point at a Slack/Discord/Mattermost incoming webhook). Alerts tell you what action was taken — a plugin that was automatically deactivated reads differently from a protected plugin that is still crashing and needs your attention. Identical repeated errors are rate-limited (configurable cooldown), and both channels have a "send test" button.

= What exactly do alert emails and webhooks contain? =

Emails and generic JSON webhooks carry the same details as the log entry: plugin name, outcome status, error type/message, file and line, the request URL, and PHP/WordPress versions. The Slack-compatible format is a compact summary — plugin, action taken, error, and file:line. That includes server file paths, so point alerts only at inboxes and endpoints you control. Webhook URLs must use https:// (plain http:// is allowed only for localhost). If a fatal happens so early that WordPress cannot send mail or HTTP requests yet, the alert is queued and delivered on the next normal page load.

= Can I reactivate a deactivated plugin? =

Yes, you can reactivate the plugin through the normal WordPress plugins page. However, be aware that if the issue hasn't been fixed, the plugin will be deactivated again if it causes another fatal error.

= Can I stop it from deactivating a specific plugin? =

Yes. Go to Tools &rarr; Fatal Plugin Log &rarr; Settings and add the plugin to the "Protected plugins" list. Protected plugins are never deactivated automatically — for example, you may prefer to keep a checkout or payments plugin running and fix it manually rather than have it switched off. The fatal error is still logged and an honest message is shown on the error page.

= Can I turn off automatic deactivation entirely? =

Yes. Enable "Log-only mode" under Tools &rarr; Fatal Plugin Log &rarr; Settings. The plugin will keep detecting and logging fatal errors and showing the custom error page, but it will never deactivate a plugin.

= How do I know the protection is actually working? =

The Fatal Plugin Log page shows a status banner ("Protection active" or a warning) with a "Protection last verified" heartbeat, and if the protection drop-in is missing, was replaced by another plugin, or could not be installed (for example because wp-content is not writable), you'll see an admin notice and a Site Health test telling you, with a one-click "Reinstall protection" button. Since 1.5.0 an hourly background watchdog also re-verifies protection even when nobody logs in: it reinstalls a missing drop-in automatically, detects when the plugin folder was moved or when the WP_DISABLE_FATAL_ERROR_HANDLER constant disables fatal error handling entirely, and notifies you (via your configured alert channels, or the admin email) when protection is lost and when it is restored. Note that WordPress cron runs on site traffic — on a site with no visitors at all, configure a real server cron for wp-cron.php as usual.

= Can I filter, search, or export the error log? =

Yes. The Fatal Plugin Log page lets you filter incidents by source (plugin, theme, must-use plugin, drop-in, core) and by status, search across the plugin name, error message, and file path, delete individual entries, and export the full log to CSV or JSON. Each row also has a "Copy" button that puts a ready-to-paste bug report (error, file, request URL, PHP/WordPress version) on your clipboard. Identical repeated fatals are grouped into a single row with an occurrence count.

= Does this work with multisite installations? =

The current version is designed for standard WordPress installations. Multisite support may be added in future updates.

= How does the plugin detect which plugin caused a fatal error? =

The plugin compares the file path of the fatal error against the directories of your active plugins. When the error originates inside an active plugin's folder, that specific plugin is deactivated. Errors originating outside the plugins directory — such as in a theme, a must-use plugin, a drop-in, or WordPress core — are still logged and shown on the custom error page, but nothing is deactivated.

= What happens if the error is not caused by a plugin? =

The plugin determines the source of the error from its file path (theme, must-use plugin, drop-in, or WordPress core) and shows an honest message explaining that the issue could not be resolved automatically and may require manual attention. It will never claim to have fixed an error it could not act on. The error is still recorded in the Fatal Plugin Log, marked as logged only.

= Will this plugin prevent all types of errors? =

This plugin specifically targets fatal PHP errors that would normally make your site inaccessible. It doesn't handle warnings, notices, or other non-fatal errors.

= What is a drop-in and why does this plugin use one? =

A drop-in is a special type of WordPress file that replaces core functionality. This plugin uses the `fatal-error-handler.php` drop-in to ensure it can handle errors even when other plugins fail. The drop-in is automatically installed when you activate the plugin and removed when you deactivate it.

= Will the drop-in conflict with other plugins? =

WordPress allows only one `fatal-error-handler.php` drop-in at a time. While this plugin is active, it installs and maintains its own drop-in, replacing any existing fatal error handler drop-in so that error handling stays reliable. When you deactivate or uninstall this plugin, it removes only its own drop-in and leaves any non-related drop-in untouched.

= Why do I see detailed error information sometimes but not others? =

For security reasons, detailed error information (file paths, line numbers, error messages) is only displayed on the front-end error page when WP_DEBUG is enabled and on-screen display is not turned off via WP_DEBUG_DISPLAY. (You can force details on or off with the FPAD_SHOW_ERROR_DETAILS constant.) Otherwise, visitors see a generic error message while administrators still receive detailed notifications in the dashboard. Every fatal error is always recorded in the Fatal Plugin Log (Tools &rarr; Fatal Plugin Log) regardless of the WP_DEBUG setting.

= Where are the error logs stored? =

Error logs are stored in your WordPress database as options. The plugin maintains both temporary logs (for admin notifications) and a permanent log (for troubleshooting history) viewable under Tools &rarr; Fatal Plugin Log. Every detected fatal error is recorded in the permanent log, including errors that could not be attributed to an active plugin (such as those originating in a theme or in WordPress core) — those are marked as logged only, with no plugin deactivated.

== Screenshots ==

1. Fatal error detected. Problematic plugin auto-deactivated (requires WP_DEBUG true).
2. Fatal error detected. Problematic plugin auto-deactivated (WP_DEBUG is false).
3. Admin notification showing a deactivated plugin and error details
4. Plugin causing fatal error was auto-deactivated for site safety.

== Changelog ==

= 1.5.0 - 15/08/2026 =
- Added: Instant alerts — get an email and/or webhook (generic JSON or Slack-compatible) the moment a fatal error is detected, saying which plugin was involved and what action was taken; identical repeats are rate-limited by a configurable cooldown, and alerts that fire too early for WordPress to deliver are queued and sent on the next page load
- Added: "Send test email" / "Send test webhook" buttons on the Settings tab
- Added: Protection watchdog — an hourly background check that re-verifies the protection drop-in, silently reinstalls it when missing, reclaims it once if another plugin overwrote it, and alerts you when protection is lost or restored
- Added: Two new protection statuses — "disabled" (fatal error handling turned off via WP_DISABLE_FATAL_ERROR_HANDLER) and "stranded" (the plugin folder was moved/renamed so the drop-in points at nothing) — surfaced in the banner, admin notice, and Site Health
- Added: "Protection last verified" heartbeat on the log page and in Site Health debug info
- Added: Out-of-memory resilience — a small reserved memory buffer lets logging and alerts work even when the fatal is a memory-exhaustion error
- Added: The plugin's first public filter, fpad_watchdog_interval, to change the watchdog cadence
- Fixed: Fatal errors that happen very early in WordPress's startup — in a caching, object-cache, database, or multisite drop-in — no longer resulted in a blank white page. These are now logged, alerted, and shown on the custom error page like any other fatal

= 1.4.0 - 23/07/2026 =
- Added: Filter the Fatal Plugin Log by source and status, and search across plugin name, error message, and file path
- Added: Delete individual log entries (in addition to clearing the whole log)
- Added: Export the log to CSV or JSON, and a per-row "Copy" button that produces a ready-to-paste bug report
- Added: Identical, repeated fatals are now grouped into a single entry with an occurrence count and first/last-seen times, so one looping error can no longer evict the rest of your history
- Added: Each log entry now records the request URL and the PHP and WordPress versions for easier reproduction
- Improved: Stored error messages are bounded in length to keep the log option small
- Changed: The Fatal Plugin Log screen now hides admin notices from other plugins and WordPress core so it stays focused (the plugin's own protection status and action messages still appear)

= 1.3.0 - 24/06/2026 =
- Added: Protected plugins allowlist — choose plugins that must never be deactivated automatically, even if they cause a fatal error (the error is still logged and reported honestly)
- Added: Log-only mode — detect and log fatal errors without ever deactivating a plugin
- Added: Settings tab on the Fatal Plugin Log page for the allowlist and log-only mode
- Added: Protection-status visibility — an admin warning, a status banner, and a Site Health test when the protection drop-in is missing, replaced by another plugin, or could not be installed, with a one-click "Reinstall protection" action
- Added: A Site Health "debug information" section summarizing protection status, settings, and recent fatals
- Improved: The log now records a status (Deactivated / Protected / Log only / Logged only) for each fatal
- Improved: Hardened drop-in ownership checks and the clear-log action

= 1.2.1 - 20/06/2026 =
- Fixed: Plugin attribution now normalizes file paths and handles single-file plugins, so Windows and symlinked installs — and single-file plugins like Hello Dolly — are matched correctly instead of silently skipped
- Fixed: Activation no longer triggers a fatal on hosts that require FTP/SSH filesystem credentials (the drop-in installer now fails gracefully)
- Fixed: The custom error page now honors WP_DEBUG_DISPLAY (and a new FPAD_SHOW_ERROR_DETAILS override), so technical details are no longer exposed to visitors under the recommended production logging setup (WP_DEBUG on, WP_DEBUG_DISPLAY off)
- Fixed: The error page no longer emits output when HTTP headers have already been sent, and now steps aside during WordPress's plugin/theme editor syntax checks
- Fixed: Escaped the deactivated plugin name and version on the error page
- Fixed: The log viewer's "Source" label now matches the error page for every WordPress drop-in
- Fixed: Corrected the invalid "Tested up to" header value

= 1.2.0 - 13/06/2026 =
- Added: Source-aware error messages that detect whether a fatal error came from a plugin, theme, must-use plugin, drop-in, or WordPress core
- Added: "Source" column, summary cards, and status badges on the Fatal Plugin Log page
- Changed: Every detected fatal error is now recorded in the permanent log, including errors that could not be attributed to a plugin
- Changed: The custom error page no longer claims an issue was resolved when no plugin was deactivated
- Improved: The fatal error handler now catches any Throwable to avoid breaking the shutdown phase
- Few minor bug fixes & improvements

= 1.1.0 - 01/06/2025 =
- Added: New "Fatal Plugin Log" admin subpage under Tools for comprehensive error management
- Added: Detailed log table showing date, time, plugin, file, line number, and error message
- Added: "Clear Log" functionality with nonce protection
- Added: "View Log" action link on plugin list for quick access
- Added: Enhanced error type detection (Fatal, Parse, Core, etc.)
- Added: Security checks during drop-in file install and management
- Improved: Validation, sanitization, and formatting of error messages with monospace font
- Improved: UI styling using WordPress admin standards and alternating row colors
- Improved: Codebase optimized for better performance
- Few minor bug fixes & improvements

= 1.0.1 - 25/05/2025 =
- Improved: Security Enhancement
- Few minor bug fixes & improvements

= 1.0.0 - 22/05/2025 =
- Initial release

== Upgrade Notice ==

= 1.5.0 =
Instant email/webhook (Slack-compatible) alerts when a fatal is detected, an hourly protection watchdog that self-heals and warns you when protection lapses, and out-of-memory resilience. Alerts are opt-in — nothing changes until you enable a channel.

= 1.4.0 =
The log viewer gains filtering, search, per-entry delete, CSV/JSON export, and copy-to-clipboard bug reports. Repeated fatals are now grouped with an occurrence count so a looping error can't bury your history.

= 1.3.0 =
New: protect critical plugins from auto-deactivation, a log-only mode, and clear protection-status warnings (admin notice + Site Health) so you always know your site is covered.

= 1.2.1 =
Reliability and security fixes: correct plugin attribution (Windows/symlinked/single-file plugins), no activation crash on FTP-credentialed hosts, and the error page no longer leaks technical details when WP_DEBUG_DISPLAY is off.

= 1.2.0 =
This update logs every fatal error, detects its source (plugin, theme, drop-in, or core), and shows honest, source-aware messages. Recommended for all users.

= 1.1.0 =
We have added a dedicated admin subpage for viewing and managing error logs. Please update to the latest version.
