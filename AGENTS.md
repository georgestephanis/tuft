# AGENTS.md

Guidance for AI agents and contributors working in this plugin.

## Scope

**Edit only:** `wp-content/plugins/design-feedback/` and all files beneath it.

**Read-only reference:** `wp-content/plugins/alpaca-issue-tracker/` — understand it to know what the bridge integrates with, but never modify it.

---

## What this plugin does

`design-feedback` is a standalone visual feedback tool. A floating "Feedback" button appears on every frontend page. Clicking it enters a targeting mode where the visitor can click any element on the page; the plugin captures the DOM selector, click coordinates, viewport size, form field state, and an optional screenshot, then presents a modal for the visitor to type their feedback. On submission everything is stored locally as a `design_feedback` custom post type.

If the **Alpaca Issue Tracker** plugin is also active, each submission is automatically mirrored as an `alpaca_issue` so it appears on the Alpaca Kanban board for triage. The two posts are cross-referenced via post meta.

---

## Files

| File | Purpose |
|------|---------|
| `design-feedback.php` | Plugin bootstrap: constants, enqueues frontend CSS/JS, passes `dfSettings` to JS |
| `includes/class-df-post-type.php` | `design_feedback` CPT, admin list-table columns, meta box detail view, Alpaca-aware menu placement, install-Alpaca notice |
| `includes/class-df-rest-controller.php` | `POST /design-feedback/v1/submit` — validates, stores the CPT post + meta + screenshot attachment, fires `df_feedback_submitted` |
| `includes/class-df-alpaca-bridge.php` | Listens to `df_feedback_submitted`; creates an `alpaca_issue` mirror when Alpaca is active |
| `assets/css/feedback.css` | All frontend UI styles: floating button, targeting overlay, element highlight, modal |
| `assets/js/feedback.js` | All frontend interaction: targeting mode, element capture, screenshot, modal, REST submission |

### Tooling

| File | Purpose |
|------|---------|
| `package.json` | `@wordpress/scripts` dev dependency; `lint:js`, `lint:css`, and `lint` scripts |
| `composer.json` | `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, installer; `phpcs`/`phpcbf` scripts |
| `phpcs.xml` | WordPress-Extra + WordPress-Docs ruleset, `df`/`DF` prefix, `design-feedback` text domain |
| `.eslintrc.json` | Extends `@wordpress/eslint-plugin/recommended`; declares `dfSettings`/`html2canvas` globals |
| `.stylelintrc.json` | Extends `@wordpress/stylelint-config`; disables `declaration-no-important` (intentional for targeting cursor) |
| `.eslintignore` | Excludes `vendor/` and `node_modules/` from ESLint |

---

## Frontend JS flow

1. `DF.init()` — builds and appends the floating button, overlay, highlight box, and modal to `document.body`.
2. Button click → `DF.enterTargeting()`:
   - Adds `body.df-targeting` (CSS crosshair cursor on everything)
   - Shows the dimmed overlay (purely visual, `pointer-events: none`)
   - Registers capture-phase `mouseover` (highlight update) and `click` (capture) listeners on `document`
3. Click in targeting mode → `DF.onTargetClick()`:
   - `preventDefault()` + `stopImmediatePropagation()` to suppress any element's own handlers
   - Records `{ selector, xPercent, yPercent, viewportWidth, viewportHeight, pageUrl, pageTitle, formState, userAgent }`
   - Exits targeting mode, waits two `requestAnimationFrame` ticks for the overlay to repaint away
   - Calls `html2canvas` on `document.documentElement` (visible viewport only); skips gracefully if offline
   - Opens the feedback modal
4. Modal submit → `fetch( dfSettings.restUrl + '/submit', { method: 'POST', … } )` with `X-WP-Nonce` header
5. On success: shows a thank-you message, auto-closes after 2.5 s

### Key implementation details

- The overlay has `pointer-events: none` so mouse events reach real page elements. The crosshair cursor is applied via `body.df-targeting * { cursor: crosshair !important }`.
- `stopImmediatePropagation()` in the capture-phase click listener prevents any subsequently-registered handler (including Atarim's click interceptor if both plugins are active) from seeing the click.
- `html2canvas` is loaded from `unpkg.com` at `wp_enqueue_scripts`. If the CDN is unreachable the screenshot field is simply omitted.
- Form state capture skips `type=password`, `type=hidden`, `type=submit`, and `type=button` fields.
- `getSelector()` walks up the DOM up to 5 levels, preferring `#id` anchors and appending `:nth-of-type()` when siblings share the same tag.

---

## REST API

