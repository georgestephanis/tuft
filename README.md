# Design Feedback

Visual design feedback for WordPress. A floating button lets anyone on the frontend click any element on the page, capture context, and submit a note — all without leaving the browser.

---

## Features

- **Click-to-annotate** — click the "Feedback" button, then click any element on the page. The plugin captures the DOM selector, viewport coordinates, viewport dimensions, and form field state automatically.
- **In-browser screenshots** — uses [html2canvas](https://html2canvas.hertzen.com/) to capture the visible viewport at submission time. Falls back gracefully if offline.
- **Feedback modal** — collects the visitor's text, name, and email. Pre-fills name/email for logged-in users.
- **Local storage** — submissions stored as a `design_feedback` custom post type with full metadata and a screenshot attachment.
- **Alpaca Issue Tracker integration** — when [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is installed, every submission is automatically mirrored as a Kanban issue on the Alpaca board.

---

## User flow

```
Frontend page
  │
  └─ Fixed "Feedback" button (right edge of screen)
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
  • html2canvas screenshot of visible viewport
       │
       ▼
  Feedback modal
  • Screenshot thumbnail
  • Name / email fields (pre-filled if logged in)
  • Feedback textarea (required)
  • Submit / Cancel
       │
       ▼ submit
  POST /wp-json/design-feedback/v1/submit
       │
       ▼
  design_feedback CPT post created
  Screenshot saved as media attachment
  df_feedback_submitted action fires
       │
       ▼ (if Alpaca active)
  alpaca_issue created, assigned to default board column
```

---

## Admin review

### Without Alpaca

Submissions appear under **Design Feedback** in the admin menu. The list table shows:

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

The "Design Feedback" menu item moves under **Project Board** (Alpaca's menu). The Alpaca board becomes the primary review and triage UI — issues are tagged `Design Feedback` and `Browser: Chrome` (or whichever browser was detected) so they can be filtered.

For visual details (screenshot, selector, click coordinates, form state) that the Alpaca board does not display, click **Design Feedback** in the Project Board submenu to reach the list table, then click any row to open the full detail view.

---

## Alpaca Issue Tracker integration

Install [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) to get a Kanban board for tracking and triaging design feedback.

When both plugins are active:

- Each submission automatically creates a matching `alpaca_issue`.
- The issue body contains the feedback text plus a context block (page, element, coordinates, viewport, submitter).
- The issue is placed at the top of the lowest-score column (your "inbox" column).
- The issue is tagged with the submitter's browser and type `Design Feedback`.
- Both posts are cross-referenced: the `design_feedback` post stores the Alpaca issue ID, and the Alpaca issue stores the `design_feedback` post ID.
- The "Design Feedback" admin menu entry moves under Project Board.

Removing Alpaca does not affect stored `design_feedback` posts. The cross-reference meta keys (`_df_alpaca_issue_id`, `alpaca_df_post_id`) become inert but are otherwise harmless.

---

## REST API

**Base URL:** `/wp-json/design-feedback/v1`

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
  "viewportWidth":  1440,
  "viewportHeight": 900,
  "formState":      [{ "index": 0, "id": "contact-form", "fields": { "name": "Alice" } }],
  "userAgent":      "Mozilla/5.0 ...",
  "name":           "Alice Reviewer",
  "email":          "alice@example.com",
  "screenshot":     "data:image/jpeg;base64,..."
}
```

**Response:**

```json
{ "success": true, "id": 42 }
```

---

## Extension hook

```php
add_action( 'df_feedback_submitted', function ( int $post_id ) {
    // $post_id is the design_feedback post.
    // All meta is already saved; the screenshot attachment (if any) is attached.
    $feedback = get_post_meta( $post_id, '_df_feedback_text', true );
    $page_url = get_post_meta( $post_id, '_df_page_url', true );
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

1. Place the plugin in `wp-content/plugins/design-feedback/`.
2. Activate **Design Feedback**.
3. Visit any frontend page — the "Feedback" button appears on the right edge of the screen.
4. Optionally install and activate **Alpaca Issue Tracker** for board-based triage.

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

---

## Main files

- [design-feedback.php](design-feedback.php) — bootstrap, script enqueues, `dfSettings` localization
- [includes/class-df-post-type.php](includes/class-df-post-type.php) — CPT registration, admin columns, detail meta box, Alpaca menu placement, install-Alpaca notice
- [includes/class-df-rest-controller.php](includes/class-df-rest-controller.php) — `POST /submit` endpoint, screenshot attachment saving
- [includes/class-df-alpaca-bridge.php](includes/class-df-alpaca-bridge.php) — Alpaca Issue Tracker integration
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
