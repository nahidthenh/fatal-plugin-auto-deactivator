# PRD: Branded Error Page — Customizable Public 500 Page

- **Status:** Proposed
- **Priority:** P2
- **Suggested milestone:** 1.6.0
- **Date:** 2026-07-28

## 1. Summary

Let site owners customize the public error page the plugin already renders on every fatal: logo, accent color, heading, message, contact line, and (for agencies) a full PHP template override — plus pre-translated static strings so the page finally matches the site's language. Today the page is hard-coded English with WordPress-blue styling and no branding hook of any kind.

## 2. Background & market evidence

- The stock WordPress "There has been a critical error on this website" page is one of the most-searched WP errors ([10Web](https://10web.io/blog/there-has-been-a-critical-error-on-this-website/), [IONOS](https://www.ionos.com/digitalguide/hosting/blogs/wordpress-there-has-been-a-critical-error-on-this-website/), [WPZOOM](https://www.wpzoom.com/blog/how-to-fix-wordpress-critical-error/) all rank for it) — visitors see it and assume the site is abandoned or hacked. Search results show **no established plugin for branding/white-labeling the fatal-error page**; maintenance-mode plugins style planned downtime but cannot hook fatals (they're plugins — they may be the thing that crashed).
- This plugin uniquely *owns* the fatal page already (`FPAD_Fatal_Error_Handler::display_custom_error_page()`), so branding is a natural, zero-new-infrastructure extension no competitor can match without first building a drop-in.
- Agencies/white-label hosts (cf. white-label offerings by [10Web](https://10web.io/blog/there-has-been-a-critical-error-on-this-website/) etc.) want client-facing pages without third-party plugin names on them.
- i18n gap is real today: the public page is intentionally untranslated because translation APIs are unavailable at shutdown ([reference.md](../reference.md#internationalization)) — non-English sites show English errors to visitors. The pre-rendered-strings design below fixes this without violating the shutdown rule.

## 3. Problem / user stories

- As a **store owner**, the error page should carry my logo and brand color so a crash looks like controlled maintenance, not abandonment.
- As a **German site owner**, visitors should read the outage message in German.
- As an **agency**, I want a fully custom template (our support phone number, our design system) across client sites, and no plugin credit visible.
- As a **cautious admin**, I want to preview the page without crashing my site.

## 4. Goals / Non-goals

**Goals:** cosmetic + copy customization with safe defaults; localized static strings; full template override for power users; live preview; all of it inert unless configured (default page byte-identical to today).

**Non-goals:** page builder/visual editor; per-error-type designs; customizing the *wp-admin* notices; runtime translation loading at shutdown (impossible by design — we snapshot instead); custom JS on the error page.

## 5. Functional requirements

### FR-1: Settings — "Error Page" section

New section on the existing Settings tab (`FPAD_Admin`), stored in new option `fpad_error_page` (separate from `fpad_settings` to keep the shutdown read small):

```php
array(
    'logo_url'     => '',        // esc_url_raw; picked via wp.media (enqueue media only on our screen)
    'accent_color' => '',        // '#rrggbb' validated via sanitize_hex_color; '' ⇒ default #dc3232/#0073aa pair
    'heading'      => '',        // plain text; '' ⇒ default "<Error type> Detected"-style heading
    'message'      => '',        // textarea, wp_kses with a,strong,em,br; overrides the intro paragraph
    'contact_html' => '',        // wp_kses same allowlist; rendered under the actions (e.g. support email/phone)
    'hide_branding'=> false,     // reserved: page currently shows no plugin credit; guarantee it stays that way when true
    'strings'      => array(),   // FR-3 snapshot, written automatically on save — not user-edited
)
```

### FR-2: Handler rendering

`display_custom_error_page()` gains a guarded read `get_error_page_settings()` (same pattern as `get_settings()`: `function_exists('get_option')` guard, strict defaults, every field re-validated on read — treat option contents as untrusted: re-check hex format, `strpos( $logo, 'http' ) === 0`, and escape at output like the current code does):

- `logo_url` set → `<img>` (max-height 60px, `esc_url`) above the container.
- `accent_color` set → replaces the header background (`#dc3232`) and button color (`#0073aa`) via the inline CSS. Validate `#[0-9a-fA-F]{6}` at render; ignore otherwise.
- `heading`/`message`/`contact_html` replace/augment their slots; `message` replaces the *intro* line only — the source/status-specific honesty copy (protected/log-only/theme/core variants) is **kept** unless a template override (FR-4) takes over, so custom copy can never claim a fix that didn't happen. Escaping: heading `esc_html`; message/contact stored pre-sanitized by `wp_kses` **and** re-sanitized at render only if `function_exists( 'wp_kses' )`, else fall back to `htmlspecialchars`-based stripping — never echo raw.
- All additions inside the existing inline-CSS/self-contained-HTML constraints; no external asset loads (logo URL is the one exception, `loading="lazy"`, and the page must remain readable if it 404s).

### FR-3: Localized static strings (snapshot pattern)

On every save of the Settings form (and on `plugins_loaded` after a detected locale change — compare stored `strings['_locale']` vs `get_locale()`, refresh if different, throttled to once/day via transient), `FPAD_Admin` renders every static error-page string through `__( …, 'fatal-plugin-auto-deactivator' )` **in the normal admin context where translations work**, and writes the results into `fpad_error_page['strings']` (keys: `reload`, `go_home`, all intro/generic/closing variants per source/status, error-type labels). At shutdown the handler uses `strings[$key]` when present, hard-coded English otherwise. This is the sanctioned way to get a translated page out of a context that cannot call `__()` — document it in architecture.md.

### FR-4: Template override (agency tier)

- If `wp-content/fpad-error-page.php` exists and is readable, the handler `include`s it *instead of* the built-in HTML, inside an isolated method wrapped in `try/catch (Throwable)` + `ob_start()`; on any Throwable, discard the buffer and fall back to the built-in page (a broken template must never yield a blank screen).
- Template contract (document in reference.md): before the include, the handler defines local vars `$fpad_error` (type label, message, file, line — message/file only when the existing detail gate passes), `$fpad_result` (status, plugin_name, deactivated), `$fpad_messages` (the three copy slots already localized), `$fpad_settings` (the FR-1 array). Template authors own escaping of anything they print; ship a commented example template in `docs/examples/fpad-error-page.php` (docs are non-distributed — also show it in readme FAQ).
- `headers_sent()` guard and `http_response_code(500)` + `exit` remain in the handler around the include, not the template's job.
- Site Health debug info reports whether an override template is active.

### FR-5: Preview

"Preview error page" button on the Settings section → opens `admin-post.php?action=fpad_preview_error_page` (GET, cap `manage_options`, nonce `fpad_preview`) in a new tab: `FPAD_Admin` constructs a fake `$error` (E_ERROR, sample message) + fake result, instantiates `FPAD_Fatal_Error_Handler`, and calls a new **public** wrapper `render_page_preview( $error, $result )` that runs the same rendering path but with `exit` suppressed and no 500 status (add an internal `$is_preview` flag; `display_custom_error_page()` refactors its body into a `render_page()` that both paths share). Preview honors current saved settings + template override.

## 6. Data model

`fpad_error_page` (new option; deleted on uninstall). No other storage. Media library images referenced by URL only (no attachment dependency at shutdown).

## 7. Technical constraints

- Shutdown rule everywhere in the render path; the snapshot pattern exists precisely to avoid translation calls there.
- Keep the built-in page's current behavior byte-stable when the option is empty (regression guard: acceptance #1).
- PHP 7.0; template include must not use `finally`-dependent cleanup patterns unavailable pre-7.1 quirks (plain try/catch is fine in 7.0).
- `wp.media` enqueue only on `tools_page_fpad-log` (first asset enqueue in the plugin — add `admin_enqueue_scripts` hook gated by screen id).

## 8. Security

- All stored fields sanitized on save (`esc_url_raw`, `sanitize_hex_color`, `sanitize_text_field`, `wp_kses` allowlist) **and** re-validated/escaped at render — defense in depth because the option could be written by other code.
- Template override is code execution by design — but only for users who can already write to `wp-content/` (≥ plugin-install privilege); note in readme. The plugin never creates that file.
- Preview endpoint: nonce + capability, and it must not write anything.

## 9. Acceptance criteria

1. Empty settings ⇒ rendered page identical to 1.4.0 output (diff the HTML).
2. Logo + color + message set ⇒ page shows them; invalid color/URL ignored gracefully.
3. Custom message set ⇒ intro replaced, but protected/log-only/theme honesty lines still present per status.
4. Site in `de_DE` ⇒ after saving settings once, error page static strings render in German at shutdown (verify with an early parse-error crash where no textdomain could load).
5. `fpad-error-page.php` present ⇒ template renders with documented vars; template that throws ⇒ built-in page renders instead.
6. Preview shows current settings + template without a real fatal, without a 500 status, and doesn't affect the log.
7. Detail gate (`WP_DEBUG`/`WP_DEBUG_DISPLAY`/`FPAD_SHOW_ERROR_DETAILS`) still governs message/file exposure in both built-in and template paths (`$fpad_error['message']` empty when gated).
8. Uninstall removes `fpad_error_page`.

## 10. Docs & release tasks

feature-map.md (feature rows, playbook "customize the error page" rewrite, new sync pair: string-snapshot keys ↔ handler fallbacks), reference.md (option schema, template contract, new admin-post actions), architecture.md (snapshot pattern), development.md (test scenarios incl. locale + broken template), readme.txt (major feature bullets, screenshots 5–6, FAQ, changelog, version ×3), example template under `docs/examples/`.

## 11. Risks & open questions

- **Risk:** users paste huge logos → slow page; mitigate with docs note (no runtime resize possible at shutdown).
- **Risk:** snapshot staleness after language switch → daily locale re-check (FR-3).
- **Open:** should `accent_color` also restyle the summary badge colors? Proposed: no, header+buttons only for v1.
- **Open:** dark-mode (`prefers-color-scheme`) variant? Proposed: defer; note as future enhancement.
