# Fatal Plugin Auto Deactivator 1.5.1 — Release Notes

Released 2026-08-03 · Requires WordPress 5.3+ / PHP 7.0+ · [Full changelog](readme.txt)

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

## Upgrade notes

Nothing to do. No settings, options, or stored log entries change, and no action is required after updating.

## For developers

Three new public static helpers on `FPAD_Fatal_Error_Handler`, safe to call in the shutdown context (core PHP only, no WordPress API):

- `path_variants( $path )` — the normalized path plus its `realpath()` counterpart when the two differ.
- `path_is_inside( $files, $dir )` — whether any variant of a file sits under `$dir`, either spelling.
- `matches_symlinked_child( $files, $root )` — whether a file lives inside a symlinked direct child of `$root`.

No hooks, options, or behavior outside attribution changed.
