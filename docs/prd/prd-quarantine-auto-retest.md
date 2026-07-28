# PRD: Quarantine & Canary Auto-Retest of Deactivated Plugins

- **Status:** Proposed
- **Priority:** P3 (high differentiation, highest risk — ship after Alerts + Watchdog exist)
- **Suggested milestone:** 1.7.0
- **Date:** 2026-07-28

## 1. Summary

Today, deactivation is a one-way door: the plugin kills the crasher and a human must remember to investigate, fix, and reactivate. Introduce a **quarantine** concept: every auto-deactivated plugin gets a tracked quarantine record with a one-click, loopback-verified **"Retest & restore"** action in wp-admin — and an opt-in **canary mode** that retests automatically on a backoff schedule and restores the plugin if the site stays healthy. This mirrors the loopback-test pattern WordPress core itself validated for update rollbacks, applied to the runtime fatals core doesn't cover.

## 2. Background & market evidence

- WordPress core normalized exactly this mechanic for a *different* failure window: since 6.3, failed **manual** plugin updates roll back, and the Rollback Auto-Update feature ([merge proposal](https://make.wordpress.org/core/2024/04/19/merge-proposal-rollback-auto-update/), [Trac #58281](https://core.trac.wordpress.org/ticket/58281), [feature plugin](https://github.com/WordPress/rollback-update-failure/blob/main/README.md)) performs a **loopback request to the home page and reverts the plugin if a fatal is observed**, emailing the admin. Core's own design proves loopback-verified activation state changes are a sound, shippable pattern.
- Core's coverage stops at **update time**. Runtime fatals — triggered by traffic hitting a rare code path, a third-party API change, an option/state change, memory pressure, a conflict with *another* plugin's update — are precisely what this plugin catches, and for those there is **no restore story anywhere in the ecosystem** (searches surface only manual processes: [recovery guides](https://andrewbaker.ninja/2026/03/02/you-just-uploaded-a-new-plugin-and-your-wordpress-site-just-crashed-now-what/), the readme FAQ of every troubleshooter).
- Real transient-fatal scenarios where auto-restore is correct: the crashing plugin (or its conflicting neighbor) gets updated with a fix; a saturated `memory_limit` episode passes; an external API that fataled during an outage recovers; a bad option value gets corrected.
- Risk context: our own FAQ warns users that reactivating an unfixed plugin re-crashes — which is *safe* under this plugin (it just gets deactivated again + logged), making a supervised retest uniquely low-stakes here compared to a site without FPAD.

## 3. Problem / user stories

- As a **site owner**, after the fatal I see *what was turned off, when, and why* in one "Quarantine" list, instead of reverse-engineering the plugins screen against the log.
- As a **support tech**, after deploying a fix I click "Retest & restore" and the plugin comes back only if the site verifiably doesn't crash.
- As an **agency running canary mode**, a plugin deactivated at 2 a.m. because a payment API was down is automatically restored at 3 a.m. when the retest passes — with an alert either way.
- As a **skeptic**, none of this happens unless I opt in per the mode I choose; a plugin that keeps failing stops being retried.

## 4. Goals / Non-goals

**Goals:** quarantine visibility for every auto-deactivation; manual loopback-verified restore; opt-in scheduled canary retest with exponential backoff and a hard attempt cap; alerts on every transition (restored / still failing / gave up); flap-proof.

**Non-goals:** restoring plugins deactivated *manually* by users; testing beyond a home-page loopback (no crawler); fixing plugins; version rollback of the plugin's code (core + dedicated rollback plugins own that); multisite semantics (defer to the Multisite PRD).

## 5. Functional requirements

### FR-1: Quarantine records

When `FPAD_Fatal_Error_Handler::deactivate_plugin()` succeeds, additionally append (guarded, same option-write pattern as the log) to new option `fpad_quarantine`:

```php
array(
    'plugin'        => 'bad-plugin/bad-plugin.php',
    'plugin_name'   => 'Bad Plugin',
    'fingerprint'   => '<log_fingerprint of the triggering entry>',
    'quarantined_at'=> 1753700000,
    'attempts'      => 0,           // completed retests
    'next_retest'   => 0,           // 0 = canary off or exhausted; else ts
    'state'         => 'quarantined', // quarantined | restored | exhausted | dismissed
    'last_result'   => '',          // '', 'passed', 'failed:<reason>'
)
```

One record per plugin (re-fatal of an already-quarantined plugin updates the record: bump `quarantined_at`, reset per-episode fields). Cap 25 records; `restored`/`dismissed` records pruned after 30 days by the scheduler.

### FR-2: Retest procedure (shared by manual & canary)

New class `FPAD_Quarantine` (`includes/class-quarantine.php`, static, bootstrap-required, normal context only):

```
retest( $plugin ):
 1. Preconditions: plugin file exists; not currently active; record exists; site currently healthy
    (no NEW log entries in last 60s). Else abort with reason.
 2. Snapshot marker: $before = latest log entry key/time.
 3. activate_plugin( $plugin, '', false, true )   // $silent=true — skip activation hooks, mirroring
                                                   // core recovery-mode resume semantics
    On WP_Error → record 'failed:activation_error', re-deactivate defensively, done.
 4. Loopback: wp_remote_get( home_url('/'), timeout 10, sslverify false, cookies: none,
    headers: X-FPAD-Canary: 1 ) — same self-request technique as core's
    rollback/site-health loopback.
 5. Verdict:
    - HTTP 500–599, or a NEW fpad_deactivation_log entry appeared since $before
      (our own shutdown handler is the most reliable fatal detector — if the retest
      crashed, the handler already re-deactivated the plugin and logged it) → FAILED.
    - Otherwise, second loopback to home_url('/?fpad_canary=' . time()) to dodge page caches;
      both OK → PASSED.
 6. PASSED → state 'restored', keep plugin active, alert "restored".
    FAILED → ensure deactivated (the handler usually already did; else deactivate_plugins()),
    attempts++, schedule next per backoff, alert "retest failed".
```

Backoff: `1h → 6h → 24h → 72h → exhausted` (state `exhausted`, `next_retest = 0`, alert "giving up; manual attention needed"). Constants in code, filterable (`fpad_quarantine_backoff`).

Concurrency: transient lock `fpad_retest_lock` (5 min) so cron + manual click can't overlap; never retest more than one plugin per scheduler run (a retest window of one keeps blame unambiguous).

### FR-3: Manual "Retest & restore"

Quarantine table (FR-5) row action → `admin_post_fpad_retest` (POST, cap `manage_options`, nonce `fpad_retest_{plugin}`): runs `FPAD_Quarantine::retest()` synchronously (loopback ≤ ~10s), redirects back with the verdict in a notice. Also a plain **"Dismiss"** action (state `dismissed`, stops tracking without touching the plugin) and **"Restore without retest"** (link to core's plugins screen — we deliberately do not offer unverified activation).

### FR-4: Canary mode (opt-in)

- Settings (extend `fpad_settings`): `canary_mode` — `'off'` (default) / `'notify'` (schedule retests, report the verdict, but **never leave the plugin active**: a passing retest deactivates again and tells the admin "safe to restore") / `'auto'` (restore on pass).
- `'notify'` exists as the trust-building middle rung; its pass-path calls `deactivate_plugins()` again immediately after the verdict, before alerting.
- Scheduler: reuse the Watchdog cron cadence — hook `fpad_quarantine_tick` (hourly, scheduled/cleared alongside `fpad_watchdog_check`; if the Watchdog PRD hasn't shipped first, this PRD brings its own identical scheduling block): pick the single due record (`next_retest ≤ now`, state `quarantined`), run `retest()`.
- Protected-plugins interplay: a protected plugin is never auto-deactivated, hence never quarantined — no interaction. `log_only` mode ⇒ likewise no quarantine records (nothing was deactivated).
- Canary must refuse to run (`failed:site_unhealthy`) while the Watchdog reports protection ≠ active — without our handler installed, a failed retest would leave a crashing site up.

### FR-5: UI

Log tab gains a **Quarantine** panel above the incident table (only when records exist): columns Plugin / Quarantined (relative time) / Attempts / Next retest / State badge / Last result / Actions (Retest & restore, Dismiss). Empty-state: none. Status badges reuse the `.fpad-badge` styles. Site Health debug info adds `quarantined_count`. Alerts (via Instant Alerts when configured, else `wp_mail`): events `fpad.plugin_restored`, `fpad.retest_failed`, `fpad.retest_exhausted`.

## 6. Data model

| Item | New |
|------|-----|
| `fpad_quarantine` option | FR-1 schema; uninstall-deleted |
| `fpad_settings.canary_mode` | `'off'|'notify'|'auto'`, default `'off'` (admin `get_settings()` only — shutdown handler writes quarantine records but never reads canary settings; the *write* is unconditional bookkeeping) |
| Cron `fpad_quarantine_tick` | hourly; cleared on deactivate/uninstall |

## 7. Technical constraints

- The only shutdown-context change is the guarded quarantine-record write inside `deactivate_plugin()` — everything else is normal context. That write follows the exact `add_to_deactivation_log()` pattern (function_exists guards, bounded array).
- Loopback requests on hosts that block self-requests (some WAF/localhost-DNS setups): detect `WP_Error` on the request itself → verdict `failed:loopback_unreachable`, **do not count as a plugin failure**, do not consume an attempt, surface distinctly ("your host blocks loopback requests; canary unavailable") and disable further scheduling for 7 days. Core's Site Health "loopback test" precedent makes this failure mode well-understood.
- `activate_plugin` with `$silent=true` skips activation hooks — document that a restored plugin may need its activation routine; this matches core recovery-mode resume behavior, and the readme FAQ must say so.
- PHP 7.0, WPCS, shutdown rule, no new deps.

## 8. Security

- All actions nonce+`manage_options`; canary state changes happen via cron (no request input).
- Loopback GET carries no cookies (unauthenticated view) — it can't trigger admin-only code paths; header `X-FPAD-Canary` lets ops teams filter logs.
- Activating code is privileged: `'auto'` mode only ever re-activates a plugin *that was active before* and was deactivated *by us* — never installs/activates anything new. State transitions logged in the quarantine record for audit.

## 9. Acceptance criteria

1. Crash plugin fatals → quarantine record appears with state `quarantined`; log entry unchanged from today.
2. Fix the crash plugin, click Retest & restore → two loopbacks OK → plugin active, state `restored`, success notice, alert sent.
3. Don't fix it, click Retest & restore → handler re-deactivates during loopback, verdict failed with the new log entry as evidence, attempts=1, plugin inactive.
4. Canary `'auto'`: with a fatal-on-load plugin, retests run at 1h/6h/24h/72h then `exhausted`; plugin never left active; four "failed" alerts + one "gave up".
5. Canary `'notify'`: passing retest ends with plugin **inactive** + "safe to restore" alert.
6. Canary `'off'` (default): scheduler runs nothing; `next_retest` stays 0; upgrade from 1.6.x shows zero behavior change.
7. Loopback blocked (simulate via `pre_http_request` filter returning WP_Error) → verdict `failed:loopback_unreachable`, attempts not incremented, canary self-disables with distinct messaging.
8. Watchdog reports drop-in missing → retest refuses to run.
9. Re-fatal of a restored plugin re-quarantines it (record updated, episode reset).
10. Dismiss stops scheduling; uninstall removes option + cron.

## 10. Docs & release tasks

feature-map.md (quarantine rows, new sync pairs: quarantine write in handler ↔ `FPAD_Quarantine` reader; backoff constants), reference.md (schemas, cron, admin-post actions, filters `fpad_quarantine_backoff`), architecture.md (entry points + a "restore flow" diagram beside the fatal flow), development.md (full manual matrix incl. loopback-block simulation), readme.txt (headline feature, FAQ on silent activation + hosts blocking loopbacks, changelog, version ×3).

## 11. Risks & open questions

| Risk | Mitigation |
|------|------------|
| Auto-restoring a genuinely broken plugin | Two-loopback verify + our handler re-kills instantly on the next fatal; backoff+cap prevents flapping; `'notify'` rung and `'off'` default keep it consensual |
| Cached/CDN home page masks the crash | Cache-busting second loopback; verdict also keys off *our own log*, which fires regardless of what HTML the visitor saw |
| Host blocks loopbacks | Distinct non-counting failure + self-disable + messaging (FR-7 constraint) |
| Plugin's fatal only triggers on non-home URLs | Documented limitation (v1 tests home only); future: retest URL setting; the handler still protects the real traffic that hits the broken path |

**Open:** retest URL list (e.g. include `/checkout/`) as a power setting in v1 or defer? Proposed: defer; single-URL keeps the loopback budget bounded. **Open:** should `'auto'` be allowed while Instant Alerts is completely unconfigured? Proposed: yes but with an admin-notice nag — silent auto-restores are auditable via the quarantine record either way.
