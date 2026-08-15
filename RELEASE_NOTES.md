# Fatal Plugin Auto Deactivator 1.5.1 — Release Notes

Released 2026-08-15 · Requires WordPress 5.3+ / PHP 7.0+ · [Full changelog](readme.txt)

A patch release with one real fix: fatal errors coming from a **symlinked plugin folder** are now attributed to the right plugin and auto-deactivated, instead of being logged as "could not be attributed" while the crashing plugin kept taking the site down.

## 🐛 Fixed: symlinked plugins were never attributed

Plugin folders are often symbolic links — local dev setups that share one checkout across several sites, Bedrock/Composer installs, and staging environments that link a shared plugin directory.

PHP reports the **resolved** (symlink-free) path in the fatal error, while `WP_PLUGIN_DIR` holds the logical one. Attribution compared only the logical path, so those two spellings never lined up:

```
error file : /srv/shared/plugins/acme-seo/includes/meta.php     ← what PHP reports
plugin dir : /srv/site-a/wp-content/plugins/acme-seo/           ← what we compared against
```

The result: the custom error page appeared and the incident was logged, but with **no plugin named and nothing deactivated** — so every reload crashed again.

Attribution now compares both spellings on both sides. The same fix applies to source classification, including plugins and themes that are symlinked in individually (those resolve to a path outside `wp-content` entirely, which no prefix test against the plugins/themes root could ever see).

Sibling-name safety is unchanged — `akismet` still cannot claim a fatal from `akismet-pro`.

## 🐛 Fixed: "Unknown" source in the log viewer

Log entries for symlinked plugins, must-use plugins, and themes were labelled **Unknown** in the Source column and in CSV/JSON exports. They are now classified correctly, for existing log entries too — the fix is in the classifier, not in what gets stored.
- **Drop-in crashes are logged now, not swallowed.** A fatal inside `advanced-cache.php`, `object-cache.php`, `db.php` or `sunrise.php` happens before WordPress has finished loading its own options API — so there was no way to write the log entry, and the incident disappeared entirely. The handler now restores just enough of WordPress (core's own `$wpdb` and object-cache fallbacks, the ones core would have used had the drop-in not crashed) to record it. All four drop-in types verified end to end.
- **`WP_DEBUG_DISPLAY` no longer suppresses the error page.** With it enabled, PHP prints its own raw error — server paths, full stack trace — *before* any shutdown handler runs. That hid the custom page entirely and, worse, left the response as **HTTP 200**: browsers, caches, and uptime monitors all saw a crashed page as a healthy one. The drop-in now takes an output buffer when errors are set to display, so the raw trace is replaced by the branded page and the 500 is sent correctly. Nothing changes when errors are not displayed, which is the usual production setting.
- **Very early fatals are no longer silent:** WordPress registers its fatal error handler before it loads its own options and escaping APIs and before `WP_PLUGIN_DIR` exists, so a crash in a caching (`advanced-cache.php`), object-cache, database (`db.php`) or multisite (`sunrise.php`) drop-in used to leave the handler unable to run — a plain blank page, with nothing logged and nothing sent. Worse, a broken `object-cache.php` aborts WordPress *before* `wp_cache_get()` exists, so `esc_html()` and friends are present but throw the moment they look up an option — a `function_exists()` check cannot see that. The handler now degrades gracefully in that whole window and still renders the branded 500 page (the fatal is reported as unattributed, since no plugin has loaded that early). Each step of the handler is also isolated, so a third-party hook throwing during deactivation or an option write can no longer cost the visitor the page. *Caveat:* when the options API itself is what broke, no log entry or alert is possible — the page is the deliverable.
- **The foreign-drop-in back-off now actually holds.** The watchdog reclaims a drop-in another plugin overwrote at most once per 24 h, but `wp-admin` was reinstalling it on *every* page load, so on any site an administrator uses, two plugins could quietly fight over the slot forever and the conflict was never surfaced. Both paths now share one back-off; when it holds you get the status banner and its one-click **Reinstall protection** action instead (that button is an explicit choice, so it always works).

## Upgrade notes

Nothing to do. No settings, options, or stored log entries change, and no action is required after updating.

## For developers

Three new public static helpers on `FPAD_Fatal_Error_Handler`, safe to call in the shutdown context (core PHP only, no WordPress API):

- `path_variants( $path )` — the normalized path plus its `realpath()` counterpart when the two differ.
- `path_is_inside( $files, $dir )` — whether any variant of a file sits under `$dir`, either spelling.
- `matches_symlinked_child( $files, $root )` — whether a file lives inside a symlinked direct child of `$root`.

No hooks, options, or behavior outside attribution changed.
