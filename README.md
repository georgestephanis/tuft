# Tuft

![Tuft — Soft on clients. Sharp on the details.](assets/wporg/banner-772x250.png)

[![Try in WordPress Playground](https://img.shields.io/badge/Try%20in-WordPress%20Playground-blue?style=for-the-badge&logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/georgestephanis/tuft/trunk/.github/blueprint.json)

Visual design feedback for WordPress. A floating button lets anyone on the frontend click any element on the page, capture context, and submit a note — all without leaving the browser.

---

## Features

- **Click-to-annotate** — click the "Feedback" button, then click any element on the page. The plugin captures the DOM selector, viewport coordinates, viewport dimensions, and form field state automatically.
- **In-browser screenshots** — uses [html2canvas](https://html2canvas.hertzen.com/) (bundled locally, no CDN dependency) to capture the visible viewport at submission time.
- **Feedback modal** — collects the visitor's feedback text. Features an annotated screenshot preview that dynamically centers its crop focal point around your click coordinates (via CSS `object-position`), ensuring the target element is never cropped out of view. Logged-in users are not prompted for name or email — their account details are used automatically. Guest visitors see name and email fields.
- **Local storage** — submissions stored as a `tuft_feedback` custom post type with full metadata and a screenshot attachment.
- **Alpaca Issue Tracker integration** — when [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is installed, every submission is automatically mirrored as a Kanban issue on the Alpaca board.

---

## User flow

```
Frontend page
  │
  └─ Circle FAB button (~2/3 down the right edge of the screen)
       │
       ▼ click
  Targeting mode
  • Page dims, cursor becomes crosshair
  • Hovered element is highlighted
  • Esc cancels
       │
       ▼ click element
  Context captured:
  • CSS selector of clicked element
  • Click coordinates (% of viewport)
  • Viewport dimensions
  • Form field values (excluding passwords)
  • html2canvas screenshot of visible viewport (bundled, always available)
       │
       ▼
  Feedback modal
  • Screenshot thumbnail
  • Name / email fields (guests only — hidden for logged-in users)
  • Feedback textarea (required)
  • Submit / Cancel
       │
       ▼ submit
  POST /wp-json/tuft/v1/submit
       │
       ▼
  tuft_feedback CPT post created
  Screenshot saved as media attachment
  tuft_feedback_submitted action fires
       │
       ▼ (if Alpaca active)
  alpaca_issue created, assigned to default board column
```

---

## Admin review

### Without Alpaca

Submissions appear under **Tuft** in the admin menu. The list table shows:

| Column | Contents |
|--------|---------|
| Feedback | Truncated feedback text (links to detail view) |
| Page | Linked page title and URL |
| Element | CSS selector of the annotated element |
| Submitted By | Name and email |
| Screenshot | Thumbnail |
| Date | Submission date |

Clicking a row opens the **detail view**: full feedback text, all metadata, and the full-size screenshot.

### With Alpaca Issue Tracker installed

The "Tuft" menu item moves under **Project Board** (Alpaca's menu). The Alpaca board becomes the primary review and triage UI — issues are tagged `Tuft` and `Browser: Chrome` (or whichever browser was detected) so they can be filtered.

For visual details (screenshot, selector, click coordinates, form state) that the Alpaca board does not display, click **Tuft** in the Project Board submenu to reach the list table, then click any row to open the full detail view.

---

## Alpaca Issue Tracker integration

Install [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) to get a Kanban board for tracking and triaging design feedback.

When both plugins are active:

- Each submission automatically creates a matching `alpaca_issue`.
- The issue body contains the feedback text. A first `issuecomment` holds the full context: feedback text, metadata (page, element, coordinates, viewport, submitter), and the annotated screenshot. The spotlight, bounding box, crosshair, and any reviewer drawings are baked into the JPEG on the client — no server-side overlay is added.
- The issue is placed at the top of the lowest-score column (your "inbox" column).
- The issue is tagged with the submitter's browser and type `Tuft`.
- Both posts are cross-referenced: the `tuft_feedback` post stores the Alpaca issue ID, and the Alpaca issue stores the `tuft_feedback` post ID.
- The "Tuft" admin menu entry moves under Project Board.

Removing Alpaca does not affect stored `tuft_feedback` posts. The cross-reference meta keys (`_tuft_alpaca_issue_id`, `alpaca_tuft_post_id`) become inert but are otherwise harmless.

---

## REST API

**Base URL:** `/wp-json/tuft/v1`

### `POST /submit`

Open to all visitors (no authentication required). Intended for local/staging use — add capability checks before exposing on a public production site.

**Body (JSON):**

```json
{
  "feedback":       "The CTA button is hard to find.",
  "pageUrl":        "https://example.com/about/",
  "pageTitle":      "About Us",
  "selector":       "header.site-header > .cta-button",
  "xPercent":       "62.3",
  "yPercent":       "18.7",
  "rectLeft":       "38.5",
  "rectTop":        "15.2",
  "rectWidth":      "18.4",
  "rectHeight":     "6.1",
  "viewportWidth":  1440,
  "viewportHeight": 900,
  "formState":      [{ "index": 0, "id": "contact-form", "fields": { "name": "Alice" } }],
  "userAgent":      "Mozilla/5.0 ...",
  "name":           "Alice Reviewer",
  "email":          "alice@example.com",
  "screenshot":     "data:image/jpeg;base64,..."
}
```

`name` and `email` are populated automatically from the submitter's WordPress account when they are logged in — the modal does not display those fields in that case.

**Response:**

```json
{ "success": true, "id": 42 }
```

---

## Extension hook

```php
add_action( 'tuft_feedback_submitted', function ( int $post_id ) {
    // $post_id is the tuft_feedback post.
    // All meta is already saved; the screenshot attachment (if any) is attached.
    $feedback = get_post_meta( $post_id, '_tuft_feedback_text', true );
    $page_url = get_post_meta( $post_id, '_tuft_page_url', true );
    // ... send a Slack notification, create a GitHub issue, etc.
} );
```

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Optional: [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) for Kanban board tracking

---

## Installation

1. Place the plugin in `wp-content/plugins/tuft/`.
2. Activate **Tuft**.
3. Visit any frontend page — the Tuft FAB button appears ~2/3 down the right edge of the screen.
4. Optionally install and activate **Alpaca Issue Tracker** for board-based triage.

---

## Brand assets

The Tuft brand kit lives in two places within the plugin:

| Path | Purpose |
|------|---------|
| `assets/img/fab-button.png` | FAB button face (coral disc + puff mark, 2× density). Served to the frontend; URL passed to JS via `tuftSettings.buttonImg`. |
| `assets/wporg/icon-128x128.png` | WordPress.org directory icon (128 px) |
| `assets/wporg/icon-256x256.png` | WordPress.org directory icon @2× |
| `assets/wporg/banner-772x250.png` | WordPress.org directory banner |
| `assets/wporg/banner-1544x500.png` | WordPress.org directory banner @2× |

**WordPress.org deployment:** the `assets/wporg/` files belong in the SVN `assets/` directory *outside* the plugin zip (sibling to `trunk/`). Copy them there before publishing.

**Palette:** Dusty coral `#e08a7e` · Sage `#9fbe9c` · Lilac `#c9b6d9` · Cream `#fbf4ec` · Cocoa `#43352f`. Full CSS custom property tokens are in the brand kit at `tokens/tuft-tokens.css`.

---

## Security notes

The `/submit` REST endpoint uses `__return_true` as its permission callback, making it open to unauthenticated requests. This is intentional for local and staging environments where anonymous client feedback is the goal. Before deploying to a publicly accessible production site, add a capability check:

```php
// Example: restrict to logged-in contributors and above
'permission_callback' => function() {
    return current_user_can( 'edit_posts' );
},
```

Screenshots are stored as JPEG media attachments. Base64 data is decoded server-side and written to the uploads directory via `file_put_contents`. Validate upload directory permissions in hardened environments.

html2canvas is bundled in `assets/js/vendor/html2canvas.min.js` and served directly from the plugin — no CDN dependency. To update it: bump the version in `package.json`, run `npm install`, and the `postinstall` hook copies the new file automatically.

---

## WordPress Playground CORS Workaround

When running inside the [WordPress Playground](https://playground.wordpress.net/) WASM environment, the Service Worker routing and asset loading rules cause enqueued stylesheets to trigger browser cross-origin (CORS) blocks during screenshot generation. This prevents `html2canvas` from reading `sheet.cssRules` and results in unstyled screenshots.

To resolve this, the demo setup script ([.github/setup.php](.github/setup.php)) writes a Must-Use plugin (`tuft-playground-cors.php`) that hooks into `style_loader_tag`. It dynamically reads local stylesheet assets from the WASM virtual filesystem and returns them as inline `<style>` blocks. This converts cross-origin CSS links into same-origin style tags, allowing `html2canvas` to render screenshots fully styled.

---

## Main files

- [tuft.php](tuft.php) — bootstrap, script enqueues, `tuftSettings` localization
- [includes/class-tuft-post-type.php](includes/class-tuft-post-type.php) — CPT registration, admin columns, detail meta box, Alpaca menu placement, install-Alpaca notice
- [includes/class-tuft-rest-controller.php](includes/class-tuft-rest-controller.php) — `POST /submit` endpoint, screenshot attachment saving
- [includes/class-tuft-alpaca-bridge.php](includes/class-tuft-alpaca-bridge.php) — Alpaca Issue Tracker integration
- [assets/css/feedback.css](assets/css/feedback.css) — floating button, targeting overlay, element highlight, modal
- [assets/js/feedback.js](assets/js/feedback.js) — targeting mode, screenshot capture, modal, REST submission

## Development

```bash
# Install PHP linting tools
composer install

# Install JS/CSS linting tools
npm install

# Lint everything
npm run lint        # JS + CSS via @wordpress/scripts
composer phpcs      # PHP via WPCS

# Auto-fix what can be fixed
composer phpcbf     # PHP
npm run lint:js -- --fix   # JS (prettier)
```
