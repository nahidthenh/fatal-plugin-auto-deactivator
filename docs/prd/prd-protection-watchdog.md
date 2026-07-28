# PRD: Protection Watchdog — Cron-Based Drop-in Health Monitoring

- **Status:** Proposed
- **Priority:** P1 (small effort, closes a real protection gap)
- **Suggested milestone:** 1.5.0 (pairs naturally with Instant Alerts)
- **Date:** 2026-07-28

## 1. Summary

The plugin's protection can silently lapse: today the drop-in is only re-checked on `admin_init`, i.e. **when somebody visits wp-admin**. On a site nobody logs into for weeks (brochure sites, client sites between maintenance windows — exactly the sites this plugin serves best), a deleted or foreign-overwritten drop-in means zero protection with zero signal. Add a WP-Cron watchdog that periodically verifies protection end-to-end, self-heals when possible, and raises an alert when it can't.

## 2. Background & evidence

- Current self-heal paths ([architecture.md](../architecture.md#drop-in-lifecycle-three-reinstall-paths)) all require either an admin page load or a plugin update event. Front-end traffic never triggers a check.
- Real-world ways protection lapses without anyone noticing:
  1. Another plugin or a "cleanup" tool overwrites/deletes `wp-content/fatal-error-handler.php` (status `foreign`/`missing`).
  2. Host migration / restore from backup omits the drop-in.
  3. `WP_DISABLE_FATAL_ERROR_HANDLER` added to `wp-config.php` (drop-in never invoked at all — currently **not surfaced anywhere** in the plugin UI).
  4. The plugin directory is renamed/moved manually, stranding the drop-in's `require` path — the drop-in exists and looks "active" but would fatal when invoked.
- Agency monitoring guides ([Modular DS](https://modulards.com/wordpress-site-monitoring-tools/), [MainWP](https://mainwp.com/)) emphasize *continuous* checks precisely because owner-visits are rare on managed sites.

Case 4 is nasty: `get_status()` returns `active` (file exists, marker present) while the protection is actually broken. No current code validates the require target.

## 3. Goals / Non-goals

**Goals:** detect all four lapse modes within the watchdog interval; auto-repair modes 1–2 (reinstall); alert on unrepairable modes (via Instant Alerts channels when configured, `wp_mail` to admin email otherwise); surface everything in the existing status banner / Site Health.

**Non-goals:** real-time filesystem watching; external uptime monitoring; guaranteeing WP-Cron itself runs (documented limitation + recommendation of a real cron trigger).

## 4. User stories

- As an **agency**, a client site whose drop-in was clobbered by another plugin repairs itself within an hour and pings my Slack, instead of being unprotected until the next maintenance login.
- As a **site owner** whose host disabled the fatal handler via `wp-config.php`, I see a clear warning naming `WP_DISABLE_FATAL_ERROR_HANDLER` instead of believing I'm protected.

## 5. Functional requirements

### FR-1: Scheduled check

- New cron hook `fpad_watchdog_check`, scheduled `hourly` on activation (`FPAD_Plugin_Lifecycle::activate()`), cleared on deactivation and uninstall. Also schedule from `check_dropin()` if missing (heals installs upgraded from older versions without re-activation).
- Callback `FPAD_Plugin_Lifecycle::watchdog_check()` (new static method; registered in `FPAD_Plugin_Lifecycle::init()`).

### FR-2: End-to-end verification (extend `FPAD_Dropin_Manager`)

New public method `verify_protection()` returning `array( 'status' => string, 'detail' => string )` with statuses (superset of `get_status()`):

| Status | Meaning | New? |
|--------|---------|------|
| `active` | Drop-in ours **and** its `require` target resolves | — |
| `foreign` / `missing` / `unwritable` / `no_filesystem` | as today | — |
| `disabled` | `WP_DISABLE_FATAL_ERROR_HANDLER` is defined truthy | **new** |
| `stranded` | Drop-in ours but `FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php'` (as the drop-in would compute it: `WP_CONTENT_DIR . '/plugins/fatal-plugin-auto-deactivator/includes/class-fatal-error-handler.php'`) is not a readable file | **new** |

`get_status()` keeps its current contract; `verify_protection()` wraps it and adds the two new checks (checked first: `disabled`, then existing statuses, then `stranded`). All existing consumers (`FPAD_Admin::get_protection_state()`, Site Health) switch to `verify_protection()` — `protection_message()` gains copy for `disabled` and `stranded`.

### FR-3: Watchdog behavior

```
status = verify_protection()
if active            → clear incident state, done
if missing|foreign   → attempt remove_dropin()+install_dropin(); re-verify; if healed → log recovery, done
if still not active  → record incident + alert (once per distinct status per 24h)
```

- Incident state in new option `fpad_watchdog_state`: `array( 'status' => …, 'since' => ts, 'last_alert' => ts, 'last_check' => ts )`.
- Alerting: if the Instant Alerts feature (see [prd-instant-alerts-email-webhook.md](prd-instant-alerts-email-webhook.md)) is present and configured, send through `FPAD_Notifier` with event `fpad.protection_lost` / `fpad.protection_restored`; otherwise fall back to a direct `wp_mail()` to `admin_email`. Alert copy includes the status, the explanation from `protection_message()`, and the reinstall link.
- `foreign` **must not** be auto-healed silently more than once per 24h (two plugins fighting over the slot would loop): after one failed reclaim (foreign again at next check), stop reinstalling, keep alerting. Track reclaim attempts in `fpad_watchdog_state`.

### FR-4: Surfacing

- Log page banner + site-wide notice + Site Health test automatically benefit via `verify_protection()` (FR-2).
- Site Health debug section (`add_debug_information()`) adds `last_watchdog_check` timestamp.
- Log tab: small "Protection last verified: {relative time}" line under the banner.

### FR-5: WP-Cron reliability note

Readme + docs must state: WP-Cron fires on traffic; on zero-traffic sites configure a real cron hitting `wp-cron.php` (standard WP guidance). The watchdog is a *detection* layer, not a guarantee.

## 6. Data model

| Option | Contents |
|--------|----------|
| `fpad_watchdog_state` (new) | status/since/last_alert/last_check/reclaim_attempts; deleted on uninstall |

Cron: `fpad_watchdog_check` hourly; cleared on deactivate/uninstall.

## 7. Technical constraints

- Runs in normal (cron) context — no shutdown guards needed, but must be safe when called concurrently with `admin_init` `check_dropin()` (both paths are idempotent copy/delete; acceptable).
- `stranded` path check must mirror how the drop-in actually computes `FPAD_PLUGIN_DIR` (relative to `WP_CONTENT_DIR`), **not** the main plugin's `FPAD_PLUGIN_DIR` constant — on symlinked installs these can differ; comment this in code and add to the sync-pairs table.
- PHP 7.0, WPCS, no new deps.

## 8. Acceptance criteria

1. Delete the drop-in, run `wp cron event run fpad_watchdog_check` → drop-in reinstalled, no alert.
2. Replace the drop-in with a foreign file → first run reclaims it; make it foreign again → second run alerts (email or configured channel) and stops fighting; banner shows `foreign`.
3. Define `WP_DISABLE_FATAL_ERROR_HANDLER` → status `disabled` everywhere (banner, notice, Site Health critical, alert once/24h); message names the constant.
4. Rename the plugin directory → status `stranded` detected and alerted (restore directory → recovery alert + `active`).
5. Fresh activation schedules exactly one `fpad_watchdog_check`; deactivation removes it; uninstall removes it + `fpad_watchdog_state`.
6. Upgrade from 1.4.0 without re-activation: event self-schedules on next `admin_init`.
7. No behavior change for sites where everything is healthy (state option written at most once per run).

## 9. Docs & release tasks

feature-map.md (new rows + sync pair: drop-in path computation mirror), reference.md (new statuses in `get_status()`/`verify_protection()` table, new option, new cron hook), architecture.md entry-points table, development.md test scenarios, readme.txt (feature bullet, FAQ "How do I know the protection is actually working?" gains the watchdog, changelog, version ×3).

## 10. Risks & open questions

- **Risk:** two FPAD-like plugins reclaim-looping — mitigated by FR-3 back-off.
- **Open:** interval setting (fixed hourly vs configurable)? Proposed: fixed hourly for v1; add a filter `fpad_watchdog_interval` (this would be the plugin's first public filter — document it in reference.md "hooks provided").
