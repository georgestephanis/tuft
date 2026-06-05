# AGENTS.md

Guidance for AI agents and contributors working in this plugin.

## Scope

**Edit only:** `wp-content/plugins/tuft/` and all files beneath it.

**Read-only reference:** `wp-content/plugins/alpaca-issue-tracker/` — understand it to know what the bridge integrates with, but never modify it.

---

## What this plugin does

`tuft` is a standalone visual feedback tool. A floating "Feedback" button appears on every frontend page. Clicking it enters a targeting mode where the visitor can click any element on the page; the plugin captures the DOM selector, click coordinates, viewport size, form field state, and a screenshot, then presents a feedback modal. The modal includes an interactive drawing canvas (pen, rectangle, undo, clear) so the reviewer can mark up the screenshot before submitting. On submission everything is stored locally as a `tuft_feedback` custom post type.

**Widget visibility** is controlled by a settings option (`tuft_visibility`). The enqueue function in `tuft.php` checks this option and returns early if the current user does not meet the threshold — so the FAB, CSS, and JS are never sent to ineligible visitors.

**Rate limiting** caps submissions per IP per hour. The submitter IP is stored in `_tuft_submitter_ip` post meta and looked up via `WP_Query` on each submission.

**Notifications** are dispatched by `Tuft_Notifications` on the `tuft_feedback_submitted` action: plain-text email to each address in `tuft_notify_email`, and a JSON POST to each URL in `tuft_webhook_urls`.

If the **Alpaca Issue Tracker** plugin is also active, each submission is automatically mirrored as an `alpaca_issue` so it appears on the Alpaca Kanban board for triage. The two posts are cross-referenced via post meta.

---

## Files

| File | Purpose |
|------|---------|
| `tuft.php` | Plugin bootstrap: constants, visibility-gated enqueue of frontend CSS/JS, passes `tuftSettings` to JS |
| `includes/class-tuft-post-type.php` | `tuft_feedback` CPT, admin list-table columns, meta box detail view, Alpaca-aware menu placement, install-Alpaca notice |
| `includes/class-tuft-settings.php` | **Settings → Tuft Feedback** page: `tuft_visibility`, `tuft_rate_limit`, `tuft_notify_email`, `tuft_webhook_urls` options |
| `includes/class-tuft-rest-controller.php` | `POST /tuft/v1/submit` — rate-limit check, CPT post + meta + screenshot attachment, fires `tuft_feedback_submitted` |
| `includes/class-tuft-notifications.php` | Listens to `tuft_feedback_submitted`; sends email and webhook notifications |
| `includes/class-tuft-alpaca-bridge.php` | Listens to `tuft_feedback_submitted`; creates an `alpaca_issue` mirror when Alpaca is active |
| `assets/css/feedback.css` | All frontend UI styles: floating button, targeting overlay, element highlight, modal, drawing toolbar |
| `assets/js/feedback.js` | All frontend interaction: targeting mode, element capture, screenshot, canvas annotation, modal, REST submission |

### Tooling

