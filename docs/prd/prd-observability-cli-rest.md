# PRD: Observability — WP-CLI Commands & REST Status Endpoint

- **Status:** Proposed
- **Priority:** P2 (agency/fleet play; grows installs through tooling integration)
- **Suggested milestone:** 1.6.0
- **Date:** 2026-07-28

## 1. Summary

Expose the plugin's two core datasets — protection status and the fatal-error log — to machines: a `wp fpad` WP-CLI command family and a read-only authenticated REST namespace `fpad/v1`. This makes the plugin scriptable for agencies managing fleets (MainWP/ManageWP-style dashboards, Uptime-Kuma-style monitors, deploy pipelines) instead of wp-admin-only.

## 2. Background & market evidence

- Agencies run WordPress at fleet scale through [MainWP](https://mainwp.com/) (self-hosted dashboard + extensions) and ManageWP; monitoring guides for agencies ([Modular DS](https://modulards.com/wordpress-site-monitoring-tools/), [wpcto](https://wpcto.net/wordpress-insights/monitoring-security-in-wordpress-agency-guide-2026/)) treat PHP-error visibility as a core need, and [WP Remote sells PHP error monitoring](https://wpremote.com/php-error-monitoring/) as a product. All of these consume machine interfaces (REST/CLI), not wp-admin screens.
- [Error Log Monitor](https://wordpress.org/plugins/error-log-monitor/) (100k+ installs) monetizes exactly this progression: free dashboard widget → Pro adds programmatic/summary access. Our data is *higher-signal* than a raw error log (attributed plugin + action taken).
- Deploy pipelines (wp-cli is the standard automation surface) want a post-deploy gate: "did anything fatal after release? is protection active?" — currently answerable only by scraping wp-admin.
- Prior art inside the plugin: Site Health test + debug info already expose this data to humans; this PRD is the machine-readable equivalent.

## 3. User stories

- As an **agency**, my dashboard polls `GET /wp-json/fpad/v1/status` on 200 client sites and flags any site where protection ≠ active or fatals occurred in the last 24 h.
- As a **DevOps engineer**, my deploy script runs `wp fpad log list --since=15min --format=count` after a release and rolls back if non-zero.
- As a **support tech**, I run `wp fpad status` over SSH on a broken site (wp-admin may be unusable) and immediately see protection state + last fatal.
- As a **monitoring hobbyist**, Uptime Kuma checks the status endpoint with a token and alerts on `"protection":"missing"`.

## 4. Goals / Non-goals

**Goals:** full read access + the same mutations the admin UI offers (clear/delete/export log, reinstall drop-in, get/set the two settings) via CLI; read-only REST for status+log; zero new attack surface by default (REST opt-in).

**Non-goals:** REST mutations (v1 is read-only; CLI covers automation writes over SSH); a hosted dashboard; webhooks (covered by the Instant Alerts PRD); Prometheus exposition format (future).

## 5. Functional requirements

### FR-0: Shared data layer refactor (prerequisite)

Log reading/filtering/serializing currently lives as private methods inside `FPAD_Admin` (`filter_log()`, `source_key()`, `entry_status()`, `get_error_type_string()`, CSV assembly in `export_log()`). Extract into a new static class `FPAD_Log_Store` (`includes/class-log-store.php`, required from the bootstrap):

- `get_entries( array $args )` — args: `source`, `status`, `search`, `since` (ts), `limit`, `offset`; returns normalized entries (legacy-entry inference applied, so consumers never see schema drift).
- `delete_entry( $key )`, `clear()`, `entry_key( $entry )`, `to_csv_row( $entry )`, `summarize( $entries )` (the totals the summary cards show).
- `FPAD_Admin` becomes a thin consumer (its private helpers delegate or move). **This is a behavior-preserving refactor** — the Log tab and exports must render identically after it (acceptance #1). Update the sync-pairs table: the `source_key` mirror discussion moves with the code.

### FR-1: WP-CLI — `wp fpad`

New file `includes/class-cli.php`, loaded from the bootstrap only when `defined( 'WP_CLI' ) && WP_CLI`; registers via `WP_CLI::add_command( 'fpad', 'FPAD_CLI' )`.

| Command | Behavior |
|---------|----------|
| `wp fpad status [--format=table\|json\|yaml]` | Protection status (from `verify_protection()` if the Watchdog PRD landed, else `get_status()`), drop-in path, settings summary, log counts, last-fatal timestamp. Exit code 0 when `active`, 1 otherwise (scriptable gate) |
| `wp fpad log list [--source=…] [--status=…] [--search=…] [--since=…] [--limit=…] [--format=table\|json\|csv\|count\|ids]` | Filtered entries via `FPAD_Log_Store::get_entries()`; `--since` accepts `strtotime`-parseable strings ("15min", "2026-07-01"); `ids` prints entry keys for use with delete |
| `wp fpad log delete <key>` | Delete one entry (same semantics as admin per-entry delete) |
| `wp fpad log clear [--yes]` | Clear the log (`WP_CLI::confirm` unless `--yes`) |
| `wp fpad log export --file=<path> [--format=csv\|json]` | Write export using the same columns as the admin export |
| `wp fpad settings get [<key>]` / `wp fpad settings set <key> <value>` | Read/write `log_only` (`on\|off`) and `protected_plugins` (comma-separated basenames, validated against installed plugins; `--append` flag for add-one) |
| `wp fpad reinstall` | Remove+install the drop-in via `FPAD_Dropin_Manager`; report resulting status |

Conventions: `WP_CLI\Utils\format_items` for output; `WP_CLI::success/warning/error`; docblock synopses so `wp help fpad …` works; every mutation logs nothing new (parity with admin actions).

### FR-2: REST — namespace `fpad/v1` (opt-in)

New file `includes/class-rest.php` (`FPAD_REST::init()` from bootstrap; registers on `rest_api_init`). Disabled unless enabled in Settings (new `fpad_settings` key `rest_enabled`, default `false`).

| Route | Method | Returns |
|-------|--------|---------|
| `/fpad/v1/status` | GET | `{ protection, dropin_path_ok, settings: {log_only, protected_count, rest_enabled}, log: {entries, occurrences, last_fatal_ts, deactivated_24h, fatals_24h}, versions: {fpad, php, wp} }` |
| `/fpad/v1/log` | GET | Paginated entries (`source`, `status`, `search`, `since`, `page`, `per_page` ≤100 params) via `FPAD_Log_Store`; `X-WP-Total`/`X-WP-TotalPages` headers |

- **Auth:** `permission_callback` requiring `current_user_can( 'manage_options' )` — satisfied remotely via [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (core since 5.6, inside our 5.3 floor via feature-detect: only document App-Passwords auth for WP ≥5.6).
- **Plus optional token auth** for dumb monitors that can't do Basic auth per-user: setting `rest_token` (generated via `wp_generate_password( 32, false )`, shown once, stored hashed with `wp_hash()`); requests may send `X-FPAD-Token`; compare with `hash_equals`. Token grants **read-only** access to these two routes only. Regenerate/revoke buttons in Settings.
- Full JSON schema declared in `register_rest_route` args (types, enums, sanitize/validate callbacks) so the routes are self-documenting via OPTIONS.
- Error entries in REST/CLI output include `key` (from `FPAD_Log_Store::entry_key`) so consumers can correlate/delete.

### FR-3: Settings UI

Settings tab, "Remote access" section: REST enable checkbox (description spells out what's exposed: error messages and file paths — treat the token like a password); token display-once/regenerate/revoke; read-only hint that WP-CLI needs no configuration.

## 6. Data model

`fpad_settings` gains `rest_enabled` (bool, default false) and `rest_token_hash` (string, ''). Both mirrored in the handler's `get_settings()`? **No** — the shutdown handler never needs them; only `FPAD_Admin::get_settings()` grows. Document that the two `get_settings()` mirrors now intentionally diverge (admin superset) — update the sync-pair entry accordingly. Uninstall deletes nothing new (keys live inside existing option).

## 7. Technical constraints

- Nothing here touches the shutdown path.
- PHP 7.0, WPCS. CLI class must not load in normal web requests (guarded require).
- REST responses must apply the same output boundedness as the log (entries are already capped/truncated at write time).
- Don't break the WP.org plugin-check: REST routes need sanitize/validate callbacks on every arg.

## 8. Security

- REST default-off; both auth paths documented; token stored hashed, compared constant-time; capability path unchanged from admin parity (`manage_options`).
- Log contents include absolute server paths and error messages — the enable-checkbox copy must say so (informed consent), mirroring how the Instant Alerts PRD words webhook payloads.
- Rate limiting deferred to the server/WAF layer (documented), as core REST does.
- CLI commands inherit shell-user trust; no extra gating needed (standard WP-CLI posture).

## 9. Acceptance criteria

1. After FR-0 refactor alone: Log tab, filters, summary, CSV/JSON export byte-identical to before (regression fixture: seeded log option → compare rendered HTML + export files).
2. `wp fpad status` exit codes: 0 active / 1 otherwise; `--format=json` parses.
3. `wp fpad log list --status=deactivated --since=24h --format=count` returns correct count against a seeded log.
4. `wp fpad log delete <key>` removes exactly the entry the admin UI would; `clear --yes` empties.
5. `wp fpad settings set log_only on` flips the same option byte the admin form writes; invalid protected basename rejected with a helpful error.
6. REST disabled ⇒ routes return 404 (not registered). Enabled ⇒ anonymous request 401; app-password `manage_options` user 200; valid token 200; token after revoke 401.
7. `/status` payload matches schema; `/log` pagination headers correct.
8. OPTIONS on both routes returns full arg schema.
9. `php -l` clean on PHP 7.0; plugin passes the WP.org Plugin Check tool with no new errors.

## 10. Docs & release tasks

feature-map.md (new platform rows: CLI/REST; sync-pair updates from FR-0 and §6), reference.md (command table, route/schema/auth reference, settings keys — and delete the "no REST endpoints" absolutes in docs/README/CLAUDE.md), architecture.md entry-points table (+CLI/REST), development.md (how to test CLI + REST locally), readme.txt (feature bullets, FAQ "Can I monitor this remotely?", changelog, version ×3).

## 11. Risks & open questions

- **Risk:** refactor regressions in the log UI — mitigated by acceptance #1 fixture-diff.
- **Risk:** token leakage → read access to error details; mitigated by read-only scope, hashing, revoke UX, and default-off.
- **Open:** also register a WP Site Health "async" test for remote pings? Proposed: no, `/status` covers it.
- **Open:** MainWP extension as a separate deliverable consuming `/status`? Out of scope here; the endpoint is designed so one can be built without plugin changes.
