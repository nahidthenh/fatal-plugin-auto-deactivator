# Product Requirements Documents

Feature proposals for Fatal Plugin Auto Deactivator, researched against the market on 2026-07-28. Each PRD is self-contained and written so an AI coding agent or developer can implement it directly: exact classes/files/hooks to touch, option schemas, security requirements, acceptance criteria, and the docs/readme sync work each release owes.

Before implementing any PRD, read [docs/feature-map.md](../feature-map.md) (especially the sync-pairs table) and [architecture.md → shutdown-context constraints](../architecture.md#shutdown-context-constraints-critical).

## Proposals by priority

| PRD | Priority | Milestone | One-line pitch |
|-----|----------|-----------|----------------|
| [Instant Alerts — email & webhook](prd-instant-alerts-email-webhook.md) | **P1** | 1.5.0 | Tell the owner *within seconds* what crashed and what we did about it — the single biggest gap vs. Fatal Error Notify (6K installs), with action-aware severity no competitor has |
| [Protection Watchdog](prd-protection-watchdog.md) | **P1** | 1.5.0 | Cron-verified protection: today a deleted/foreign/stranded/disabled drop-in goes unnoticed until someone opens wp-admin |
| [Branded Error Page](prd-branded-error-page.md) | P2 | 1.6.0 | Logo, colors, custom copy, localized strings, agency template override — we already own the fatal page; nobody in the ecosystem brands it |
| [Observability — WP-CLI & REST](prd-observability-cli-rest.md) | P2 | 1.6.0 | `wp fpad …` + opt-in read-only `fpad/v1` REST for agency fleets (MainWP-style dashboards, uptime monitors, deploy gates) |
| [Quarantine & Canary Auto-Retest](prd-quarantine-auto-retest.md) | P3 | 1.7.0 | Loopback-verified "Retest & restore" for deactivated plugins, opt-in auto-restore with backoff — the pattern WP core validated for update rollbacks, applied to runtime fatals core doesn't cover |
| [Multisite Support](prd-multisite-support.md) | P3 | 2.0.0 | Network-activated plugin policy, network settings/overview, correct lifecycle — currently "subtly wrong" on multisite, and a standing readme promise |

## Dependency graph

```
Instant Alerts ──┬─→ Protection Watchdog (reuses alert channels; has wp_mail fallback)
                 └─→ Quarantine (transition alerts; has wp_mail fallback)
Protection Watchdog ─→ Quarantine (health precondition + shared cron cadence)
Observability FR-0 (FPAD_Log_Store refactor) ─→ benefits every later log consumer
Branded Error Page, Multisite: independent
```

Recommended build order: Alerts → Watchdog (1.5.0) → Branded Page + Observability (1.6.0) → Quarantine (1.7.0) → Multisite (2.0.0). Every PRD is also implementable standalone — each specifies its fallback when a dependency hasn't shipped.

## Market snapshot backing these choices

- **Own baseline:** [90+ installs, 5★](https://wordpress.org/plugins/fatal-plugin-auto-deactivator/) — early; features below target the discovery levers (alerting keyword space, multisite, agency tooling).
- **Alerting demand proven:** [Fatal Error Notify](https://wordpress.org/plugins/fatal-error-notify/) ~6K installs; paywalls Slack, logging, OOM handling, multisite — we can ship the full set free, attached to actual remediation.
- **Core recovery email is unreliable** (sent via raw `mail()` when SMTP plugins can't load): [Recovery Mode docs](https://wordpress.org/documentation/article/recovery-mode/), [DreamHost WSOD guide](https://help.dreamhost.com/hc/en-us/articles/360030636332-Fixing-the-White-Screen-of-Death) — webhook channel bypasses it.
- **Core rollback (WP 6.3/6.6) covers update-time fatals only**, via loopback testing: [merge proposal](https://make.wordpress.org/core/2024/04/19/merge-proposal-rollback-auto-update/), [Trac #58281](https://core.trac.wordpress.org/ticket/58281) — runtime restore is unclaimed territory and the loopback pattern is core-sanctioned.
- **Agencies pay for PHP-error visibility:** [WP Remote](https://wpremote.com/php-error-monitoring/), [MainWP ecosystem](https://mainwp.com/), [agency monitoring guides](https://modulards.com/wordpress-site-monitoring-tools/) — machine interfaces (REST/CLI) are how fleets consume it.
- **Nobody brands the fatal page**: the ["critical error" page](https://10web.io/blog/there-has-been-a-critical-error-on-this-website/) is a top-searched WP scare; maintenance-mode plugins can't hook fatals (they might *be* the fatal). Only a drop-in owner can — that's us.

## Ideas considered and rejected

- **Theme fatal fallback (auto-switch to a default theme):** blast radius and data-loss perception too high; theme fatals stay log+report.
- **SMS/push channels:** webhook → Zapier/ntfy covers them without vendoring gateways.
- **Full error monitoring (warnings/notices):** scope creep into Query Monitor/Sentry territory; fatals-only is the identity.
- **Log in a custom table:** at a 100-entry coalesced cap, `wp_options` remains the right cost/benefit; revisit only if retention demands grow.
- **Email digests:** deferred until Instant Alerts ships and feedback shows digest demand.