| File | Purpose |
|------|---------|
| `package.json` | `@wordpress/scripts` + `html2canvas` dev dependencies; `lint:js`, `lint:css`, `lint`, `copy-vendor`, and `postinstall` scripts |
| `composer.json` | `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, installer; `phpcs`/`phpcbf` scripts |
| `phpcs.xml` | WordPress-Extra + WordPress-Docs ruleset, `tuft`/`Tuft` prefix, `tuft` text domain |
| `.eslintrc.json` | Extends `@wordpress/eslint-plugin/recommended`; declares `tuftSettings`/`html2canvas` globals |
| `.stylelintrc.json` | Extends `@wordpress/stylelint-config`; disables `declaration-no-important` (intentional for targeting cursor) |
| `.eslintignore` | Excludes `vendor/` and `node_modules/` from ESLint |

### Brand assets

| Path | Purpose |
|------|---------|
| `assets/img/fab-button.png` | FAB button face served to the frontend — coral disc + puff mark at 2× pixel density. URL passed to JS via `tuftSettings.buttonImg`. |
| `assets/wporg/icon-128x128.png` | WordPress.org plugin directory icon (128 px) |
| `assets/wporg/icon-256x256.png` | WordPress.org plugin directory icon @2× |
| `assets/wporg/banner-772x250.png` | WordPress.org plugin directory banner |
| `assets/wporg/banner-1544x500.png` | WordPress.org plugin directory banner @2× |

> **Deployment note:** WordPress.org expects banner and icon files in the SVN `assets/` directory *outside* the plugin zip (sibling to `trunk/`). Copy `assets/wporg/*` there before publishing.

---

## Frontend JS flow

1. `DF.init()` — builds and appends the floating button, overlay, highlight box, and modal to `document.body`. The button is a circle FAB (`#tuft-trigger`) positioned 2/3 down the right edge of the viewport; its face is `<img src="tuftSettings.buttonImg">` (the brand-kit PNG served from `assets/img/fab-button.png`).
2. Button click → `DF.enterTargeting()`:
   - Adds `body.tuft-targeting` (CSS crosshair cursor on everything)
   - Shows the dimmed overlay (purely visual, `pointer-events: none`)
   - Registers capture-phase `mouseover` (highlight update) and `click` (capture) listeners on `document`
3. Click in targeting mode → `DF.onTargetClick()`:
   - `preventDefault()` + `stopImmediatePropagation()` to suppress any element's own handlers
   - Records `{ selector, xPercent, yPercent, rectLeft, rectTop, rectWidth, rectHeight, viewportWidth, viewportHeight, pageUrl, pageTitle, formState, userAgent }`
   - Exits targeting mode, waits two `requestAnimationFrame` ticks for the overlay to repaint away
   - Calls `html2canvas` on `document.documentElement` (visible viewport only); bundled locally so always available
   - Opens the feedback modal
4. Modal opens with an interactive draw canvas (`DF.setupDrawCanvas()`): the raw screenshot is loaded into `<canvas id="tuft-screenshot-canvas">` scaled to the modal's display width, then the spotlight cutout, optional dashed bounding-box rect, and crosshair/ring marker are painted directly onto it. The resulting pixel state is saved as `drawing.baseSnapshot` (an `ImageData` object). A toolbar below the canvas offers pen (freehand), rectangle, undo, and clear tools. Pointer event listeners let the reviewer draw on the canvas before submitting; undo replays `drawing.strokes[]` from the baseSnapshot.
5. Modal shows a feedback textarea. Name/email fields are visible for guests; for logged-in users they are hidden — the values are pre-populated from `tuftSettings` and submitted automatically without prompting.
6. Modal submit → `fetch( tuftSettings.restUrl + '/submit', { method: 'POST', … } )` with `X-WP-Nonce` header
7. On success: shows a thank-you message, auto-closes after 2.5 s

### Key implementation details

- The overlay has `pointer-events: none` so mouse events reach real page elements. The crosshair cursor is applied via `body.tuft-targeting * { cursor: crosshair !important }`.
- `stopImmediatePropagation()` in the capture-phase click listener prevents any subsequently-registered handler (including Atarim's click interceptor if both plugins are active) from seeing the click.
- `html2canvas` is bundled locally at `assets/js/vendor/html2canvas.min.js` — no CDN dependency. The `postinstall` npm hook keeps it in sync with the version declared in `package.json`.
- Form state capture skips `type=password`, `type=hidden`, `type=submit`, and `type=button` fields.
- `getSelector()` walks up the DOM up to 5 levels, preferring `#id` anchors and appending `:nth-of-type()` when siblings share the same tag.
- `ignoreElements` in the `html2canvas` call skips any element whose `id` starts with `tuft-`, preventing the plugin's own UI from appearing in screenshots.
- `canvas#tuft-screenshot-canvas` has `touch-action: none` so pointer events during drawing don't trigger scroll on touch devices. Its CSS sets `width: 100%`; its pixel dimensions (`canvas.width` / `canvas.height`) are set by JS to match `canvas.offsetWidth` × proportional height — so canvas coordinates equal CSS coordinates and no scaling is needed for pointer events.
- `drawing.baseSnapshot` (ImageData) is captured after the spotlight annotation is painted. `redrawCanvas()` calls `ctx.putImageData(baseSnapshot)` then replays all committed strokes, so Undo is O(n strokes) regardless of drawing complexity.
- `submit()` exports `drawCanvas.toDataURL('image/jpeg', 0.85)` when `drawing.baseSnapshot` is set — this includes both the spotlight annotation and any reviewer drawings. It falls back to the raw `data.screenshot` string only when html2canvas was unavailable and the canvas was never initialised.
- `teardownDrawCanvas()` removes all pointer event listeners; it is called from `closeModal()` so listeners are never leaked between sessions.

---

## Settings (`Tuft_Settings`)

Registered under **Settings → Tuft Feedback** (`options-general.php?page=tuft-settings`).

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `tuft_visibility` | string | `logged_in` | Who sees the widget: `everyone`, `logged_in`, `editors`, `admins` |
| `tuft_rate_limit` | int | `5` | Max submissions per IP per hour; `0` disables rate limiting |
| `tuft_notify_email` | string | `''` | Comma-separated email addresses for submission notifications |
| `tuft_webhook_urls` | string | `''` | Newline-separated webhook URLs; a JSON payload is POSTed to each on submission |

`Tuft_Settings::get_visibility()` is a `public static` method used by `tuft.php` during enqueue.

`sanitize_visibility()` falls back to `'logged_in'` for unknown values. `sanitize_webhook_urls()` runs each line through `esc_url_raw()` and drops blank or invalid entries. `sanitize_email_list()` runs each comma-delimited part through `sanitize_email()` and drops invalid addresses.

---

## REST API

**Namespace:** `tuft/v1`

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/submit` | POST | `__return_true` (public) | Create a feedback entry |

### `/submit` payload

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `feedback` | string | Yes | The visitor's feedback text |
| `pageUrl` | string | No | Absolute URL of the page |
| `pageTitle` | string | No | Document title |
| `selector` | string | No | CSS selector of clicked element |
| `xPercent` | float | No | Click X as % of viewport width |
| `yPercent` | float | No | Click Y as % of viewport height |
| `rectLeft` | float | No | Element bounding box left edge as % of viewport width |
| `rectTop` | float | No | Element bounding box top edge as % of viewport height |
| `rectWidth` | float | No | Element bounding box width as % of viewport width |
| `rectHeight` | float | No | Element bounding box height as % of viewport height |
| `viewportWidth` | int | No | Viewport width in px |
| `viewportHeight` | int | No | Viewport height in px |
| `formState` | array | No | Captured form field values |
| `userAgent` | string | No | `navigator.userAgent` |
| `name` | string | No | Submitter name — shown in modal for guests; auto-populated from WP session for logged-in users |
| `email` | string | No | Submitter email — same behaviour as `name` |
| `screenshot` | string | No | Base64 JPEG data URL (includes spotlight annotation and any canvas drawings); saved as a media attachment |

**Responses:** `{ "success": true, "id": <post_id> }` on 201; `{ "success": false, "message": "…" }` with status 429 when the rate limit is exceeded; WP error on 5xx.

---

## Data model (`tuft_feedback` CPT)

| Post field / meta key | Stores |
|-----------------------|--------|
| `post_title` | Auto-generated: `Feedback on "{page title}"` |
| `_tuft_feedback_text` | Full feedback text |
| `_tuft_page_url` | Source page URL |
| `_tuft_page_title` | Source page title |
| `_tuft_selector` | CSS selector of clicked element |
| `_tuft_x_percent` | Click X (% of viewport width) |
| `_tuft_y_percent` | Click Y (% of viewport height) |
| `_tuft_rect` | JSON object `{left, top, width, height}` — element bounding box as viewport percentages |
| `_tuft_viewport_w` | Viewport width in px |
| `_tuft_viewport_h` | Viewport height in px |
| `_tuft_form_state` | JSON-encoded form field snapshot |
| `_tuft_submitter_name` | Name — entered by guest, or sourced from WP user for logged-in submitters |
| `_tuft_submitter_email` | Email — same sourcing as `_tuft_submitter_name` |
| `_tuft_user_agent` | Browser user-agent string |
| `_tuft_submitter_ip` | Submitter IP address (used for rate limiting) |
| `_tuft_screenshot_id` | Attachment ID of the JPEG screenshot |
| `_tuft_alpaca_issue_id` | ID of the mirrored `alpaca_issue` (set by bridge) |

---

## Notifications (`Tuft_Notifications`)

Hooked to `tuft_feedback_submitted`. Reads `tuft_notify_email` and `tuft_webhook_urls` from options on each invocation (no caching).

### Email

`wp_mail()` is called once with all configured addresses as recipients. Subject: `[Tuft] New feedback on "{page title}"`. Body: plain-text with feedback text, page URL, element selector, submitter name/email, and admin edit link.

### Webhook payload

`wp_remote_post()` is called for each URL with `blocking: false` (fire-and-forget). Content-Type is `application/json`. `WP_Error` results are logged via `error_log()` only — failures never surface to the submitter. Each URL is validated with `filter_var( FILTER_VALIDATE_URL )` before the request is sent.

Payload shape:

```json
{
  "text":            "New feedback from Jane on \"About Us\": …",
  "id":              42,
  "feedback":        "…",
  "page_url":        "https://example.com/about/",
  "page_title":      "About Us",
  "selector":        ".hero .cta-button",
  "submitter_name":  "Jane",
  "submitter_email": "jane@example.com",
  "admin_url":       "https://example.com/wp-admin/post.php?post=42&action=edit",
  "submitted_at":    "2026-06-05T12:00:00+00:00"
}
```

The `text` field is a human-readable summary compatible with Slack incoming webhooks, Discord, and Teams. All other fields are available for Zapier/Make field mapping or custom endpoint parsing.

---

## Alpaca Issue Tracker integration (`Tuft_Alpaca_Bridge`)

The bridge is always loaded. It is a no-op when Alpaca is not active (`post_type_exists('alpaca_issue')` returns false).

When Alpaca is active, on every `tuft_feedback_submitted` action:

1. Creates an `alpaca_issue` whose `post_content` is the feedback text only.
2. Immediately inserts a context comment (`issuecomment` type) containing: the feedback text in a `<p>`, a metadata `<ul>` (page, element, coordinates, viewport, submitter), and an `<img>` of the screenshot. The spotlight, bounding box, crosshair, and any reviewer drawings are already baked into the JPEG on the client before submission — no server-side SVG overlay is applied. The comment is where reviewers see the full context; the issue body stays clean for the Kanban card title.
3. Reads `alpaistr_get_statuses()` and assigns the lowest-score status (the Alpaca "default" column), respecting the `alpaca_default_status` filter.
4. Prepends the new issue ID to `issue_order` term meta so it appears at the top of that column.
5. Stores `alpaca_url`, `alpaca_screenwidth`, `alpaca_screenheight` so Alpaca's own context display works.
6. Tags the issue with the detected browser (`alpaca_browser` taxonomy) and type `Tuft` (`alpaca_type` taxonomy).
7. Cross-references: `_tuft_alpaca_issue_id` on the `tuft_feedback` post; `alpaca_tuft_post_id` on the `alpaca_issue` post.
8. Calls `alpaistr_clear_board_cache()` so the board reflects the new issue immediately.

### Admin menu behaviour

When Alpaca is active, `Tuft_Post_Type::adjust_menu()` (hooked to `admin_menu` at priority 20):
- Removes the standalone "Tuft Feedback" top-level menu entry.
- Adds "Tuft Feedback" as a submenu under Alpaca's **Project Board** (`project-board`).

The standard CPT list table and post-edit screen (the expanded detail view with screenshot) remain fully functional at their existing URLs.

### Install-Alpaca notice

`Tuft_Post_Type::maybe_suggest_alpaca()` (hooked to `admin_notices`) shows a dismissible info notice on the Tuft list screen (`edit-tuft_feedback`) when Alpaca is **not** installed. The notice links to the Alpaca plugin-install page in wp-admin and is silently skipped when Alpaca is already active.

---

## WordPress Playground Workaround

When loaded in the WASM [WordPress Playground](https://playground.wordpress.net/) environment, CORS and Service Worker constraints prevent `html2canvas` from accessing enqueued external stylesheets (causing screenshots to render unstyled).

To resolve this, the Playground blueprint setup script ([.github/setup.php](.github/setup.php)) writes a Must-Use plugin (`tuft-playground-cors.php`) that hooks into the `style_loader_tag` filter. When a stylesheet handle matches `localhost`, `127.0.0.1`, `playground.wordpress.net`, or root-relative paths, it reads the CSS content from the WASM virtual filesystem and returns it as an inline `<style>` tag, converting the link to same-origin.

---

## Action hooks

| Hook | When | Args |
|------|------|------|
| `tuft_feedback_submitted` | After a `tuft_feedback` post and all its meta (including screenshot attachment) are fully saved | `$post_id` (int) |

Use this hook to integrate with other systems without modifying the REST controller. Built-in email and webhook notifications are also dispatched via this hook by `Tuft_Notifications`.

---

## Editing rules

- Keep changes scoped to the stated task.
- Follow WordPress coding standards: escape output (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`), sanitize input, use `$wpdb->prepare()` for any raw SQL.
- **Run linters before declaring any PHP/JS/CSS change done:**
  - PHP: `composer phpcs` (auto-fix with `composer phpcbf`)
  - JS + CSS: `npm run lint` (or individually `npm run lint:js` / `npm run lint:css`)
- The `/submit` endpoint uses `permission_callback => '__return_true'` intentionally — visibility gating happens at the enqueue level in `tuft.php`. Add `current_user_can()` checks to the REST controller only if the endpoint itself needs to be locked down.
- The Alpaca bridge must never hard-depend on Alpaca functions: always guard with `function_exists()` or `post_type_exists()` before calling.
- Do not modify `alpaca-issue-tracker` files; interact with it only through its public functions, filters, and the `alpaca_default_status` / `alpaca_user_can` filter hooks.
- `html2canvas` is bundled locally at `assets/js/vendor/html2canvas.min.js` — do not reintroduce a CDN dependency. To upgrade: bump the version in `package.json` and run `npm install` (the `postinstall` hook copies the new file). Test that the `ignoreElements` option (used to exclude elements whose `id` starts with `tuft-` from screenshots) still works after any upgrade.
- The FAB button face is `assets/img/fab-button.png` from the Tuft brand kit. Do not replace it with an inline SVG — the PNG preserves the rounded rendering of the mark. Its URL is passed to JS via `tuftSettings.buttonImg`.
- PHP class files must be named after the class with `class-` prepended and underscores converted to dashes, prefixed with `tuft-`: e.g. `Tuft_Post_Type` → `class-tuft-post-type.php`.
