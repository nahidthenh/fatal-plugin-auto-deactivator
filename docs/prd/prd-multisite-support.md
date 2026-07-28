# PRD: Multisite Support

- **Status:** Proposed
- **Priority:** P3 (large surface; unlocks a whole install base — ship deliberately)
- **Suggested milestone:** 2.0.0 (behavioral semantics on multisite change from "undefined" to "defined" — worth a major)
- **Date:** 2026-07-28

## 1. Summary

Make the plugin first-class on WordPress multisite: correct attribution and policy for **network-activated** plugins, network-level settings, a network-admin log overview, complete lifecycle (activation modes, uninstall cleanup across sites), and honest surfacing of the fact that the drop-in protects — and its policies govern — the **entire network** at once.

## 2. Background & evidence

- The readme FAQ has promised it since 1.0: *"The current version is designed for standard WordPress installations. Multisite support may be added in future updates."* — a standing roadmap commitment.
- Competitive pressure: [Fatal Error Notify **Pro** paywalls multisite support](https://wordpress.org/plugins/fatal-error-notify/) — shipping it free is a differentiation lever in this exact niche.
- Multisite networks (universities, agencies, franchise/brand networks) are disproportionately *managed* WordPress — the audience most likely to want automated fatal handling and least able to have humans watching every subsite. Support forums show recurring confusion around network-activated plugin deactivation mechanics ([example](https://wordpress.org/support/topic/plugin-breaks-multisite-if-activated-on-the-network/)).
- **The current behavior on multisite is not "unsupported — inert"; it is "unsupported — subtly wrong",** which is worse:
  1. The drop-in lives in the shared `wp-content/`, so activating the plugin on *one* subsite silently installs fatal handling for **every** site in the network.
  2. `FPAD_Fatal_Error_Handler::get_active_plugins()` reads only the crashed site's `active_plugins`; **network-activated plugins** (`active_sitewide_plugins`, a network option) are invisible → their fatals are logged `unattributed` and never handled.
  3. `uninstall()` deletes options only on the site where it runs, stranding rows on every other site.
  4. Settings (`fpad_settings`) are per-site, but the drop-in they govern is network-global — whichever site crashed supplies the policy, a semantic nobody chose.

## 3. User stories

- As a **network admin**, a fatal in a plugin activated only on subsite 12 deactivates it on subsite 12 — the other 40 sites and their traffic never notice.
- As a **network admin**, when a *network-activated* plugin fatals, I control the blast radius: by default it is logged loudly but **not** network-deactivated; if I opt in, it is.
- As a **network admin**, one Network Admin screen shows me which sites have logged fatals, with per-site drill-down.
- As a **subsite admin**, I still see my own site's log under Tools, but I cannot change network-wide policy.

## 4. Goals / Non-goals

**Goals:** correct attribution incl. sitewide plugins; explicit, network-controlled deactivation policy; network settings as the single source of truth; network log overview; clean lifecycle on every (de)activation/uninstall path; truthful UI about network-wide scope.

**Non-goals:** per-site policy overrides (v1 is network-policy-only; note as future work); per-site drop-ins (impossible — one `wp-content/`); aggregated *merged* log table across sites (v1 = per-site counts + drill-down); BuddyPress/WP.com-specific stacks.

## 5. Functional requirements

### FR-1: Handler — sitewide-aware attribution (shutdown context)

`FPAD_Fatal_Error_Handler`:

- `get_active_plugins()` returns the merge of `get_option( 'active_plugins', array() )` and, when `function_exists( 'get_site_option' ) && is_multisite()` (both guarded — `is_multisite` needs `function_exists` too), `array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )`. Track which set matched: new protected helper returns `array( $basename, $is_sitewide )`.
- `maybe_deactivate_plugin()` policy for a **sitewide** match, checked *before* the existing log_only/protected checks in this order: settings (FR-2) key `network_deactivate`:
  - `false` (default): do not deactivate; outcome status **`network_active`** (new status — badge "Network active", urgent copy on the error page mirroring the `protected` tone: attributed, not self-healed, needs a network admin).
  - `true`: `deactivate_plugins( $basename, false, true )` (third arg = network-wide; function guarded/loaded as today); status `deactivated`, log field `network_wide => true`.
- Per-site matches behave exactly as today (`deactivate_plugins( $basename )` acts on the crashed site because shutdown runs in that site's context — current behavior, now documented as intended).
- Log entries gain optional fields `site_id` (`get_current_blog_id()` guarded, multisite only) and `network_wide` (bool, only when a sitewide plugin was involved). Legacy-tolerant reads everywhere (`isset()` pattern per the existing convention).

### FR-2: Settings — network source of truth

- On multisite, policy settings live in a **network option** `fpad_settings` (`get_site_option`/`update_site_option`); single-site continues with `get_option` untouched. Both `get_settings()` mirrors (handler guarded + admin) gain the same storage switch — sync pair grows a storage dimension.
- New key: `network_deactivate` (bool, default `false`). Existing keys (`log_only`, `protected_plugins`, and keys added by other PRDs) become network-wide semantics on multisite.
- Settings **UI moves to Network Admin** (`network_admin_menu` → Settings → "Fatal Plugin Auto Deactivator", cap `manage_network_options`, `edit.php`-style form posting to `network_admin_edit_fpad_settings` via `admin_post`-equivalent multisite pattern with nonce). The subsite Settings tab is replaced by a read-only summary + "managed in Network Admin" link (hidden entirely from non-network admins beyond the summary).
- Protected-plugins choices list = union of network-activated plugins and plugins active on ≥1 site is overkill; v1 lists **all installed plugins** (`get_plugins()`) since network admins reason about the whole fleet.

### FR-3: Log surfaces

- **Per-site** Tools → Fatal Plugin Log keeps working exactly as now (log data is per-site by construction — the crashed site's options).
- **New Network Admin page** (`network_admin_menu`, cap `manage_network_options`): table of sites — columns Site / Logged incidents / Occurrences / Last fatal / Protection-relevant note — built via `get_sites( array( 'number' => 200 ) )` + `switch_to_blog()` reading each site's `fpad_deactivation_log` (counts only; restore with `restore_current_blog()`), paginated ≥200 sites. On `wp_is_large_network()`, show the first page with a notice that counts are per-page on demand (no full-network scans).
- Site rows link to that site's own Tools page. The network page also hosts the protection banner (drop-in status is network-global) and, when other PRDs land, network-level alert/watchdog settings.

### FR-4: Lifecycle & cleanup

- **Network activation** (`activate` hook receives `$network_wide=true`): install drop-in (once). **Single-site activation on multisite**: still installs the shared drop-in but immediately shows a persistent notice on that site + network admin: "Protection is network-wide by nature; network-activate for correct management" (honest about item 2.1 above).
- **Deactivation**: remove the drop-in only when no other site still has the plugin active — concretely: on multisite, `deactivate()` removes the drop-in only for network deactivation, or for single-site deactivation when `! is_plugin_active_for_network()` and no other site lists it in `active_plugins` (checked via a bounded `get_sites`/`switch_to_blog` loop, skipped with a warning on large networks — leaving the drop-in is fail-safe: protection persists, and `check_dropin()` on any remaining active site would reinstall it anyway).
- **Uninstall**: delete network option, then iterate `get_sites()` (batched 100, unbounded count — uninstall may be slow, that's acceptable and standard) deleting all per-site `fpad_*` options; on `wp_is_large_network()` fall back to documenting leftover rows in readme (mirrors core guidance).
- `check_dropin()` also hooked to `network_admin_menu` context (it already runs on `admin_init`, which fires in network admin — verify, no change expected).

### FR-5: Notices & Site Health

- Deactivation notices: per-site queue unchanged; when a **network-wide** deactivation happens, also write a network queue (`fpad_network_deactivated` site option) displayed once in Network Admin (`network_admin_notices`).
- Protection warning + Site Health test run per site today; on multisite, gate the *warning* to `manage_network_options` (subsite admins can't fix wp-content) and point the reinstall action at network admin. Site Health test text notes network-wide scope.

## 6. Data model

| Item | Change |
|------|--------|
| `fpad_settings` | becomes a **network option** on multisite (+`network_deactivate`); per-site on single-site — no migration needed for existing single-site installs; on multisite, first network-admin save writes the network option (per-site remnants ignored thereafter and removed by uninstall) |
| `fpad_deactivation_log` / `fpad_deactivated_plugins` | unchanged, per-site; log entries + admin-notice queue entries gain optional `site_id`, `network_wide` |
| `fpad_network_deactivated` | new site option (network notice queue) |
| Status vocabulary | +`network_active` → badge, filter option, `entry_status()` handling, error-page copy (sync-pair table update) |

## 7. Technical constraints

- Shutdown rule: `is_multisite()`, `get_site_option()`, `get_current_blog_id()` are all core (wp-includes) but must still be `function_exists`-guarded in the handler.
- Never call `switch_to_blog()` in the shutdown handler.
- `deactivate_plugins( …, false, true )` network flag requires the same includes already loaded for `deactivate_plugins` — no new loading logic.
- All network pages: `manage_network_options`, network nonces, `network_admin_url()` for links.
- PHP 7.0, WPCS; `get_sites` (WP 4.6+) is safely ≥ our 5.3 floor.

## 8. Acceptance criteria (test on a 3-site network + single-site regression)

1. Single-site installs: byte-identical behavior before/after (regression suite = existing development.md scenarios).
2. Subsite-activated plugin fatals on subsite A → deactivated on A only; B/C unaffected; A's log has `site_id`.
3. Network-activated plugin fatals, `network_deactivate=false` (default) → stays active everywhere; status `network_active` badge in A's log; error page uses the not-self-healed copy; network admin notice appears.
4. Same with `network_deactivate=true` → plugin network-deactivated; network notice; log entry `network_wide=true`.
5. Settings saved in Network Admin govern fatals on every subsite (verify `log_only` network-wide); subsite Settings tab is read-only summary.
6. Network Admin log overview lists per-site counts/last-fatal; links land on each site's Tools page; 201-site simulation paginates.
7. Single-site activation on multisite shows the network-scope notice; network activation doesn't.
8. Deactivating on one subsite while another still has it active leaves the drop-in installed; final deactivation removes it.
9. Network uninstall removes the network option + every site's `fpad_*` rows (3-site check).
10. Site Health warning on a subsite (non-network admin) does not offer a reinstall it can't perform.

## 9. Docs & release tasks

This one rewrites docs meaningfully: architecture.md (multisite section: shared drop-in, option locality, policy flow), reference.md (network option storage matrix, new status, new pages/caps/nonces, `site_id`/`network_wide` fields), feature-map.md (rows + sync-pair updates: settings storage switch, status vocabulary), development.md (multisite test env via `wp-env`/`wp core multisite-convert` + full matrix), readme.txt (drop the "designed for standard installations" FAQ, add multisite FAQ set, changelog, version ×3 — bump to 2.0.0), CLAUDE.md (multisite constraints join the critical-constraints section).

## 10. Risks & open questions

| Risk | Mitigation |
|------|------------|
| Network-wide deactivation from one subsite's fatal (blast radius) | Default `network_deactivate=false` + explicit opt-in + loud `network_active` status |
| Large-network scans (uninstall, drop-in refcount, overview) | Batching, `wp_is_large_network()` guards, fail-safe = leave drop-in installed |
| Settings-migration confusion (which option wins on multisite) | Network option is authoritative from first save; subsite UI says so; docs matrix |
| Testing burden | Dedicated matrix + wp-env multisite config committed to development.md |

**Open:** per-site `protected_plugins` overrides — deferred; collect demand. **Open:** should the Network Admin overview cache per-site counts in a site transient (5 min)? Proposed: yes if >50 sites, implementation detail left to build.
