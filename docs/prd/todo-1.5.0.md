# Implementation TODO — Release 1.5.0

Scope: the two P1 PRDs — [Instant Alerts](prd-instant-alerts-email-webhook.md) (email + webhook notifications) and [Protection Watchdog](prd-protection-watchdog.md) (cron-verified drop-in health). Tasks are ordered by dependency; each leaves the plugin in a shippable state. FR numbers reference the PRDs.

**Read first:** [feature-map.md → sync pairs](../feature-map.md#things-that-must-change-together-sync-pairs) and [architecture.md → shutdown-context constraints](../architecture.md#shutdown-context-constraints-critical). No automated test suite exists — every task's verification is `php -l` (PHP 7.x) plus the named manual scenario from a crash-test plugin per [development.md](../development.md#testing).

## Decisions locked for this release (from PRD open questions)

- Rate-limit granularity = per **fingerprint** (not per plugin). Cooldown default 900 s.
- Email notifications are **opt-in** — nothing auto-enables on upgrade or fresh install.
- Alert strings sent from shutdown stay untranslated (matches error-page convention).
- Watchdog interval fixed `hourly`; `fpad_watchdog_interval` filter is the escape hatch — this becomes the plugin's **first public filter** (must be added to reference.md under a new "Hooks provided" heading).
- Notifier cron (`fpad_notifier_drain`) is scheduled lazily on the first settings-save that enables a channel; watchdog cron (`fpad_watchdog_check`) is scheduled on activation + self-heals from `check_dropin()`.
- Build order: Alerts before Watchdog (Watchdog consumes `FPAD_Notifier` when configured, `wp_mail` fallback otherwise).

---

## Phase 1 — Alerts: email vertical slice (FR-1, FR-2 subset)

### Task A1: Notification settings storage (both mirrors)
Add the seven `notify_*` keys with defaults to `FPAD_Fatal_Error_Handler::get_settings()` (guarded) and `FPAD_Admin::get_settings()` — identical defaults, per the sync pair.
- [ ] Empty/missing option returns full defaults from both methods
- [ ] Malformed option values (wrong types) normalize to defaults
- **Verify:** `php -l`; `wp eval` dump of both getters matches
- **Deps:** none · **Files:** `includes/class-fatal-error-handler.php`, `includes/class-admin.php` · **Size:** S

### Task A2: Settings UI — email channel + shared fields
"Notifications" section in `render_settings_tab()`: email toggle, recipients field, "Notify about" status checkboxes, cooldown input; validation in `handle_settings_save()` (sanitize_email per item, absint clamp 60–86400, status whitelist).
- [ ] Saved values round-trip; invalid emails dropped silently; empty recipients ⇒ falls back to `admin_email` at send time (store `''`)
- [ ] All strings translatable
- **Verify:** save form, `wp option get fpad_settings` shows normalized keys
- **Deps:** A1 · **Files:** `includes/class-admin.php` · **Size:** S

### Task A3: `maybe_notify()` — capture, rate limit, direct email send
New protected method in the handler called from `handle()` after `add_to_deactivation_log()`, before `display_custom_error_page()`: status filter → fingerprint cooldown via `fpad_alert_state` (prune >7 days) → guarded `wp_mail` plain-text send (subject/body per FR-4, includes log-page URL). Every WP call `function_exists()`-guarded; no output, no exit.
- [ ] Crash-test fatal with email enabled ⇒ one email naming plugin, status verb, error, file:line
- [ ] Same fatal repeated within cooldown ⇒ no second email; `fpad_alert_state` holds one fingerprint
- [ ] Status not in `notify_statuses` ⇒ no send, no state write
- **Verify:** manual crash matrix (deactivated + protected wording difference); confirm error page still renders after send
- **Deps:** A1 · **Files:** `includes/class-fatal-error-handler.php` · **Size:** M

### Checkpoint 1
- [ ] `php -l` sweep clean; defaults-off upgrade shows **zero** behavior change vs 1.4.0 (no emails, no new option rows until enabled)
- [ ] Email vertical works end-to-end on the dev site

---

## Phase 2 — Alerts: webhook channel + queue reliability (FR-2 rest, FR-3)

### Task A4: Webhook payloads + direct send
Payload builders in the handler (protected methods): FR-4 JSON schema and Slack `{"text": …}` with 🟠/🔴 severity; direct send via guarded `wp_remote_post`, `'blocking' => false`, timeout 3, JSON header. UI: webhook toggle, URL field (`wp_http_validate_url`, https-required with localhost exception), format radio — extends A2's section/validation.
- [ ] Local endpoint (e.g. `nc -l` / webhook.site) receives exact FR-4 JSON once per cooldown window
- [ ] Slack format posts render in a real Slack/Discord-slash-compatible webhook
- [ ] Invalid/http URL rejected on save with settings error
- **Verify:** capture request body, diff against PRD schema
- **Deps:** A3 · **Files:** `includes/class-fatal-error-handler.php`, `includes/class-admin.php` · **Size:** M

### Task A5: Queue fallback + `FPAD_Notifier` drain
When a channel's transport function doesn't exist at shutdown, append payload to `fpad_alert_queue` (cap 20, oldest dropped). New `includes/class-notifier.php` (`FPAD_Notifier::init()` from bootstrap): drain on `init` prio 20 + hourly `fpad_notifier_drain` backstop, transient lock `fpad_notifier_lock`, cooldown re-checked at drain.
- [ ] Parse error in an early-loading mu-plugin-style crash ⇒ entry lands in queue; next normal request delivers it and empties the queue
- [ ] Lock prevents double-send under two concurrent requests (best-effort check)
- **Verify:** seed `fpad_alert_queue` manually via `wp option update`, load front-end, confirm delivery + empty queue
- **Deps:** A4 · **Files:** `includes/class-notifier.php` (new), `fatal-plugin-auto-deactivator.php`, `includes/class-fatal-error-handler.php` · **Size:** M

### Task A6: Scheduling lifecycle + uninstall hygiene
Lazy-schedule `fpad_notifier_drain` on first channel-enabling save; `wp_clear_scheduled_hook` on deactivation; uninstall deletes `fpad_alert_state` + `fpad_alert_queue` and clears the cron.
- [ ] Never-enabled install: `wp cron event list` shows no fpad events
- [ ] Enable → event exists; deactivate → gone; uninstall → both options gone
- **Verify:** `wp cron event list`, `wp option list --search=fpad_*` at each stage
- **Deps:** A5 · **Files:** `includes/class-admin.php`, `includes/class-plugin-lifecycle.php` · **Size:** S

### Checkpoint 2
- [ ] Alerts acceptance criteria 1–7 + 10 from the PRD all pass
- [ ] Fatal with both channels enabled delays the visitor error page imperceptibly (webhook non-blocking confirmed)

---

## Phase 3 — Alerts: polish (FR-5, FR-6)

### Task A7: "Send test notification" buttons
`admin_post_fpad_test_alert` (cap `manage_options`, nonce `fpad_test_alert`) → `FPAD_Notifier::send_test( $channel )`; redirect back with success/failure notice incl. webhook HTTP code.
- [ ] Test email arrives; test webhook fires; failure shows response code in the notice
- **Verify:** break the webhook URL (404 endpoint) and confirm the code surfaces
- **Deps:** A5 · **Files:** `includes/class-admin.php`, `includes/class-notifier.php` · **Size:** S

### Task A8: OOM reserved-memory buffer
`$GLOBALS['fpad_reserved_memory'] = str_repeat( 'x', 256 * 1024 );` in **both** the tracked drop-in source and the `create_dropin_source()` heredoc (sync pair); `unset()` as the first statement of `handle()`. Reinstall the drop-in on the dev site after the change (Reinstall button) — deployed copies only update via a reinstall path.
- [ ] Crash via `memory_limit` exhaustion (e.g. `str_repeat` bomb in the test plugin at a low `memory_limit`) still produces a log entry and an alert
- [ ] `OWNERSHIP_MARKER` string still present in both variants
- **Verify:** diff tracked source vs a freshly generated one — functionally identical
- **Deps:** A3 · **Files:** `includes/fatal-error-handler-dropin.php`, `includes/class-dropin-manager.php`, `includes/class-fatal-error-handler.php` · **Size:** S

---

## Phase 4 — Protection Watchdog

### Task W1: `FPAD_Dropin_Manager::verify_protection()`
New public method wrapping `get_status()` with two new statuses: `disabled` (`WP_DISABLE_FATAL_ERROR_HANDLER` truthy — checked first) and `stranded` (drop-in ours but `WP_CONTENT_DIR . '/plugins/fatal-plugin-auto-deactivator/includes/class-fatal-error-handler.php'` not readable — computed the way the **drop-in** computes it, not via `FPAD_PLUGIN_DIR`; comment this).
- [ ] Returns `array( 'status', 'detail' )`; all four legacy statuses pass through unchanged
- [ ] Define the constant in wp-config ⇒ `disabled`; rename the plugin dir ⇒ `stranded`
- **Verify:** `wp eval` calls under each simulated condition
- **Deps:** none (parallel-safe with Phase 1–3) · **Files:** `includes/class-dropin-manager.php` · **Size:** S

### Task W2: Switch admin consumers + new status copy
`FPAD_Admin::get_protection_state()` uses `verify_protection()`; `protection_message()` gains `disabled` and `stranded` copy (name the constant; explain the stranded path). Banner, site-wide notice, Site Health inherit automatically.
- [ ] Both new statuses render in banner + notice + Site Health as critical, with actionable text
- [ ] `active` sites see no change
- **Verify:** simulate each status, walk the three surfaces
- **Deps:** W1 · **Files:** `includes/class-admin.php` · **Size:** S

### Task W3: Watchdog cron — check, heal, state
`FPAD_Plugin_Lifecycle::watchdog_check()` on new hourly `fpad_watchdog_check` (interval via `apply_filters( 'fpad_watchdog_interval', 'hourly' )`): verify → auto-reinstall for `missing`/`foreign` (reclaim max once per 24 h — track `reclaim_attempts`) → record `fpad_watchdog_state`. Schedule on activation; self-schedule from `check_dropin()` if absent (upgrade path); clear on deactivate/uninstall (+ delete state option).
- [ ] Delete drop-in, `wp cron event run fpad_watchdog_check` ⇒ reinstalled, state `active`, no alert
- [ ] Foreign file twice in a row ⇒ one reclaim, then back-off (no reinstall loop), state records it
- [ ] Upgrade-without-reactivation self-schedules on next `admin_init`
- **Verify:** `wp cron event list/run`, `wp option get fpad_watchdog_state` between steps
- **Deps:** W1 · **Files:** `includes/class-plugin-lifecycle.php` · **Size:** M

### Task W4: Watchdog alerting
On unrepairable non-active status: send `fpad.protection_lost` via `FPAD_Notifier` when a channel is configured, else direct `wp_mail` to `admin_email`; max one alert per distinct status per 24 h (`last_alert` in state); `fpad.protection_restored` on recovery.
- [ ] Lost→alert once, second run within 24 h silent, restore→recovery alert
- [ ] Works with alerts feature fully unconfigured (wp_mail fallback)
- **Verify:** simulate `missing`+unwritable wp-content (chmod) to force unrepairable, watch inbox/webhook
- **Deps:** W3, A5 (soft — fallback path must work without it) · **Files:** `includes/class-plugin-lifecycle.php`, `includes/class-notifier.php` · **Size:** S

### Task W5: Surfacing polish
"Protection last verified: {human_time_diff}" under the log-page banner; `last_watchdog_check` row in Site Health debug info.
- [ ] Both render; absent state (pre-first-run) shows an em dash, not a PHP notice
- **Verify:** fresh install before/after first cron run
- **Deps:** W3 · **Files:** `includes/class-admin.php` · **Size:** XS

### Checkpoint 3
- [ ] Watchdog PRD acceptance criteria 1–7 pass
- [ ] Combined soak: healthy site runs watchdog + drain crons for a day with zero option churn (state written only on change) and zero notices in `debug.log`

---

## Phase 5 — Docs, readme, release

### Task R1: Developer docs sync
feature-map.md (new feature rows; sync-pair additions: settings mirrors' new keys, report format handler↔admin, drop-in source↔generator OOM buffer, stranded-path computation mirror), reference.md (new options `fpad_alert_state`/`fpad_alert_queue`/`fpad_watchdog_state`, settings schema, new hooks incl. `admin_post_fpad_test_alert`, crons, **new "Hooks provided" section** for `fpad_watchdog_interval`, `verify_protection()` statuses), architecture.md (entry-points + communication tables), development.md (new manual scenarios: alert matrix, queue drain, OOM crash, watchdog statuses).
- [ ] Every new symbol in this TODO appears in reference.md; sync-pairs table covers every mirror introduced
- **Deps:** all implementation tasks · **Size:** M (docs only)

### Task R2: readme.txt
Feature bullets (notifications, watchdog), rewrite FAQ "Will I be notified…?" (currently admin-notice-only) + extend "How do I know the protection is actually working?" (watchdog + WP-Cron caveat per FR-5), new FAQ for webhook privacy wording, changelog `= 1.5.0 - DD/MM/YYYY =`, upgrade notice.
- [ ] FAQ claims match shipped behavior exactly (no overpromising on delivery guarantees)
- **Deps:** implementation complete · **Size:** S

### Task R3: Version bump + POT
`Version:` header + `FPAD_VERSION` + `Stable tag:` → 1.5.0; confirm `Tested up to` still current; regenerate POT (`wp i18n make-pot . languages/fatal-plugin-auto-deactivator.pot`) — Phase 1–4 added many strings.
- [ ] Three version spots match; POT contains the new settings-UI strings
- **Deps:** R2 · **Size:** XS

### Task R4: Full regression + release
Run the complete development.md manual matrix (old scenarios must still pass — especially: defaults-off upgrade parity, drop-in self-heal, foreign drop-in never deleted), `php -l` sweep, then tag per [deployment.md](../deployment.md) (`git tag 1.5.0`, push → WP.org deploy) and verify the drop-in survives an in-dashboard update (A8 changed the drop-in!).
- [ ] All checkpoints re-verified on the release build zip (build-archive workflow)
- **Deps:** R1–R3 · **Size:** M (testing time)

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| New handler code breaks the crash path | High | Every A3/A4/A8 change reviewed against the shutdown rule; Checkpoint 1 parity test; all sends inside existing Throwable guard |
| Alert flood / option churn | Med | Fingerprint cooldown + queue cap + state-write-only-on-change (Checkpoint 3 soak) |
| Drop-in edit (A8) strands deployed sites | Med | Generator kept in sync; release test includes in-dashboard update survival (R4) |
| Two-plugins-fighting reclaim loop | Low | W3 24 h back-off |

## Open questions (need owner input before the marked task)

1. **A2:** exact default for `notify_email_to` placeholder — show current `admin_email` value or generic text? (cosmetic; proposal: show the address)
2. **R2:** should 1.5.0 readme announce the `fpad_watchdog_interval` filter publicly or keep it undocumented-internal for one release? (proposal: document it)