**Namespace:** `design-feedback/v1`

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
| `viewportWidth` | int | No | Viewport width in px |
| `viewportHeight` | int | No | Viewport height in px |
| `formState` | array | No | Captured form field values |
| `userAgent` | string | No | `navigator.userAgent` |
| `name` | string | No | Submitter name (pre-filled from WP user if logged in) |
| `email` | string | No | Submitter email |
| `screenshot` | string | No | Base64 JPEG data URL; saved as a media attachment |

**Response:** `{ "success": true, "id": <post_id> }` on 201, or a WP error on 4xx/5xx.

---

## Data model (`design_feedback` CPT)

| Post field / meta key | Stores |
|-----------------------|--------|
| `post_title` | Auto-generated: `Feedback on "{page title}"` |
| `_df_feedback_text` | Full feedback text |
| `_df_page_url` | Source page URL |
| `_df_page_title` | Source page title |
| `_df_selector` | CSS selector of clicked element |
| `_df_x_percent` | Click X (% of viewport width) |
| `_df_y_percent` | Click Y (% of viewport height) |
| `_df_viewport_w` | Viewport width in px |
| `_df_viewport_h` | Viewport height in px |
| `_df_form_state` | JSON-encoded form field snapshot |
| `_df_submitter_name` | Name from form or logged-in user |
| `_df_submitter_email` | Email from form or logged-in user |
| `_df_user_agent` | Browser user-agent string |
| `_df_screenshot_id` | Attachment ID of the JPEG screenshot |
| `_df_alpaca_issue_id` | ID of the mirrored `alpaca_issue` (set by bridge) |

---

## Alpaca Issue Tracker integration (`DF_Alpaca_Bridge`)

The bridge is always loaded. It is a no-op when Alpaca is not active (`post_type_exists('alpaca_issue')` returns false).

When Alpaca is active, on every `df_feedback_submitted` action:

1. Creates an `alpaca_issue` whose `post_content` is the feedback text plus a plain-text context block (page, element, coordinates, viewport, submitter).
2. Reads `alpaistr_get_statuses()` and assigns the lowest-score status (the Alpaca "default" column), respecting the `alpaca_default_status` filter.
3. Prepends the new issue ID to `issue_order` term meta so it appears at the top of that column.
4. Stores `alpaca_url`, `alpaca_screenwidth`, `alpaca_screenheight` so Alpaca's own context display works.
5. Tags the issue with the detected browser (`alpaca_browser` taxonomy) and type `Design Feedback` (`alpaca_type` taxonomy).
6. Cross-references: `_df_alpaca_issue_id` on the `design_feedback` post; `alpaca_df_post_id` on the `alpaca_issue` post.
7. Calls `alpaistr_clear_board_cache()` so the board reflects the new issue immediately.

### Admin menu behaviour

When Alpaca is active, `DF_Post_Type::adjust_menu()` (hooked to `admin_menu` at priority 20):
- Removes the standalone "Design Feedback" top-level menu entry.
- Adds "Design Feedback" as a submenu under Alpaca's **Project Board** (`project-board`).

The standard CPT list table and post-edit screen (the expanded detail view with screenshot) remain fully functional at their existing URLs.

### Install-Alpaca notice

`DF_Post_Type::maybe_suggest_alpaca()` (hooked to `admin_notices`) shows a dismissible info notice on the Design Feedback list screen (`edit-design_feedback`) when Alpaca is **not** installed. The notice links to the Alpaca plugin-install page in wp-admin and is silently skipped when Alpaca is already active.

---

## Action hooks

| Hook | When | Args |
|------|------|------|
| `df_feedback_submitted` | After a `design_feedback` post and all its meta (including screenshot attachment) are fully saved | `$post_id` (int) |

Use this hook to integrate with other systems without modifying the REST controller.

---

## Editing rules

- Keep changes scoped to the stated task.
- Follow WordPress coding standards: escape output (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`), sanitize input, use `$wpdb->prepare()` for any raw SQL.
- **Run linters before declaring any PHP/JS/CSS change done:**
  - PHP: `composer phpcs` (auto-fix with `composer phpcbf`)
  - JS + CSS: `npm run lint` (or individually `npm run lint:js` / `npm run lint:css`)
- The `/submit` endpoint uses `permission_callback => '__return_true'` intentionally — this plugin is for local/staging use. Add `current_user_can()` checks before deploying to production.
- The Alpaca bridge must never hard-depend on Alpaca functions: always guard with `function_exists()` or `post_type_exists()` before calling.
- Do not modify `alpaca-issue-tracker` files; interact with it only through its public functions, filters, and the `alpaca_default_status` / `alpaca_user_can` filter hooks.
- `html2canvas` is loaded from a CDN. Do not bundle it or switch CDNs without testing that `ignoreElements` (used to exclude our own UI) still works.
- PHP class files must be named after the class with `class-` prepended and underscores converted to dashes, prefixed with `df-`: e.g. `DF_Post_Type` → `class-df-post-type.php`.
